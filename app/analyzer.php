<?php
// Analyse-Engine: URL auslesen, Inhalte gegen Regeln prüfen (Trigger + KI)
require_once __DIR__ . '/ai.php';

/** Lädt eine URL herunter. */
function fetch_url(string $url, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; EmpCo-Greenwashing-Check/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $html = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['html' => $html === false ? '' : $html, 'code' => $code, 'error' => $err];
}

/** Extrahiert sichtbaren Text + Attribut-Texte (Tooltips/Alt) aus HTML. */
function extract_content(string $html): array {
    $attrs = [];
    if (preg_match_all('/(?:title|alt|aria-label)\s*=\s*"([^"]{3,300})"/i', $html, $m)) {
        $attrs = array_values(array_unique(array_map('trim', $m[1])));
    }
    // Navigation, Skripte, Styles, SVG und Kommentare entfernen (Menü-Rauschen raus)
    $clean = preg_replace('#<(script|style|noscript|nav|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $clean = preg_replace('#<!--.*?-->#s', ' ', $clean) ?? $clean;
    $text  = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text  = preg_replace('/\s+/u', ' ', $text);
    return ['text' => trim((string)$text), 'attrs' => $attrs];
}

/** Wählt Regeln, deren Trigger-Begriffe im Text/Attributen vorkommen. */
function candidate_rules(array $rules, string $haystack): array {
    $out = [];
    foreach ($rules as $r) {
        $terms = array_filter(array_map('trim', explode(',', (string)$r['trigger_terms'])));
        foreach ($terms as $term) {
            if ($term !== '' && mb_stripos($haystack, $term) !== false) {
                $out[] = $r;
                break;
            }
        }
    }
    return $out;
}

/**
 * Bereitet eine Analyse vor: Seed-URL als erste (noch ungelesene) Seite einreihen.
 * Das eigentliche Lesen (Crawl) + KI-Bewertung erfolgt schrittweise über process_step().
 * @return array{total:int, note:string}
 */
function prepare_analysis(int $analysisId, string $url, string $scope = 'exact'): array {
    $seed = preg_replace('/#.*$/', '', trim($url)); // Fragment entfernen
    db()->prepare(
        "INSERT INTO pages (analysis_id, url, depth, status, checks)
         VALUES (:a, :u, 0, 'pending', NULL)"
    )->execute([':a' => $analysisId, ':u' => mb_substr((string)$seed, 0, 500)]);
    db()->prepare("UPDATE analyses SET status='running' WHERE id=:id")->execute([':id' => $analysisId]);
    return ['total' => 0, 'note' => ''];
}

/** Umfang → maximale relative Pfad-Tiefe, Seiten-Obergrenze und Domain-Modus. */
function scope_config(string $scope): array {
    return match ($scope) {
        'depth1' => ['maxDepth' => 1,           'maxPages' => 25, 'wholeDomain' => false],
        'depth2' => ['maxDepth' => 2,           'maxPages' => 50, 'wholeDomain' => false],
        'full'   => ['maxDepth' => PHP_INT_MAX, 'maxPages' => 80, 'wholeDomain' => true],
        default  => ['maxDepth' => 0,           'maxPages' => 1,  'wholeDomain' => false],
    };
}

/** Extrahiert Text aus einem PDF (poppler-utils / pdftotext). */
function extract_pdf_text(string $path): string {
    $cmd = 'pdftotext -enc UTF-8 -q ' . escapeshellarg($path) . ' - 2>/dev/null';
    $out = @shell_exec($cmd);
    if (!is_string($out)) { $out = ''; }
    $out = preg_replace('/\s+/u', ' ', $out) ?? $out;
    return trim((string)$out);
}

/** Bereitet eine PDF-Analyse vor: Text extrahieren → Seite + Kandidaten (kein Crawl). */
function prepare_pdf_analysis(int $analysisId, string $pdfPath, string $filename): array {
    $text = extract_pdf_text($pdfPath);
    $checks = ['text' => $text !== '' ? 'ok' : 'failed', 'code' => 'skipped', 'js' => 'skipped', 'ocr' => 'skipped'];

    $stmt = db()->prepare(
        "INSERT INTO pages (analysis_id, url, depth, status, checks)
         VALUES (:a, :u, 0, 'fetched', :c) RETURNING id"
    );
    $stmt->execute([':a' => $analysisId, ':u' => mb_substr('PDF: ' . $filename, 0, 500), ':c' => json_encode($checks)]);
    $pageId = (int) $stmt->fetchColumn();

    if ($text === '') {
        db()->prepare("UPDATE analyses SET status='done' WHERE id=:id")->execute([':id' => $analysisId]);
        return ['total' => 0];
    }

    $rules = db()->query("SELECT * FROM rules WHERE active ORDER BY rule_id")->fetchAll();
    $cands = build_candidates($rules, $text, []);
    $ins = db()->prepare(
        "INSERT INTO candidates (analysis_id, page_id, rule_id, category, content_type, snippet)
         VALUES (:a, :p, :rid, :cat, :ct, :snip)"
    );
    foreach ($cands as $c) {
        $ins->execute([
            ':a' => $analysisId, ':p' => $pageId, ':rid' => $c['rule_id'],
            ':cat' => $c['category'], ':ct' => $c['content_type'], ':snip' => mb_substr($c['snippet'], 0, 1000),
        ]);
    }
    return ['total' => count($cands)];
}

/** Normalisiert einen Host (führendes www. entfernen). */
function norm_host(string $h): string {
    return preg_replace('#^www\.#i', '', strtolower($h)) ?? strtolower($h);
}

/** Gleiche Website (Host-Vergleich ohne www)? */
function same_site(string $a, string $b): bool {
    return $a !== '' && $b !== '' && norm_host($a) === norm_host($b);
}

/** Zerlegt einen Pfad in nicht-leere Segmente. */
function path_segments(string $path): array {
    return array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
}

/** Ermittelt Pfad-Prefix und Segmentanzahl der Ausgangs-URL. */
function seed_prefix(string $seedUrl): array {
    $path = parse_url($seedUrl, PHP_URL_PATH) ?: '/';
    $segs = path_segments($path);
    return ['prefix' => $segs ? '/' . implode('/', $segs) : '', 'segs' => count($segs)];
}

/**
 * Darf dieser Link laut Umfang gecrawlt werden?
 * - gleiche Website (Host ohne www)
 * - „Ganze Domain": alle Seiten
 * - sonst: Pfad muss unter dem Ausgangs-Prefix liegen UND relative Tiefe ≤ maxDepth
 */
function link_allowed(string $link, array $seed, array $cfg): bool {
    $host = (string) (parse_url($link, PHP_URL_HOST) ?: '');
    if (!same_site($host, $seed['host'])) { return false; }
    if (!empty($cfg['wholeDomain'])) { return true; }
    $path = parse_url($link, PHP_URL_PATH) ?: '/';
    if ($seed['prefix'] !== '') {
        if ($path !== $seed['prefix'] && !str_starts_with($path, $seed['prefix'] . '/')) { return false; }
    }
    $rel = count(path_segments($path)) - (int) $seed['segs'];
    return $rel >= 0 && $rel <= $cfg['maxDepth'];
}

/** Löst ../ und ./ in einer absoluten URL auf und normalisiert Host/Slash. */
function normalize_path_url(string $url): string {
    $p = parse_url($url);
    if (!isset($p['host'])) { return $url; }
    $scheme = $p['scheme'] ?? 'https';
    $host   = strtolower($p['host']);
    $port   = isset($p['port']) ? ':' . $p['port'] : '';
    $path   = $p['path'] ?? '/';
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') { continue; }
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    $newPath = '/' . implode('/', $out);
    if ($newPath !== '/' && substr($newPath, -1) === '/') { $newPath = rtrim($newPath, '/'); }
    return $scheme . '://' . $host . $port . $newPath;
}

/** Wandelt einen (evtl. relativen) Link in eine absolute, prüfbare http(s)-URL um. */
function resolve_url(string $base, string $rel): ?string {
    $rel = trim($rel);
    if ($rel === '' || preg_match('~^(mailto:|tel:|javascript:|data:|#)~i', $rel)) { return null; }

    if (preg_match('#^https?://#i', $rel)) {
        $abs = $rel;
    } elseif (str_starts_with($rel, '//')) {
        $bp = parse_url($base);
        $abs = ($bp['scheme'] ?? 'https') . ':' . $rel;
    } else {
        $bp = parse_url($base);
        if (!isset($bp['scheme'], $bp['host'])) { return null; }
        $origin = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');
        if (str_starts_with($rel, '/')) {
            $abs = $origin . $rel;
        } else {
            $dir = preg_replace('#/[^/]*$#', '/', $bp['path'] ?? '/');
            if ($dir === null || $dir === '') { $dir = '/'; }
            $abs = $origin . $dir . $rel;
        }
    }
    $abs = preg_replace('/#.*$/', '', $abs);   // Fragment entfernen
    $abs = preg_replace('/\?.*$/', '', $abs);  // Query entfernen (begrenzt Crawl-Explosion)
    if (!is_string($abs) || !preg_match('#^https?://#i', $abs)) { return null; }
    // Datei-Endungen überspringen, die wir nicht als Text auslesen
    if (preg_match('#\.(pdf|jpe?g|png|gif|svg|webp|zip|docx?|xlsx?|pptx?|mp4|mp3|css|js|ico|woff2?|ttf)$#i', $abs)) { return null; }
    return normalize_path_url($abs);
}

/** Extrahiert alle absoluten Links (<a href>) aus HTML (auch unquotierte hrefs). */
function extract_links(string $html, string $baseUrl): array {
    $links = [];
    if (preg_match_all('#<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $set) {
            $href = '';
            if (isset($set[1]) && $set[1] !== '')      { $href = $set[1]; }
            elseif (isset($set[2]) && $set[2] !== '')  { $href = $set[2]; }
            elseif (isset($set[3]) && $set[3] !== '')  { $href = $set[3]; }
            if ($href === '') { continue; }
            $abs = resolve_url($baseUrl, html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($abs !== null) { $links[$abs] = true; }
        }
    }
    return array_keys($links);
}

/**
 * Sammelt URLs aus der/den Sitemap(s) einer Website (robots.txt + /sitemap.xml, inkl. Sitemap-Index).
 * @return string[] normalisierte, absolute URLs
 */
function fetch_sitemap_urls(string $origin, array $extraSitemaps = [], int $maxUrls = 3000): array {
    $origin = rtrim($origin, '/');
    $queue  = [];
    foreach ($extraSitemaps as $sm) { $sm = trim((string)$sm); if ($sm !== '') { $queue[] = $sm; } }

    // 1) robots.txt nach "Sitemap:"-Einträgen durchsuchen
    $robots = fetch_url($origin . '/robots.txt', 12);
    if ($robots['code'] < 400 && $robots['html'] !== '' && preg_match_all('/^\s*Sitemap:\s*(\S+)/im', $robots['html'], $rm)) {
        foreach ($rm[1] as $s) { $queue[] = trim($s); }
    }
    // 2) Standard-Sitemap als Fallback
    $queue[] = $origin . '/sitemap.xml';

    $urls = [];
    $seen = [];
    $fetched = 0;
    while ($queue && $fetched < 12 && count($urls) < $maxUrls) {
        $sm = array_shift($queue);
        if ($sm === '' || isset($seen[$sm])) { continue; }
        $seen[$sm] = true;

        $res = fetch_url($sm, 12);
        $fetched++;
        if ($res['code'] >= 400 || $res['html'] === '') { continue; }
        $xml = $res['html'];

        if (!preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $xml, $mm)) { continue; }
        $isIndex = stripos($xml, '<sitemapindex') !== false;
        foreach ($mm[1] as $loc) {
            $loc = html_entity_decode(trim($loc), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($loc === '') { continue; }
            if ($isIndex) {
                if (!isset($seen[$loc])) { $queue[] = $loc; }
            } else {
                $abs = resolve_url($origin, $loc);
                if ($abs !== null) { $urls[$abs] = true; }
                if (count($urls) >= $maxUrls) { break; }
            }
        }
    }
    return array_keys($urls);
}

/** Im Admin gepflegte Sitemap-URLs, die zur geprüften Domain passen. */
function configured_sitemaps_for_host(string $host): array {
    if ($host === '') { return []; }
    $out = [];
    try {
        foreach (db()->query("SELECT url FROM sitemaps ORDER BY id")->fetchAll() as $r) {
            $u = (string) $r['url'];
            $h = (string) (parse_url($u, PHP_URL_HOST) ?: '');
            if (same_site($h, $host)) { $out[] = $u; }
        }
    } catch (Throwable $e) { /* Tabelle evtl. noch nicht vorhanden */ }
    return $out;
}

/** Rendert eine URL headless (JS ausgeführt) → text/attrs/links/images oder null. */
function render_url(string $url): ?array {
    $cmd = 'node ' . escapeshellarg(__DIR__ . '/../render.mjs') . ' ' . escapeshellarg($url) . ' 2>/dev/null';
    $out = @shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') { return null; }
    $data = json_decode($out, true);
    if (!is_array($data)) { return null; }
    return [
        'text'   => (string)($data['text'] ?? ''),
        'attrs'  => is_array($data['attrs'] ?? null) ? $data['attrs'] : [],
        'links'  => is_array($data['links'] ?? null) ? $data['links'] : [],
        'images' => is_array($data['images'] ?? null) ? $data['images'] : [],
    ];
}

/** Absolute Asset-URL (Bild) — anders als resolve_url ohne Datei-Endungs-Filter. */
function resolve_asset_url(string $base, string $rel): ?string {
    $rel = trim($rel);
    if ($rel === '' || preg_match('~^(data:|#)~i', $rel)) { return null; }
    if (preg_match('#^https?://#i', $rel)) { return $rel; }
    if (str_starts_with($rel, '//')) { $bp = parse_url($base); return ($bp['scheme'] ?? 'https') . ':' . $rel; }
    $bp = parse_url($base);
    if (!isset($bp['scheme'], $bp['host'])) { return null; }
    $origin = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');
    if (str_starts_with($rel, '/')) { return $origin . $rel; }
    $dir = preg_replace('#/[^/]*$#', '/', $bp['path'] ?? '/');
    if ($dir === null || $dir === '') { $dir = '/'; }
    return $origin . $dir . $rel;
}

/** Bild-URLs aus HTML (<img src>). */
function extract_image_urls(string $html, string $baseUrl): array {
    $imgs = [];
    if (preg_match_all('#<img\b[^>]*\bsrc\s*=\s*("|\')(.*?)\1#is', $html, $m)) {
        foreach ($m[2] as $src) {
            $abs = resolve_asset_url($baseUrl, html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($abs !== null) { $imgs[$abs] = true; }
        }
    }
    return array_keys($imgs);
}

/** Lädt eine Datei per cURL herunter. */
function download_file(string $url, string $dest): bool {
    $fp = @fopen($dest, 'wb');
    if (!$fp) { return false; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; EmpCo-OCR/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok   = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    return $ok !== false && $code < 400 && is_file($dest) && filesize($dest) > 0;
}

/** OCR über eine begrenzte Zahl Bilder (Tesseract, deu+eng). */
function ocr_images(array $imageUrls, int $max = 8): string {
    $texts = [];
    $count = 0;
    foreach ($imageUrls as $img) {
        if ($count >= $max) { break; }
        $ext = strtolower(pathinfo((string)(parse_url($img, PHP_URL_PATH) ?: ''), PATHINFO_EXTENSION));
        if (in_array($ext, ['svg', 'gif'], true)) { continue; }
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        if (!download_file($img, $tmp)) { @unlink($tmp); continue; }
        $count++;
        $t = @shell_exec('tesseract ' . escapeshellarg($tmp) . ' stdout -l deu+eng 2>/dev/null');
        @unlink($tmp);
        if (is_string($t)) {
            $t = trim((string) preg_replace('/\s+/u', ' ', $t));
            if (mb_strlen($t) >= 8) { $texts[] = $t; }
        }
    }
    return trim(implode("\n", $texts));
}

/** Holt Seiteninhalt — gerendert (JS) oder als Roh-HTML. */
function fetch_page_content(string $url, bool $useJs): array {
    if ($useJs) {
        $r = render_url($url);
        if ($r !== null) {
            $links = [];
            foreach ($r['links'] as $l) { $a = resolve_url($url, $l); if ($a !== null) { $links[$a] = true; } }
            return ['ok' => true, 'rendered' => true, 'text' => $r['text'], 'attrs' => $r['attrs'],
                    'links' => array_keys($links), 'images' => $r['images']];
        }
    }
    $res = fetch_url($url, 20);
    if ($res['html'] === '' || $res['code'] >= 400) {
        return ['ok' => false, 'rendered' => false, 'text' => '', 'attrs' => [], 'links' => [], 'images' => []];
    }
    $content = extract_content($res['html']);
    return ['ok' => true, 'rendered' => false, 'text' => $content['text'], 'attrs' => $content['attrs'],
            'links' => extract_links($res['html'], $url), 'images' => extract_image_urls($res['html'], $url)];
}

/**
 * Liest eine ausstehende Seite: (gerendert oder HTML) → Kandidaten (Text, ggf. OCR) → Kinder einreihen.
 */
function crawl_one_page(int $analysisId, array $page, array $cfg, array $seed, bool $useJs = false, bool $useOcr = false): void {
    $pageId = (int) $page['id'];
    $url    = (string) $page['url'];
    $depth  = (int) $page['depth'];
    $checks = ['text' => 'skipped', 'code' => 'skipped', 'js' => 'skipped', 'ocr' => 'skipped'];

    $c = fetch_page_content($url, $useJs);
    if (!$c['ok']) {
        $checks['text'] = 'failed';
        $checks['code'] = 'failed';
        db()->prepare("UPDATE pages SET status='failed', checks=:c WHERE id=:id")
            ->execute([':c' => json_encode($checks), ':id' => $pageId]);
        return;
    }

    $checks['text'] = $c['text'] !== '' ? 'ok' : 'failed';
    $checks['code'] = 'ok';
    $checks['js']   = $c['rendered'] ? 'ok' : 'skipped';
    // Seite als gelesen markieren (verhindert Doppelverarbeitung)
    db()->prepare("UPDATE pages SET status='fetched', checks=:c WHERE id=:id")
        ->execute([':c' => json_encode($checks), ':id' => $pageId]);

    $rules = db()->query("SELECT * FROM rules WHERE active ORDER BY rule_id")->fetchAll();
    $ins = db()->prepare(
        "INSERT INTO candidates (analysis_id, page_id, rule_id, category, content_type, snippet)
         VALUES (:a, :p, :rid, :cat, :ct, :snip)"
    );

    // Text- + Attribut-Kandidaten
    foreach (build_candidates($rules, $c['text'], $c['attrs']) as $cd) {
        $ins->execute([
            ':a' => $analysisId, ':p' => $pageId, ':rid' => $cd['rule_id'],
            ':cat' => $cd['category'], ':ct' => $cd['content_type'], ':snip' => mb_substr($cd['snippet'], 0, 1000),
        ]);
    }

    // OCR-Kandidaten (Text in Bildern/Siegeln)
    if ($useOcr) {
        $ocrText = ocr_images($c['images']);
        if ($ocrText !== '') {
            $checks['ocr'] = 'ok';
            foreach (build_candidates($rules, $ocrText, []) as $cd) {
                $ins->execute([
                    ':a' => $analysisId, ':p' => $pageId, ':rid' => $cd['rule_id'],
                    ':cat' => $cd['category'], ':ct' => 'image', ':snip' => mb_substr($cd['snippet'], 0, 1000),
                ]);
            }
        } else {
            $checks['ocr'] = 'failed';
        }
        db()->prepare("UPDATE pages SET checks=:c WHERE id=:id")
            ->execute([':c' => json_encode($checks), ':id' => $pageId]);
    }

    // Kinder einreihen (nach Umfang: Pfad-Prefix + relative Tiefe, Seiten-Obergrenze)
    if ($seed['host'] !== '') {
        $pagesCount = (int) db()->query("SELECT COUNT(*) FROM pages WHERE analysis_id = " . (int)$analysisId)->fetchColumn();
        if ($pagesCount < $cfg['maxPages']) {
            $enq = db()->prepare(
                "INSERT INTO pages (analysis_id, url, depth, status, checks)
                 SELECT :a, :u, :d, 'pending', NULL
                 WHERE NOT EXISTS (SELECT 1 FROM pages WHERE analysis_id = :a2 AND url = :u2)"
            );
            // Beim Seed zusätzlich die Sitemap auswerten
            if ($depth === 0 && $cfg['maxDepth'] > 0) {
                $pp = parse_url($url);
                $origin = ($pp['scheme'] ?? 'https') . '://' . ($pp['host'] ?? '') . (isset($pp['port']) ? ':' . $pp['port'] : '');
                $extra = configured_sitemaps_for_host($seed['host']);
                foreach (fetch_sitemap_urls($origin, $extra) as $link) {
                    if ($pagesCount >= $cfg['maxPages']) { break; }
                    if (!link_allowed($link, $seed, $cfg)) { continue; }
                    $short = mb_substr($link, 0, 500);
                    $enq->execute([':a' => $analysisId, ':u' => $short, ':d' => 1, ':a2' => $analysisId, ':u2' => $short]);
                    if ($enq->rowCount() > 0) { $pagesCount++; }
                }
            }
            // Links der Seite selbst (bei JS: gerenderte Links)
            foreach ($c['links'] as $link) {
                if ($pagesCount >= $cfg['maxPages']) { break; }
                if (!link_allowed($link, $seed, $cfg)) { continue; }
                $short = mb_substr($link, 0, 500);
                $enq->execute([':a' => $analysisId, ':u' => $short, ':d' => $depth + 1, ':a2' => $analysisId, ':u2' => $short]);
                if ($enq->rowCount() > 0) { $pagesCount++; }
            }
        }
    }
}

/** Zählt Seiten/Kandidaten und liefert den Fortschritts-Status zurück. */
function step_status(int $analysisId, string $phase, bool $finished): array {
    $id = (int) $analysisId;
    $pagesTotal   = (int) db()->query("SELECT COUNT(*) FROM pages WHERE analysis_id = $id")->fetchColumn();
    $pagesFetched = (int) db()->query("SELECT COUNT(*) FROM pages WHERE analysis_id = $id AND status <> 'pending'")->fetchColumn();
    $candTotal    = (int) db()->query("SELECT COUNT(*) FROM candidates WHERE analysis_id = $id")->fetchColumn();
    $candDone     = (int) db()->query("SELECT COUNT(*) FROM candidates WHERE analysis_id = $id AND processed")->fetchColumn();
    return [
        'phase'        => $phase,
        'finished'     => $finished,
        'pagesTotal'   => $pagesTotal,
        'pagesFetched' => $pagesFetched,
        'candTotal'    => $candTotal,
        'candDone'     => $candDone,
        // Kompatibilität mit älterem Frontend
        'total'        => $candTotal,
        'processed'    => $candDone,
    ];
}

/**
 * Ein Verarbeitungsschritt: erst ausstehende Seite lesen (Crawl), dann Kandidaten-Block bewerten.
 * @return array Fortschritts-Status (siehe step_status)
 */
function process_step(int $analysisId, int $size = 12): array {
    $id = (int) $analysisId;
    $a  = db()->query("SELECT scope, use_js, use_ocr FROM analyses WHERE id = $id")->fetch();
    $cfg = scope_config((string) ($a['scope'] ?? 'exact'));
    $useJs  = !empty($a['use_js']);
    $useOcr = !empty($a['use_ocr']);
    $seedRow  = db()->query("SELECT url FROM pages WHERE analysis_id = $id AND depth = 0 ORDER BY id LIMIT 1")->fetch();
    $seedUrl  = $seedRow ? (string) $seedRow['url'] : '';
    $sp       = seed_prefix($seedUrl);
    $seed     = ['host' => (string) (parse_url($seedUrl, PHP_URL_HOST) ?: ''), 'prefix' => $sp['prefix'], 'segs' => $sp['segs']];

    // Phase 1 — Crawl: nächste ausstehende Seite lesen
    $pending = db()->query("SELECT * FROM pages WHERE analysis_id = $id AND status = 'pending' ORDER BY depth, id LIMIT 1")->fetch();
    if ($pending) {
        crawl_one_page($id, $pending, $cfg, $seed, $useJs, $useOcr);
        return step_status($id, 'crawl', false);
    }

    // Phase 2 — Klassifizierung: nächsten Kandidaten-Block per KI bewerten
    $sel = db()->prepare(
        "SELECT * FROM candidates WHERE analysis_id = :a AND processed = FALSE ORDER BY id LIMIT " . (int)$size
    );
    $sel->execute([':a' => $id]);
    $batch = $sel->fetchAll();

    if ($batch) {
        $rules = db()->query("SELECT * FROM rules WHERE active")->fetchAll();
        try {
            $classified = ai_classify($batch, $rules);
            $ins = db()->prepare(
                "INSERT INTO findings (analysis_id, page_id, rule_id, category, content_type, snippet, assessment, severity, status)
                 VALUES (:a, :p, :rid, :cat, :ct, :snip, :ass, :sev, 'open')"
            );
            foreach ($batch as $i => $c) {
                $f = $classified[$i] ?? null;
                if (!$f) { continue; }
                $ins->execute([
                    ':a' => $id, ':p' => ((int)$c['page_id'] ?: null), ':rid' => $f['rule_id'], ':cat' => $f['category'],
                    ':ct' => $f['content_type'], ':snip' => mb_substr($f['snippet'], 0, 1000),
                    ':ass' => mb_substr($f['assessment'], 0, 1000), ':sev' => $f['severity'],
                ]);
            }
        } catch (Throwable $e) {
            // Block trotzdem als verarbeitet markieren, damit die Analyse nicht hängen bleibt
        }
        $ids = array_map('intval', array_column($batch, 'id'));
        db()->exec("UPDATE candidates SET processed = TRUE WHERE id IN (" . implode(',', $ids) . ")");
        return step_status($id, 'classify', false);
    }

    // Fertig
    db()->prepare("UPDATE analyses SET status='done' WHERE id=:id")->execute([':id' => $id]);
    return step_status($id, 'done', true);
}

/** Speichert einen Seiten-Eintrag inkl. Prüf-Status. */
function save_page(int $analysisId, string $url, array $checks): int {
    $stmt = db()->prepare("INSERT INTO pages (analysis_id, url, checks) VALUES (:a, :u, :c) RETURNING id");
    $stmt->execute([':a' => $analysisId, ':u' => mb_substr($url, 0, 500), ':c' => json_encode($checks)]);
    return (int) $stmt->fetchColumn();
}

/** Zerlegt Text in Sätze/Zeilen als Prüf-Einheiten. */
function split_units(string $text): array {
    $parts = preg_split('/(?<=[.!?…])\s+|\r?\n+/u', $text) ?: [$text];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') { $out[] = $p; }
    }
    return $out;
}

/** Erkennt Navigations-/Linklisten (lange Blöcke ohne Satzzeichen) → nicht prüfen. */
function is_boilerplate(string $unit): bool {
    if (mb_strlen($unit) < 180) { return false; }
    $punct = preg_match_all('/[.!?:]/u', $unit);
    $words = preg_match_all('/\S+/u', $unit);
    return ($punct <= 1 && $words >= 25);
}

/** Schneidet einen fokussierten Ausschnitt rund um den Trigger-Begriff aus. */
function snippet_window(string $unit, string $term): string {
    $pos = mb_stripos($unit, $term);
    if ($pos === false) { return trim(mb_substr($unit, 0, 240)); }
    $start = max(0, $pos - 90);
    $len   = mb_strlen($term) + 180;
    $snip  = mb_substr($unit, $start, $len);
    if ($start > 0) { $snip = '…' . $snip; }
    if ($start + $len < mb_strlen($unit)) { $snip .= '…'; }
    return trim($snip);
}

/** Bildet Kandidaten: jede Text-/Attribut-Stelle mit einem Trigger-Begriff. */
function build_candidates(array $rules, string $text, array $attrs): array {
    $units = [];
    foreach (split_units($text) as $s) {
        if (!is_boilerplate($s)) { $units[] = ['t' => $s, 'ct' => 'text']; }
    }
    foreach ($attrs as $a) { $a = trim($a); if ($a !== '') { $units[] = ['t' => $a, 'ct' => 'tooltip']; } }

    $cands = [];
    $seen = [];
    foreach ($rules as $r) {
        $terms = array_filter(array_map('trim', explode(',', (string)$r['trigger_terms'])));
        foreach ($units as $u) {
            foreach ($terms as $term) {
                if ($term === '') { continue; }
                if (mb_stripos($u['t'], $term) !== false) {
                    $snippet = snippet_window($u['t'], $term);
                    $key = mb_strtolower($r['rule_id'] . '|' . preg_replace('/\s+/u', ' ', $snippet));
                    if (isset($seen[$key])) { break; }
                    $seen[$key] = true;
                    $cands[] = [
                        'rule_id'      => $r['rule_id'],
                        'category'     => $r['category'],
                        'content_type' => $u['ct'],
                        'snippet'      => $snippet,
                    ];
                    break; // ein Kandidat je (Regel, Einheit)
                }
            }
        }
    }
    return array_slice($cands, 0, 120); // Obergrenze für KI-Aufwand
}

/** Lässt die KI jeden Kandidaten bewerten. Verwirft NICHTS (deterministische Finding-Menge). */
function ai_classify(array $candidates, array $rules): array {
    $ruleMap = [];
    foreach ($rules as $r) { $ruleMap[$r['rule_id']] = $r; }

    $system = "Du bist Prüf-Assistent für Greenwashing nach der EmpCo-Richtlinie (EU) 2024/825 sowie "
        . "UWG/UCPD. Du erhältst KANDIDATEN — Textstellen, in denen ein Trigger-Begriff einer Regel vorkommt. "
        . "Beurteile JEDE Stelle im Kontext der genannten Regel und vergib eine severity: "
        . "'violation' = klar irreführend/unbelegt; 'warn' = potenziell problematisch/kontextabhängig; "
        . "'info' = kein echter Umweltbezug/Fehltreffer ODER eindeutig konform (Beleg/Quelle/Zertifikat dabei). "
        . "Verwirf nichts — jede Stelle bekommt eine severity. Antworte AUSSCHLIESSLICH als JSON-Array in "
        . "Kandidaten-Reihenfolge, ohne Markdown: [{\"i\":0,\"severity\":\"violation\",\"assessment\":\"kurze Begründung\"}].";

    $out = [];
    foreach (array_chunk($candidates, 20, false) as $batch) {
        $list = '';
        foreach ($batch as $i => $c) {
            $r = $ruleMap[$c['rule_id']] ?? [];
            $list .= "#{$i} Regel {$c['rule_id']} [{$c['category']}]: " . ($r['description'] ?? '') . "\n"
                . "   Verstoß-Beispiel: " . ($r['example_violation'] ?? '') . "\n"
                . "   Konform-Beispiel: " . ($r['example_ok'] ?? '') . "\n"
                . "   Fundstelle: \"{$c['snippet']}\"\n";
        }
        $byI = [];
        try {
            $raw = call_ai($system, "KANDIDATEN:\n{$list}");
            foreach (parse_json_array($raw) as $v) {
                if (isset($v['i'])) { $byI[(int)$v['i']] = $v; }
            }
        } catch (Throwable $e) {
            // KI-Ausfall: Kandidaten trotzdem als Findings (severity 'warn') behalten
        }
        foreach ($batch as $i => $c) {
            $v = $byI[$i] ?? [];
            $out[] = [
                'rule_id'      => $c['rule_id'],
                'category'     => $c['category'],
                'content_type' => $c['content_type'],
                'snippet'      => $c['snippet'],
                'assessment'   => (string)($v['assessment'] ?? ''),
                'severity'     => in_array($v['severity'] ?? '', ['violation', 'warn', 'info'], true) ? $v['severity'] : 'warn',
            ];
        }
    }
    return $out;
}

/** Robustes Parsen eines JSON-Arrays aus einer KI-Antwort. */
function parse_json_array(string $raw): array {
    $raw = trim($raw);
    $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
    $data = json_decode(trim($raw), true);
    if (is_array($data)) { return $data; }
    // Fallback: erstes [...] herausschneiden
    if (preg_match('/\[.*\]/s', $raw, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) { return $data; }
    }
    throw new RuntimeException('KI-Antwort war kein gültiges JSON.');
}

/** Robustes Parsen eines JSON-Objekts aus einer KI-Antwort. */
function parse_json_object(string $raw): array {
    $raw = trim($raw);
    $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
    $data = json_decode(trim($raw), true);
    if (is_array($data)) { return $data; }
    if (preg_match('/\{.*\}/s', $raw, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) { return $data; }
    }
    throw new RuntimeException('KI-Antwort war kein gültiges JSON.');
}

/** Belege, die zur Regel-ID (Liste) oder Kategorie eines Findings passen. */
function matching_evidence(string $ruleId, string $category): array {
    try {
        $all = db()->query("SELECT * FROM evidence WHERE active ORDER BY title")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $cat = mb_strtolower(trim($category));
    $out = [];
    foreach ($all as $ev) {
        $rids = array_filter(array_map('trim', explode(',', (string)($ev['rule_id'] ?? ''))));
        $evCat = mb_strtolower(trim((string)($ev['category'] ?? '')));
        if (in_array($ruleId, $rids, true) || ($cat !== '' && $evCat === $cat)) {
            $out[] = $ev;
        }
    }
    return $out;
}

/**
 * Nachweis-Check (Stufe B): prüft je Finding, ob ein passender Beleg vorliegt.
 * Ohne passenden Beleg → 'nicht_belegbar' (kein KI-Aufruf). Mit Beleg entscheidet die KI.
 * Speichert das Ergebnis am Finding und liefert es zurück.
 */
function nachweis_check(int $findingId): array {
    $stmt = db()->prepare("SELECT * FROM findings WHERE id = :id");
    $stmt->execute([':id' => $findingId]);
    $f = $stmt->fetch();
    if (!$f) { return ['path' => '', 'evidence' => '', 'note' => 'Finding nicht gefunden.']; }

    $matched = matching_evidence((string)$f['rule_id'], (string)$f['category']);

    if (!$matched) {
        $res = ['path' => 'nicht_belegbar', 'evidence' => '', 'note' => 'Kein passender Beleg hinterlegt → Umformulierung empfohlen.'];
    } else {
        $list = '';
        foreach ($matched as $i => $ev) {
            $list .= "#{$i} [{$ev['type']}] {$ev['title']}\n"
                . "   Inhalt: " . mb_substr((string)$ev['content'], 0, 800) . "\n"
                . ($ev['source_url'] ? "   Quelle: {$ev['source_url']}\n" : '')
                . ($ev['valid_until'] ? "   Gültig bis: {$ev['valid_until']}\n" : '');
        }
        $system = "Du prüfst, ob eine beanstandete Werbeaussage mit vorliegenden Belegen NACHGEWIESEN werden kann "
            . "(EmpCo-Richtlinie (EU) 2024/825, UWG/UCPD). Entscheide genau einen Weg: "
            . "'belegbar' = ein Beleg deckt die Aussage inhaltlich ab, sie kann mit Quellenangabe bestehen bleiben; "
            . "'belegt_anpassen' = Beleg vorhanden, aber die Formulierung bleibt irreführend → Beleg + Umformulierung nötig; "
            . "'nicht_belegbar' = kein Beleg deckt die Aussage ab → Umformulierung nötig. "
            . "Antworte AUSSCHLIESSLICH als JSON ohne Markdown: "
            . "{\"path\":\"belegbar|belegt_anpassen|nicht_belegbar\",\"evidence\":\"Titel des passenden Belegs oder leer\",\"note\":\"kurze Begründung + ggf. empfohlener Quellen-/Zusatztext\"}.";
        $user = "FUNDSTELLE: \"" . mb_substr((string)$f['snippet'], 0, 600) . "\"\n"
            . "REGEL: {$f['rule_id']} [{$f['category']}]\n"
            . "BISHERIGE BEWERTUNG: " . mb_substr((string)$f['assessment'], 0, 400) . "\n\n"
            . "VORLIEGENDE BELEGE:\n{$list}";
        try {
            $data = parse_json_object(call_ai($system, $user));
            $path = in_array($data['path'] ?? '', ['belegbar', 'belegt_anpassen', 'nicht_belegbar'], true) ? $data['path'] : 'nicht_belegbar';
            $res = ['path' => $path, 'evidence' => (string)($data['evidence'] ?? ''), 'note' => (string)($data['note'] ?? '')];
        } catch (Throwable $e) {
            $res = ['path' => '', 'evidence' => '', 'note' => 'Nachweis-Prüfung fehlgeschlagen: ' . $e->getMessage()];
        }
    }

    db()->prepare("UPDATE findings SET remedy_path = :p, remedy_evidence = :ev, remedy_note = :n WHERE id = :id")
        ->execute([
            ':p'  => $res['path'],
            ':ev' => mb_substr($res['evidence'], 0, 500),
            ':n'  => mb_substr($res['note'], 0, 1000),
            ':id' => $findingId,
        ]);
    return $res;
}

/** Normalisiert Text für Vergleiche (Kleinschreibung, Whitespace zusammenfassen). */
function normalize_text(string $s): string {
    $s = mb_strtolower(trim($s));
    return (string) preg_replace('/\s+/u', ' ', $s);
}

/** Vorher/Nachher-Beispiele, die zur Regel-ID (Liste) oder Kategorie eines Findings passen. */
function matching_examples(string $ruleId, string $category): array {
    try {
        $all = db()->query("SELECT * FROM training_examples WHERE active ORDER BY id")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $cat = mb_strtolower(trim($category));
    $out = [];
    foreach ($all as $ex) {
        $rids = array_filter(array_map('trim', explode(',', (string)($ex['rule_id'] ?? ''))));
        $exCat = mb_strtolower(trim((string)($ex['category'] ?? '')));
        if (in_array($ruleId, $rids, true) || ($cat !== '' && $exCat === $cat)) {
            $out[] = $ex;
        }
    }
    return $out;
}

/** Speichert einen Umformulierungs-Vorschlag (ersetzt vorherige, noch nicht übernommene). */
function save_reformulation(int $findingId, string $kind, string $text, string $agentsUsed = ''): int {
    db()->prepare("DELETE FROM reformulations WHERE finding_id = :f AND accepted = FALSE")->execute([':f' => $findingId]);
    $stmt = db()->prepare("INSERT INTO reformulations (finding_id, kind, text, accepted, agents_used) VALUES (:f, :k, :t, FALSE, :ag) RETURNING id");
    $stmt->execute([':f' => $findingId, ':k' => $kind, ':t' => mb_substr($text, 0, 4000), ':ag' => $agentsUsed !== '' ? $agentsUsed : null]);
    return (int) $stmt->fetchColumn();
}

/**
 * Stufe 3b: schleift einen bereits EmpCo-konformen Text auf die Brand Voice.
 * Läuft nach dem Umformulierungs-Redakteur, ändert nur die Tonalität (keine neuen
 * Umweltaussagen). Bei deaktiviertem ToV-Agent oder Fehler bleibt der Text unverändert.
 * Rückgabe: [Text, bool ob ToV angewandt wurde].
 */
function apply_tone_of_voice(string $text, array $f): array {
    $sys = tone_prompt();
    if (trim($sys) === '' || trim($text) === '') { return [$text, false]; }
    $user = "EMPCO-KONFORMER TEXT (nur tonal anpassen, Konformität nicht verändern):\n\"{$text}\"\n\n"
        . "AUFGABE: Passe ausschließlich die Tonalität an die Brand Voice an. Behalte Sprache, Kernbotschaft "
        . "und alle belegten Konkretisierungen bei. Führe keine neuen Umwelt-/Nachhaltigkeitsaussagen ein. "
        . "Antworte NUR mit dem angepassten Text (ohne Anführungszeichen, ohne Erläuterung).";
    try {
        $toned = trim(call_ai($sys, $user));
        $toned = trim($toned, "\"'„“ \n\r\t");
        if ($toned === '') { return [$text, false]; }
        return [$toned, true];
    } catch (Throwable $e) {
        return [$text, false];
    }
}

/**
 * Stufe 3b (manuell): wendet den Tonalitäts-Redakteur auf die aktuelle
 * Umformulierung eines Findings an. Basis ist der übergebene (ggf. editierte)
 * Text oder – falls leer – der gespeicherte Vorschlag. Aktualisiert den Datensatz
 * und ergänzt agents_used. Rückgabe: ['text'=>…, 'id'=>…, 'agents'=>…] oder ['error'=>…].
 */
function tone_reformulation(int $findingId, string $baseText = ''): array {
    if (trim(tone_prompt()) === '') {
        return ['error' => 'Tonalitäts-Redakteur ist deaktiviert.'];
    }
    $stmt = db()->prepare("SELECT * FROM findings WHERE id = :id");
    $stmt->execute([':id' => $findingId]);
    $f = $stmt->fetch();
    if (!$f) { return ['error' => 'Finding nicht gefunden.']; }

    $rStmt = db()->prepare("SELECT * FROM reformulations WHERE finding_id = :f ORDER BY accepted DESC, id DESC LIMIT 1");
    $rStmt->execute([':f' => $findingId]);
    $rf = $rStmt->fetch();
    if (!$rf) { return ['error' => 'Keine Umformulierung vorhanden.']; }

    $src = trim($baseText) !== '' ? trim($baseText) : (string)$rf['text'];
    if (trim($src) === '') { return ['error' => 'Kein Text zum Anpassen.']; }

    [$toned, $applied] = apply_tone_of_voice($src, $f);
    if (!$applied) { return ['error' => 'Tonalitätsanpassung fehlgeschlagen.']; }

    $agents = (string)($rf['agents_used'] ?? '');
    if (mb_stripos($agents, 'Tonalität') === false) {
        $agents = $agents === '' ? 'Tonalität (Brand Voice)' : $agents . ' + Tonalität (Brand Voice)';
    }
    db()->prepare("UPDATE reformulations SET text = :t, agents_used = :ag WHERE id = :id")
        ->execute([':t' => mb_substr($toned, 0, 4000), ':ag' => $agents, ':id' => (int)$rf['id']]);
    return ['text' => $toned, 'id' => (int)$rf['id'], 'agents' => $agents];
}

/**
 * Umformulierung (Stufe C): erzeugt einen EmpCo-konformen Vorschlag für ein Finding.
 * 1) Exakt-Match-Kurzschluss: (nahezu) wortgleiche Fundstelle → geprüfter „Nachher"-Text 1:1.
 * 2) sonst KI mit passenden Beispielen (Few-Shot) + passenden Belegen als Kontext.
 */
function generate_reformulation(int $findingId): array {
    $stmt = db()->prepare("SELECT * FROM findings WHERE id = :id");
    $stmt->execute([':id' => $findingId]);
    $f = $stmt->fetch();
    if (!$f) { return ['kind' => '', 'text' => '', 'error' => 'Finding nicht gefunden.']; }

    $snippet  = (string) $f['snippet'];
    $examples = matching_examples((string) $f['rule_id'], (string) $f['category']);

    // 1) Exakt-Match-Kurzschluss
    $normSnip = normalize_text($snippet);
    foreach ($examples as $ex) {
        $before = normalize_text((string)($ex['before_text'] ?? ''));
        $after  = trim((string)($ex['after_text'] ?? ''));
        if ($before !== '' && $after !== '' && mb_strlen($before) >= 15
            && ($before === $normSnip || mb_strpos($normSnip, $before) !== false)) {
            // Rechtsgeprüftes Beispiel bleibt 1:1 erhalten (kein ToV-Schliff, um die
            // geprüfte Formulierung nicht zu verändern).
            $refId = save_reformulation($findingId, 'example', $after, 'Rechtsgeprüftes Beispiel');
            return ['kind' => 'example', 'text' => $after, 'id' => $refId, 'agents' => 'Rechtsgeprüftes Beispiel'];
        }
    }

    // 2) KI-Umformulierung mit Few-Shot-Beispielen + Belegen
    $evidence = matching_evidence((string) $f['rule_id'], (string) $f['category']);
    $ctx = '';
    if ($evidence) {
        $ctx .= "VERFÜGBARE BELEGE (passende mit Quellenangabe einbauen):\n";
        foreach ($evidence as $ev) {
            $ctx .= "- [{$ev['type']}] {$ev['title']}: " . mb_substr((string)$ev['content'], 0, 500)
                . ($ev['source_url'] ? " (Quelle: {$ev['source_url']})" : '') . "\n";
        }
        $ctx .= "\n";
    }
    if ($examples) {
        $ctx .= "BEISPIELE FÜR KONFORME UMFORMULIERUNGEN (Muster für Stil & Lösungsweg, NICHT wörtlich übernehmen):\n";
        foreach (array_slice($examples, 0, 6) as $ex) {
            $ctx .= "Vorher: " . mb_substr((string)$ex['before_text'], 0, 300) . "\n"
                . "Nachher: " . mb_substr((string)$ex['after_text'], 0, 300) . "\n---\n";
        }
        $ctx .= "\n";
    }

    $system = editor_prompt();
    $user = "BEANSTANDETE TEXTSTELLE:\n\"{$snippet}\"\n\n"
        . "REGEL: {$f['rule_id']} [{$f['category']}]\n"
        . "BEWERTUNG: " . mb_substr((string)$f['assessment'], 0, 400) . "\n\n"
        . $ctx
        . "AUFGABE: Formuliere die beanstandete Textstelle EmpCo-konform um. Behalte Sprache und Kernbotschaft bei. "
        . "Antworte NUR mit dem umformulierten Text (ohne Anführungszeichen, ohne Erläuterung).";

    try {
        $text = trim(call_ai($system, $user));
        $text = trim($text, "\"'„“ \n\r\t");
        if ($text === '') { throw new RuntimeException('Leere Antwort.'); }

        // Nur EmpCo-Umformulierung. Der Tonalitäts-Schliff (Stufe 3b) wird
        // separat und manuell über tone_reformulation() ausgelöst.
        $refId = save_reformulation($findingId, 'ai', $text, 'EmpCo-Redakteur');
        return ['kind' => 'ai', 'text' => $text, 'id' => $refId, 'agents' => 'EmpCo-Redakteur'];
    } catch (Throwable $e) {
        return ['kind' => '', 'text' => '', 'error' => 'Umformulierung fehlgeschlagen: ' . $e->getMessage()];
    }
}

/**
 * Stufe D: akzeptierte Umformulierung als gelerntes Trainingsbeispiel speichern.
 * Upsert je Finding (kein Duplikat) mit source='learned'. Un-Learn = Löschen im Admin.
 */
function learn_from_reformulation(int $findingId, string $text): void {
    $text = trim($text);
    if ($findingId <= 0 || $text === '') { return; }
    try {
        $st = db()->prepare("SELECT snippet, category, rule_id FROM findings WHERE id = :id");
        $st->execute([':id' => $findingId]);
        $f = $st->fetch();
        if (!$f) { return; }
        db()->prepare("DELETE FROM training_examples WHERE finding_id = :f AND source = 'learned'")->execute([':f' => $findingId]);
        db()->prepare(
            "INSERT INTO training_examples (category, rule_id, before_text, after_text, note, active, source, finding_id)
             VALUES (:cat, :rid, :b, :a, :n, TRUE, 'learned', :fid)"
        )->execute([
            ':cat' => (string)$f['category'],
            ':rid' => (string)$f['rule_id'],
            ':b'   => mb_substr((string)$f['snippet'], 0, 1000),
            ':a'   => mb_substr($text, 0, 4000),
            ':n'   => 'Automatisch gelernt aus akzeptierter Umformulierung.',
            ':fid' => $findingId,
        ]);
    } catch (Throwable $e) { /* ignoriert */ }
}
