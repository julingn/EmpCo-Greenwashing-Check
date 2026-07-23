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
try {
    db_init();
    $stmt = db()->prepare("SELECT * FROM analyses WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $analysis = $stmt->fetch();
    if ($analysis) {
        $p = db()->prepare("SELECT * FROM pages WHERE analysis_id = :id ORDER BY id");
        $p->execute([':id' => $id]);
        $pages = $p->fetchAll();
        $f = db()->prepare("SELECT * FROM findings WHERE analysis_id = :id ORDER BY severity, category, rule_id");
        $f->execute([':id' => $id]);
        $findings = $f->fetchAll();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$sevLabel = ['violation' => 'Verstoß', 'warn' => 'Prüfen', 'info' => 'Hinweis'];
$checkLabel = ['text' => 'Text', 'code' => 'Code', 'js' => 'JS', 'ocr' => 'OCR'];

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

  <h2 style="margin:24px 0 12px">
    <?= count($findings) ?> Finding<?= count($findings) === 1 ? '' : 's' ?>
  </h2>

  <?php if (!$findings): ?>
    <div class="card"><p class="sub" style="margin:0">Keine Verstöße gefunden.</p></div>
  <?php else: ?>
    <?php foreach ($findings as $f):
        $sev = $f['severity'];
        $bg = $sev === 'violation' ? 'var(--red-bg)' : ($sev === 'warn' ? 'var(--amber-bg)' : 'var(--accent-bg)');
        $bd = $sev === 'violation' ? 'var(--red-border)' : ($sev === 'warn' ? 'var(--amber-border)' : 'var(--accent-border)');
    ?>
      <div class="card" style="padding:16px 20px">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span class="badge <?= h($sev) ?>"><?= h($sevLabel[$sev] ?? $sev) ?></span>
          <span class="tag"><?= h($f['category']) ?></span>
          <span class="mono" style="color:var(--text3);font-size:12px"><?= h($f['rule_id']) ?></span>
          <span class="badge skipped"><?= h($f['content_type']) ?></span>
        </div>
        <blockquote style="margin:12px 0 8px;padding:10px 14px;border-left:3px solid <?= $bd ?>;background:<?= $bg ?>;border-radius:6px;color:var(--text)">
          „<?= h($f['snippet']) ?>"
        </blockquote>
        <div style="color:var(--text2);font-size:14px"><?= h($f['assessment']) ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
<?php
page_foot();
