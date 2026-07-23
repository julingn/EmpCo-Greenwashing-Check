<?php
// Zentrale Konfiguration + Session + Hilfsfunktionen

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** Liest eine Environment-Variable, mit Fallback. */
function env_val(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

// KI-Konfiguration (OpenAI primär)
define('OPENAI_API_KEY', env_val('OPENAI_API_KEY', ''));
define('OPENAI_MODEL', env_val('OPENAI_MODEL', 'gpt-4o'));
define('ANTHROPIC_API_KEY', env_val('ANTHROPIC_API_KEY', ''));
define('ANTHROPIC_MODEL', env_val('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'));

// Passwörter (in Railway setzen)
define('ADMIN_PASSWORD', env_val('ADMIN_PASSWORD', ''));
define('APP_PASSWORD', env_val('APP_PASSWORD', ADMIN_PASSWORD));

// Standard-Prompt des KI-Redakteurs (im Admin überschreibbar, in DB gespeichert)
define('DEFAULT_EDITOR_PROMPT',
    "Du bist ein Redakteur für rechtskonforme Umweltkommunikation nach der EmpCo-Richtlinie "
    . "(EU) 2024/825 sowie UWG/UCPD. Formuliere beanstandete Textstellen so um, dass sie NICHT "
    . "mehr irreführend sind: keine pauschalen Umwelt-/Klimaaussagen ohne Beleg, keine "
    . "unzulässige Klimaneutralitäts-/Kompensationswerbung, kein Übertragen von Teil-Eigenschaften "
    . "aufs Gesamtprodukt, keine Eigen-Siegel ohne Zertifizierung, keine vagen Zukunftsversprechen. "
    . "Konkretisiere mit belegbaren Fakten, nenne Quellen/Methodik wo sinnvoll. Behalte Sprache "
    . "(Deutsch oder Englisch) und Kernbotschaft bei. Antworte nur mit dem umformulierten Text."
);

/** Erzeugt/liefert das CSRF-Token der aktuellen Session. */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** Prüft ein übermitteltes CSRF-Token. */
function csrf_check(?string $token): bool {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

/** Kurzform für sicheres HTML-Escaping. */
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Zugang zur Eingabeseite (User- oder Admin-Session)? */
function has_user_access(): bool {
    return !empty($_SESSION['user']) || !empty($_SESSION['admin']);
}
