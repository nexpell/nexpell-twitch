<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

use nexpell\LanguageService;

global $_database, $languageService, $tpl;

if (isset($languageService) && method_exists($languageService, 'readModule')) {
    $languageService->readModule('twitch');
}

$config = mysqli_fetch_array(safe_query("SELECT selected_style FROM settings_headstyle_config WHERE id=1"));
$class = htmlspecialchars($config['selected_style']);

$data_array = [
    'class'    => $class,
    'title'    => $languageService->get('title'),
    'subtitle' => 'Twitch'
];

echo $tpl->loadTemplate("twitch", "head", $data_array, 'plugin');
echo '<link rel="stylesheet" href="/includes/plugins/twitch/css/twitch.css?v=1.0.5.1">';

$result = $_database->query("SELECT * FROM plugins_twitch_settings WHERE id = 1");
$row = $result ? $result->fetch_assoc() : null;

$mainChannel = trim((string)($row['main_channel'] ?? ''));
$extraChannels = array_values(array_filter(array_map('trim', explode(',', (string)($row['extra_channels'] ?? '')))));

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

if (!function_exists('twitch_ensure_banner_cache_schema')) {
    function twitch_ensure_banner_cache_schema(mysqli $database): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $database->query("
            CREATE TABLE IF NOT EXISTS plugins_twitch_banner_cache (
                channel varchar(100) NOT NULL,
                banner_url text NOT NULL,
                updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (channel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $ensured = true;
    }
}

if (!function_exists('twitch_ensure_state_cache_schema')) {
    function twitch_ensure_state_cache_schema(mysqli $database): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $database->query("
            CREATE TABLE IF NOT EXISTS plugins_twitch_state_cache (
                cache_key varchar(64) NOT NULL,
                payload mediumtext NOT NULL,
                updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (cache_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $ensured = true;
    }
}

if (!function_exists('twitch_normalize_channel_login')) {
    /**
     * Kanal-Eingabe (Login, @name, twitch.tv/…) → gültiger Twitch-Login (kleingeschrieben).
     */
    function twitch_normalize_channel_login(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '@')) {
            $value = ltrim(substr($value, 1));
        }

        if (preg_match('~(?:https?://)?(?:www\.)?twitch\.tv/(?:popout/)?([a-zA-Z0-9_]{4,25})(?:[/?#]|$)~i', $value, $match)) {
            return strtolower($match[1]);
        }

        if (preg_match('~(?:https?://)?(?:www\.)?twitch\.tv/videos/\d+~i', $value)) {
            return '';
        }

        $value = preg_replace('/[^a-zA-Z0-9_]/', '', $value);

        return strlen($value) >= 4 ? strtolower($value) : '';
    }
}

if (!function_exists('twitch_channel_public_url')) {
    function twitch_channel_public_url(string $login): string
    {
        $login = twitch_normalize_channel_login($login);
        if ($login === '') {
            return '';
        }

        return 'https://www.twitch.tv/' . $login;
    }
}

if (!function_exists('twitch_collect_channels')) {
    function twitch_collect_channels(string $mainChannel, array $extraChannels): array
    {
        $channels = [];

        $mainLogin = twitch_normalize_channel_login($mainChannel);
        if ($mainLogin !== '') {
            $channels[] = $mainLogin;
        }

        foreach ($extraChannels as $channel) {
            $login = twitch_normalize_channel_login((string)$channel);
            if ($login !== '' && !in_array($login, $channels, true)) {
                $channels[] = $login;
            }
        }

        return $channels;
    }
}

$channels = twitch_collect_channels($mainChannel, $extraChannels);

if (!function_exists('twitch_channel_initials')) {
    function twitch_channel_initials(string $channel): string
    {
        $clean = preg_replace('/[^a-z0-9]+/i', ' ', $channel);
        $parts = array_values(array_filter(explode(' ', (string)$clean)));
        if (empty($parts)) {
            return strtoupper(substr($channel, 0, 2));
        }

        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : strtoupper(substr($channel, 0, 2));
    }
}

if (!function_exists('twitch_card_variant')) {
    function twitch_card_variant(int $index): string
    {
        $variants = ['violet', 'blue', 'red', 'orange', 'dark', 'indigo'];
        return $variants[$index % count($variants)];
    }
}

if (!function_exists('twitch_is_placeholder_image_url')) {
    function twitch_is_placeholder_image_url(string $url, bool $isLive = false): bool
    {
        $url = strtolower(trim($url));
        if ($url === '') {
            return true;
        }

        if (
            str_contains($url, '404_preview')
            || str_contains($url, 'jtv-static/404')
            || str_contains($url, 'ttv-static/404')
        ) {
            return true;
        }

        return !$isLive && str_contains($url, 'previews-ttv/live_user_');
    }
}

if (!function_exists('twitch_is_invalid_live_cover')) {
    function twitch_is_invalid_live_cover(string $url): bool
    {
        return twitch_is_placeholder_image_url($url, true)
            || twitch_is_profile_banner_url($url);
    }
}

if (!function_exists('twitch_is_profile_banner_url')) {
    function twitch_is_profile_banner_url(string $url): bool
    {
        $url = strtolower(trim($url));
        if ($url === '') {
            return false;
        }

        return str_contains($url, 'jtv_user_pictures')
            && str_contains($url, 'profile_banner');
    }
}

if (!function_exists('twitch_is_invalid_offline_cover')) {
    function twitch_is_invalid_offline_cover(string $url): bool
    {
        $url = strtolower(trim($url));
        if ($url === '') {
            return true;
        }

        if (!str_starts_with($url, 'http')) {
            return true;
        }

        return twitch_is_placeholder_image_url($url);
    }
}

if (!function_exists('twitch_banner_via_ivr')) {
    function twitch_banner_via_ivr(string $channel): string
    {
        static $cache = [];

        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return '';
        }

        if (array_key_exists($channel, $cache)) {
            return $cache[$channel];
        }

        $response = twitch_http_json(
            'https://api.ivr.fi/v2/twitch/user?login=' . rawurlencode($channel),
            ['User-Agent: nexpell-twitch/1.0', 'Accept: application/json']
        );

        $banner = '';
        if (is_array($response) && isset($response[0]) && is_array($response[0])) {
            $banner = trim((string)($response[0]['banner'] ?? ''));
        }

        if ($banner === '' || !twitch_is_profile_banner_url($banner)) {
            $cache[$channel] = '';
            return '';
        }

        $cache[$channel] = $banner;
        return $banner;
    }
}

if (!function_exists('twitch_sanitize_offline_cover_sources')) {
    function twitch_sanitize_offline_cover_sources(array $sources): array
    {
        $clean = [];

        foreach ($sources as $source) {
            $source = trim((string)$source);
            if ($source === '' || twitch_is_invalid_offline_cover($source)) {
                continue;
            }
            $clean[] = $source;
        }

        usort($clean, static function (string $a, string $b): int {
            $a480 = str_contains(strtolower($a), 'profile_banner-480') ? 0 : 1;
            $b480 = str_contains(strtolower($b), 'profile_banner-480') ? 0 : 1;
            if ($a480 !== $b480) {
                return $a480 <=> $b480;
            }

            return strlen($a) <=> strlen($b);
        });

        return array_values(array_unique($clean));
    }
}

if (!function_exists('twitch_banner_via_decapi')) {
    function twitch_banner_via_decapi(string $channel): string
    {
        static $cache = [];

        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return '';
        }

        if (array_key_exists($channel, $cache)) {
            return $cache[$channel];
        }

        $response = twitch_http_text(
            'https://decapi.me/twitch/banner/' . rawurlencode($channel),
            ['User-Agent: nexpell-twitch/1.0']
        );

        if ($response === null) {
            $cache[$channel] = '';
            return '';
        }

        $candidate = trim($response);
        if (
            $candidate === ''
            || !str_starts_with($candidate, 'http')
            || !twitch_is_profile_banner_url($candidate)
        ) {
            $cache[$channel] = '';
            return '';
        }

        $cache[$channel] = $candidate;
        return $candidate;
    }
}

if (!function_exists('twitch_resolve_profile_avatar')) {
    function twitch_resolve_profile_avatar(string $channel, array $state): string
    {
        $avatar = trim((string)($state['profile_image'] ?? ''));
        if ($avatar !== '' && !twitch_is_profile_banner_url($avatar)) {
            return $avatar;
        }

        $decapiAvatar = twitch_avatar_via_decapi($channel);
        if ($decapiAvatar !== '' && !twitch_is_profile_banner_url($decapiAvatar)) {
            return $decapiAvatar;
        }

        return $avatar;
    }
}

if (!function_exists('twitch_channel_page_html')) {
    function twitch_channel_page_html(string $channel): string
    {
        static $htmlCache = [];

        $channel = twitch_normalize_channel_login($channel);
        if ($channel === '') {
            return '';
        }

        if (array_key_exists($channel, $htmlCache)) {
            return $htmlCache[$channel];
        }

        $html = twitch_http_text(
            twitch_channel_public_url($channel),
            [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language: de-DE,de;q=0.9,en-US;q=0.8',
            ]
        );

        $htmlCache[$channel] = is_string($html) ? $html : '';

        return $htmlCache[$channel];
    }
}

if (!function_exists('twitch_offline_screen_image')) {
    function twitch_offline_screen_image(mysqli $database, string $channel): string
    {
        static $memoryCache = [];

        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return '';
        }

        if (array_key_exists($channel, $memoryCache)) {
            return $memoryCache[$channel];
        }

        $cacheKey = 'offline:' . $channel;
        $cached = twitch_cached_profile_banner_image($database, $cacheKey);
        if ($cached !== '' && !twitch_is_invalid_offline_cover($cached)) {
            $memoryCache[$channel] = $cached;
            return $cached;
        }

        $html = twitch_channel_page_html($channel);
        if ($html === '') {
            $memoryCache[$channel] = '';
            return '';
        }

        $normalizedHtml = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $normalizedHtml = str_replace(['\\/', '\u002F'], '/', $normalizedHtml);

        $patterns = [
            '/"offlineImageURL"\s*:\s*"([^"]+)"/i',
            '/"offline_image_url"\s*:\s*"([^"]+)"/i',
            '/"offlineImage"\s*:\s*\{\s*"url"\s*:\s*"([^"]+)"/i',
            '/https:\/\/static-cdn\.jtvnw\.net\/jtv_user_pictures\/[^"\']*-offline-[^"\']*\.(?:png|jpe?g|webp)/i',
            '/https:\/\/static-cdn\.jtvnw\.net\/jtv_user_pictures\/[^"\']*\/[0-9a-f-]+\-offlineimage-[^"\']*\.(?:png|jpe?g|webp)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedHtml, $matches) !== 1) {
                continue;
            }

            $candidate = isset($matches[1]) ? (string)$matches[1] : (string)$matches[0];
            $candidate = html_entity_decode($candidate, ENT_QUOTES, 'UTF-8');
            $candidate = str_replace(['\\/', '\u002F'], '/', $candidate);

            if ($candidate === '' || str_contains(strtolower($candidate), '404_preview')) {
                continue;
            }

            twitch_store_profile_banner_image($database, $cacheKey, $candidate);
            $memoryCache[$channel] = $candidate;
            return $candidate;
        }

        $memoryCache[$channel] = '';
        return '';
    }
}

if (!function_exists('twitch_profile_banner_url_for_user_id')) {
    function twitch_profile_banner_url_for_user_id(string $userId, int $size = 480): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            return '';
        }

        if (!in_array($size, [480, 600, 1200], true)) {
            $size = 480;
        }

        return 'https://static-cdn.jtvnw.net/jtv_user_pictures/'
            . $userId
            . '-profile_banner-'
            . $size
            . '.png';
    }
}

if (!function_exists('twitch_profile_banner_urls_from_user_id')) {
    function twitch_profile_banner_urls_from_user_id(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        $urls = [twitch_profile_banner_url_for_user_id($userId, 480)];
        foreach ([480, 600, 1200] as $size) {
            foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
                $urls[] = 'https://static-cdn.jtvnw.net/jtv_user_pictures/'
                    . $userId
                    . '-profile_banner-'
                    . $size
                    . '.'
                    . $extension;
            }
        }

        return $urls;
    }
}

if (!function_exists('twitch_extract_profile_banner_from_html')) {
    function twitch_extract_profile_banner_from_html(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $normalized = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $normalized = str_replace(['\\/', '\u002F', '\u002f'], '/', $normalized);

        $patterns = [
            '/"(?:profileBannerURL|profile_banner_url)"\s*:\s*"([^"]+)"/i',
            '/(https:\/\/static-cdn\.jtvnw\.net\/jtv_user_pictures\/[0-9a-f-]+-profile_banner-(?:480|600|1200)\.(?:png|jpe?g|webp))/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) !== 1) {
                continue;
            }

            $candidate = isset($matches[1]) ? (string)$matches[1] : (string)$matches[0];
            $candidate = stripslashes(html_entity_decode($candidate, ENT_QUOTES, 'UTF-8'));
            if ($candidate !== '' && twitch_is_profile_banner_url($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('twitch_user_id_from_asset_url')) {
    function twitch_user_id_from_asset_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#jtv_user_pictures/([0-9a-f-]{36})-(?:profile_image|profile_banner|offline)#i', $url, $matches) === 1) {
            return (string)$matches[1];
        }

        return '';
    }
}

if (!function_exists('twitch_resolve_user_id')) {
    function twitch_resolve_user_id(string $channel, array $state): string
    {
        $userId = trim((string)($state['user_id'] ?? ''));
        if ($userId !== '') {
            return $userId;
        }

        $userId = twitch_user_id_from_asset_url((string)($state['profile_image'] ?? ''));
        if ($userId !== '') {
            return $userId;
        }

        $userId = twitch_user_id_from_asset_url((string)($state['offline_image'] ?? ''));
        if ($userId !== '') {
            return $userId;
        }

        $profileImage = twitch_resolve_profile_avatar($channel, $state);
        $userId = twitch_user_id_from_asset_url($profileImage);
        if ($userId !== '') {
            return $userId;
        }

        return twitch_user_id_from_channel_page($channel);
    }
}

if (!function_exists('twitch_live_preview_url')) {
    function twitch_live_preview_url(string $channel): string
    {
        $slug = strtolower(trim($channel));
        if ($slug === '') {
            return '';
        }

        return 'https://static-cdn.jtvnw.net/previews-ttv/live_user_'
            . rawurlencode($slug)
            . '-640x360.jpg';
    }
}

if (!function_exists('twitch_resolve_live_thumbnail_sources')) {
    function twitch_resolve_live_thumbnail_sources(string $channel, array $state): array
    {
        $template = trim((string)($state['cover_image'] ?? ''));
        $sources = [];

        if ($template !== '' && str_contains($template, '{width}')) {
            foreach ([['1280', '720'], ['640', '360'], ['440', '248']] as $size) {
                $url = str_replace(['{width}', '{height}'], $size, $template);
                if ($url !== '' && !twitch_is_placeholder_image_url($url)) {
                    $sources[] = $url;
                }
            }
        } elseif ($template !== '' && !twitch_is_placeholder_image_url($template)) {
            $sources[] = $template;
        }

        if ($sources === []) {
            $preview = twitch_live_preview_url($channel);
            if ($preview !== '') {
                $sources[] = $preview;
            }
        }

        return array_values(array_unique($sources));
    }
}

if (!function_exists('twitch_resolve_offline_banner')) {
    function twitch_resolve_offline_banner(string $channel, array $state, mysqli $database): string
    {
        $offlineImage = trim((string)($state['offline_image'] ?? ''));
        if ($offlineImage !== '' && !twitch_is_invalid_offline_cover($offlineImage)) {
            return $offlineImage;
        }

        $sources = [];

        $cachedBanner = twitch_cached_profile_banner_image($database, $channel);
        if ($cachedBanner !== '') {
            $sources[] = $cachedBanner;
        }

        if (!empty($sources)) {
            return $sources[0];
        }

        if (!empty($state)) {
            return '';
        }

        $ivrBanner = twitch_banner_via_ivr($channel);
        if ($ivrBanner !== '') {
            twitch_store_profile_banner_image($database, $channel, $ivrBanner);
            return $ivrBanner;
        }

        $pageBanner = twitch_extract_profile_banner_from_html(twitch_channel_page_html($channel));
        if ($pageBanner !== '') {
            $sources[] = $pageBanner;
        }

        $scrapedBanner = twitch_profile_banner_image($database, $channel);
        if ($scrapedBanner !== '') {
            $sources[] = $scrapedBanner;
        }

        $decapiBanner = twitch_banner_via_decapi($channel);
        if ($decapiBanner !== '') {
            $sources[] = $decapiBanner;
        }

        $sources = twitch_sanitize_offline_cover_sources($sources);
        if (!empty($sources)) {
            twitch_store_profile_banner_image($database, $channel, $sources[0]);
            return $sources[0];
        }

        return '';
    }
}

if (!function_exists('twitch_user_id_from_channel_page')) {
    function twitch_user_id_from_channel_page(string $channel): string
    {
        $html = twitch_channel_page_html($channel);
        if ($html === '') {
            return '';
        }

        if (preg_match('/jtv_user_pictures\/([0-9a-f-]+)-profile_banner/i', $html, $matches) === 1) {
            return (string)$matches[1];
        }

        if (preg_match('/jtv_user_pictures\/([0-9a-f-]+)-profile_image/i', $html, $matches) === 1) {
            return (string)$matches[1];
        }

        if (preg_match('/"broadcasterID":"([0-9a-f-]+)"/i', $html, $matches) === 1) {
            return (string)$matches[1];
        }

        if (preg_match('/"id":"([0-9a-f-]{36})"/i', $html, $matches) === 1) {
            return (string)$matches[1];
        }

        return '';
    }
}

if (!function_exists('twitch_remote_image_available')) {
    function twitch_remote_image_available(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || twitch_is_invalid_offline_cover($url)) {
            return false;
        }

        if (!function_exists('curl_init')) {
            return true;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; nexpell-twitch/1.0)');
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status >= 200 && $status < 400;
    }
}

if (!function_exists('twitch_first_working_cover_url')) {
    function twitch_first_working_cover_url(array $urls): string
    {
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '' || !twitch_is_profile_banner_url($url)) {
                continue;
            }
            if (twitch_remote_image_available($url)) {
                return $url;
            }
        }

        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '' && twitch_is_profile_banner_url($url)) {
                return $url;
            }
        }

        return '';
    }
}

if (!function_exists('twitch_avatar_via_decapi')) {
    function twitch_avatar_via_decapi(string $channel): string
    {
        static $cache = [];

        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return '';
        }

        if (array_key_exists($channel, $cache)) {
            return $cache[$channel];
        }

        $response = twitch_http_text(
            'https://decapi.me/twitch/avatar/' . rawurlencode($channel),
            ['User-Agent: nexpell-twitch/1.0']
        );

        if ($response === null) {
            $cache[$channel] = '';
            return '';
        }

        $candidate = trim($response);
        if (
            $candidate === ''
            || !str_starts_with($candidate, 'http')
            || twitch_is_profile_banner_url($candidate)
        ) {
            $cache[$channel] = '';
            return '';
        }

        $cache[$channel] = $candidate;
        return $candidate;
    }
}

if (!function_exists('twitch_card_cover_sources')) {
    function twitch_card_cover_sources(string $channel, bool $isLive, array $state, mysqli $database): array
    {
        if ($isLive) {
            return twitch_resolve_live_thumbnail_sources($channel, $state);
        }

        $sources = [];

        $offlineImage = trim((string)($state['offline_image'] ?? ''));
        if ($offlineImage !== '' && !twitch_is_invalid_offline_cover($offlineImage)) {
            $sources[] = $offlineImage;
        }

        $cachedBanner = twitch_cached_profile_banner_image($database, $channel);
        if ($cachedBanner !== '') {
            $sources[] = $cachedBanner;
        }

        if (!empty($sources) || !empty($state)) {
            return twitch_sanitize_offline_cover_sources($sources);
        }

        $ivrBanner = twitch_banner_via_ivr($channel);
        if ($ivrBanner !== '') {
            $sources[] = $ivrBanner;
        }

        $pageBanner = twitch_extract_profile_banner_from_html(twitch_channel_page_html($channel));
        if ($pageBanner !== '') {
            $sources[] = $pageBanner;
        }

        $scrapedBanner = twitch_profile_banner_image($database, $channel);
        if ($scrapedBanner !== '') {
            $sources[] = $scrapedBanner;
        }

        $decapiBanner = twitch_banner_via_decapi($channel);
        if ($decapiBanner !== '') {
            $sources[] = $decapiBanner;
        }

        $sources = twitch_sanitize_offline_cover_sources($sources);

        if (!empty($sources)) {
            twitch_store_profile_banner_image($database, $channel, $sources[0]);
        }

        return $sources;
    }
}

if (!function_exists('twitch_viewer_count_via_decapi')) {
    function twitch_viewer_count_via_decapi(string $channel): int
    {
        static $cache = [];

        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return 0;
        }

        if (array_key_exists($channel, $cache)) {
            return $cache[$channel];
        }

        $response = twitch_http_text(
            'https://decapi.me/twitch/viewercount/' . rawurlencode($channel),
            ['User-Agent: nexpell-twitch/1.0']
        );

        if ($response === null) {
            $cache[$channel] = 0;
            return 0;
        }

        $text = trim($response);
        if ($text === '' || stripos($text, 'offline') !== false) {
            $cache[$channel] = 0;
            return 0;
        }

        if (preg_match('/(\d[\d,.]*)/', $text, $matches) === 1) {
            $digits = preg_replace('/[^\d]/', '', $matches[1]);
            $cache[$channel] = max(0, (int)$digits);
            return $cache[$channel];
        }

        $cache[$channel] = max(0, (int)$text);
        return $cache[$channel];
    }
}

if (!function_exists('twitch_resolve_viewer_count')) {
    function twitch_resolve_viewer_count(string $channel, bool $isLive, array $state): int
    {
        if (!$isLive) {
            return 0;
        }

        $count = (int)($state['viewer_count'] ?? 0);
        if ($count > 0) {
            return $count;
        }

        return twitch_viewer_count_via_decapi($channel);
    }
}

if (!function_exists('twitch_check_live_fallback')) {
    function twitch_check_live_fallback(string $channel): ?bool
    {
        static $cache = [];

        $key = strtolower(trim($channel));
        if ($key === '') {
            return null;
        }

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $response = twitch_http_text(
            'https://decapi.me/twitch/uptime/' . rawurlencode($channel),
            ['User-Agent: nexpell-twitch/1.0']
        );

        if ($response === null) {
            $cache[$key] = null;
            return null;
        }

        $normalized = strtolower(trim($response));
        if (
            $normalized === ''
            || str_contains($normalized, 'invalid')
            || str_contains($normalized, 'does not exist')
            || str_contains($normalized, 'error')
        ) {
            $cache[$key] = null;
            return null;
        }

        if (str_contains($normalized, 'offline') || str_contains($normalized, 'not live')) {
            $cache[$key] = false;
            return false;
        }

        $cache[$key] = true;
        return true;
    }
}

if (!function_exists('twitch_http_json')) {
    function twitch_http_json(string $url, array $headers = [], string $method = 'GET', ?string $body = null): ?array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
            }

            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($response === false || $status >= 400) {
                return null;
            }

            $decoded = json_decode($response, true);
            return is_array($decoded) ? $decoded : null;
        }

        $contextOptions = [
            'method' => $method,
            'timeout' => 5,
            'header' => implode("\r\n", $headers),
        ];

        if ($method === 'POST' && $body !== null) {
            $contextOptions['content'] = $body;
        }

        $context = stream_context_create(['http' => $contextOptions]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('twitch_http_text')) {
    function twitch_http_text(string $url, array $headers = []): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($response === false || $status >= 400) {
                return null;
            }

            return is_string($response) ? $response : null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'header' => implode("\r\n", $headers),
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        return $response !== false ? (string)$response : null;
    }
}

if (!function_exists('twitch_cached_profile_banner_image')) {
    function twitch_cached_profile_banner_image(mysqli $database, string $channel): string
    {
        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return '';
        }

        $channelEsc = $database->real_escape_string($channel);
        $result = $database->query("
            SELECT banner_url
            FROM plugins_twitch_banner_cache
            WHERE channel = '{$channelEsc}'
            LIMIT 1
        ");

        if (!$result instanceof mysqli_result) {
            return '';
        }

        $row = $result->fetch_assoc();
        $url = trim((string)($row['banner_url'] ?? ''));
        if ($url !== '' && !twitch_is_profile_banner_url($url)) {
            return '';
        }

        return $url;
    }
}

if (!function_exists('twitch_store_profile_banner_image')) {
    function twitch_store_profile_banner_image(mysqli $database, string $channel, string $bannerUrl): void
    {
        $channel = strtolower(trim($channel));
        $bannerUrl = trim($bannerUrl);
        if ($channel === '' || $bannerUrl === '' || !twitch_is_profile_banner_url($bannerUrl)) {
            return;
        }

        $channelEsc = $database->real_escape_string($channel);
        $bannerEsc = $database->real_escape_string($bannerUrl);

        $database->query("
            INSERT INTO plugins_twitch_banner_cache (channel, banner_url)
            VALUES ('{$channelEsc}', '{$bannerEsc}')
            ON DUPLICATE KEY UPDATE banner_url = VALUES(banner_url), updated_at = CURRENT_TIMESTAMP
        ");
    }
}

if (!function_exists('twitch_profile_banner_image')) {
    function twitch_profile_banner_image(mysqli $database, string $channel): string
    {
        static $bannerCache = [];

        $channel = strtolower(trim($channel));
        if ($channel === '') {
            return '';
        }

        if (array_key_exists($channel, $bannerCache)) {
            return $bannerCache[$channel];
        }

        $cachedBanner = twitch_cached_profile_banner_image($database, $channel);
        if ($cachedBanner !== '' && !twitch_is_invalid_offline_cover($cachedBanner)) {
            $bannerCache[$channel] = $cachedBanner;
            return $cachedBanner;
        }

        $html = twitch_channel_page_html($channel);
        if ($html === '') {
            $bannerCache[$channel] = '';
            return '';
        }

        $directBanner = twitch_extract_profile_banner_from_html($html);
        if ($directBanner !== '') {
            twitch_store_profile_banner_image($database, $channel, $directBanner);
            $bannerCache[$channel] = $directBanner;
            return $directBanner;
        }

        $normalizedHtml = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $normalizedHtml = str_replace(['\\/', '\u002F'], '/', $normalizedHtml);

        $patterns = [
            '/https:\/\/static-cdn\.jtvnw\.net\/jtv_user_pictures\/[0-9a-f-]+-profile_banner-(?:1200|600|480)\.(?:png|jpe?g|webp)/i',
            '/https:\/\/static-cdn\.jtvnw\.net\/jtv_user_pictures\/[^"\']*profile_banner[^"\']*\.(?:png|jpe?g|webp)/i',
            '/"profileBannerImageURL"\s*:\s*"([^"]+)"/i',
            '/"bannerImageURL"\s*:\s*"([^"]+)"/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedHtml, $matches) !== 1) {
                continue;
            }

            $candidate = isset($matches[1]) ? (string)$matches[1] : (string)$matches[0];
            $candidate = html_entity_decode($candidate, ENT_QUOTES, 'UTF-8');
            $candidate = str_replace(['\\/', '\u002F'], '/', $candidate);

            if (
                stripos($candidate, 'profile_banner') === false
                && stripos($candidate, 'jtv_user_pictures') === false
            ) {
                continue;
            }

            if (twitch_is_invalid_offline_cover($candidate)) {
                continue;
            }

            twitch_store_profile_banner_image($database, $channel, $candidate);
            $bannerCache[$channel] = $candidate;
            return $candidate;
        }

        $bannerCache[$channel] = '';
        return '';
    }
}

if (!function_exists('twitch_api_access_token')) {
    function twitch_api_access_token(string $clientId, string $clientSecret): ?string
    {
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $cachedToken = (string)($_SESSION['twitch_api_access_token'] ?? '');
        $cachedExpiry = (int)($_SESSION['twitch_api_access_token_expires'] ?? 0);

        if ($cachedToken !== '' && $cachedExpiry > (time() + 60)) {
            return $cachedToken;
        }

        $tokenBody = 'client_id=' . rawurlencode($clientId)
            . '&client_secret=' . rawurlencode($clientSecret)
            . '&grant_type=client_credentials';

        $data = twitch_http_json(
            'https://id.twitch.tv/oauth2/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            'POST',
            $tokenBody
        );
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        $_SESSION['twitch_api_access_token'] = (string)$data['access_token'];
        $_SESSION['twitch_api_access_token_expires'] = time() + max(60, ((int)($data['expires_in'] ?? 0)) - 60);

        return (string)$data['access_token'];
    }
}

if (!function_exists('twitch_fetch_channel_state')) {
    function twitch_fetch_channel_state(array $channels, string $clientId, string $clientSecret): array
    {
        $token = twitch_api_access_token($clientId, $clientSecret);
        if ($token === null || empty($channels)) {
            return [];
        }

        $queryUsers = [];
        foreach ($channels as $channel) {
            $queryUsers[] = 'login=' . rawurlencode($channel);
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Client-Id: ' . $clientId,
        ];

        $usersResponse = twitch_http_json('https://api.twitch.tv/helix/users?' . implode('&', $queryUsers), $headers);

        $state = [];
        foreach ($channels as $channel) {
            $key = twitch_normalize_channel_login($channel);
            if ($key === '') {
                continue;
            }

            $state[$key] = [
                'login' => $key,
                'display_name' => $channel,
                'user_id' => '',
                'is_live' => false,
                'viewer_count' => 0,
                'cover_image' => '',
                'offline_image' => '',
                'profile_image' => '',
            ];
        }

        foreach ((array)($usersResponse['data'] ?? []) as $user) {
            $key = strtolower((string)($user['login'] ?? ''));
            if ($key === '' || !isset($state[$key])) {
                continue;
            }

            $state[$key]['login'] = $key;
            $state[$key]['user_id'] = (string)($user['id'] ?? '');
            $state[$key]['display_name'] = (string)($user['display_name'] ?? $state[$key]['display_name']);
            $state[$key]['profile_image'] = (string)($user['profile_image_url'] ?? '');
            $state[$key]['offline_image'] = (string)($user['offline_image_url'] ?? '');
        }

        $streamsResponse = ['data' => []];
        $queryStreams = [];
        foreach ($channels as $channel) {
            $queryStreams[] = 'user_login=' . rawurlencode($channel);
        }
        if (!empty($queryStreams)) {
            $streamsResponse = twitch_http_json(
                'https://api.twitch.tv/helix/streams?' . implode('&', $queryStreams),
                $headers
            ) ?? ['data' => []];
        }

        foreach ((array)($streamsResponse['data'] ?? []) as $stream) {
            $key = strtolower((string)($stream['user_login'] ?? ''));
            if ($key === '' || !isset($state[$key])) {
                continue;
            }

            $state[$key]['is_live'] = true;
            $state[$key]['viewer_count'] = (int)($stream['viewer_count'] ?? 0);
            $thumbnail = trim((string)($stream['thumbnail_url'] ?? ''));
            if ($thumbnail !== '') {
                $state[$key]['cover_image'] = $thumbnail;
            }
        }

        return $state;
    }
}

if (!function_exists('twitch_channel_state_cache_key')) {
    function twitch_channel_state_cache_key(array $channels, string $clientId): string
    {
        $normalized = [];
        foreach ($channels as $channel) {
            $login = twitch_normalize_channel_login((string)$channel);
            if ($login !== '') {
                $normalized[] = $login;
            }
        }

        sort($normalized);

        return sha1($clientId . '|' . implode(',', $normalized));
    }
}

if (!function_exists('twitch_read_channel_state_cache')) {
    function twitch_read_channel_state_cache(mysqli $database, string $cacheKey, int $maxAgeSeconds): array
    {
        $cacheKey = trim($cacheKey);
        if ($cacheKey === '') {
            return [];
        }

        $cacheKeyEsc = $database->real_escape_string($cacheKey);
        $maxAgeSeconds = max(1, $maxAgeSeconds);
        $result = $database->query("
            SELECT payload
            FROM plugins_twitch_state_cache
            WHERE cache_key = '{$cacheKeyEsc}'
              AND updated_at >= (NOW() - INTERVAL {$maxAgeSeconds} SECOND)
            LIMIT 1
        ");

        if (!$result instanceof mysqli_result) {
            return [];
        }

        $row = $result->fetch_assoc();
        $payload = (string)($row['payload'] ?? '');
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('twitch_store_channel_state_cache')) {
    function twitch_store_channel_state_cache(mysqli $database, string $cacheKey, array $state): void
    {
        $cacheKey = trim($cacheKey);
        if ($cacheKey === '' || empty($state)) {
            return;
        }

        $payload = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload) || $payload === '') {
            return;
        }

        $cacheKeyEsc = $database->real_escape_string($cacheKey);
        $payloadEsc = $database->real_escape_string($payload);
        $database->query("
            INSERT INTO plugins_twitch_state_cache (cache_key, payload)
            VALUES ('{$cacheKeyEsc}', '{$payloadEsc}')
            ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = CURRENT_TIMESTAMP
        ");
    }
}

if (!function_exists('twitch_fetch_channel_state_cached')) {
    function twitch_fetch_channel_state_cached(mysqli $database, array $channels, string $clientId, string $clientSecret): array
    {
        $cacheKey = twitch_channel_state_cache_key($channels, $clientId);
        $freshState = twitch_read_channel_state_cache($database, $cacheKey, 60);
        if (!empty($freshState)) {
            return $freshState;
        }

        $state = twitch_fetch_channel_state($channels, $clientId, $clientSecret);
        if (!empty($state)) {
            twitch_store_channel_state_cache($database, $cacheKey, $state);
            return $state;
        }

        return twitch_read_channel_state_cache($database, $cacheKey, 600);
    }
}

if (!function_exists('twitch_has_status_data')) {
    function twitch_has_status_data(array $channels, array $channelState, string $clientId, string $clientSecret): bool
    {
        return $clientId !== ''
            && $clientSecret !== ''
            && !empty($channels)
            && !empty($channelState);
    }
}

twitch_ensure_settings_schema($_database);
twitch_ensure_banner_cache_schema($_database);
twitch_ensure_state_cache_schema($_database);

$clientId = trim((string)($row['client_id'] ?? ''));
$clientSecret = trim((string)($row['client_secret'] ?? ''));
$channelState = twitch_fetch_channel_state_cached($_database, $channels, $clientId, $clientSecret);
$hasHelixData = twitch_has_status_data($channels, $channelState, $clientId, $clientSecret);
$hasStatusData = $hasHelixData;

$channelCards = [];
foreach ($channels as $index => $channel) {
    $variant = twitch_card_variant((int)$index);
    $channelLogin = twitch_normalize_channel_login($channel);
    if ($channelLogin === '') {
        continue;
    }

    $initials = twitch_channel_initials($channelLogin);
    $state = $channelState[$channelLogin] ?? [];
    $channelLogin = (string)($state['login'] ?? $channelLogin);
    $channelUrl = twitch_channel_public_url($channelLogin);
    $displayName = (string)($state['display_name'] ?? $channelLogin);
    $profileImage = twitch_resolve_profile_avatar($channelLogin, $state);
    $isLive = $hasHelixData && !empty($state['is_live']);

    if (!$hasHelixData) {
        $fallbackLive = twitch_check_live_fallback($channelLogin);
        if ($fallbackLive !== null) {
            $isLive = $fallbackLive;
            $hasStatusData = true;
        }
    }

    $viewerLabel = twitch_resolve_viewer_count($channelLogin, $isLive, $state);

    if ($isLive) {
        $coverSources = twitch_resolve_live_thumbnail_sources($channelLogin, $state);
        $resolvedCover = (string)($coverSources[0] ?? '');
        $coverFallbacks = array_values(array_filter(
            array_slice($coverSources, 1),
            static fn(string $url): bool => $url !== '' && !twitch_is_invalid_live_cover($url)
        ));
    } else {
        $coverSources = twitch_card_cover_sources($channelLogin, false, $state, $_database);
        $resolvedCover = twitch_resolve_offline_banner($channelLogin, $state, $_database);
        if ($resolvedCover !== '' && !twitch_is_profile_banner_url($resolvedCover)) {
            $resolvedCover = '';
        }
        $coverFallbacks = twitch_sanitize_offline_cover_sources($coverSources);
    }

    $channelCards[] = [
        'channel' => $channelLogin,
        'index' => (int)$index,
        'variant' => $variant,
        'initials' => $initials,
        'channel_url' => $channelUrl,
        'display_name' => $displayName,
        'cover_image' => $resolvedCover,
        'cover_fallbacks' => array_values(array_unique($coverFallbacks)),
        'profile_image' => $profileImage,
        'is_live' => $isLive,
        'viewer_count' => $viewerLabel,
    ];
}

$streamerCount = count($channelCards);

$liveCount = 0;
$viewerCount = 0;
foreach ($channelCards as $card) {
    if (!empty($card['is_live'])) {
        $liveCount++;
        $viewerCount += (int)($card['viewer_count'] ?? 0);
    }
}
$offlineCount = max(0, $streamerCount - $liveCount);

if ($hasStatusData && count($channelCards) > 1) {
    usort($channelCards, static function (array $a, array $b): int {
        if ($a['is_live'] !== $b['is_live']) {
            return $a['is_live'] ? -1 : 1;
        }
        if ($a['is_live'] && $b['is_live']) {
            return $b['viewer_count'] <=> $a['viewer_count'];
        }
        return $a['index'] <=> $b['index'];
    });
}

$labelWatch = (string)$languageService->get('watch_channel');
$labelOpenTwitch = (string)$languageService->get('open_on_twitch');
$labelFilterAll = (string)($languageService->get('filter_all') ?? 'Alle');
$labelFilterLive = (string)($languageService->get('filter_live') ?? 'Live');
$labelFilterOffline = (string)($languageService->get('filter_offline') ?? 'Offline');
?>

<div class="twitch-plugin">
    <div class="card shadow-sm border-0 overflow-hidden mb-4">
        <div class="card-header twitch-hero border-0 py-4 text-center text-white">
            <div class="d-inline-flex align-items-center justify-content-center gap-3 mb-2">
                <i class="bi bi-twitch fs-1" aria-hidden="true"></i>
                <h2 class="h2 mb-0 fw-bold"><?= htmlspecialchars($languageService->get('hero_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <div class="d-flex flex-wrap justify-content-center gap-4 small">
                <span><strong class="fs-6"><?= $streamerCount ?></strong> <?= htmlspecialchars($languageService->get('stat_streamers'), ENT_QUOTES, 'UTF-8') ?></span>
                <span><strong class="fs-6 text-danger"><?= $hasStatusData ? $liveCount : 0 ?></strong> <?= htmlspecialchars($languageService->get('stat_online'), ENT_QUOTES, 'UTF-8') ?></span>
                <span><strong class="fs-6"><?= $hasStatusData ? $offlineCount : $streamerCount ?></strong> <?= htmlspecialchars($languageService->get('stat_offline'), ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($hasStatusData && $viewerCount > 0): ?>
                <span><strong class="fs-6"><?= number_format($viewerCount, 0, ',', '.') ?></strong> <?= htmlspecialchars($languageService->get('stat_viewers'), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-body">
        <?php if (!empty($channels)): ?>
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 border-bottom pb-3 mb-4">
                <div class="flex-grow-1" style="min-width: 220px;">
                    <h3 class="h5 mb-1 d-flex align-items-center gap-2">
                        <i class="bi bi-people-fill text-primary" aria-hidden="true"></i>
                        <?= htmlspecialchars($languageService->get('channel_section'), ENT_QUOTES, 'UTF-8') ?>
                        <span class="text-muted fw-normal">(<?= $streamerCount ?>)</span>
                    </h3>
                    <p class="text-muted small mb-0"><?= htmlspecialchars($languageService->get('hero_text'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <?php if ($hasStatusData): ?>
                <div class="btn-group flex-wrap" role="group" aria-label="<?= htmlspecialchars($languageService->get('channel_section'), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="btn btn-sm btn-outline-primary active" data-twitch-filter="all"><?= htmlspecialchars($labelFilterAll, ENT_QUOTES, 'UTF-8') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-twitch-filter="live"><?= htmlspecialchars($labelFilterLive, ENT_QUOTES, 'UTF-8') ?> (<?= $liveCount ?>)</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-twitch-filter="offline"><?= htmlspecialchars($labelFilterOffline, ENT_QUOTES, 'UTF-8') ?> (<?= $offlineCount ?>)</button>
                </div>
                <?php endif; ?>
            </div>

            <div class="row g-4" id="twitch-streamer-grid">
                    <?php foreach ($channelCards as $card): ?>
                        <?php
                        $hasCoverImage = $card['cover_image'] !== ''
                            && (
                            $card['is_live']
                                    ? !twitch_is_invalid_live_cover($card['cover_image'])
                                    : !twitch_is_placeholder_image_url($card['cover_image'])
                            );
                        $coverCacheBust = $card['is_live'] ? time() : (int)(time() / 300);
                        $coverSrc = $hasCoverImage
                            ? $card['cover_image'] . (strpos($card['cover_image'], '?') !== false ? '&' : '?') . 't=' . $coverCacheBust
                            : '';
                        $cardBtnClass = 'card w-100 h-100 text-start p-0 border shadow-sm twitch-channel-card variant-' . $card['variant'];
                        if ($card['is_live']) {
                            $cardBtnClass .= ' border-danger border-opacity-25';
                        }
                        ?>
                <div class="col-12 col-md-6 col-lg-4" data-twitch-item data-live="<?= $card['is_live'] ? '1' : '0' ?>">
                        <div
                            class="<?= $cardBtnClass ?>"
                            role="button"
                            tabindex="0"
                            data-channel="<?= htmlspecialchars($card['channel'], ENT_QUOTES, 'UTF-8') ?>"
                            data-url="<?= htmlspecialchars($card['channel_url'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <div class="twitch-cover position-relative<?= $card['is_live'] ? ' is-live' : ' is-offline' ?><?= $hasCoverImage ? ' has-cover-image' : '' ?><?= !$card['is_live'] && $hasCoverImage && twitch_is_profile_banner_url($card['cover_image']) ? ' uses-profile-banner' : '' ?>">
                                <?php if ($hasCoverImage): ?>
                                <img
                                    class="twitch-cover-img w-100"
                                    src="<?= htmlspecialchars($coverSrc, ENT_QUOTES, 'UTF-8') ?>"
                                    data-cover-mode="<?= $card['is_live'] ? 'live' : 'offline' ?>"
                                    <?php if (!empty($card['cover_fallbacks'])): ?>data-fallbacks="<?= htmlspecialchars(json_encode($card['cover_fallbacks'], JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>
                                    alt="<?= htmlspecialchars($card['display_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    loading="lazy"
                                    decoding="async"
                                    referrerpolicy="origin"
                                >
                                <?php else: ?>
                                <div class="twitch-cover-art"><?= htmlspecialchars($card['initials'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <div class="twitch-cover-overlay"></div>
                                <?php if ($hasStatusData && $card['is_live']): ?>
                                    <span class="badge bg-danger position-absolute top-0 start-0 m-2 d-inline-flex align-items-center gap-1">
                                        <span class="twitch-live-dot" aria-hidden="true"></span>
                                        <?= htmlspecialchars($languageService->get('status_live'), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php elseif ($hasStatusData): ?>
                                    <span class="badge bg-secondary position-absolute top-0 start-0 m-2"><?= htmlspecialchars($languageService->get('status_offline'), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                                <div class="twitch-cover-action">
                                    <i class="bi bi-play-circle-fill" aria-hidden="true"></i>
                                    <span><?= htmlspecialchars($labelWatch, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                            <div class="card-body position-relative twitch-card-body">
                                <div class="twitch-avatar rounded-circle">
                                    <?php if ($card['profile_image'] !== ''): ?>
                                        <img src="<?= htmlspecialchars($card['profile_image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($card['display_name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php else: ?>
                                        <?= htmlspecialchars($card['initials'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                                <h4 class="h6 mb-0 fw-bold mt-3"><?= htmlspecialchars($card['display_name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                <p class="small text-muted mb-2">@<?= htmlspecialchars($card['channel'], ENT_QUOTES, 'UTF-8') ?></p>
                                <?php if ($hasStatusData): ?>
                                    <div class="small d-flex flex-wrap align-items-center gap-2<?= $card['is_live'] ? ' text-danger' : ' text-muted' ?>">
                                        <?php if ($card['is_live']): ?>
                                            <?php if ($card['viewer_count'] > 0): ?>
                                            <span class="badge text-bg-danger"><i class="bi bi-eye-fill" aria-hidden="true"></i> <?= number_format($card['viewer_count'], 0, ',', '.') ?></span>
                                            <span><?= htmlspecialchars($languageService->get('status_viewers'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php else: ?>
                                            <i class="bi bi-broadcast" aria-hidden="true"></i>
                                            <?= htmlspecialchars($languageService->get('status_live'), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <i class="bi bi-moon-stars-fill" aria-hidden="true"></i>
                                            <?= htmlspecialchars($languageService->get('status_offline'), ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($card['channel_url'] !== ''): ?>
                                    <a
                                        href="<?= htmlspecialchars($card['channel_url'], ENT_QUOTES, 'UTF-8') ?>"
                                        class="small fw-semibold text-primary d-inline-flex align-items-center gap-1 twitch-external-link"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <?= htmlspecialchars($labelOpenTwitch, ENT_QUOTES, 'UTF-8') ?>
                                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                    </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="small text-muted">
                                        <i class="bi bi-twitch" aria-hidden="true"></i>
                                        <?= htmlspecialchars($languageService->get('channel_label'), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                </div>
                    <?php endforeach; ?>
            </div>
        <?php else: ?>
                <div class="alert alert-info mb-0" role="alert">
                    <?= htmlspecialchars($languageService->get('no_channels_available'), ENT_QUOTES, 'UTF-8') ?>
                </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<div id="fallback-twitch" class="alert alert-info text-center mt-3"<?= (isset($_COOKIE['nexpell_consent_twitch']) && $_COOKIE['nexpell_consent_twitch'] === 'declined') ? '' : ' style="display:none;"' ?>>
    <?= htmlspecialchars((string)$languageService->get('info_cookie'), ENT_QUOTES, 'UTF-8') ?>
</div>

<div class="modal fade" id="twitch-stream-modal" tabindex="-1" aria-labelledby="twitch-stream-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content bg-dark text-white border-0 overflow-hidden">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="twitch-stream-modal-title"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars($languageService->get('close_modal'), ENT_QUOTES, 'UTF-8') ?>"></button>
            </div>
            <div class="modal-body p-0">
                <div id="twitch-stream-modal-player" class="twitch-modal-player"></div>
            </div>
            <div class="modal-footer border-0 justify-content-between flex-wrap gap-2">
                <p class="small text-white-50 mb-0"><?= htmlspecialchars($languageService->get('modal_hint'), ENT_QUOTES, 'UTF-8') ?></p>
                <a id="twitch-stream-modal-link" class="btn btn-outline-light btn-sm" href="#" target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-box-arrow-up-right"></i>
                    <?= htmlspecialchars($languageService->get('open_channel'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
  const TWITCH_MODAL_CONFIG = {
    parent: <?= json_encode($_SERVER['HTTP_HOST'] ?? 'localhost') ?>
  };

  (function () {
    let modalPlayer = null;
    let bsModal = null;
    let playerScriptPromise = null;

    function getCookie(name) {
      const cookies = document.cookie ? document.cookie.split('; ') : [];
      for (const cookie of cookies) {
        const parts = cookie.split('=');
        const key = parts.shift();
        if (key === name) {
          return decodeURIComponent(parts.join('='));
        }
      }

      return '';
    }

    function hasTwitchConsent() {
      return getCookie('nexpell_consent_twitch') === 'accepted';
    }

    function openCookieSettings() {
      if (typeof window.nxOpenCookieSettings === 'function') {
        window.nxOpenCookieSettings();
      }
    }

    function loadTwitchPlayerScript() {
      if (typeof Twitch !== 'undefined' && Twitch.Player) {
        return Promise.resolve(true);
      }

      if (playerScriptPromise) {
        return playerScriptPromise;
      }

      playerScriptPromise = new Promise(function (resolve) {
        const existing = document.getElementById('twitch-player-script');
        if (existing) {
          existing.addEventListener('load', function () {
            resolve(typeof Twitch !== 'undefined' && !!Twitch.Player);
          }, { once: true });
          existing.addEventListener('error', function () {
            resolve(false);
          }, { once: true });
          return;
        }

        const script = document.createElement('script');
        script.id = 'twitch-player-script';
        script.src = 'https://player.twitch.tv/js/embed/v1.js';
        script.async = true;
        script.onload = function () {
          resolve(typeof Twitch !== 'undefined' && !!Twitch.Player);
        };
        script.onerror = function () {
          resolve(false);
        };
        document.body.appendChild(script);
      });

      return playerScriptPromise;
    }

    function getBsModal() {
      const modalEl = document.getElementById('twitch-stream-modal');
      if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return null;
      }

      if (!bsModal) {
        bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modalEl.addEventListener('hidden.bs.modal', function () {
          if (modalPlayer && typeof modalPlayer.pause === 'function') {
            modalPlayer.pause();
          }
        });
      }

      return bsModal;
    }

    async function ensurePlayer(channel) {
      const target = document.getElementById('twitch-stream-modal-player');
      if (!target || !channel || !hasTwitchConsent()) {
        return false;
      }

      const playerReady = await loadTwitchPlayerScript();
      if (!playerReady) {
        return false;
      }

      if (modalPlayer) {
        modalPlayer.setChannel(channel);
        return true;
      }

      target.innerHTML = '';
      modalPlayer = new Twitch.Player('twitch-stream-modal-player', {
        channel: channel,
        parent: [TWITCH_MODAL_CONFIG.parent],
        width: '100%',
        height: 720
      });

      return true;
    }

    async function openModal(channel, url) {
      if (!hasTwitchConsent()) {
        openCookieSettings();
        return;
      }

      const title = document.getElementById('twitch-stream-modal-title');
      const link = document.getElementById('twitch-stream-modal-link');
      const modal = getBsModal();

      if (!modal || !(await ensurePlayer(channel))) {
        window.open(url, '_blank', 'noopener');
        return;
      }

      if (title) {
        title.textContent = channel;
      }

      if (link) {
        link.href = url;
      }

      modal.show();
    }

    function closeModal() {
      const modal = getBsModal();
      if (modal) {
        modal.hide();
      }
    }

    window.addEventListener('nexpell:consent-change', function (event) {
      if (!event.detail || event.detail.provider !== 'twitch' || event.detail.accepted) {
        return;
      }

      closeModal();
      if (modalPlayer && typeof modalPlayer.pause === 'function') {
        modalPlayer.pause();
      }

      const target = document.getElementById('twitch-stream-modal-player');
      if (target) {
        target.innerHTML = '';
      }
      modalPlayer = null;
    });

    document.addEventListener('DOMContentLoaded', function () {
      function twitchIsPlaceholderCover(url, coverMode) {
        const value = String(url || '').toLowerCase();
        if (
          value.includes('404_preview')
          || value.includes('jtv-static/404')
          || value.includes('ttv-static/404')
        ) {
          return true;
        }

        return coverMode !== 'live' && value.includes('previews-ttv/live_user_');
      }

      function twitchIsInvalidOfflineCover(url) {
        const value = String(url || '').toLowerCase();
        if (!value.includes('jtv_user_pictures') || !value.includes('profile_banner')) {
          return true;
        }

        return twitchIsPlaceholderCover(value);
      }

      document.querySelectorAll('.twitch-cover-img').forEach(function (img) {
        const coverMode = img.getAttribute('data-cover-mode') || 'offline';
        const current = img.currentSrc || img.src;

        if (twitchIsPlaceholderCover(current, coverMode) || (coverMode === 'offline' && twitchIsInvalidOfflineCover(current))) {
          img.removeAttribute('src');
          img.dispatchEvent(new Event('error'));
        }

        img.addEventListener('error', function () {
          const raw = img.getAttribute('data-fallbacks');
          if (!raw) {
            if (coverMode === 'offline') {
              img.closest('.twitch-cover')?.classList.remove('has-cover-image');
            }
            return;
          }

          let fallbacks = [];
          try {
            fallbacks = JSON.parse(raw);
          } catch (error) {
            fallbacks = [];
          }

          let index = parseInt(img.dataset.fallbackIndex || '0', 10);
          while (index < fallbacks.length) {
            const candidate = String(fallbacks[index] || '').toLowerCase();
            if (twitchIsPlaceholderCover(candidate, coverMode)) {
              index += 1;
              continue;
            }
            if (coverMode === 'live' && candidate.includes('profile_banner')) {
              index += 1;
              continue;
            }
            if (coverMode === 'offline' && twitchIsInvalidOfflineCover(candidate)) {
              index += 1;
              continue;
            }
            break;
          }

          if (!Array.isArray(fallbacks) || index >= fallbacks.length) {
            if (coverMode === 'offline') {
              img.remove();
              img.closest('.twitch-cover')?.classList.remove('has-cover-image');
            }
            return;
          }

          img.dataset.fallbackIndex = String(index + 1);
          img.src = fallbacks[index];
        });
      });

      const grid = document.getElementById('twitch-streamer-grid');
      const filterButtons = document.querySelectorAll('[data-twitch-filter]');

      function applyTwitchFilter(mode) {
        if (!grid) {
          return;
        }

        grid.querySelectorAll('[data-twitch-item]').forEach(function (item) {
          const isLive = item.getAttribute('data-live') === '1';
          let visible = true;

          if (mode === 'live') {
            visible = isLive;
          } else if (mode === 'offline') {
            visible = !isLive;
          }

          item.classList.toggle('d-none', !visible);
        });
      }

      filterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
          const mode = button.getAttribute('data-twitch-filter') || 'all';

          filterButtons.forEach(function (item) {
            item.classList.toggle('active', item === button);
          });

          applyTwitchFilter(mode);
        });
      });

      document.querySelectorAll('.twitch-channel-card[data-channel]').forEach(function (card) {
        card.addEventListener('click', function (event) {
          if (event.target.closest('a.twitch-external-link')) {
            return;
          }

          const channel = card.getAttribute('data-channel') || '';
          const url = card.getAttribute('data-url') || '';
          if (url === '') {
            return;
          }

          openModal(channel, url);
        });

        card.addEventListener('keydown', function (event) {
          if (event.target.closest('a.twitch-external-link')) {
            return;
          }

          if (event.key !== 'Enter' && event.key !== ' ') {
            return;
          }

          event.preventDefault();
          const channel = card.getAttribute('data-channel') || '';
          const url = card.getAttribute('data-url') || '';
          if (url === '') {
            return;
          }

          openModal(channel, url);
        });
      });
    });
  })();
</script>
