<?php
// Ergebnisseite eines Prüflaufs
require __DIR__ . '/app/config.php';
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/layout.php';

if (!has_user_access()) {
    header('Location: /');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$error = '';
$analysis = null;
$pages = [];
$findings = [];
$reforms = [];
$tovActive = false;

// Status eines Findings ändern (Ignorieren/Erledigt/Zurücksetzen)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finding_status') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        $st = in_array($_POST['status'] ?? '', ['open', 'ignored', 'done'], true) ? $_POST['status'] : 'open';
        try {
            db()->prepare("UPDATE findings SET status = :s WHERE id = :fid AND analysis_id = :a")
                ->execute([':s' => $st, ':fid' => (int)($_POST['fid'] ?? 0), ':a' => $id]);
        } catch (Throwable $e) { /* ignoriert */ }
    }
    header('Location: /results.php?id=' . $id);
    exit;
}

// Nachweis-Check (Stufe B): prüft, ob ein Beleg vorliegt → belegen oder umformulieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_evidence') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        require_once __DIR__ . '/app/analyzer.php';
        set_time_limit(0);
        try {
            db_init();
            nachweis_check((int)($_POST['fid'] ?? 0));
        } catch (Throwable $e) { /* ignoriert */ }
    }
    header('Location: /results.php?id=' . $id . '#f' . (int)($_POST['fid'] ?? 0));
    exit;
}

// Umformulierung generieren (Stufe C): KI mit Few-Shot-Beispielen + passenden Belegen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reformulate') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        require_once __DIR__ . '/app/analyzer.php';
        set_time_limit(0);
        try {
            db_init();
            generate_reformulation((int)($_POST['fid'] ?? 0));
        } catch (Throwable $e) { /* ignoriert */ }
    }
    header('Location: /results.php?id=' . $id . '#f' . (int)($_POST['fid'] ?? 0));
    exit;
}

// Tonalitäts-Schliff (Stufe 3b, manuell): wendet den Brand-Voice-Redakteur auf die
// vorhandene Umformulierung an (Basis = ggf. editierter Textarea-Inhalt).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tone_of_voice') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        require_once __DIR__ . '/app/analyzer.php';
        set_time_limit(0);
        try {
            db_init();
            tone_reformulation((int)($_POST['fid'] ?? 0), trim($_POST['reform_text'] ?? ''));
        } catch (Throwable $e) { /* ignoriert */ }
    }
    header('Location: /results.php?id=' . $id . '#f' . (int)($_POST['fid'] ?? 0));
    exit;
}

// Umformulierung übernehmen (ggf. bearbeitet)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reformulation_accept') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        $rid = (int)($_POST['rid'] ?? 0);
        $txt = trim($_POST['reform_text'] ?? '');
        $accAgents = trim($_POST['acc_agents'] ?? '');
        try {
            $row = db()->prepare("SELECT finding_id FROM reformulations WHERE id = :id");
            $row->execute([':id' => $rid]);
            $fidOf = (int)($row->fetchColumn() ?: 0);
            db()->prepare("UPDATE reformulations SET text = :t, accepted = TRUE, tov_text = NULL, agents_used = COALESCE(NULLIF(:ag, ''), agents_used) WHERE id = :id")
                ->execute([':t' => mb_substr($txt, 0, 4000), ':ag' => $accAgents, ':id' => $rid]);
            if ($fidOf > 0) {
                db()->prepare("DELETE FROM reformulations WHERE finding_id = :f AND id <> :id")
                    ->execute([':f' => $fidOf, ':id' => $rid]);
                require_once __DIR__ . '/app/analyzer.php';
                learn_from_reformulation($fidOf, $txt);
            }
        } catch (Throwable $e) { /* ignoriert */ }
    }
    header('Location: /results.php?id=' . $id . '#f' . (int)($_POST['fid'] ?? 0));
    exit;
}

// Umformulierung verwerfen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reformulation_reject') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        try {
            db()->prepare("DELETE FROM reformulations WHERE id = :id")->execute([':id' => (int)($_POST['rid'] ?? 0)]);
        } catch (Throwable $e) { /* ignoriert */ }
    }
    header('Location: /results.php?id=' . $id . '#f' . (int)($_POST['fid'] ?? 0));
    exit;
}

try {
    db_init();
    $stmt = db()->prepare("SELECT * FROM analyses WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $analysis = $stmt->fetch();
    if ($analysis) {
        $p = db()->prepare("SELECT * FROM pages WHERE analysis_id = :id ORDER BY id");
        $p->execute([':id' => $id]);
        $pages = $p->fetchAll();
        $f = db()->prepare("SELECT * FROM findings WHERE analysis_id = :id ORDER BY status, severity, category, rule_id");
        $f->execute([':id' => $id]);
        $findings = $f->fetchAll();
        $rq = db()->prepare("SELECT r.* FROM reformulations r JOIN findings f ON f.id = r.finding_id WHERE f.analysis_id = :id ORDER BY r.accepted DESC, r.id DESC");
        $rq->execute([':id' => $id]);
        foreach ($rq->fetchAll() as $rr) {
            $rfid = (int)$rr['finding_id'];
            if (!isset($reforms[$rfid])) { $reforms[$rfid] = $rr; }
        }
        // Ist der Tonalitäts-Redakteur (Brand Voice) aktiv? Steuert den ToV-Button.
        $tovActive = trim(tone_prompt()) !== '';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$sevLabel = ['violation' => 'Verstoß', 'warn' => 'Prüfen', 'info' => 'Hinweis'];
$statusLabel = ['open' => 'offen', 'ignored' => 'ignoriert', 'done' => 'erledigt'];
$checkLabel = ['text' => 'Text', 'code' => 'Code', 'js' => 'JS', 'ocr' => 'OCR'];
$scopeLabel = ['exact' => 'Nur exakte URL', 'depth1' => 'Tiefe 1', 'depth2' => 'Tiefe 2', 'full' => 'Ganze Domain', 'pdf' => 'PDF-Dokument'];
$pageUrls = [];
foreach ($pages as $pg) { $pageUrls[(int)$pg['id']] = (string)$pg['url']; }
$counts = ['open' => 0, 'ignored' => 0, 'done' => 0];
foreach ($findings as $ff) { $counts[$ff['status']] = ($counts[$ff['status']] ?? 0) + 1; }
$sevCounts = ['violation' => 0, 'warn' => 0, 'info' => 0];
foreach ($findings as $ff) { $sevCounts[$ff['severity']] = ($sevCounts[$ff['severity']] ?? 0) + 1; }

page_head('Ergebnis — EmpCo Greenwashing-Check', 'analyse');
?>
<div style="display:flex;justify-content:space-between;align-items:center">
  <h1 style="margin:0">Prüfergebnis</h1>
  <a href="/" class="btn btn-ghost btn-sm" style="margin:0">Neue Analyse</a>
</div>

<?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>

<?php if (!$analysis): ?>
  <div class="alert err">Prüflauf nicht gefunden.</div>
<?php else: ?>
  <div class="card">
    <div style="word-break:break-all"><strong>Quelle:</strong> <?= h($analysis['source_ref']) ?></div>
    <div style="color:var(--text2);font-size:14px;margin-top:6px">
      Umfang: <?= h($scopeLabel[$analysis['scope']] ?? $analysis['scope']) ?> · Sprache: <?= h($analysis['language']) ?> · Status: <?= h($analysis['status']) ?> · <?= count($pages) ?> Seite<?= count($pages) === 1 ? '' : 'n' ?> · <?= h($analysis['created_at']) ?>
    </div>
    <?php foreach ($pages as $pg): $checks = json_decode((string)$pg['checks'], true) ?: []; ?>
      <div style="margin-top:12px">
        <?php if (count($pages) > 1): ?>
          <div style="font-size:12px;color:var(--text2);word-break:break-all;margin-bottom:5px"><?= h($pg['url']) ?></div>
        <?php endif; ?>
        <div class="check-status">
          <?php foreach ($checkLabel as $k => $lbl):
              $st = $checks[$k] ?? 'skipped';
              $cls = $st === 'ok' ? 'ok' : ($st === 'failed' ? 'violation' : 'skipped');
              $sym = $st === 'ok' ? '✓' : ($st === 'failed' ? '✕' : '–');
          ?>
            <span class="badge <?= $cls ?>"><?= $sym ?> <?= h($lbl) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($analysis['status'] === 'running'): ?>
  <div class="card">
    <div class="progress-head">
      <div><span class="spinner"></span> <strong>Analyse läuft…</strong></div>
      <div class="progress-pct" id="pct">0&nbsp;%</div>
    </div>
    <div class="progress-bar-bg"><div class="progress-bar-fill" id="bar"></div></div>
    <div class="hint" id="ptext">Seiten werden gelesen…</div>
  </div>
  <script>
  (function(){
    var id = <?= (int)$id ?>;
    var bar = document.getElementById('bar'), pct = document.getElementById('pct'), ptext = document.getElementById('ptext');
    function set(p, txt){ p = Math.max(0, Math.min(100, Math.round(p))); bar.style.width = p+'%'; pct.innerHTML = p+'&nbsp;%'; ptext.textContent = txt; }
    function step(){
      fetch('/analyze_step.php?id='+id)
        .then(function(r){ return r.json(); })
        .then(function(d){
          if(d.error){ ptext.textContent = 'Fehler: '+d.error; return; }
          if(d.finished){ set(100, 'Fertig — Ergebnis wird geladen…'); setTimeout(function(){ location.reload(); }, 600); return; }
          if(d.phase === 'crawl'){
            var pt = d.pagesTotal||0, pf = d.pagesFetched||0;
            var p = pt ? (pf/pt*100) : 0;
            set(p*0.5, 'Seiten werden gelesen… ('+pf+' / '+pt+')');
          } else {
            var t = d.candTotal||0, dn = d.candDone||0;
            var p = t ? (dn/t*100) : 100;
            set(50 + p*0.5, dn+' von '+t+' Fundstellen geprüft');
          }
          setTimeout(step, 300);
        })
        .catch(function(){ setTimeout(step, 1500); });
    }
    step();
  })();
  </script>
  <?php else: ?>

  <?php if (!$findings): ?>
    <div class="card"><p class="sub" style="margin:0">Keine Verstöße gefunden.</p></div>
  <?php else:
    $totalF = count($findings);
    $donutSegs = [
        ['Verstoß', 'var(--red)',    (int)$sevCounts['violation'], 'klar irreführend / unbelegt'],
        ['Prüfen',  'var(--amber)',  (int)$sevCounts['warn'],      'kontextabhängig — manuell prüfen'],
        ['Hinweis', 'var(--accent)', (int)$sevCounts['info'],      'Trigger vorhanden, eher unkritisch'],
    ];
    $R = 42; $SW = 15; $C = 2 * M_PI * $R; $CX = 60; $CY = 60; $acc = 0.0;
  ?>
    <div class="summary-card">
      <div class="summary-main">
        <div class="summary-donut">
          <svg width="120" height="120" viewBox="0 0 120 120">
            <circle cx="<?= $CX ?>" cy="<?= $CY ?>" r="<?= $R ?>" fill="none" stroke="var(--border)" stroke-width="<?= $SW ?>"/>
            <?php foreach ($donutSegs as $s): if ($s[2] <= 0) { continue; }
                $frac = $s[2] / $totalF; $len = $frac * $C; $angle = -90 + ($acc * 360); $acc += $frac; ?>
            <circle cx="<?= $CX ?>" cy="<?= $CY ?>" r="<?= $R ?>" fill="none" stroke="<?= $s[1] ?>" stroke-width="<?= $SW ?>"
                    stroke-dasharray="<?= round($len, 1) ?> <?= round($C, 1) ?>"
                    transform="rotate(<?= round($angle, 2) ?> <?= $CX ?> <?= $CY ?>)"/>
            <?php endforeach; ?>
          </svg>
          <div class="donut-center">
            <div class="donut-num"><?= $totalF ?></div>
            <div class="donut-lbl">Finding<?= $totalF === 1 ? '' : 's' ?></div>
          </div>
        </div>
        <div class="summary-legend">
          <?php foreach ($donutSegs as $s): $share = round($s[2] / $totalF * 100); ?>
          <div class="legend-row">
            <span class="legend-dot" style="background:<?= $s[1] ?>"></span>
            <span class="legend-name" style="color:<?= $s[1] ?>"><?= h($s[0]) ?></span>
            <span class="legend-count"><?= $s[2] ?></span>
            <span class="legend-share"><?= $share ?> %</span>
            <span class="legend-desc"><?= h($s[3]) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="summary-side">
          <a href="/export.php?id=<?= (int)$id ?>" class="btn btn-download">⭳ Export CSV/Excel</a>
          <div class="summary-status"><b><?= (int)$counts['open'] ?></b> offen · <b><?= (int)$counts['done'] ?></b> erledigt · <b><?= (int)$counts['ignored'] ?></b> ignoriert</div>
        </div>
      </div>
      <div class="summary-foot">
        <span>Prüfungen:</span>
        <span><span class="badge ok">✓</span> durchgeführt</span>
        <span><span class="badge skipped">–</span> nicht durchgeführt</span>
        <span><span class="badge violation">✕</span> fehlgeschlagen</span>
        <span style="color:var(--text3)">Text &amp; Code immer aktiv · JS &amp; OCR je nach gewählter Analyse-Option.</span>
      </div>
    </div>

    <?php foreach ($findings as $f):
        $sev = $f['severity'];
        $st  = $f['status'];
        $resolved = $st !== 'open' ? ' resolved' : '';
        $pgUrl = $pageUrls[(int)$f['page_id']] ?? '';
        $pgPath = $pgUrl !== '' ? (parse_url($pgUrl, PHP_URL_PATH) ?: '/') : '';
        $rf = $reforms[(int)$f['id']] ?? null;
    ?>
      <div class="finding <?= h($sev) . $resolved ?>" id="f<?= (int)$f['id'] ?>">
        <div class="finding-head">
          <span class="sev-name"><?= h($sevLabel[$sev] ?? $sev) ?></span>
          <span class="finding-cat"><?= h($f['category']) ?></span>
          <?php if (preg_match('#^https?://#i', (string)$pgUrl)): ?>
          <span class="preview-wrap">
            <button type="button" class="preview-btn" aria-label="Vorschau der Fundstelle">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              Preview
            </button>
            <span class="preview-pop">
              <span class="preview-loading">Screenshot wird erstellt …</span>
              <img alt="Fundstelle" data-src="/preview.php?fid=<?= (int)$f['id'] ?>">
            </span>
          </span>
          <?php endif; ?>
          <?php if (count($pages) > 1 && $pgUrl !== ''): ?><a class="finding-page" href="<?= h($pgUrl) ?>" target="_blank" rel="noopener" title="<?= h($pgUrl) ?>">↗ <?= h($pgPath) ?></a><?php endif; ?>
          <?php if ($st !== 'open'): ?><span class="finding-status">· <?= h($statusLabel[$st] ?? $st) ?></span><?php endif; ?>
          <span class="finding-meta"><?= h($f['rule_id']) ?><br><?= h($f['content_type']) ?></span>
        </div>
        <div class="finding-quote">„<?= h($f['snippet']) ?>"</div>
        <div class="finding-assess"><?= h($f['assessment']) ?></div>
        <?php if (!empty($f['remedy_path'])):
            $rp = $f['remedy_path'];
            $rpLabel = ['belegbar' => 'Belegbar', 'belegt_anpassen' => 'Beleg vorhanden — Formulierung anpassen', 'nicht_belegbar' => 'Nicht belegbar — umformulieren'][$rp] ?? $rp;
            $rpCls = $rp === 'belegbar' ? 'ok' : ($rp === 'nicht_belegbar' ? 'violation' : 'warn');
        ?>
          <div class="remedy">
            <span class="badge <?= $rpCls ?>"><?= h($rpLabel) ?></span>
            <?php if (!empty($f['remedy_evidence'])): ?><span class="remedy-ev">Beleg: <?= h($f['remedy_evidence']) ?></span><?php endif; ?>
            <?php if (!empty($f['remedy_note'])): ?><div class="remedy-note"><?= h($f['remedy_note']) ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($rf):
            $accepted = !empty($rf['accepted']);
            $kindLbl = $rf['kind'] === 'example' ? 'Vorschlag (geprüftes Beispiel)' : ($rf['kind'] === 'manual' ? 'Manuell' : 'KI-Vorschlag');
            $baseAgents = $rf['kind'] === 'example' ? 'Rechtsgeprüftes Beispiel' : 'EmpCo-Redakteur';
            $tovAgents = 'EmpCo-Redakteur + Tonalität (Brand Voice)';
            $hasTov = !$accepted && !empty($rf['tov_text']);
        ?>
          <?php if ($accepted): ?>
          <div class="reform accepted">
            <div class="reform-tag">✓ Übernommene Umformulierung<?php if (!empty($rf['agents_used'])): ?> <span class="reform-agents">· <?= h($rf['agents_used']) ?></span><?php endif; ?></div>
            <form method="post" action="/results.php?id=<?= (int)$id ?>" style="margin:0">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= (int)$rf['id'] ?>">
              <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
              <textarea name="reform_text" class="reform-text"><?= h($rf['text']) ?></textarea>
              <div class="reform-actions">
                <button type="submit" name="action" value="reformulation_accept" class="btn-soft ok">✓ Änderung speichern</button>
                <button type="submit" name="action" value="reformulation_reject" class="btn-soft" formnovalidate>✕ Verwerfen</button>
              </div>
            </form>
          </div>
          <?php else: ?>
          <div class="reform">
            <div class="reform-tag">✎ <?= h($kindLbl) ?> <span class="reform-agents">· EmpCo-konform</span></div>
            <form method="post" action="/results.php?id=<?= (int)$id ?>" style="margin:0">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= (int)$rf['id'] ?>">
              <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="acc_agents" value="<?= h($baseAgents) ?>">
              <textarea name="reform_text" class="reform-text"><?= h($rf['text']) ?></textarea>
              <div class="reform-actions">
                <button type="submit" name="action" value="reformulation_accept" class="btn-soft ok">✓ Übernehmen</button>
                <?php if ($tovActive): ?><button type="submit" name="action" value="tone_of_voice" class="btn-soft" formnovalidate title="Erzeugt aus diesem Text eine MVV-Brand-Voice-Fassung"><?= $hasTov ? '🎨 Tonalität neu erzeugen' : '🎨 Tonalität anpassen' ?></button><?php endif; ?>
                <button type="submit" name="action" value="reformulation_reject" class="btn-soft" formnovalidate>✕ Verwerfen</button>
              </div>
            </form>
          </div>
          <?php if ($hasTov): ?>
          <div class="reform reform-tov">
            <div class="reform-tag">🎨 Mit MVV Brand Voice <span class="reform-agents">· <?= h($tovAgents) ?></span></div>
            <form method="post" action="/results.php?id=<?= (int)$id ?>" style="margin:0">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= (int)$rf['id'] ?>">
              <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="acc_agents" value="<?= h($tovAgents) ?>">
              <textarea name="reform_text" class="reform-text"><?= h($rf['tov_text']) ?></textarea>
              <div class="reform-actions">
                <button type="submit" name="action" value="reformulation_accept" class="btn-soft ok">✓ Diese Version übernehmen</button>
              </div>
            </form>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
        <div class="finding-actions">
          <form method="post" action="/results.php?id=<?= (int)$id ?>" style="margin:0">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="check_evidence">
            <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
            <button type="submit" class="btn-soft"><?= empty($f['remedy_path']) ? '🔎 Nachweis prüfen' : '↻ Erneut prüfen' ?></button>
          </form>
          <form method="post" action="/results.php?id=<?= (int)$id ?>" style="margin:0">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="reformulate">
            <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
            <button type="submit" class="btn-soft"><?= $rf ? '✎ Neu umformulieren' : '✎ Umformulieren' ?></button>
          </form>
          <?php if ($st === 'open'): ?>
            <form method="post" action="/results.php?id=<?= (int)$id ?>" style="margin:0">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="finding_status">
              <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="status" value="done">
              <button type="submit" class="btn-soft ok">✓ Erledigt</button>
            </form>
            <form method="post" action="/results.php?id=<?= (int)$id ?>" style="margin:0">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="finding_status">
              <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="status" value="ignored">
              <button type="submit" class="btn-soft">⊘ Ignorieren</button>
            </form>
          <?php else: ?>
            <form method="post" action="/results.php?id=<?= (int)$id ?>" style="margin:0">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="finding_status">
              <input type="hidden" name="fid" value="<?= (int)$f['id'] ?>">
              <input type="hidden" name="status" value="open">
              <button type="submit" class="btn-soft">↺ Zurücksetzen</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<script>
document.querySelectorAll('.preview-wrap').forEach(function(w){
  var img = w.querySelector('img[data-src]');
  var loading = w.querySelector('.preview-loading');
  var loaded = false;
  w.addEventListener('mouseenter', function(){
    if(loaded || !img){ return; }
    loaded = true;
    img.addEventListener('load', function(){ if(loading){ loading.style.display='none'; } });
    img.addEventListener('error', function(){ if(loading){ loading.textContent='Vorschau nicht verfügbar.'; } });
    img.src = img.getAttribute('data-src');
  });
});
</script>
<?php
page_foot();
