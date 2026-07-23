<?php
// Verarbeitet den nächsten Kandidaten-Block einer laufenden Analyse (JSON-Endpunkt)
require __DIR__ . '/app/config.php';
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/analyzer.php';

header('Content-Type: application/json');

if (!has_user_access()) {
    http_response_code(403);
    echo json_encode(['error' => 'Kein Zugang']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige ID']);
    exit;
}

// Session-Lock freigeben, damit parallele Requests nicht blockieren
session_write_close();
set_time_limit(0);

try {
    db_init();
    $r = process_step($id, 12);
    echo json_encode($r);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
