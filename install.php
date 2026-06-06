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

safe_query("INSERT IGNORE INTO plugins_twitch_settings (id, main_channel, extra_channels, client_id, client_secret, updated_at) VALUES
(1, 'fl0m', 'zonixxcs,trilluxe', '', '', '2025-07-13 19:03:30')");

safe_query("CREATE TABLE IF NOT EXISTS plugins_twitch_banner_cache (
  channel varchar(100) NOT NULL,
  banner_url text NOT NULL,
  updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

## SYSTEM #####################################################################################################################################

safe_query("
    INSERT IGNORE INTO settings_plugins
        (pluginID, modulname, admin_file, activate, author, website, index_link, hiddenfiles, version, path, status_display, plugin_display, widget_display, delete_display, sidebar)
    VALUES
        ('', 'twitch', 'admin_twitch', 1, 'T-Seven', 'https://www.nexpell.de', 'twitch', '', '0.1', 'includes/plugins/twitch/', 1, 1, 1, 1, 'deactivated');
");

safe_query("
    INSERT IGNORE INTO settings_plugins_lang 
        (content_key, language, content, updated_at)
    VALUES
        ('plugin_name_twitch', 'de', 'Twitch', NOW()),
        ('plugin_name_twitch', 'en', 'Twitch', NOW()),
        ('plugin_name_twitch', 'it', 'Twitch', NOW()),

        ('plugin_info_twitch', 'de', 'Mit diesem Plugin koennt ihr Twitch-Kanaele mit Live-Status, Zuschauerzahlen und Kanalvorschau anzeigen.', NOW()),
        ('plugin_info_twitch', 'en', 'This plugin displays Twitch channels including live status, viewer counts, and channel previews.', NOW()),
        ('plugin_info_twitch', 'it', 'Questo plugin visualizza canali Twitch con stato live, numero di spettatori e anteprime del canale.', NOW())
");

## NAVIGATION #####################################################################################################################################

safe_query("
    INSERT IGNORE INTO navigation_dashboard_links
        (catID, modulname, url, sort)
    VALUES
        (11, 'twitch', 'admincenter.php?site=admin_twitch', 1)
");
$linkID = mysqli_insert_id($_database);

safe_query("
    INSERT IGNORE INTO navigation_dashboard_lang
        (content_key, language, content, updated_at)
    VALUES
        ('nav_link_{$linkID}', 'de', 'Twitch', NOW()),
        ('nav_link_{$linkID}', 'en', 'Twitch', NOW()),
        ('nav_link_{$linkID}', 'it', 'Twitch', NOW())
");

safe_query("
    INSERT IGNORE INTO navigation_website_sub
        (mnavID, modulname, url, sort, indropdown, last_modified)
    VALUES
        (4, 'twitch', 'index.php?site=twitch', 1, 1, NOW())
");

$snavID = mysqli_insert_id($_database);

safe_query("
    INSERT IGNORE INTO navigation_website_lang
        (content_key, language, content, updated_at)
    VALUES
        ('nav_sub_{$snavID}', 'de', 'Twitch', NOW()),
        ('nav_sub_{$snavID}', 'en', 'Twitch', NOW()),
        ('nav_sub_{$snavID}', 'it', 'Twitch', NOW())
");

#######################################################################################################################################
safe_query("
  INSERT IGNORE INTO user_role_admin_navi_rights (id, roleID, type, modulname)
  VALUES ('', 1, 'link', 'twitch')
");
 ?>
