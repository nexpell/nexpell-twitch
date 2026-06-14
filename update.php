<?php

if (!function_exists('safe_query')) {
    die('Access denied');
}

global $modulname, $version, $plugin;

$modulname = 'twitch';
$version = isset($plugin['version']) ? (string)$plugin['version'] : ($version ?? '0.0.0');

$twitchClientIdColumn = safe_query("SHOW COLUMNS FROM plugins_twitch_settings LIKE 'client_id'");
if (!$twitchClientIdColumn || mysqli_num_rows($twitchClientIdColumn) === 0) {
    safe_query("ALTER TABLE plugins_twitch_settings
  ADD COLUMN client_id varchar(255) NOT NULL DEFAULT '' AFTER extra_channels
");
}

$twitchClientSecretColumn = safe_query("SHOW COLUMNS FROM plugins_twitch_settings LIKE 'client_secret'");
if (!$twitchClientSecretColumn || mysqli_num_rows($twitchClientSecretColumn) === 0) {
    safe_query("ALTER TABLE plugins_twitch_settings
  ADD COLUMN client_secret varchar(255) NOT NULL DEFAULT '' AFTER client_id
");
}

require __DIR__ . '/install.php';