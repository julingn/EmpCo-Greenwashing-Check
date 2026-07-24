<?php
// Prüf-Archiv: Übersicht aller bisherigen Prüfläufe
require __DIR__ . '/app/config.php';
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/layout.php';

if (!has_user_access()) {
    header('Location: /');
    exit;
}

// Prüflauf löschen (kaskadiert auf Seiten/Findings/Kandidaten/Umformulierungen)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_analysis') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        try {
            db()->prepare("DELETE FROM analyses WHERE id = :id")->execute([':id' => (int)($_POST['id'] ?? 0)]);
        } catch (Throwable $e) { /* ignoriert */ }
    }
    header('Location: /archive.php');
    exit;
}

$error = '';
$rows = [];
try {
    db_init();
    $rows = db()->query(
        "SELECT a.*,
            (SELECT COUNT(*) FROM pages p    WHERE p.analysis_id = a.id) AS n_pages,
            (SELECT COUNT(*) FROM findings f WHERE f.analysis_id = a.id) AS n_total,
            (SELECT COUNT(*) FROM findings f WHERE f.analysis_id = a.id AND f.severity = 'violation') AS n_viol,
            (SELECT COUNT(*) FROM findings f WHERE f.analysis_id = a.id AND f.severity = 'warn')      AS n_warn,
            (SELECT COUNT(*) FROM findings f WHERE f.analysis_id = a.id AND f.severity = 'info')      AS n_info
         FROM analyses a
         ORDER BY a.created_at DESC
         LIMIT 300"
    )->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$scopeLabel = ['exact' => 'Nur exakte URL', 'depth1' => 'Tiefe 1', 'depth2' => 'Tiefe 2', 'full' => 'Ganze Domain', 'pdf' => 'PDF-Dokument'];
$statusLabel = ['running' => 'läuft', 'done' => 'fertig', 'error' => 'Fehler', 'pending' => 'wartet'];

page_head('Prüf-Archiv — EmpCo Greenwashing-Check', 'archive');
?>
<h1>Prüf-Archiv</h1>
<p class="sub">Alle bisherigen Prüfläufe. Ergebnis erneut öffnen oder einen Lauf löschen.</p>

<?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>

<?php if (!$rows): ?>
  <div class="card"><p class="sub" style="margin:0">Noch keine Prüfläufe vorhanden. <a href="/">Neue Analyse starten</a>.</p></div>
<?php else: ?>
  <?php foreach ($rows as $r):
      $sc = (string)($r['scope'] ?? '');
      $isRunning = ($r['status'] ?? '') === 'running';
  ?>
    <div class="card" style="padding:16px 20px;margin-bottom:12px">
      <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <a href="/results.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;color:var(--text);text-decoration:none;word-break:break-all">
            <?= h($r['source_ref']) ?>
          </a>
          <div style="color:var(--text2);font-size:13px;margin-top:6px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <span><?= h($scopeLabel[$sc] ?? $sc) ?></span>
            <span>· <?= (int)$r['n_pages'] ?> Seite<?= (int)$r['n_pages'] === 1 ? '' : 'n' ?></span>
            <?php if (!empty($r['use_js'])): ?><span class="badge info">JS</span><?php endif; ?>
            <?php if (!empty($r['use_ocr'])): ?><span class="badge info">OCR</span><?php endif; ?>
            <span>· <?= h($statusLabel[$r['status']] ?? (string)$r['status']) ?></span>
            <span style="color:var(--text3)">· <?= h((string)$r['created_at']) ?></span>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            <?php if ($isRunning): ?>
              <span class="sev-chip warn"><span class="dot"></span>läuft …</span>
            <?php elseif ((int)$r['n_total'] === 0): ?>
              <span class="sev-chip info"><span class="dot"></span>keine Findings</span>
            <?php else: ?>
              <?php if ((int)$r['n_viol']): ?><span class="sev-chip violation"><span class="dot"></span><?= (int)$r['n_viol'] ?> Verstoß</span><?php endif; ?>
              <?php if ((int)$r['n_warn']): ?><span class="sev-chip warn"><span class="dot"></span><?= (int)$r['n_warn'] ?> Prüfen</span><?php endif; ?>
              <?php if ((int)$r['n_info']): ?><span class="sev-chip info"><span class="dot"></span><?= (int)$r['n_info'] ?> Hinweis</span><?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;flex-shrink:0">
          <a href="/results.php?id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" style="margin:0">Öffnen</a>
          <form method="post" action="/archive.php" style="margin:0" onsubmit="return confirm('Diesen Prüflauf wirklich löschen?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_analysis">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn-soft" style="margin:0">Löschen</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php
page_foot();
