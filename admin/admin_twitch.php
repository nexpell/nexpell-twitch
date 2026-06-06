<?php

// Session absichern
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

use nexpell\LanguageService;
use nexpell\AccessControl;

// Admin-Zugriff prüfen
AccessControl::checkAdminAccess('twitch');

global $_database, $languageService;

if (isset($languageService) && method_exists($languageService, 'readModule')) {
    $languageService->readModule('twitch');
}

if (!function_exists('twitch_ensure_settings_schema')) {
    function twitch_ensure_settings_schema(mysqli $database): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $database->query("
            CREATE TABLE IF NOT EXISTS plugins_twitch_settings (
              id int(11) NOT NULL AUTO_INCREMENT,
              main_channel varchar(100) NOT NULL,
              extra_channels text NOT NULL,
              client_id varchar(255) NOT NULL DEFAULT '',
              client_secret varchar(255) NOT NULL DEFAULT '',
              updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=2
        ");

        $requiredColumns = [
            'client_id' => "ALTER TABLE plugins_twitch_settings ADD client_id varchar(255) NOT NULL DEFAULT '' AFTER extra_channels",
            'client_secret' => "ALTER TABLE plugins_twitch_settings ADD client_secret varchar(255) NOT NULL DEFAULT '' AFTER client_id",
        ];

        foreach ($requiredColumns as $column => $sql) {
            $exists = $database->query("SHOW COLUMNS FROM plugins_twitch_settings LIKE '" . $database->real_escape_string($column) . "'");
            if ($exists instanceof mysqli_result && $exists->num_rows === 0) {
                $database->query($sql);
            }
        }

        $database->query("
            INSERT IGNORE INTO plugins_twitch_settings (id, main_channel, extra_channels, client_id, client_secret)
            VALUES (1, 'fl0m', 'zonixxcs,trilluxe', '', '')
        ");

        $ensured = true;
    }
}

twitch_ensure_settings_schema($_database);

// POST speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        nx_redirect('admincenter.php?site=admin_twitch', 'danger', 'alert_transaction_invalid', false);
    }

    $main = $_database->real_escape_string($_POST['main_channel'] ?? '');
    $extra = $_database->real_escape_string($_POST['extra_channels'] ?? '');
    $clientId = $_database->real_escape_string($_POST['client_id'] ?? '');
    $clientSecret = $_database->real_escape_string($_POST['client_secret'] ?? '');

    $sql = "
        INSERT INTO plugins_twitch_settings (id, main_channel, extra_channels, client_id, client_secret)
        VALUES (1, '$main', '$extra', '$clientId', '$clientSecret')
        ON DUPLICATE KEY UPDATE
            main_channel = VALUES(main_channel),
            extra_channels = VALUES(extra_channels),
            client_id = VALUES(client_id),
            client_secret = VALUES(client_secret)
    ";
    if ($_database->query($sql)) {
        nx_redirect('admincenter.php?site=admin_twitch', 'success', 'alert_saved', false);
    } else {
        nx_alert('danger', 'alert_db_error', false);
    }
}

// Aktuelle Werte holen
$result = $_database->query("SELECT main_channel, extra_channels, client_id, client_secret FROM plugins_twitch_settings WHERE id = 1");
if ($result && $row = $result->fetch_assoc()) {
  $main_channel = $row['main_channel'];
  $extra_channels = $row['extra_channels'];
  $client_id = $row['client_id'];
  $client_secret = $row['client_secret'];
} else {
  $main_channel = "";
  $extra_channels = "";
  $client_id = "";
  $client_secret = "";
}
?>

<div class="card shadow-sm mt-4">
    <div class="card-header">
        <div class="card-title">
            <i class="bi bi-twitch"></i> <span><?= $languageService->get('title_twitch') ?></span>
            <small class="text-muted"><?= $languageService->get('overview') ?></small>
        </div>
    </div>

    <div class="card-body">
        <form method="POST" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
                <label for="main_channel" class="form-label"><?= $languageService->get('label_mainchannel') ?></label>
                <input type="text" class="form-control" id="main_channel" name="main_channel" value="<?= htmlspecialchars($main_channel) ?>" required>
            </div>

            <div class="mb-3">
                <label for="extra_channels" class="form-label"><?= $languageService->get('label_subchannels') ?></label>
                <textarea class="form-control" id="extra_channels" name="extra_channels" rows="3"><?= htmlspecialchars($extra_channels) ?></textarea>
            </div>

            <div class="mb-3">
                <label for="client_id" class="form-label"><?= $languageService->get('label_client_id') ?></label>
                <input type="text" class="form-control" id="client_id" name="client_id" value="<?= htmlspecialchars($client_id) ?>">
                <div class="form-text"><?= $languageService->get('help_client_id') ?></div>
            </div>

            <div class="mb-3">
                <label for="client_secret" class="form-label"><?= $languageService->get('label_client_secret') ?></label>
                <input type="password" class="form-control" id="client_secret" name="client_secret" value="<?= htmlspecialchars($client_secret) ?>" autocomplete="new-password">
                <div class="form-text"><?= $languageService->get('help_client_secret') ?></div>
            </div>

            <button type="submit" class="btn btn-primary">
                <?= $languageService->get('save') ?>
            </button>
        </form>
    </div>
</div>
