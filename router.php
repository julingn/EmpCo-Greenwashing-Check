<?php
// Router für den eingebauten PHP-Server (Railway)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Existierende Dateien (admin.php, assets) direkt ausliefern
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// Alles andere -> Startseite
require __DIR__ . '/index.php';
