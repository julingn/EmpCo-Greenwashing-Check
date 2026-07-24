<?php
// EmpCo Greenwashing-Check — Admin (Sidebar: Regeln + KI-Redakteure)
require __DIR__ . '/app/config.php';
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/layout.php';

// --- Logout ---
if (isset($_GET['logout'])) {
    $_SESSION['admin'] = false;
    header('Location: /admin.php');
    exit;
}

// --- Login ---
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $loginError = 'Ungültiges Formular. Bitte erneut versuchen.';
    } elseif (ADMIN_PASSWORD === '') {
        $loginError = 'Kein Admin-Passwort konfiguriert (ADMIN_PASSWORD in Railway setzen).';
    } elseif (hash_equals(ADMIN_PASSWORD, (string)$_POST['password'])) {
        $_SESSION['admin'] = true;
        header('Location: /admin.php');
        exit;
    } else {
        $loginError = 'Falsches Passwort.';
    }
}

if (empty($_SESSION['admin'])) {
    page_head('Admin-Login — EmpCo', '', true);
    ?>
    <h1>Admin-Login</h1>
    <p class="sub">Bitte anmelden, um Regeln und KI-Redakteure zu verwalten.</p>
    <?php if ($loginError): ?><div class="alert err"><?= h($loginError) ?></div><?php endif; ?>
    <div class="card">
      <form method="post" action="/admin.php">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label for="password">Passwort</label>
        <input type="password" id="password" name="password" required autofocus>
        <button type="submit">Anmelden</button>
      </form>
    </div>
    <?php
    page_foot();
    exit;
}

// --- Eingeloggt ---
$section = in_array($_GET['section'] ?? '', ['rules', 'evidence', 'agents', 'settings'], true) ? $_GET['section'] : 'rules';
$error = '';
$info = '';
try {
    db_init();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// ===== POST-Handler =====

// Regeln importieren (CSV/xlsx)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_rules') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges Formular (CSRF).';
    } elseif (empty($_FILES['rulefile']['tmp_name']) || !is_uploaded_file($_FILES['rulefile']['tmp_name'])) {
        $error = 'Keine Datei hochgeladen.';
    } else {
        try {
            $name = $_FILES['rulefile']['name'] ?? '';
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $rows = $ext === 'xlsx'
                ? parse_xlsx_rows($_FILES['rulefile']['tmp_name'])
                : parse_csv_rows($_FILES['rulefile']['tmp_name']);
            $imported = upsert_rules($rows);
            $info = "$imported Regel(n) importiert/aktualisiert.";
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

// Regel löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_rule') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        try {
            db()->prepare("DELETE FROM rules WHERE id = :id")->execute([':id' => (int)($_POST['id'] ?? 0)]);
            $info = 'Regel gelöscht.';
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

// Regel speichern (neu/bearbeiten)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rule') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges Formular (CSRF).';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $rid = trim($_POST['rule_id'] ?? '');
        if ($rid === '') {
            $error = 'Rule-ID darf nicht leer sein.';
        } else {
            $params = [
                ':rule_id'           => mb_substr($rid, 0, 120),
                ':category'          => trim($_POST['category'] ?? ''),
                ':description'       => trim($_POST['description'] ?? ''),
                ':trigger_terms'     => trim($_POST['trigger_terms'] ?? ''),
                ':example_violation' => trim($_POST['example_violation'] ?? ''),
                ':example_ok'        => trim($_POST['example_ok'] ?? ''),
                ':law_reference'     => trim($_POST['law_reference'] ?? ''),
                ':active'            => isset($_POST['active']),
            ];
            try {
                if ($id > 0) {
                    $params[':id'] = $id;
                    db()->prepare(
                        "UPDATE rules SET rule_id=:rule_id, category=:category, description=:description,
                            trigger_terms=:trigger_terms, example_violation=:example_violation,
                            example_ok=:example_ok, law_reference=:law_reference, active=:active
                         WHERE id=:id"
                    )->execute($params);
                    $info = 'Regel aktualisiert.';
                } else {
                    db()->prepare(
                        "INSERT INTO rules (rule_id, category, description, trigger_terms, example_violation, example_ok, law_reference, active)
                         VALUES (:rule_id, :category, :description, :trigger_terms, :example_violation, :example_ok, :law_reference, :active)
                         ON CONFLICT (rule_id) DO UPDATE SET
                            category=EXCLUDED.category, description=EXCLUDED.description, trigger_terms=EXCLUDED.trigger_terms,
                            example_violation=EXCLUDED.example_violation, example_ok=EXCLUDED.example_ok,
                            law_reference=EXCLUDED.law_reference, active=EXCLUDED.active"
                    )->execute($params);
                    $info = 'Regel angelegt.';
                }
            } catch (Throwable $e) { $error = $e->getMessage(); }
        }
    }
}

// KI-Redakteur speichern (neu/bearbeiten)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_agent') {
    $section = 'agents';
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges Formular (CSRF).';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $key = trim($_POST['agent_key'] ?? '');
        if ($name === '') {
            $error = 'Name darf nicht leer sein.';
        } else {
            try {
                if ($id > 0) {
                    db()->prepare(
                        "UPDATE agents SET name=:n, description=:d, prompt=:p, active=:a WHERE id=:id"
                    )->execute([
                        ':n' => mb_substr($name, 0, 120),
                        ':d' => trim($_POST['description'] ?? ''),
                        ':p' => trim($_POST['prompt'] ?? ''),
                        ':a' => isset($_POST['active']),
                        ':id' => $id,
                    ]);
                    $info = 'Redakteur aktualisiert.';
                } else {
                    if ($key === '') { $key = 'agent_' . substr(bin2hex(random_bytes(4)), 0, 8); }
                    db()->prepare(
                        "INSERT INTO agents (agent_key, name, description, prompt, active)
                         VALUES (:k, :n, :d, :p, :a)"
                    )->execute([
                        ':k' => preg_replace('/[^a-z0-9_]/', '', strtolower($key)),
                        ':n' => mb_substr($name, 0, 120),
                        ':d' => trim($_POST['description'] ?? ''),
                        ':p' => trim($_POST['prompt'] ?? ''),
                        ':a' => isset($_POST['active']),
                    ]);
                    $info = 'Redakteur angelegt.';
                }
            } catch (Throwable $e) { $error = $e->getMessage(); }
        }
    }
}

// KI-Redakteur löschen (Standard-Redakteur bleibt geschützt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_agent') {
    $section = 'agents';
    if (csrf_check($_POST['csrf'] ?? null)) {
        try {
            db()->prepare("DELETE FROM agents WHERE id = :id AND agent_key <> 'reformulator'")
                ->execute([':id' => (int)($_POST['id'] ?? 0)]);
            $info = 'Redakteur gelöscht.';
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

// Sitemap hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_sitemap') {
    $section = 'settings';
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges Formular (CSRF).';
    } else {
        $u = trim($_POST['sitemap_url'] ?? '');
        if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL)) {
            $error = 'Bitte eine gültige Sitemap-URL angeben (inkl. https://).';
        } else {
            try {
                db()->prepare("INSERT INTO sitemaps (url) VALUES (:u)")->execute([':u' => mb_substr($u, 0, 500)]);
                $info = 'Sitemap hinzugefügt.';
            } catch (Throwable $e) { $error = $e->getMessage(); }
        }
    }
}

// Sitemap löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_sitemap') {
    $section = 'settings';
    if (csrf_check($_POST['csrf'] ?? null)) {
        try {
            db()->prepare("DELETE FROM sitemaps WHERE id = :id")->execute([':id' => (int)($_POST['id'] ?? 0)]);
            $info = 'Sitemap entfernt.';
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

// Beleg speichern (neu/bearbeiten)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_evidence') {
    $section = 'evidence';
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges Formular (CSRF).';
    } else {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            $error = 'Titel darf nicht leer sein.';
        } else {
            $params = [
                ':title'       => mb_substr($title, 0, 300),
                ':type'        => trim($_POST['type'] ?? ''),
                ':category'    => trim($_POST['category'] ?? ''),
                ':rule_id'     => trim($_POST['rule_id'] ?? ''),
                ':content'     => trim($_POST['content'] ?? ''),
                ':source_url'  => trim($_POST['source_url'] ?? ''),
                ':valid_until' => trim($_POST['valid_until'] ?? ''),
                ':active'      => isset($_POST['active']),
            ];
            try {
                $eid = (int)($_POST['id'] ?? 0);
                if ($eid > 0) {
                    $params[':id'] = $eid;
                    db()->prepare(
                        "UPDATE evidence SET title=:title, type=:type, category=:category, rule_id=:rule_id,
                            content=:content, source_url=:source_url, valid_until=:valid_until, active=:active
                         WHERE id=:id"
                    )->execute($params);
                    $info = 'Beleg aktualisiert.';
                } else {
                    db()->prepare(
                        "INSERT INTO evidence (title, type, category, rule_id, content, source_url, valid_until, active)
                         VALUES (:title, :type, :category, :rule_id, :content, :source_url, :valid_until, :active)"
                    )->execute($params);
                    $info = 'Beleg angelegt.';
                }
            } catch (Throwable $e) { $error = $e->getMessage(); }
        }
    }
}

// Beleg löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_evidence') {
    $section = 'evidence';
    if (csrf_check($_POST['csrf'] ?? null)) {
        try {
            db()->prepare("DELETE FROM evidence WHERE id = :id")->execute([':id' => (int)($_POST['id'] ?? 0)]);
            $info = 'Beleg gelöscht.';
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

/** Bearbeiten-Formular für einen Beleg (leer = neuer Beleg). */
function evidence_form(array $e = []): void {
    $g = fn(string $k) => h((string)($e[$k] ?? ''));
    $id = (int)($e['id'] ?? 0);
    $isNew = $id === 0;
    $active = $isNew ? true : !empty($e['active']);
    $types = ['Zertifikat', 'Rechtsgrundlage', 'Methodik', 'Freigegebene Aussage'];
    $curType = (string)($e['type'] ?? '');
    ?>
    <form method="post" action="/admin.php?section=evidence">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_evidence">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="row">
        <div>
          <label>Titel</label>
          <input type="text" name="title" value="<?= $g('title') ?>" required placeholder="z. B. Grüner-Strom-Label-Zertifikat 2026">
        </div>
        <div>
          <label>Typ</label>
          <select name="type">
            <?php foreach ($types as $t): ?>
              <option value="<?= h($t) ?>" <?= $curType === $t ? 'selected' : '' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row">
        <div>
          <label>Kategorie (passend zur Regel-Kategorie)</label>
          <input type="text" name="category" value="<?= $g('category') ?>" placeholder="z. B. pauschalaussage">
        </div>
        <div>
          <label>Regel-ID (optional)</label>
          <input type="text" name="rule_id" value="<?= $g('rule_id') ?>" placeholder="EMPCO-XXX-...">
        </div>
      </div>
      <label>Beleg-Inhalt / Nachweis-Text</label>
      <textarea name="content" style="min-height:90px" placeholder="Konkreter Nachweis, Zertifikatstext, rechtssichere Formulierung …"><?= $g('content') ?></textarea>
      <div class="row">
        <div>
          <label>Quelle / Link</label>
          <input type="text" name="source_url" value="<?= $g('source_url') ?>" placeholder="https://…">
        </div>
        <div>
          <label>Gültig bis (optional)</label>
          <input type="text" name="valid_until" value="<?= $g('valid_until') ?>" placeholder="z. B. 2027-12-31">
        </div>
      </div>
      <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-weight:600">
        <input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?> style="width:auto"> aktiv
      </label>
      <div class="form-actions">
        <button type="submit"><?= $isNew ? 'Beleg anlegen' : 'Änderungen speichern' ?></button>
      </div>
    </form>
    <?php
}

// ===== Import-Parser =====
function parse_csv_rows(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') { throw new RuntimeException('CSV ist leer.'); }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $firstLine = strtok($raw, "\r\n");
    $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $header = fgetcsv($fh, 0, $delim);
    if (!$header) { throw new RuntimeException('CSV-Kopfzeile fehlt.'); }
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $assoc = [];
        foreach ($header as $i => $col) { $assoc[$col] = isset($row[$i]) ? trim((string)$row[$i]) : ''; }
        $rows[] = $assoc;
    }
    fclose($fh);
    return $rows;
}

function parse_xlsx_rows(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZIP-Extension fehlt (xlsx nicht lesbar). CSV nutzen oder Deploy abwarten.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { throw new RuntimeException('xlsx konnte nicht geöffnet werden.'); }
    $sharedRaw = $zip->getFromName('xl/sharedStrings.xml');
    $sheetRaw  = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetRaw === false) { throw new RuntimeException('Kein Tabellenblatt in der xlsx gefunden.'); }
    $shared = [];
    if ($sharedRaw !== false && $sharedRaw !== '') {
        $d = new DOMDocument();
        @$d->loadXML($sharedRaw);
        foreach ($d->getElementsByTagNameNS('*', 'si') as $si) {
            $txt = '';
            foreach ($si->getElementsByTagNameNS('*', 't') as $t) { $txt .= $t->textContent; }
            $shared[] = $txt;
        }
    }
    $d = new DOMDocument();
    @$d->loadXML($sheetRaw);
    $grid = [];
    foreach ($d->getElementsByTagNameNS('*', 'row') as $row) {
        $cells = [];
        foreach ($row->getElementsByTagNameNS('*', 'c') as $c) {
            $ref  = $c->getAttribute('r');
            $col  = preg_replace('/\d+/', '', $ref);
            $type = $c->getAttribute('t');
            $val  = '';
            if ($type === 'inlineStr') {
                foreach ($c->getElementsByTagNameNS('*', 't') as $t) { $val .= $t->textContent; }
            } else {
                $vnode = $c->getElementsByTagNameNS('*', 'v')->item(0);
                $val = $vnode ? $vnode->textContent : '';
                if ($type === 's') { $val = $shared[(int)$val] ?? ''; }
            }
            $cells[$col] = trim($val);
        }
        $grid[] = $cells;
    }
    if (!$grid) { return []; }
    $headerRow = array_shift($grid);
    $map = [];
    foreach ($headerRow as $letter => $name) {
        $n = strtolower(trim((string)$name));
        if ($n !== '') { $map[$letter] = $n; }
    }
    $rows = [];
    foreach ($grid as $cells) {
        $assoc = [];
        foreach ($map as $letter => $name) { $assoc[$name] = $cells[$letter] ?? ''; }
        $rows[] = $assoc;
    }
    return $rows;
}

function upsert_rules(array $rows): int {
    $stmt = db()->prepare(
        "INSERT INTO rules (rule_id, category, description, trigger_terms, example_violation, example_ok, law_reference, active)
         VALUES (:rule_id, :category, :description, :trigger_terms, :example_violation, :example_ok, :law_reference, TRUE)
         ON CONFLICT (rule_id) DO UPDATE SET
            category=EXCLUDED.category, description=EXCLUDED.description, trigger_terms=EXCLUDED.trigger_terms,
            example_violation=EXCLUDED.example_violation, example_ok=EXCLUDED.example_ok, law_reference=EXCLUDED.law_reference"
    );
    $count = 0;
    foreach ($rows as $r) {
        $rid = trim((string)($r['rule_id'] ?? ''));
        if ($rid === '') { continue; }
        $stmt->execute([
            ':rule_id'           => mb_substr($rid, 0, 120),
            ':category'          => (string)($r['category'] ?? ''),
            ':description'       => (string)($r['description'] ?? ''),
            ':trigger_terms'     => (string)($r['trigger_terms'] ?? ''),
            ':example_violation' => (string)($r['example_violation'] ?? ''),
            ':example_ok'        => (string)($r['example_ok'] ?? ''),
            ':law_reference'     => (string)($r['law_reference'] ?? ''),
        ]);
        $count++;
    }
    return $count;
}

/** Bearbeiten-Formular für eine Regel (leer = neue Regel). */
function rule_form(array $r = []): void {
    $g = fn(string $k) => h((string)($r[$k] ?? ''));
    $id = (int)($r['id'] ?? 0);
    $isNew = $id === 0;
    $active = $isNew ? true : !empty($r['active']);
    ?>
    <form method="post" action="/admin.php?section=rules">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_rule">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="row">
        <div>
          <label>Rule-ID</label>
          <input type="text" name="rule_id" value="<?= $g('rule_id') ?>" required placeholder="EMPCO-XXX-...">
        </div>
        <div>
          <label>Kategorie</label>
          <input type="text" name="category" value="<?= $g('category') ?>" placeholder="z. B. pauschalaussage">
        </div>
      </div>
      <label>Beschreibung</label>
      <textarea name="description" style="min-height:70px"><?= $g('description') ?></textarea>
      <label>Trigger-Begriffe (kommagetrennt)</label>
      <textarea name="trigger_terms" style="min-height:60px"><?= $g('trigger_terms') ?></textarea>
      <label>Beispiel — Verstoß</label>
      <textarea name="example_violation" style="min-height:60px"><?= $g('example_violation') ?></textarea>
      <label>Beispiel — konform</label>
      <textarea name="example_ok" style="min-height:60px"><?= $g('example_ok') ?></textarea>
      <label>Rechtsbezug</label>
      <input type="text" name="law_reference" value="<?= $g('law_reference') ?>">
      <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-weight:600">
        <input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?> style="width:auto"> aktiv
      </label>
      <div class="form-actions">
        <button type="submit"><?= $isNew ? 'Regel anlegen' : 'Änderungen speichern' ?></button>
      </div>
    </form>
    <?php
}

/** Bearbeiten-Formular für einen KI-Redakteur (leer = neuer Redakteur). */
function agent_form(array $a = []): void {
    $g = fn(string $k) => h((string)($a[$k] ?? ''));
    $id = (int)($a['id'] ?? 0);
    $isNew = $id === 0;
    $active = $isNew ? true : !empty($a['active']);
    ?>
    <form method="post" action="/admin.php?section=agents">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_agent">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="row">
        <div>
          <label>Name</label>
          <input type="text" name="name" value="<?= $g('name') ?>" required placeholder="z. B. Tone-of-Voice-Redakteur">
        </div>
        <div>
          <label>Schlüssel (technisch)<?= $isNew ? '' : ' — fest' ?></label>
          <input type="text" <?= $isNew ? 'name="agent_key"' : 'disabled' ?> value="<?= $g('agent_key') ?>" placeholder="automatisch">
        </div>
      </div>
      <label>Beschreibung</label>
      <input type="text" name="description" value="<?= $g('description') ?>">
      <label>Prompt</label>
      <textarea name="prompt" style="min-height:180px"><?= $g('prompt') ?></textarea>
      <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-weight:600">
        <input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?> style="width:auto"> aktiv
      </label>
      <div class="form-actions">
        <button type="submit"><?= $isNew ? 'Redakteur anlegen' : 'Änderungen speichern' ?></button>
      </div>
    </form>
    <?php
}

// ===== Daten laden =====
$rules = [];
$agents = [];
$sitemaps = [];
$evidence = [];
try {
    $rules = db()->query("SELECT * FROM rules ORDER BY rule_id")->fetchAll();
    $agents = get_agents();
    $sitemaps = get_sitemaps();
    $evidence = get_evidence();
} catch (Throwable $e) { if (!$error) { $error = $e->getMessage(); } }

$secTitle = ['rules' => 'Regeln', 'evidence' => 'Belege', 'agents' => 'KI-Redakteure', 'settings' => 'Einstellungen'];
page_head('Admin — EmpCo Greenwashing-Check', $section);
?>
<h1><?= h($secTitle[$section] ?? 'Admin') ?></h1>
<p class="sub">Verwaltung von Regelset, Belegen, KI-Redakteuren und Sitemaps.</p>

<?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>
<?php if ($info): ?><div class="alert ok"><?= h($info) ?></div><?php endif; ?>

<?php if ($section === 'rules'): ?>

      <h2 style="margin-top:0">Regeln importieren</h2>
      <div class="card">
        <p class="hint" style="margin-top:0">Datei (<code>.xlsx</code> oder <code>.csv</code>) mit Spalten: <code>rule_id, category, description, trigger_terms, example_violation, example_ok, law_reference</code>. Bestehende Regeln (gleiche <code>rule_id</code>) werden aktualisiert.</p>
        <form method="post" action="/admin.php?section=rules" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="import_rules">
          <input type="file" name="rulefile" accept=".csv,.xlsx" required style="margin-top:8px">
          <button type="submit">Importieren</button>
        </form>
      </div>

      <h2><?= count($rules) ?> Regel<?= count($rules) === 1 ? '' : 'n' ?></h2>
      <details class="rule" style="margin-bottom:16px">
        <summary><span class="tag">＋ Neue Regel anlegen</span>
          <svg class="sum-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="rule-body"><?php rule_form(); ?></div>
      </details>
      <?php if (!$rules): ?>
        <div class="card"><p class="sub" style="margin:0">Noch keine Regeln importiert.</p></div>
      <?php else: ?>
        <?php foreach ($rules as $r): ?>
          <details class="rule">
            <summary>
              <span class="sum-id"><?= h($r['rule_id']) ?></span>
              <span class="tag"><?= h($r['category']) ?></span>
              <?php if (empty($r['active'])): ?><span class="badge skipped">inaktiv</span><?php endif; ?>
              <span class="sum-desc"><?= h($r['description']) ?></span>
              <svg class="sum-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </summary>
            <div class="rule-body">
              <?php rule_form($r); ?>
              <form method="post" action="/admin.php?section=rules" style="margin-top:10px" onsubmit="return confirm('Regel wirklich löschen?')">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_rule">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn-ghost btn-sm">Regel löschen</button>
              </form>
            </div>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php elseif ($section === 'agents'): ?>

      <p class="hint" style="margin-top:0">Jeder Redakteur hat einen eigenen Prompt. Der <strong>Umformulierungs-Redakteur</strong> steuert die konforme Neuformulierung (Stufe 3). Weitere Redakteure (z. B. Tone of Voice) können ergänzt werden.</p>

      <details class="rule" style="margin:12px 0 16px">
        <summary><span class="tag">＋ Neuer Redakteur</span>
          <svg class="sum-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="rule-body"><?php agent_form(); ?></div>
      </details>

      <?php foreach ($agents as $a): ?>
        <details class="rule">
          <summary>
            <span class="sum-id"><?= h($a['name']) ?></span>
            <?php if (empty($a['active'])): ?><span class="badge skipped">inaktiv</span><?php endif; ?>
            <span class="sum-desc"><?= h($a['description']) ?></span>
            <svg class="sum-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
          </summary>
          <div class="rule-body">
            <?php agent_form($a); ?>
            <?php if ($a['agent_key'] !== 'reformulator'): ?>
              <form method="post" action="/admin.php?section=agents" style="margin-top:10px" onsubmit="return confirm('Redakteur wirklich löschen?')">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_agent">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn-ghost btn-sm">Redakteur löschen</button>
              </form>
            <?php else: ?>
              <p class="hint" style="margin-top:10px">Standard-Redakteur — nicht löschbar.</p>
            <?php endif; ?>
          </div>
        </details>
      <?php endforeach; ?>

    <?php elseif ($section === 'evidence'): ?>

      <p class="hint" style="margin-top:0">Belege (Zertifikate, Rechtsgrundlagen, Methodik, freigegebene Aussagen), die die KI später zum <strong>Nachweisen</strong> kritischer Aussagen nutzt. Ordne sie über <strong>Kategorie</strong> und/oder <strong>Regel-ID</strong> den passenden Findings zu.</p>

      <details class="rule" style="margin:12px 0 16px">
        <summary><span class="tag">＋ Neuer Beleg</span>
          <svg class="sum-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>
        <div class="rule-body"><?php evidence_form(); ?></div>
      </details>

      <?php if (!$evidence): ?>
        <div class="card"><p class="sub" style="margin:0">Noch keine Belege hinterlegt.</p></div>
      <?php else: ?>
        <?php foreach ($evidence as $e): ?>
          <details class="rule">
            <summary>
              <span class="sum-id"><?= h($e['title']) ?></span>
              <?php if (!empty($e['type'])): ?><span class="tag"><?= h($e['type']) ?></span><?php endif; ?>
              <?php if (empty($e['active'])): ?><span class="badge skipped">inaktiv</span><?php endif; ?>
              <span class="sum-desc"><?= h($e['category'] ?: $e['rule_id']) ?></span>
              <svg class="sum-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </summary>
            <div class="rule-body">
              <?php evidence_form($e); ?>
              <form method="post" action="/admin.php?section=evidence" style="margin-top:10px" onsubmit="return confirm('Beleg wirklich löschen?')">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_evidence">
                <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                <button type="submit" class="btn-ghost btn-sm">Beleg löschen</button>
              </form>
            </div>
          </details>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php else: ?>

      <p class="hint" style="margin-top:0">Hinterlege konkrete <strong>Sitemaps</strong> (XML), damit der Crawl auch Seiten findet, die nur über JavaScript-Navigation verlinkt sind. Beim Analysieren werden Sitemaps genutzt, deren Domain zur geprüften URL passt – gefiltert nach der gewählten Tiefe.</p>

      <div class="card">
        <form method="post" action="/admin.php?section=settings" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_sitemap">
          <div style="flex:1;min-width:260px">
            <label style="margin-top:0">Sitemap-URL</label>
            <input type="url" name="sitemap_url" placeholder="https://www.beispiel.de/sitemap.xml" required>
          </div>
          <button type="submit" style="margin:0">Hinzufügen</button>
        </form>
      </div>

      <?php if (!$sitemaps): ?>
        <div class="card"><p class="sub" style="margin:0">Noch keine Sitemaps hinterlegt. Ohne Eintrag versucht der Crawl automatisch <code>robots.txt</code> und <code>/sitemap.xml</code>.</p></div>
      <?php else: ?>
        <?php foreach ($sitemaps as $sm): ?>
          <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 20px">
            <a href="<?= h($sm['url']) ?>" target="_blank" rel="noopener" style="word-break:break-all;color:var(--accent);text-decoration:none"><?= h($sm['url']) ?></a>
            <form method="post" action="/admin.php?section=settings" style="margin:0" onsubmit="return confirm('Sitemap wirklich entfernen?')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_sitemap">
              <input type="hidden" name="id" value="<?= (int)$sm['id'] ?>">
              <button type="submit" class="btn-ghost btn-sm">Entfernen</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php endif; ?>
<?php
page_foot();
