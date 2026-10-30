<?php
namespace ContentMcpBridge\Health;

use Throwable;

/**
 * Builds a read-only snapshot of a site's health: versions, plugins, and a
 * list of checks for settings that are commonly wrong or forgotten at
 * launch. Shared by the get-site-health MCP ability and the monitor REST
 * route, so Claude and the monitoring dashboard see the same thing.
 *
 * Each check returns ['key', 'label', 'status', 'message', 'details'],
 * with status one of ok, warning, critical or unknown. A check that throws
 * is reported as unknown rather than breaking the whole report.
 */
class Report {
    // End of security support per PHP minor version (php.net/supported-versions).
    private const PHP_EOL_DATES = [
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    // Email domains that should never receive a live site's mail.
    private const PLACEHOLDER_EMAIL_DOMAINS = ['example.com', 'example.org', 'example.net', 'test', 'local', 'localhost', 'invalid'];

    /**
     * @return array<string, mixed>
     */
    public static function build(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $plugins = self::plugins();

        $checks = [
            'coreUpdate',
            'phpVersion',
            'pluginUpdates',
            'inactivePlugins',
            'searchVisibility',
            'debugMode',
            'fileEditing',
            'adminEmail',
            'sampleContent',
            'timezone',
            'cron',
            'administrators',
            'gravityFormsNotifications',
            'gravityFormsSpam',
            'gravityFormsLicense',
            'acfSync',
            'wpRocket',
            'rankMathSitemap',
            'imagify',
        ];

        $results = [];

        foreach ($checks as $check) {
            try {
                $result = self::$check($plugins);
            } catch (Throwable $e) {
                $result = self::result($check, $check, 'unknown', 'Check failed: '.$e->getMessage());
            }

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return [
            'plugin_version' => get_file_data(CONTENT_MCP_BRIDGE_FILE, ['Version' => 'Version'])['Version'],
            'generated_at'   => gmdate('c'),
            'environment'    => self::environment(),
            'plugins'        => $plugins,
            'checks'         => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function environment(): array {
        global $wpdb;

        $theme = wp_get_theme();

        return [
            'wp_version'       => get_bloginfo('version'),
            'php_version'      => PHP_VERSION,
            'db_version'       => $wpdb->db_server_info(),
            'theme'            => trim($theme->get('Name').' '.$theme->get('Version')),
            'environment_type' => wp_get_environment_type(),
            'home_url'         => home_url(),
            'multisite'        => is_multisite(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function plugins(): array {
        $updates = get_site_transient('update_plugins');
        $pending = is_object($updates) && isset($updates->response) ? (array)$updates->response : [];

        $plugins = [];

        foreach (get_plugins() as $file => $data) {
            $plugins[] = [
                'file'    => $file,
                'name'    => $data['Name'],
                'version' => $data['Version'],
                'active'  => is_plugin_active($file),
                'update'  => isset($pending[$file]->new_version) ? (string)$pending[$file]->new_version : null,
            ];
        }

        usort($plugins, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $plugins;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private static function result(string $key, string $label, string $status, string $message, array $details = []): array {
        return [
            'key'     => $key,
            'label'   => $label,
            'status'  => $status,
            'message' => $message,
            'details' => (object)$details,
        ];
    }

    private static function coreUpdate(): array {
        $updates = get_site_transient('update_core');
        $current = get_bloginfo('version');

        foreach ((is_object($updates) ? (array)$updates->updates : []) as $update) {
            if (($update->response ?? '') === 'upgrade') {
                return self::result('core_update', 'WordPress core', 'warning', "WordPress {$update->current} is available (running {$current}).");
            }
        }

        return self::result('core_update', 'WordPress core', 'ok', "WordPress {$current} is up to date.");
    }

    private static function phpVersion(): array {
        $minor = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $eol   = self::PHP_EOL_DATES[$minor] ?? null;

        if ($eol === null) {
            return self::result('php_version', 'PHP version', 'ok', 'PHP '.PHP_VERSION.'.');
        }

        $daysLeft = (int)floor((strtotime($eol) - time()) / DAY_IN_SECONDS);

        if ($daysLeft < 0) {
            return self::result('php_version', 'PHP version', 'critical', 'PHP '.PHP_VERSION." stopped receiving security fixes on {$eol}.");
        }

        if ($daysLeft < 120) {
            return self::result('php_version', 'PHP version', 'warning', 'PHP '.PHP_VERSION." stops receiving security fixes on {$eol}.");
        }

        return self::result('php_version', 'PHP version', 'ok', 'PHP '.PHP_VERSION." (supported until {$eol}).");
    }

    private static function pluginUpdates(array $plugins): array {
        $outdated = array_values(array_filter($plugins, static fn (array $plugin): bool => $plugin['update'] !== null));

        if (!$outdated) {
            return self::result('plugin_updates', 'Plugin updates', 'ok', 'All plugins are up to date.');
        }

        $names = array_map(static fn (array $plugin): string => "{$plugin['name']} {$plugin['version']} → {$plugin['update']}", $outdated);

        return self::result('plugin_updates', 'Plugin updates', 'warning', count($outdated).' plugin update(s) available: '.implode(', ', $names).'.');
    }

    private static function inactivePlugins(array $plugins): array {
        $inactive = array_values(array_filter($plugins, static fn (array $plugin): bool => !$plugin['active']));

        if (!$inactive) {
            return self::result('inactive_plugins', 'Inactive plugins', 'ok', 'No inactive plugins installed.');
        }

        $names = array_column($inactive, 'name');

        return self::result('inactive_plugins', 'Inactive plugins', 'warning', count($inactive).' inactive plugin(s) still installed: '.implode(', ', $names).'.');
    }

    private static function searchVisibility(): array {
        if ((string)get_option('blog_public') === '0') {
            return self::result('search_visibility', 'Search engine visibility', 'critical', '"Discourage search engines from indexing this site" is turned on.');
        }

        return self::result('search_visibility', 'Search engine visibility', 'ok', 'Search engines may index the site.');
    }

    private static function debugMode(): array {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return self::result('debug_mode', 'Debug mode', 'ok', 'WP_DEBUG is off.');
        }

        if (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY) {
            return self::result('debug_mode', 'Debug mode', 'critical', 'WP_DEBUG is on and errors are shown to visitors.');
        }

        return self::result('debug_mode', 'Debug mode', 'warning', 'WP_DEBUG is on (errors are logged, not displayed).');
    }

    private static function fileEditing(): array {
        if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
            return self::result('file_editing', 'Theme & plugin file editor', 'ok', 'The built-in file editor is disabled.');
        }

        return self::result('file_editing', 'Theme & plugin file editor', 'warning', 'Admins can edit PHP files from wp-admin. Set DISALLOW_FILE_EDIT to true.');
    }

    private static function adminEmail(): array {
        $email = (string)get_option('admin_email');

        if (self::isPlaceholderEmail($email)) {
            return self::result('admin_email', 'Admin email', 'critical', "Admin email is a placeholder address ({$email}).");
        }

        return self::result('admin_email', 'Admin email', 'ok', "Admin email is {$email}.");
    }

    private static function sampleContent(): array {
        $leftovers = [];

        foreach (['post' => 'hello-world', 'page' => 'sample-page'] as $type => $slug) {
            $post = get_page_by_path($slug, OBJECT, $type);

            if ($post && $post->post_status === 'publish') {
                $leftovers[] = '"'.get_the_title($post).'"';
            }
        }

        if ($leftovers) {
            return self::result('sample_content', 'Sample content', 'warning', 'WordPress sample content is still published: '.implode(', ', $leftovers).'.');
        }

        return self::result('sample_content', 'Sample content', 'ok', 'No WordPress sample content is published.');
    }

    private static function timezone(): array {
        $timezone = (string)get_option('timezone_string');

        if ($timezone === '' && (float)get_option('gmt_offset') === 0.0) {
            return self::result('timezone', 'Timezone', 'warning', 'Timezone is left at the default UTC+0.');
        }

        return self::result('timezone', 'Timezone', 'ok', 'Timezone is '.wp_timezone_string().'.');
    }

    private static function cron(): array {
        $overdue = 0;
        $cutoff  = time() - HOUR_IN_SECONDS;

        foreach ((array)_get_cron_array() as $timestamp => $hooks) {
            if ((int)$timestamp < $cutoff) {
                $overdue += count((array)$hooks);
            }
        }

        if ($overdue > 0) {
            return self::result('cron', 'Scheduled tasks', 'warning', "{$overdue} scheduled task(s) are over an hour late. WP-Cron may not be running.");
        }

        return self::result('cron', 'Scheduled tasks', 'ok', 'Scheduled tasks are running on time.');
    }

    private static function administrators(): array {
        $logins = get_users(['role' => 'administrator', 'fields' => 'user_login']);

        return self::result('administrators', 'Administrators', 'ok', count($logins).' administrator(s): '.implode(', ', $logins).'.', ['logins' => $logins]);
    }

    private static function gravityFormsNotifications(): ?array {
        if (!class_exists('GFAPI')) {
            return null;
        }

        $siteDomain = self::siteDomain();
        $problems   = [];

        foreach (\GFAPI::get_forms(true) as $form) {
            $title  = $form['title'];
            $active = array_filter((array)(    $form['notifications'] ?? []    ), static fn (array $n): bool => !isset($n['isActive']) || $n['isActive']);

            if (!$active) {
                $problems[] = "\"{$title}\" has no active notifications";

                continue;
            }

            foreach ($active as $notification) {
                $name = $notification['name'] ?? 'notification';
                $to   = ($notification['toType'] ?? 'email') === 'email' ? (string)(    $notification['to'] ?? ''    ) : '';

                foreach (array_filter(array_map('trim', explode(',', $to))) as $recipient) {
                    if (self::isPlaceholderEmail($recipient)) {
                        $problems[] = "\"{$title}\" / {$name} sends to placeholder address {$recipient}";
                    }
                }

                $from = (string)(    $notification['from'] ?? ''    );

                if ($from !== '' && !str_contains($from, '{') && $siteDomain && !str_ends_with(strtolower($from), '@'.$siteDomain)) {
                    $problems[] = "\"{$title}\" / {$name} sends from {$from}, not an @{$siteDomain} address (may land in spam)";
                }
            }
        }

        if ($problems) {
            return self::result('gf_notifications', 'Gravity Forms notifications', 'warning', implode('; ', $problems).'.', ['problems' => $problems]);
        }

        return self::result('gf_notifications', 'Gravity Forms notifications', 'ok', 'All active forms send notifications from and to real addresses.');
    }

    private static function gravityFormsSpam(array $plugins): ?array {
        if (!class_exists('GFAPI')) {
            return null;
        }

        $siteWideProtection = class_exists('GF_Zero_Spam') || (bool)array_filter($plugins, static fn (array $plugin): bool => $plugin['active'] && (
            str_starts_with($plugin['file'], 'gravity-forms-zero-spam/') || str_starts_with($plugin['file'], 'gravityformsrecaptcha/') || str_starts_with($plugin['file'], 'gravityformsturnstile/')
        ));

        if ($siteWideProtection) {
            return self::result('gf_spam', 'Gravity Forms spam protection', 'ok', 'A spam protection add-on is active.');
        }

        $unprotected = [];

        foreach (\GFAPI::get_forms(true) as $form) {
            if (empty($form['enableHoneypot'])) {
                $unprotected[] = '"'.$form['title'].'"';
            }
        }

        if ($unprotected) {
            return self::result('gf_spam', 'Gravity Forms spam protection', 'warning', 'No spam add-on is active and these forms have no honeypot: '.implode(', ', $unprotected).'.');
        }

        return self::result('gf_spam', 'Gravity Forms spam protection', 'ok', 'Every active form has the honeypot enabled.');
    }

    private static function gravityFormsLicense(): ?array {
        if (!class_exists('GFAPI')) {
            return null;
        }

        if ((string)get_option('rg_gforms_key') === '' && !defined('GF_LICENSE_KEY')) {
            return self::result('gf_license', 'Gravity Forms license', 'warning', 'No Gravity Forms license key is set, so it will not receive updates.');
        }

        return self::result('gf_license', 'Gravity Forms license', 'ok', 'A Gravity Forms license key is set.');
    }

    /**
     * Field groups defined in code (ACF Extended PHP AutoSync or local JSON)
     * that have no database copy yet, i.e. still waiting for "Sync" in
     * ACF → Field Groups. A heuristic: ACF reports such groups with ID 0.
     */
    private static function acfSync(): ?array {
        if (!function_exists('acf_get_field_groups')) {
            return null;
        }

        $pending = [];

        foreach (acf_get_field_groups() as $group) {
            if (empty($group['ID']) && !empty($group['local']) && empty($group['private'])) {
                $pending[] = '"'.$group['title'].'"';
            }
        }

        if ($pending) {
            return self::result('acf_sync', 'ACF field group sync', 'warning', count($pending).' field group(s) are defined in code but not synced to the database: '.implode(', ', $pending).'.');
        }

        return self::result('acf_sync', 'ACF field group sync', 'ok', 'All code-defined field groups are synced.');
    }

    private static function wpRocket(): ?array {
        if (!function_exists('get_rocket_option')) {
            return null;
        }

        if (!defined('WP_CACHE') || !WP_CACHE) {
            return self::result('wp_rocket', 'WP Rocket', 'warning', 'WP Rocket is active but page caching is off (WP_CACHE is not true).');
        }

        if ((string)get_rocket_option('consumer_key') === '' && !defined('WP_ROCKET_KEY')) {
            return self::result('wp_rocket', 'WP Rocket', 'warning', 'WP Rocket has no license key, so it will not receive updates.');
        }

        return self::result('wp_rocket', 'WP Rocket', 'ok', 'Page caching is on and a license key is set.');
    }

    private static function rankMathSitemap(): ?array {
        if (!class_exists('RankMath\\Helper')) {
            return null;
        }

        if (!\RankMath\Helper::is_module_active('sitemap')) {
            return self::result('rank_math_sitemap', 'Rank Math sitemap', 'warning', 'The Rank Math sitemap module is turned off.');
        }

        return self::result('rank_math_sitemap', 'Rank Math sitemap', 'ok', 'The Rank Math sitemap module is on.');
    }

    private static function imagify(): ?array {
        if (!function_exists('get_imagify_option')) {
            return null;
        }

        if ((string)get_imagify_option('api_key') === '' && !defined('IMAGIFY_API_KEY')) {
            return self::result('imagify', 'Imagify', 'warning', 'Imagify is active but has no API key, so images are not optimized.');
        }

        return self::result('imagify', 'Imagify', 'ok', 'Imagify has an API key.');
    }

    private static function isPlaceholderEmail(string $email): bool {
        $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));

        if ($domain === '') {
            return false;
        }

        foreach (self::PLACEHOLDER_EMAIL_DOMAINS as $placeholder) {
            if ($domain === $placeholder || str_ends_with($domain, '.'.$placeholder)) {
                return true;
            }
        }

        return false;
    }

    private static function siteDomain(): string {
        $host = strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
