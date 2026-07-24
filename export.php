<?php
// Export der Findings eines Prüflaufs als CSV (Excel-kompatibel)
require __DIR__ . '/app/config.php';
require __DIR__ . '/app/db.php';

if (!has_user_access()) {
    header('Location: /');
    exit;
}

$id = (int)($_GET['id'] ?? 0);

try {
    db_init();
    $a = db()->prepare("SELECT * FROM analyses WHERE id = :id");
    $a->execute([':id' => $id]);
    $analysis = $a->fetch();
    if (!$analysis) { http_response_code(404); exit('Prüflauf nicht gefunden.'); }

    $f = db()->prepare("SELECT f.*, p.url AS page_url FROM findings f LEFT JOIN pages p ON p.id = f.page_id WHERE f.analysis_id = :id ORDER BY f.status, f.severity, f.category, f.rule_id");
    $f->execute([':id' => $id]);
    $findings = $f->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Fehler: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
}

$sevLabel = ['violation' => 'Verstoß', 'warn' => 'Prüfen', 'info' => 'Hinweis'];
$statusLabel = ['open' => 'offen', 'ignored' => 'ignoriert', 'done' => 'erledigt'];

$filename = 'empco_pruefung_' . $id . '_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel

$delim = ';';
fputcsv($out, ['Quelle', $analysis['source_ref']], $delim);
fputcsv($out, ['Umfang', $analysis['scope'], 'Sprache', $analysis['language'], 'Datum', $analysis['created_at']], $delim);
fputcsv($out, [], $delim);
fputcsv($out, ['Rule-ID', 'Kategorie', 'Schweregrad', 'Status', 'Inhaltsart', 'Seite', 'Fundstelle', 'Begründung'], $delim);

foreach ($findings as $r) {
    fputcsv($out, [
        $r['rule_id'],
        $r['category'],
        $sevLabel[$r['severity']] ?? $r['severity'],
        $statusLabel[$r['status']] ?? $r['status'],
        $r['content_type'],
        $r['page_url'] ?? '',
        $r['snippet'],
        $r['assessment'],
    ], $delim);
}
fclose($out);
