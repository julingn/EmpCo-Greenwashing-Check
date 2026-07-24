<?php
// Screenshot der Fundstelle eines Findings (on-demand, gecacht als PNG)
require __DIR__ . '/app/config.php';
require __DIR__ . '/app/db.php';

if (!has_user_access()) {
    http_response_code(403);
    exit;
}

$fid = (int)($_GET['fid'] ?? 0);
if ($fid <= 0) {
    http_response_code(400);
    exit;
}

set_time_limit(0);

$dir = __DIR__ . '/app/assets/previews';
$out = $dir . '/f' . $fid . '.png';

// Cache-Treffer direkt ausliefern
if (is_file($out) && filesize($out) > 0) {
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=86400');
    readfile($out);
    exit;
}

try {
    db_init();
    $st = db()->prepare(
        "SELECT f.snippet, COALESCE(p.url, a.source_ref) AS url
         FROM findings f
         LEFT JOIN pages p ON p.id = f.page_id
         JOIN analyses a ON a.id = f.analysis_id
         WHERE f.id = :id"
    );
    $st->execute([':id' => $fid]);
    $row = $st->fetch();

    if (!$row || empty($row['url'])) {
        http_response_code(404);
        exit;
    }

    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    $snipFile = tempnam(sys_get_temp_dir(), 'snip');
    file_put_contents($snipFile, (string)$row['snippet']);

    $cmd = 'node ' . escapeshellarg(__DIR__ . '/preview_shot.mjs')
        . ' ' . escapeshellarg((string)$row['url'])
        . ' ' . escapeshellarg($snipFile)
        . ' ' . escapeshellarg($out) . ' 2>&1';

    $output = [];
    $code = 1;
    @exec($cmd, $output, $code);
    @unlink($snipFile);

    if ($code === 0 && is_file($out) && filesize($out) > 0) {
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=86400');
        readfile($out);
        exit;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Screenshot fehlgeschlagen.';
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}
