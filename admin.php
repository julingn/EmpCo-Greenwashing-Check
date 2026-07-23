<?php
// EmpCo Greenwashing-Check — Admin (Regeln verwalten + Import, KI-Redakteur-Prompt)
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
    page_head('Admin-Login — EmpCo');
    ?>
    <h1>Admin-Login</h1>
    <p class="sub">Bitte anmelden, um Regeln und Einstellungen zu verwalten.</p>
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
$error = '';
$info = '';
try {
    db_init();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// KI-Redakteur-Prompt speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_prompt') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges Formular (CSRF).';
    } else {
        try {
            setting_set('editor_prompt', trim($_POST['editor_prompt'] ?? ''));
            $info = 'KI-Redakteur-Prompt gespeichert.';
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

// Regel speichern (neu oder bearbeiten)
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

// Regeln importieren (CSV-Upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_rules') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges Formular (CSRF).';
    } elseif (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $error = 'Keine CSV-Datei hochgeladen.';
    } else {
        try {
            $imported = import_rules_csv($_FILES['csv']['tmp_name']);
            $info = "$imported Regel(n) importiert/aktualisiert.";
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

/** Liest eine CSV (Header: rule_id,category,description,trigger_terms,example_violation,example_ok,law_reference) und upsertet. */
function import_rules_csv(string $path): int {
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') { throw new RuntimeException('CSV ist leer.'); }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM entfernen
    // Trennzeichen erkennen (; oder ,)
    $firstLine = strtok($raw, "\r\n");
    $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $header = fgetcsv($fh, 0, $delim);
    if (!$header) { throw new RuntimeException('CSV-Kopfzeile fehlt.'); }
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
    $idx = array_flip($header);
    $need = 'rule_id';
    if (!isset($idx[$need])) { throw new RuntimeException('Spalte "rule_id" fehlt in der CSV.'); }

    $stmt = db()->prepare(
        "INSERT INTO rules (rule_id, category, description, trigger_terms, example_violation, example_ok, law_reference, active)
         VALUES (:rule_id, :category, :description, :trigger_terms, :example_violation, :example_ok, :law_reference, TRUE)
         ON CONFLICT (rule_id) DO UPDATE SET
            category=EXCLUDED.category, description=EXCLUDED.description, trigger_terms=EXCLUDED.trigger_terms,
            example_violation=EXCLUDED.example_violation, example_ok=EXCLUDED.example_ok, law_reference=EXCLUDED.law_reference"
    );
    $get = fn($row, $col) => isset($idx[$col]) && isset($row[$idx[$col]]) ? trim((string)$row[$idx[$col]]) : '';
    $count = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $rid = $get($row, 'rule_id');
        if ($rid === '') { continue; }
        $stmt->execute([
            ':rule_id'           => $rid,
            ':category'          => $get($row, 'category'),
            ':description'       => $get($row, 'description'),
            ':trigger_terms'     => $get($row, 'trigger_terms'),
            ':example_violation' => $get($row, 'example_violation'),
            ':example_ok'        => $get($row, 'example_ok'),
            ':law_reference'     => $get($row, 'law_reference'),
        ]);
        $count++;
    }
    fclose($fh);
    return $count;
}

/** Rendert das Bearbeiten-Formular für eine Regel (leer = neue Regel). */
function rule_form(array $r = []): void {
    $g = fn(string $k) => h((string)($r[$k] ?? ''));
    $id = (int)($r['id'] ?? 0);
    $isNew = $id === 0;
    $active = $isNew ? true : !empty($r['active']);
    ?>
    <form method="post" action="/admin.php">
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

// Daten laden
$rules = [];
try {
    $rules = db()->query("SELECT * FROM rules ORDER BY rule_id")->fetchAll();
} catch (Throwable $e) { if (!$error) { $error = $e->getMessage(); } }

page_head('Admin — EmpCo Greenwashing-Check', 'admin');
?>
<div style="display:flex;justify-content:space-between;align-items:center">
  <h1 style="margin:0">Admin-Bereich</h1>
  <a href="/admin.php?logout=1" style="color:var(--text2);font-size:14px">Abmelden</a>
</div>
<p class="sub">Regelset verwalten und KI-Redakteur konfigurieren.</p>

<?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>
<?php if ($info): ?><div class="alert ok"><?= h($info) ?></div><?php endif; ?>

<h2>Regeln importieren</h2>
<div class="card">
  <p class="hint" style="margin-top:0">CSV mit Spalten: <code>rule_id, category, description, trigger_terms, example_violation, example_ok, law_reference</code>. Bestehende Regeln (gleiche <code>rule_id</code>) werden aktualisiert.</p>
  <form method="post" action="/admin.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="import_rules">
    <input type="file" name="csv" accept=".csv" required style="margin-top:8px">
    <button type="submit">Importieren</button>
  </form>
</div>

<h2>KI-Redakteur — Prompt</h2>
<div class="card">
  <form method="post" action="/admin.php">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_prompt">
    <textarea name="editor_prompt" style="min-height:180px"><?= h(editor_prompt()) ?></textarea>
    <div class="hint">Dieser Prompt steuert die KI-basierte Umformulierung (Stufe 3).</div>
    <button type="submit">Prompt speichern</button>
  </form>
</div>

<h2><?= count($rules) ?> Regel<?= count($rules) === 1 ? '' : 'n' ?> im System</h2>

<details class="rule" style="margin-bottom:16px">
  <summary><span class="tag">＋ Neue Regel anlegen</span></summary>
  <div class="rule-body"><?php rule_form(); ?></div>
</details>

<?php if (!$rules): ?>
  <div class="card"><p class="sub" style="margin:0">Noch keine Regeln importiert. Nutze „Regeln importieren" oder lege oben eine neue Regel an.</p></div>
<?php else: ?>
  <?php foreach ($rules as $r): ?>
    <details class="rule">
      <summary>
        <span class="sum-id"><?= h($r['rule_id']) ?></span>
        <span class="tag"><?= h($r['category']) ?></span>
        <?php if (empty($r['active'])): ?><span class="badge skipped">inaktiv</span><?php endif; ?>
        <span class="sum-desc"><?= h($r['description']) ?></span>
      </summary>
      <div class="rule-body">
        <?php rule_form($r); ?>
        <form method="post" action="/admin.php" style="margin-top:10px" onsubmit="return confirm('Regel wirklich löschen?')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_rule">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button type="submit" class="btn-ghost btn-sm">Regel löschen</button>
        </form>
      </div>
    </details>
  <?php endforeach; ?>
<?php endif; ?>
<?php
page_foot();
