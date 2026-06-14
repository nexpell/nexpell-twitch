<?php

if (!function_exists('safe_query')) {
    die('Access denied');
}

global $plugin;

PluginInstallerHelper::install([

    'modulname'  => 'twitch',
    'name'       => 'Twitch',
    'version'    => (string)($plugin['version'] ?? '0.0.0'),
    'author'     => 'T-Seven',
    'website'    => 'https://www.nexpell.de',
    'path'       => 'includes/plugins/twitch/',

    'admin_file' => 'admin_twitch',
    'index_link' => 'twitch',
    'sidebar'    => 'deactivated',

    'languages' => [
        'plugin_info_twitch' => [
            'de' => 'Mit diesem Plugin könnt ihr Twitch-Kanäle mit Live-Status, Zuschauerzahlen und Kanalvorschau anzeigen.',
            'en' => 'This plugin displays Twitch channels including live status, viewer counts, and channel previews.',
            'it' => 'Questo plugin visualizza canali Twitch con stato live, numero di spettatori e anteprime del canale.'
        ]
    ],

    'permissions' => [
        'twitch'
    ],

    'admin_navigation' => [
        [
            'url'   => 'admincenter.php?site=admin_twitch',
            'catID' => 11,
            'sort'  => 1,
            'labels' => [
                'de' => 'Twitch',
                'en' => 'Twitch',
                'it' => 'Twitch'
            ]
        ]
    ],

    'website_navigation' => [
        [
            'url'        => 'index.php?site=twitch',
            'mnavID'     => 4,
            'sort'       => 1,
            'indropdown' => 1,
            'labels' => [
                'de' => 'Twitch',
                'en' => 'Twitch',
                'it' => 'Twitch'
            ]
        ]
    ]

]);

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
