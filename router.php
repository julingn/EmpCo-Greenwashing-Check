<?php
// Router für den eingebauten PHP-Server (Railway)
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . ltrim(urldecode($uri), '/');
$file = __DIR__ . $path;
$real = realpath($file);
$root = realpath(__DIR__);

// Sensible Dateien/Ordner nie direkt ausliefern (nur über die App erreichbar):
// interne Doku, Regeln, Node-/Build-Dateien, App-PHP-Quellen (außer assets).
$ext          = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$blockedExt   = ['md', 'mjs', 'toml', 'csv', 'xlsx', 'xls', 'lock', 'sh', 'sql', 'env'];
$blockedName  = ['dockerfile', 'procfile', 'package.json', 'package-lock.json', 'composer.json', 'composer.lock'];
$blockedDir   = (bool) preg_match('#^/(documents|\.git|node_modules)(/|$)#i', $path);
$appInternal  = (bool) preg_match('#^/app/(?!assets/)#i', $path); // app/*.php etc., aber app/assets/* erlaubt
$blocked      = $blockedDir
    || $appInternal
    || in_array($ext, $blockedExt, true)
    || in_array(strtolower(basename($path)), $blockedName, true);

// Existierende, nicht gesperrte Dateien direkt ausliefern (bzw. PHP ausführen).
// realpath-Check verhindert Path-Traversal aus dem Projektordner heraus.
if ($uri !== '/' && $real !== false && $root !== false
    && str_starts_with($real, $root) && !is_dir($real) && !$blocked) {
    return false;
}

// Alles andere -> Startseite
require __DIR__ . '/index.php';
