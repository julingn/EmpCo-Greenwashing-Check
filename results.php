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
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$sevLabel = ['violation' => 'Verstoß', 'warn' => 'Prüfen', 'info' => 'Hinweis'];
$statusLabel = ['open' => 'offen', 'ignored' => 'ignoriert', 'done' => 'erledigt'];
$checkLabel = ['text' => 'Text', 'code' => 'Code', 'js' => 'JS', 'ocr' => 'OCR'];
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
      Umfang: <?= h($analysis['scope']) ?> · Sprache: <?= h($analysis['language']) ?> · Status: <?= h($analysis['status']) ?> · <?= h($analysis['created_at']) ?>
    </div>
    <?php foreach ($pages as $pg): $checks = json_decode((string)$pg['checks'], true) ?: []; ?>
      <div class="check-status" style="margin-top:12px">
        <?php foreach ($checkLabel as $k => $lbl):
            $st = $checks[$k] ?? 'skipped';
            $cls = $st === 'ok' ? 'ok' : ($st === 'failed' ? 'violation' : 'skipped');
            $sym = $st === 'ok' ? '✓' : ($st === 'failed' ? '✕' : '–');
        ?>
          <span class="badge <?= $cls ?>"><?= $sym ?> <?= h($lbl) ?></span>
        <?php endforeach; ?>
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
    <div class="hint" id="ptext">Fundstellen werden geprüft…</div>
  </div>
  <script>
  (function(){
    var id = <?= (int)$id ?>;
    var bar = document.getElementById('bar'), pct = document.getElementById('pct'), ptext = document.getElementById('ptext');
    function step(){
      fetch('/analyze_step.php?id='+id)
        .then(function(r){ return r.json(); })
        .then(function(d){
          if(d.error){ ptext.textContent = 'Fehler: '+d.error; return; }
          var total = d.total||0, done = d.processed||0;
          var p = total ? Math.round(done/total*100) : 100;
          bar.style.width = p+'%'; pct.innerHTML = p+'&nbsp;%';
          ptext.textContent = done+' von '+total+' Fundstellen geprüft';
          if(d.finished){ ptext.textContent = 'Fertig — Ergebnis wird geladen…'; setTimeout(function(){ location.reload(); }, 600); }
          else { setTimeout(step, 300); }
        })
        .catch(function(){ setTimeout(step, 1500); });
    }
    step();
  })();
  </script>
  <?php else: ?>

  <div class="card" style="padding:14px 20px;font-size:13px;color:var(--text2)">
    <strong style="color:var(--text)">Legende</strong>
    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px">
      <span><span class="badge violation">Verstoß</span> klar irreführend / unbelegt</span>
      <span><span class="badge warn">Prüfen</span> kontextabhängig — manuell prüfen</span>
      <span><span class="badge info">Hinweis</span> Trigger vorhanden, eher unkritisch</span>
    </div>
    <div style="margin-top:12px;display:flex;gap:18px;flex-wrap:wrap;align-items:center">
      <span>Prüfungen:</span>
      <span><span class="badge ok">✓</span> durchgeführt</span>
      <span><span class="badge skipped">–</span> nicht durchgeführt</span>
      <span><span class="badge violation">✕</span> fehlgeschlagen</span>
      <span style="color:var(--text3)">Aktuell: Text &amp; Code aktiv · JS &amp; OCR folgen später.</span>
    </div>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:flex-end;margin:24px 0 14px;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0 0 8px"><?= count($findings) ?> Finding<?= count($findings) === 1 ? '' : 's' ?></h2>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($sevCounts['violation']): ?><span class="sev-chip violation"><span class="dot"></span><?= (int)$sevCounts['violation'] ?> Verstoß</span><?php endif; ?>
        <?php if ($sevCounts['warn']): ?><span class="sev-chip warn"><span class="dot"></span><?= (int)$sevCounts['warn'] ?> Prüfen</span><?php endif; ?>
        <?php if ($sevCounts['info']): ?><span class="sev-chip info"><span class="dot"></span><?= (int)$sevCounts['info'] ?> Hinweis</span><?php endif; ?>
        <span style="font-size:12px;color:var(--text3);align-self:center">· <?= (int)$counts['open'] ?> offen · <?= (int)$counts['done'] ?> erledigt · <?= (int)$counts['ignored'] ?> ignoriert</span>
      </div>
    </div>
    <?php if ($findings): ?>
      <a href="/export.php?id=<?= (int)$id ?>" class="btn btn-download">⭳ Export CSV/Excel</a>
    <?php endif; ?>
  </div>

  <?php if (!$findings): ?>
    <div class="card"><p class="sub" style="margin:0">Keine Verstöße gefunden.</p></div>
  <?php else: ?>
    <?php foreach ($findings as $f):
        $sev = $f['severity'];
        $st  = $f['status'];
        $resolved = $st !== 'open' ? ' resolved' : '';
    ?>
      <div class="finding <?= h($sev) . $resolved ?>">
        <div class="finding-head">
          <span class="sev-name"><?= h($sevLabel[$sev] ?? $sev) ?></span>
          <span class="finding-cat"><?= h($f['category']) ?></span>
          <?php if ($st !== 'open'): ?><span class="finding-status">· <?= h($statusLabel[$st] ?? $st) ?></span><?php endif; ?>
          <span class="finding-meta"><?= h($f['rule_id']) ?><br><?= h($f['content_type']) ?></span>
        </div>
        <div class="finding-quote">„<?= h($f['snippet']) ?>"</div>
        <div class="finding-assess"><?= h($f['assessment']) ?></div>
        <div class="finding-actions">
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
<?php
page_foot();
