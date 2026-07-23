<?php
// Datenbank-Anbindung (PostgreSQL via PDO) + Schema

/** Liefert eine gemeinsame PDO-Verbindung (Singleton). */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $url = env_val('DATABASE_URL');
    if (!$url) {
        throw new RuntimeException('DATABASE_URL ist nicht gesetzt. Bitte in Railway eine PostgreSQL-Datenbank hinzufügen.');
    }

    $p = parse_url($url);
    if ($p === false || empty($p['host'])) {
        throw new RuntimeException('DATABASE_URL konnte nicht gelesen werden.');
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $p['host'],
        $p['port'] ?? 5432,
        ltrim($p['path'] ?? '', '/')
    );

    $pdo = new PDO($dsn, $p['user'] ?? '', $p['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

/** Legt das komplette Schema an (idempotent). */
function db_init(): void {
    // Regelset (aus empco_rules.xlsx importiert, im Admin pflegbar)
    db()->exec("
        CREATE TABLE IF NOT EXISTS rules (
            id                SERIAL PRIMARY KEY,
            rule_id           TEXT UNIQUE NOT NULL,
            category          TEXT,
            description       TEXT,
            trigger_terms     TEXT,
            example_violation TEXT,
            example_ok        TEXT,
            law_reference     TEXT,
            active            BOOLEAN DEFAULT TRUE,
            created_at        TIMESTAMP DEFAULT NOW()
        )
    ");

    // Prüfläufe (Archiv)
    db()->exec("
        CREATE TABLE IF NOT EXISTS analyses (
            id          SERIAL PRIMARY KEY,
            source_type TEXT,             -- url | tld | pdf
            source_ref  TEXT,             -- URL oder Dateiname
            scope       TEXT,             -- exact | depth1 | depth2 | full
            status      TEXT DEFAULT 'pending',
            language    TEXT,
            created_at  TIMESTAMP DEFAULT NOW()
        )
    ");

    // Geprüfte Seiten je Prüflauf (inkl. Prüf-Status Text/Code/JS/OCR)
    db()->exec("
        CREATE TABLE IF NOT EXISTS pages (
            id          SERIAL PRIMARY KEY,
            analysis_id INTEGER REFERENCES analyses(id) ON DELETE CASCADE,
            url         TEXT,
            checks      TEXT,             -- JSON: {text,code,js,ocr: ok|failed|skipped}
            created_at  TIMESTAMP DEFAULT NOW()
        )
    ");

    // Findings
    db()->exec("
        CREATE TABLE IF NOT EXISTS findings (
            id            SERIAL PRIMARY KEY,
            analysis_id   INTEGER REFERENCES analyses(id) ON DELETE CASCADE,
            page_id       INTEGER REFERENCES pages(id) ON DELETE SET NULL,
            rule_id       TEXT,
            category      TEXT,
            content_type  TEXT,           -- text | code | tooltip | footnote | image
            snippet       TEXT,
            location      TEXT,
            assessment    TEXT,           -- KI-Begründung
            severity      TEXT,           -- info | warn | violation
            status        TEXT DEFAULT 'open', -- open | ignored | done
            created_at    TIMESTAMP DEFAULT NOW()
        )
    ");

    // Umformulierungen
    db()->exec("
        CREATE TABLE IF NOT EXISTS reformulations (
            id          SERIAL PRIMARY KEY,
            finding_id  INTEGER REFERENCES findings(id) ON DELETE CASCADE,
            kind        TEXT,             -- manual | ai
            text        TEXT,
            accepted    BOOLEAN DEFAULT FALSE,
            created_at  TIMESTAMP DEFAULT NOW()
        )
    ");

    // Trainingsbeispiele (akzeptierte Umformulierungen — Stufe 4)
    db()->exec("
        CREATE TABLE IF NOT EXISTS training_examples (
            id            SERIAL PRIMARY KEY,
            category      TEXT,
            rule_id       TEXT,
            before_text   TEXT,
            after_text    TEXT,
            created_at    TIMESTAMP DEFAULT NOW()
        )
    ");

    // Einstellungen (z. B. editierbarer Agent-Prompt)
    db()->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key        TEXT PRIMARY KEY,
            value      TEXT,
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");
}

/** Liest einen Einstellungswert (mit Default). */
function setting_get(string $key, string $default = ''): string {
    $stmt = db()->prepare("SELECT value FROM settings WHERE key = :k");
    $stmt->execute([':k' => $key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string)$v;
}

/** Speichert einen Einstellungswert (Upsert). */
function setting_set(string $key, string $value): void {
    $stmt = db()->prepare(
        "INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, NOW())
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()"
    );
    $stmt->execute([':k' => $key, ':v' => $value]);
}

/** Aktueller Redakteur-Prompt (DB-Override oder Default). */
function editor_prompt(): string {
    $p = setting_get('editor_prompt', '');
    return $p !== '' ? $p : DEFAULT_EDITOR_PROMPT;
}
