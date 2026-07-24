<?php
// EmpCo Greenwashing-Check — Eingabeseite (Analyse starten)
require __DIR__ . '/app/config.php';
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/layout.php';

// --- Logout ---
if (isset($_GET['logout'])) {
    $_SESSION['user'] = false;
    $_SESSION['admin'] = false;
    header('Location: /');
    exit;
}

// --- Zugangsschutz: Login-Verarbeitung ---
$gateError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_password'])) {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $gateError = 'Ungültiges Formular. Bitte erneut versuchen.';
    } elseif (APP_PASSWORD === '') {
        $gateError = 'Kein Zugangspasswort konfiguriert (ADMIN_PASSWORD oder APP_PASSWORD in Railway setzen).';
    } elseif (hash_equals(APP_PASSWORD, (string)$_POST['access_password'])) {
        $_SESSION['user'] = true;
        header('Location: /');
        exit;
    } else {
        $gateError = 'Falsches Passwort.';
    }
}

// --- Login-Formular anzeigen, wenn kein Zugang ---
if (!has_user_access()) {
    page_head('Zugang — EmpCo Greenwashing-Check', '', true);
    ?>
    <h1>Zugang</h1>
    <p class="sub">Dieser Bereich ist passwortgeschützt. Bitte gib das Zugangspasswort ein.</p>
    <?php if ($gateError): ?><div class="alert err"><?= h($gateError) ?></div><?php endif; ?>
    <div class="card">
      <form method="post" action="/">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label for="access_password">Passwort</label>
        <input type="password" id="access_password" name="access_password" required autofocus>
        <button type="submit">Zugang</button>
      </form>
    </div>
    <?php
    page_foot();
    exit;
}

// --- Analyse anlegen (Engine folgt in Schritt 2) ---
$error = '';
$info = '';
$ruleCount = 0;
try {
    db_init();
    $ruleCount = (int) db()->query("SELECT COUNT(*) FROM rules WHERE active")->fetchColumn();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges Formular (CSRF).';
    } else {
        $url = trim($_POST['url'] ?? '');
        $scope = in_array($_POST['scope'] ?? '', ['exact', 'depth1', 'depth2', 'full'], true) ? $_POST['scope'] : 'exact';
        $language = in_array($_POST['language'] ?? '', ['auto', 'de', 'en'], true) ? $_POST['language'] : 'auto';
        if ($url === '') {
            $error = 'Bitte eine URL angeben.';
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            $error = 'Bitte eine gültige URL angeben (inkl. https://).';
        } else {
            try {
                require_once __DIR__ . '/app/analyzer.php';
                set_time_limit(0);
                $stmt = db()->prepare(
                    "INSERT INTO analyses (source_type, source_ref, scope, language, status)
                     VALUES (:t, :r, :s, :l, 'running') RETURNING id"
                );
                $stmt->execute([
                    ':t' => $scope === 'exact' ? 'url' : 'tld',
                    ':r' => mb_substr($url, 0, 500),
                    ':s' => $scope,
                    ':l' => $language,
                ]);
                $id = (int) $stmt->fetchColumn();
                prepare_analysis($id, $url, $scope);
                header('Location: /results.php?id=' . $id);
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

page_head('Analyse — EmpCo Greenwashing-Check', 'analyse');
?>
<h1>Neue Analyse</h1>
<p class="sub">Prüfe Inhalte auf Greenwashing nach der EmpCo-Richtlinie (EU) 2024/825.</p>

<?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>
<?php if ($info): ?><div class="alert info"><?= h($info) ?></div><?php endif; ?>

<div class="card">
  <form method="post" action="/">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="analyze">

    <label for="url">URL</label>
    <input type="url" id="url" name="url" placeholder="https://www.beispiel.de/tarif" required>

    <div class="row">
      <div>
        <label for="scope">Umfang</label>
        <select id="scope" name="scope">
          <option value="exact">Nur exakte URL</option>
          <option value="depth1">Tiefe 1 (Seite + direkte Unterseiten)</option>
          <option value="depth2">Tiefe 2</option>
          <option value="full">Ganze Domain (TLD)</option>
        </select>
      </div>
      <div>
        <label for="language">Sprache</label>
        <select id="language" name="language">
          <option value="auto">Automatisch erkennen</option>
          <option value="de">Deutsch</option>
          <option value="en">Englisch</option>
        </select>
      </div>
    </div>

    <button type="submit">Analyse starten</button>
  </form>
</div>

<p>
  <span class="count-pill"><?= $ruleCount ?> aktive Regel<?= $ruleCount === 1 ? '' : 'n' ?></span>
  <?php if ($ruleCount === 0): ?>
    <span class="hint">Noch keine Regeln geladen — im <a href="/admin.php">Admin-Bereich</a> importieren.</span>
  <?php endif; ?>
</p>
<?php
page_foot();
