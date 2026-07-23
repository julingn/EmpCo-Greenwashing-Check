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
    $haystack = $content['text'] . ' ' . implode(' ', $content['attrs']);
    $candidates = candidate_rules($rules, $haystack);

    $pageId = save_page($analysisId, $url, $checks);

    $count = 0;
    if ($candidates) {
        try {
            $findings = ai_findings($content, $candidates);
            $ruleMap = [];
            foreach ($candidates as $r) { $ruleMap[$r['rule_id']] = $r; }
            $stmt = db()->prepare(
                "INSERT INTO findings (analysis_id, page_id, rule_id, category, content_type, snippet, assessment, severity, status)
                 VALUES (:a, :p, :rid, :cat, :ct, :snip, :ass, :sev, 'open')"
            );
            foreach ($findings as $f) {
                $rid = (string)($f['rule_id'] ?? '');
                if ($rid === '' || !isset($ruleMap[$rid])) { continue; }
                $stmt->execute([
                    ':a'   => $analysisId,
                    ':p'   => $pageId,
                    ':rid' => $rid,
                    ':cat' => $ruleMap[$rid]['category'] ?? '',
                    ':ct'  => in_array($f['content_type'] ?? '', ['text','tooltip','image','code','footnote'], true) ? $f['content_type'] : 'text',
                    ':snip'=> mb_substr((string)($f['snippet'] ?? ''), 0, 1000),
                    ':ass' => mb_substr((string)($f['assessment'] ?? ''), 0, 1000),
                    ':sev' => in_array($f['severity'] ?? '', ['violation','warn','info'], true) ? $f['severity'] : 'violation',
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

/** Fragt die KI nach konkreten Verstößen. Liefert Array von Findings. */
function ai_findings(array $content, array $candidates): array {
    $rulesText = '';
    foreach ($candidates as $r) {
        $rulesText .= "- {$r['rule_id']} [{$r['category']}]: {$r['description']}\n"
            . "  Trigger: {$r['trigger_terms']}\n"
            . "  Verstoß-Beispiel: {$r['example_violation']}\n";
    }
    $text  = mb_substr($content['text'], 0, 12000);
    $attrs = mb_substr(implode(' | ', $content['attrs']), 0, 2000);

    $system = "Du bist ein Prüf-Assistent für Greenwashing nach der EmpCo-Richtlinie (EU) 2024/825 "
        . "sowie UWG/UCPD. Du erhältst Website-Inhalte und eine Liste von Regeln. Finde konkrete "
        . "Textstellen, die tatsächlich gegen eine Regel verstoßen. Melde NUR echte, belegbare Verstöße "
        . "(keine erfundenen, keine bereits belegten/konformen Aussagen). Zitiere die EXAKTE Fundstelle. "
        . "Antworte AUSSCHLIESSLICH als gültiges JSON-Array, ohne Markdown, im Format: "
        . "[{\"rule_id\":\"EMPCO-...\",\"snippet\":\"exaktes Zitat\",\"content_type\":\"text|tooltip|image|code|footnote\","
        . "\"severity\":\"violation|warn|info\",\"assessment\":\"kurze Begründung\"}]. "
        . "Wenn nichts zu beanstanden ist: [].";

    $user = "REGELN:\n{$rulesText}\n\nSICHTBARER TEXT:\n{$text}\n\nATTRIBUTE (Tooltips/Alt-Texte):\n{$attrs}";

    $raw = call_ai($system, $user);
    return parse_json_array($raw);
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
