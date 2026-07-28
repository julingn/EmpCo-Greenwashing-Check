<?php
// KI-Anbindung: OpenAI (primär) oder Anthropic (Fallback)

/**
 * Ruft ein KI-Modell mit System- und User-Prompt auf.
 * @param bool $json  Bei true fordert OpenAI ein striktes JSON-Objekt an (response_format).
 * @throws RuntimeException bei Konfigurations- oder API-Fehlern
 */
function call_ai(string $system, string $user, bool $json = false): string {
    if (OPENAI_API_KEY !== '') {
        return call_openai($system, $user, $json);
    }
    if (ANTHROPIC_API_KEY !== '') {
        return call_anthropic($system, $user);
    }
    throw new RuntimeException('Kein KI-Schlüssel gesetzt (OPENAI_API_KEY oder ANTHROPIC_API_KEY).');
}

function call_openai(string $system, string $user, bool $json = false): string {
    $body = [
        'model'       => OPENAI_MODEL,
        'temperature' => 0,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
    ];
    if ($json) {
        $body['response_format'] = ['type' => 'json_object'];
    }
    $payload = json_encode($body);
    $res = http_post_json('https://api.openai.com/v1/chat/completions', $payload, [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Content-Type: application/json',
    ]);
    $data = json_decode($res, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    if ($text === '') {
        throw new RuntimeException('OpenAI lieferte keine Antwort: ' . substr($res, 0, 400));
    }
    return $text;
}

function call_anthropic(string $system, string $user): string {
    $payload = json_encode([
        'model'      => ANTHROPIC_MODEL,
        'max_tokens' => 4000,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $user]],
    ]);
    $res = http_post_json('https://api.anthropic.com/v1/messages', $payload, [
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ]);
    $data = json_decode($res, true);
    $text = $data['content'][0]['text'] ?? '';
    if ($text === '') {
        throw new RuntimeException('Anthropic lieferte keine Antwort: ' . substr($res, 0, 400));
    }
    return $text;
}

/** Führt einen JSON-POST per cURL aus und liefert den Rohtext zurück. */
function http_post_json(string $url, string $payload, array $headers): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        throw new RuntimeException('Netzwerkfehler: ' . $err);
    }
    if ($code >= 400) {
        throw new RuntimeException('API-Fehler (HTTP ' . $code . '): ' . substr($res, 0, 400));
    }
    return $res;
}
