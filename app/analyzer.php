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

/** Umfang → maximale Crawl-Tiefe und Seiten-Obergrenze. */
function scope_config(string $scope): array {
    return match ($scope) {
        'depth1' => ['maxDepth' => 1,  'maxPages' => 20],
        'depth2' => ['maxDepth' => 2,  'maxPages' => 40],
        'full'   => ['maxDepth' => 99, 'maxPages' => 60],
        default  => ['maxDepth' => 0,  'maxPages' => 1],
    };
}

/** Normalisiert einen Host (führendes www. entfernen). */
function norm_host(string $h): string {
    return preg_replace('#^www\.#i', '', strtolower($h)) ?? strtolower($h);
}

/** Gleiche Website (Host-Vergleich ohne www)? */
function same_site(string $a, string $b): bool {
    return $a !== '' && $b !== '' && norm_host($a) === norm_host($b);
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

/** Extrahiert alle absoluten Links (<a href>) aus HTML. */
function extract_links(string $html, string $baseUrl): array {
    $links = [];
    if (preg_match_all('#<a\b[^>]*\bhref\s*=\s*("|\')(.*?)\1#is', $html, $m)) {
        foreach ($m[2] as $href) {
            $abs = resolve_url($baseUrl, html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($abs !== null) { $links[$abs] = true; }
        }
    }
    return array_keys($links);
}

/**
 * Liest eine ausstehende Seite: fetch → extract → Kandidaten bilden → ggf. Kinder einreihen.
 */
function crawl_one_page(int $analysisId, array $page, array $cfg, string $seedHost): void {
    $pageId = (int) $page['id'];
    $url    = (string) $page['url'];
    $depth  = (int) $page['depth'];
    $checks = ['text' => 'skipped', 'code' => 'skipped', 'js' => 'skipped', 'ocr' => 'skipped'];

    $res = fetch_url($url, 20);
    if ($res['html'] === '' || $res['code'] >= 400) {
        $checks['text'] = 'failed';
        $checks['code'] = 'failed';
        db()->prepare("UPDATE pages SET status='failed', checks=:c WHERE id=:id")
            ->execute([':c' => json_encode($checks), ':id' => $pageId]);
        return;
    }

    $content = extract_content($res['html']);
    $checks['text'] = $content['text'] !== '' ? 'ok' : 'failed';
    $checks['code'] = 'ok';
    db()->prepare("UPDATE pages SET status='fetched', checks=:c WHERE id=:id")
        ->execute([':c' => json_encode($checks), ':id' => $pageId]);

    // Kandidaten dieser Seite
    $rules = db()->query("SELECT * FROM rules WHERE active ORDER BY rule_id")->fetchAll();
    $cands = build_candidates($rules, $content['text'], $content['attrs']);
    $stmt = db()->prepare(
        "INSERT INTO candidates (analysis_id, page_id, rule_id, category, content_type, snippet)
         VALUES (:a, :p, :rid, :cat, :ct, :snip)"
    );
    foreach ($cands as $c) {
        $stmt->execute([
            ':a' => $analysisId, ':p' => $pageId, ':rid' => $c['rule_id'],
            ':cat' => $c['category'], ':ct' => $c['content_type'], ':snip' => mb_substr($c['snippet'], 0, 1000),
        ]);
    }

    // Kinder einreihen (nur gleiche Website, innerhalb Tiefe & Seiten-Obergrenze)
    if ($depth < $cfg['maxDepth'] && $seedHost !== '') {
        $pagesCount = (int) db()->query("SELECT COUNT(*) FROM pages WHERE analysis_id = " . (int)$analysisId)->fetchColumn();
        if ($pagesCount < $cfg['maxPages']) {
            $enq = db()->prepare(
                "INSERT INTO pages (analysis_id, url, depth, status, checks)
                 SELECT :a, :u, :d, 'pending', NULL
                 WHERE NOT EXISTS (SELECT 1 FROM pages WHERE analysis_id = :a2 AND url = :u2)"
            );
            foreach (extract_links($res['html'], $url) as $link) {
                if ($pagesCount >= $cfg['maxPages']) { break; }
                $host = (string) (parse_url($link, PHP_URL_HOST) ?: '');
                if (!same_site($host, $seedHost)) { continue; }
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
    $a  = db()->query("SELECT scope FROM analyses WHERE id = $id")->fetch();
    $cfg = scope_config((string) ($a['scope'] ?? 'exact'));
    $seedRow  = db()->query("SELECT url FROM pages WHERE analysis_id = $id AND depth = 0 ORDER BY id LIMIT 1")->fetch();
    $seedHost = $seedRow ? (string) (parse_url((string)$seedRow['url'], PHP_URL_HOST) ?: '') : '';

    // Phase 1 — Crawl: nächste ausstehende Seite lesen
    $pending = db()->query("SELECT * FROM pages WHERE analysis_id = $id AND status = 'pending' ORDER BY depth, id LIMIT 1")->fetch();
    if ($pending) {
        crawl_one_page($id, $pending, $cfg, $seedHost);
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
