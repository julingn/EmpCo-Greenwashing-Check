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
            scope       TEXT,             -- exact | depth1 | depth2 | full | pdf
            status      TEXT DEFAULT 'pending',
            language    TEXT,
            use_js      BOOLEAN DEFAULT FALSE,
            use_ocr     BOOLEAN DEFAULT FALSE,
            created_at  TIMESTAMP DEFAULT NOW()
        )
    ");
    db()->exec("ALTER TABLE analyses ADD COLUMN IF NOT EXISTS use_js BOOLEAN DEFAULT FALSE");
    db()->exec("ALTER TABLE analyses ADD COLUMN IF NOT EXISTS use_ocr BOOLEAN DEFAULT FALSE");

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
            remedy_path     TEXT,         -- belegbar | belegt_anpassen | nicht_belegbar
            remedy_evidence TEXT,         -- Titel des passenden Belegs
            remedy_note     TEXT,         -- KI-Begründung / empfohlener Zusatztext
            created_at    TIMESTAMP DEFAULT NOW()
        )
    ");
    // Migration für bestehende DBs (Nachweis-Check, Stufe B)
    db()->exec("ALTER TABLE findings ADD COLUMN IF NOT EXISTS remedy_path TEXT");
    db()->exec("ALTER TABLE findings ADD COLUMN IF NOT EXISTS remedy_evidence TEXT");
    db()->exec("ALTER TABLE findings ADD COLUMN IF NOT EXISTS remedy_note TEXT");

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

    // Trainingsbeispiele / Vorher-Nachher (Few-Shot für Umformulierung, Stufe C+D)
    db()->exec("
        CREATE TABLE IF NOT EXISTS training_examples (
            id            SERIAL PRIMARY KEY,
            category      TEXT,
            rule_id       TEXT,
            before_text   TEXT,
            after_text    TEXT,
            note          TEXT,
            active        BOOLEAN DEFAULT TRUE,
            source        TEXT DEFAULT 'manual', -- manual | learned
            finding_id    INTEGER,
            created_at    TIMESTAMP DEFAULT NOW()
        )
    ");
    db()->exec("ALTER TABLE training_examples ADD COLUMN IF NOT EXISTS note TEXT");
    db()->exec("ALTER TABLE training_examples ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE");
    db()->exec("ALTER TABLE training_examples ADD COLUMN IF NOT EXISTS source TEXT DEFAULT 'manual'");
    db()->exec("ALTER TABLE training_examples ADD COLUMN IF NOT EXISTS finding_id INTEGER");

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

    // Vorher/Nachher-Beispiele einmalig befüllen (rechtlich fundiert: VKU-FAQ + BDEW-Gutachten)
    $hasExamples = (int) db()->query("SELECT COUNT(*) FROM training_examples")->fetchColumn();
    if ($hasExamples === 0) {
        seed_training_examples();
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

/** Liste der Vorher/Nachher-Beispiele. */
function get_examples(): array {
    try {
        return db()->query("SELECT * FROM training_examples ORDER BY category, id")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Befüllt die Beispiel-Bibliothek einmalig mit rechtlich fundierten Vorher/Nachher-Beispielen. */
function seed_training_examples(): void {
    $examples = [
        ['pauschalaussage', 'EMPCO-006-OEKOSTROM-GENERISCH, EMPCO-052-100-PROZENT-ERNEUERBAR',
            '100 % Ökostrom für Ihr Zuhause – gut für die Umwelt.',
            'Ökostrom – 100 % Strom aus erneuerbaren Energien.',
            'BDEW-Gutachten „Vorgehensweise 2“: „Ökostrom“ ist eine allgemeine Umweltaussage (Nr. 4a UWG n.F.) und muss auf demselben Medium klar und hervorgehoben spezifiziert werden. Mindestmaß: Herkunft „aus erneuerbaren Energien“ (gesetzlich definiert, § 3 Nr. 21 EEG 2023). Gem. Erwägungsgrund 9 EmpCo ausreichend.'],
        ['pauschalaussage', 'EMPCO-006-OEKOSTROM-GENERISCH',
            'Ökostrom',
            'Ökostrom – 100 % Strom aus erneuerbaren Energien.* *Physikalisch kann bei der Nutzung öffentlicher Stromnetze nicht sichergestellt werden, dass der konkrete Strom, den Sie verbrauchen, aus Kraftwerken mit erneuerbaren Energien stammt. Durch Einkauf und Entwertung von Herkunftsnachweisen wird jedoch sichergestellt, dass eine aus erneuerbaren Energien erzeugte Strommenge nur einmal unter dieser Kennzeichnung entnommen werden kann. Herkunftsnachweise können auch aus anderen europäischen Ländern stammen (siehe Stromkennzeichnung). Weitere Informationen: [LINK]',
            'BDEW-Gutachten „Vorgehensweise 3“ (Drei-Schritt-Ansatz: Claim → Erläuterung → Weblink/QR-Code). Beseitigt auch das Restrisiko der systemischen Herkunftsnachweis-Kritik.'],
        ['pauschalaussage', 'EMPCO-001-PAUSCHAL-UMWELT',
            'Mit unserem umweltfreundlichen Stromtarif tun Sie etwas Gutes für die Natur.',
            'Unser Stromtarif besteht zu 100 % aus Strom aus erneuerbaren Energien (Herkunftsnachweise gem. § 42 EnWG, siehe Stromkennzeichnung).',
            '„umweltfreundlich“ ist eine allgemeine Umweltaussage (Nr. 4a) ohne nachweisbare anerkannte hervorragende Umweltleistung → durch spezifische, belegbare Herkunftsangabe ersetzen.'],
        ['pauschalaussage', 'EMPCO-002-PAUSCHAL-KLIMA',
            'Unser klimafreundliches Gasangebot für Ihr Zuhause.',
            'Unser Gasangebot enthält 30 % Biomethan aus regionalen Anlagen (Emissionsfaktor 110 g CO₂eq/kWh ggü. 245 g CO₂eq/kWh bei Erdgas; Methodik: GHG-Protocol Scope 1+3).',
            'Pauschales „klimafreundlich“ (Nr. 4a) durch konkrete, belegte Kennzahl mit Methodik ersetzen.'],
        ['pauschalaussage', 'EMPCO-004-PAUSCHAL-NACHHALTIG',
            'Nachhaltige Energieversorgung für die Region.',
            'Unsere Erzeugung erreichte 2025 einen Anteil von 38 % erneuerbarer Energien (Bilanzkreis-Nachweis).',
            '„nachhaltig“ (Nr. 4a) durch konkrete, überprüfbare Kennzahl ersetzen.'],
        ['klimaneutralitaet_kompensation', 'EMPCO-020-KLIMANEUTRAL-PRODUKT, EMPCO-021-KLIMANEUTRALES-GAS',
            'Unser klimaneutraler Strom – durch Kompensation in zertifizierten Klimaschutzprojekten.',
            'Für unsere Energieprodukte treffen wir keine Klimaneutralitäts-Aussage. Über unsere Investitionen in Klimaschutzprojekte informieren wir transparent unter [LINK] – ohne die Bezeichnung „klimaneutral“.',
            'Produktbezogene Kompensations-/Klimaneutralitäts-Aussagen sind per se verboten (Nr. 4c). Werbung für Investitionen in Umweltinitiativen bleibt zulässig, aber nicht mit „klimaneutral“ (VKU-FAQ 12c).'],
        ['teil_zu_gesamt', 'EMPCO-050-TEIL-AUF-GESAMT',
            'Klimafreundliche Stadtwerke.',
            'Unsere Fernwärmeversorgung stammt zu 48 % aus erneuerbaren Quellen (Geothermie, Biomasse; Stand 2025).',
            'Reichweiten-Verbot (Nr. 4b): Aussage nur auf die Sparte beziehen, die die Eigenschaft tatsächlich belegt (VKU-FAQ 12b, Beispiel 2).'],
        ['teil_zu_gesamt', 'EMPCO-051-GRUENE-FERNWAERME',
            'Grüne Fernwärme für Mannheim.',
            'Unsere Fernwärme stammt zu 48 % aus erneuerbaren Quellen (Geothermie, Biomasse) und zu 52 % aus Erdgas-KWK (Stand 2025; Ziel: 65 % EE bis 2030).',
            'Wärmemix transparent und anteilig ausweisen statt pauschal „grün“ (Nr. 4a + 4b).'],
        ['teil_zu_gesamt', 'EMPCO-050-TEIL-AUF-GESAMT',
            'Mit Recyclingmaterial hergestellt.',
            'Die Verpackung besteht zu 100 % aus Recyclingmaterial.',
            'VKU-FAQ 12b, Beispiel 1 – Reichweite klarstellen, wenn nur ein Teil (Verpackung) die Eigenschaft erfüllt.'],
        ['teil_zu_gesamt', 'EMPCO-051-GRUENE-FERNWAERME, EMPCO-002-PAUSCHAL-KLIMA',
            'Klimaneutrale Fernwärme.',
            'Fernwärme aus hocheffizienter Kraft-Wärme-Kopplung (KWK) im Sinne der EU-Energieeffizienzrichtlinie 2023/1791 (Art. 2 Nr. 40).',
            'VKU-FAQ 15b – allgemeine Aussage durch Hinweis auf den Energieträger bzw. einen gesetzlich definierten Begriff spezifizieren (z. B. KWK, effiziente Fernwärme, Umweltwärme).'],
        ['zukunftsversprechen', 'EMPCO-040-FUTURE-CLAIM',
            'Wir sind klimaneutral bis 2040.',
            'Wir verfolgen das Ziel der Konzern-Klimaneutralität (Scope 1+2) bis 2040 gemäß unserem öffentlich einsehbaren Umsetzungsplan mit messbaren Zwischenzielen (–30 % bis 2027, –60 % bis 2032). Der Plan wird regelmäßig von einem unabhängigen externen Sachverständigen geprüft (Ergebnisse: [LINK]).',
            '§ 5 Abs. 3 Nr. 4 UWG n.F. / VKU-FAQ 13 – Zukunftsaussage nur mit klaren, öffentlich einsehbaren, überprüfbaren Verpflichtungen in einem detaillierten Umsetzungsplan + externer Prüfung.'],
        ['eigene_siegel', 'EMPCO-030-EIGENSIEGEL',
            'Mit dem MVV-Eco-Siegel ausgezeichnet.',
            'Ausgezeichnet mit dem Grüner-Strom-Label (zertifiziert durch Grüner Strom Label e. V. – unabhängiges Zertifizierungssystem).',
            'Nr. 2a – Nachhaltigkeitssiegel muss auf einem Zertifizierungssystem beruhen oder staatlich festgesetzt sein; Eigen-Siegel ohne Drittprüfung unzulässig (VKU-FAQ 12d).'],
        ['gesetzeskonformitaet_als_usp', 'EMPCO-060-GESETZ-ALS-USP',
            'Unser Stromtarif erfüllt alle gesetzlichen EE-Quoten – ein klares Plus für Sie.',
            'Hinweis zur Stromkennzeichnung: Unser Bundes-Mix entspricht den Vorgaben des § 42 EnWG.',
            'Nr. 10a – gesetzlich vorgeschriebene Eigenschaften nicht als besonderes Verkaufsargument („USP“) darstellen; reine Pflichtinformation.'],
    ];
    $stmt = db()->prepare(
        "INSERT INTO training_examples (category, rule_id, before_text, after_text, note, active)
         VALUES (:cat, :rid, :b, :a, :n, TRUE)"
    );
    foreach ($examples as $e) {
        $stmt->execute([':cat' => $e[0], ':rid' => $e[1], ':b' => $e[2], ':a' => $e[3], ':n' => $e[4]]);
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
