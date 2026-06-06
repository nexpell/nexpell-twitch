<?php

safe_query("CREATE TABLE IF NOT EXISTS plugins_twitch_settings (
  id int(11) NOT NULL AUTO_INCREMENT,
  main_channel varchar(100) NOT NULL,
  extra_channels text NOT NULL,
  client_id varchar(255) NOT NULL DEFAULT '',
  client_secret varchar(255) NOT NULL DEFAULT '',
  updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci AUTO_INCREMENT=2
");

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

safe_query("CREATE TABLE IF NOT EXISTS plugins_twitch_banner_cache (
  channel varchar(100) NOT NULL,
  banner_url text NOT NULL,
  updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

safe_query("INSERT IGNORE INTO plugins_twitch_settings (id, main_channel, extra_channels, client_id, client_secret)
VALUES (1, 'fl0m', 'zonixxcs,trilluxe', '', '')
");

safe_query("
    INSERT INTO settings_plugins_lang (content_key, language, content, updated_at)
    VALUES
        ('plugin_info_twitch', 'de', 'Mit diesem Plugin koennt ihr Twitch-Kanaele mit Live-Status, Zuschauerzahlen und Kanalvorschau anzeigen.', NOW()),
        ('plugin_info_twitch', 'en', 'This plugin displays Twitch channels including live status, viewer counts, and channel previews.', NOW()),
        ('plugin_info_twitch', 'it', 'Questo plugin visualizza canali Twitch con stato live, numero di spettatori e anteprime del canale.', NOW())
    ON DUPLICATE KEY UPDATE
        content = VALUES(content),
        updated_at = NOW()
");
