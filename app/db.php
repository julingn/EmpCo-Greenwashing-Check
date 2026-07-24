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
            status      TEXT DEFAULT 'pending', -- pending | fetched | failed
            depth       INTEGER DEFAULT 0,
            created_at  TIMESTAMP DEFAULT NOW()
        )
    ");
    // Migration für bestehende DBs (Spalten für den Crawl)
    db()->exec("ALTER TABLE pages ADD COLUMN IF NOT EXISTS status TEXT DEFAULT 'pending'");
    db()->exec("ALTER TABLE pages ADD COLUMN IF NOT EXISTS depth INTEGER DEFAULT 0");

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

    // Kandidaten (Trigger-Treffer, die schrittweise per KI bewertet werden)
    db()->exec("
        CREATE TABLE IF NOT EXISTS candidates (
            id           SERIAL PRIMARY KEY,
            analysis_id  INTEGER REFERENCES analyses(id) ON DELETE CASCADE,
            page_id      INTEGER,
            rule_id      TEXT,
            category     TEXT,
            content_type TEXT,
            snippet      TEXT,
            processed    BOOLEAN DEFAULT FALSE,
            created_at   TIMESTAMP DEFAULT NOW()
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

    // Einstellungen (z. B. Alt-Werte)
    db()->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key        TEXT PRIMARY KEY,
            value      TEXT,
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    // Sitemaps (im Admin gepflegt, für den Crawl genutzt)
    db()->exec("
        CREATE TABLE IF NOT EXISTS sitemaps (
            id         SERIAL PRIMARY KEY,
            url        TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");

    // Belege / Nachweise (Zertifikate, Rechtsgrundlagen, Methodik) für den Nachweis-Weg
    db()->exec("
        CREATE TABLE IF NOT EXISTS evidence (
            id          SERIAL PRIMARY KEY,
            title       TEXT NOT NULL,
            type        TEXT,
            category    TEXT,
            rule_id     TEXT,
            content     TEXT,
            source_url  TEXT,
            valid_until TEXT,
            active      BOOLEAN DEFAULT TRUE,
            created_at  TIMESTAMP DEFAULT NOW()
        )
    ");

    // KI-Redakteure (mehrere Agenten, je eigener Prompt)
    db()->exec("
        CREATE TABLE IF NOT EXISTS agents (
            id          SERIAL PRIMARY KEY,
            agent_key   TEXT UNIQUE NOT NULL,
            name        TEXT NOT NULL,
            description TEXT,
            prompt      TEXT,
            active      BOOLEAN DEFAULT TRUE,
            created_at  TIMESTAMP DEFAULT NOW()
        )
    ");

    // Standard-Redakteur einmalig anlegen (übernimmt ggf. alten settings-Prompt)
    $hasAgents = (int) db()->query("SELECT COUNT(*) FROM agents")->fetchColumn();
    if ($hasAgents === 0) {
        $existing = setting_get('editor_prompt', '');
        $prompt = $existing !== '' ? $existing : DEFAULT_EDITOR_PROMPT;
        db()->prepare(
            "INSERT INTO agents (agent_key, name, description, prompt)
             VALUES ('reformulator', :n, :d, :p)"
        )->execute([
            ':n' => 'Umformulierungs-Redakteur',
            ':d' => 'Formuliert beanstandete Textstellen EmpCo-konform um (Stufe 3).',
            ':p' => $prompt,
        ]);
    }
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

/** Liste der im Admin gepflegten Sitemap-URLs. */
function get_sitemaps(): array {
    try {
        return db()->query("SELECT * FROM sitemaps ORDER BY id")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Liste der im Admin gepflegten Belege/Nachweise. */
function get_evidence(): array {
    try {
        return db()->query("SELECT * FROM evidence ORDER BY title")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Aktueller Redakteur-Prompt des Umformulierungs-Redakteurs (DB oder Default). */
function editor_prompt(): string {
    try {
        $stmt = db()->prepare("SELECT prompt FROM agents WHERE agent_key = 'reformulator' AND active LIMIT 1");
        $stmt->execute();
        $p = $stmt->fetchColumn();
        if ($p !== false && (string)$p !== '') { return (string)$p; }
    } catch (Throwable $e) { /* Fallback unten */ }
    return DEFAULT_EDITOR_PROMPT;
}

/** Liefert alle KI-Redakteure. */
function get_agents(): array {
    return db()->query("SELECT * FROM agents ORDER BY id")->fetchAll();
}
