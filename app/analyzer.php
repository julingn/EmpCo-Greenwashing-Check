<?php
// Analyse-Engine: URL auslesen, Inhalte gegen Regeln prüfen (Trigger + KI)
require_once __DIR__ . '/ai.php';

/** Lädt eine URL herunter. */
function fetch_url(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
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
    $clean = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
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
 * Führt eine Analyse aus: fetch → extract → prefilter → KI-Bewertung → Findings speichern.
 * @return array{findings:int, checks:array, note:string}
 */
function run_analysis(int $analysisId, string $url): array {
    $checks = ['text' => 'skipped', 'code' => 'skipped', 'js' => 'skipped', 'ocr' => 'skipped'];
    $note = '';

    $res = fetch_url($url);
    if ($res['html'] === '' || $res['code'] >= 400) {
        $checks['text'] = 'failed';
        $checks['code'] = 'failed';
        save_page($analysisId, $url, $checks);
        db()->prepare("UPDATE analyses SET status='error' WHERE id=:id")->execute([':id' => $analysisId]);
        return ['findings' => 0, 'checks' => $checks, 'note' => 'Seite nicht erreichbar (HTTP ' . $res['code'] . ') ' . $res['error']];
    }

    $content = extract_content($res['html']);
    $checks['text'] = $content['text'] !== '' ? 'ok' : 'failed';
    $checks['code'] = 'ok'; // HTML/Attribute wurden ausgewertet
    // js/ocr bleiben 'skipped' (folgt in späterem Schritt)

    $rules = db()->query("SELECT * FROM rules WHERE active ORDER BY rule_id")->fetchAll();
    $pageId = save_page($analysisId, $url, $checks);

    // Kandidaten deterministisch aus Trigger-Treffern bilden (jede Fundstelle wird geprüft)
    $candidates = build_candidates($rules, $content['text'], $content['attrs']);

    $count = 0;
    if ($candidates) {
        try {
            $classified = ai_classify($candidates, $rules);
            $stmt = db()->prepare(
                "INSERT INTO findings (analysis_id, page_id, rule_id, category, content_type, snippet, assessment, severity, status)
                 VALUES (:a, :p, :rid, :cat, :ct, :snip, :ass, :sev, 'open')"
            );
            foreach ($classified as $f) {
                $stmt->execute([
                    ':a'   => $analysisId,
                    ':p'   => $pageId,
                    ':rid' => $f['rule_id'],
                    ':cat' => $f['category'],
                    ':ct'  => $f['content_type'],
                    ':snip'=> mb_substr($f['snippet'], 0, 1000),
                    ':ass' => mb_substr($f['assessment'], 0, 1000),
                    ':sev' => $f['severity'],
                ]);
                $count++;
            }
        } catch (Throwable $e) {
            $note = 'KI-Bewertung fehlgeschlagen: ' . $e->getMessage();
        }
    } else {
        $note = 'Keine Trigger-Begriffe aus dem Regelset im Inhalt gefunden.';
    }

    db()->prepare("UPDATE analyses SET status='done' WHERE id=:id")->execute([':id' => $analysisId]);
    return ['findings' => $count, 'checks' => $checks, 'note' => $note];
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

/** Bildet Kandidaten: jede Text-/Attribut-Stelle mit einem Trigger-Begriff. */
function build_candidates(array $rules, string $text, array $attrs): array {
    $units = [];
    foreach (split_units($text) as $s) { $units[] = ['t' => $s, 'ct' => 'text']; }
    foreach ($attrs as $a) { $a = trim($a); if ($a !== '') { $units[] = ['t' => $a, 'ct' => 'tooltip']; } }

    $cands = [];
    $seen = [];
    foreach ($rules as $r) {
        $terms = array_filter(array_map('trim', explode(',', (string)$r['trigger_terms'])));
        foreach ($units as $u) {
            foreach ($terms as $term) {
                if ($term === '') { continue; }
                if (mb_stripos($u['t'], $term) !== false) {
                    $snippet = mb_substr($u['t'], 0, 400);
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

/** Lässt die KI jeden Kandidaten bewerten (Verstoß/Prüfen/Hinweis oder verwerfen). */
function ai_classify(array $candidates, array $rules): array {
    $ruleMap = [];
    foreach ($rules as $r) { $ruleMap[$r['rule_id']] = $r; }

    $system = "Du bist Prüf-Assistent für Greenwashing nach der EmpCo-Richtlinie (EU) 2024/825 sowie "
        . "UWG/UCPD. Du erhältst KANDIDATEN — Textstellen, in denen ein Trigger-Begriff einer Regel vorkommt. "
        . "Beurteile JEDE Stelle im Kontext der genannten Regel. severity: 'violation' = klar irreführend/unbelegt; "
        . "'warn' = potenziell problematisch/kontextabhängig; 'info' = Trigger vorhanden, aber eher unkritisch. "
        . "Setze keep=false NUR, wenn die Stelle eindeutig konform ist (konkreter Beleg/Quelle/Zertifikat direkt "
        . "dabei) ODER der Begriff hier gar keine Umweltaussage ist (Fehltreffer). Im Zweifel keep=true mit "
        . "severity 'warn'. Antworte AUSSCHLIESSLICH als JSON-Array in Kandidaten-Reihenfolge, ohne Markdown: "
        . "[{\"i\":0,\"keep\":true,\"severity\":\"violation\",\"assessment\":\"kurze Begründung\"}].";

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
        $raw = call_ai($system, "KANDIDATEN:\n{$list}");
        $byI = [];
        foreach (parse_json_array($raw) as $v) {
            if (isset($v['i'])) { $byI[(int)$v['i']] = $v; }
        }
        foreach ($batch as $i => $c) {
            $v = $byI[$i] ?? ['keep' => true, 'severity' => 'warn', 'assessment' => ''];
            if (array_key_exists('keep', $v) && !$v['keep']) { continue; }
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
