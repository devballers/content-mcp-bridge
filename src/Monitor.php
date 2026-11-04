<?php
namespace ContentMcpBridge;

use ContentMcpBridge\Health\Report;
use WP_REST_Request;

/**
 * Read-only REST route for an external monitoring dashboard:
 * GET /wp-json/content-mcp-bridge/v1/health with the X-Monitor-Key header.
 *
 * Deliberately uses its own key, separate from CONTENT_MCP_BRIDGE_KEY: the
 * MCP key can edit content, and a dashboard that holds keys for many sites
 * should only ever be able to read their health. When no key is configured
 * the site generates and stores its own, so monitoring needs no setup.
 *
 * Runs without MCP Adapter, so the plugin can be installed on any site
 * purely for monitoring.
 *
 * With CONTENT_MCP_BRIDGE_DASHBOARD_URL and CONTENT_MCP_BRIDGE_ENROLL_TOKEN
 * set, a production site also announces itself to the dashboard once a
 * day (URL, name, monitor key), so new sites show up there for approval
 * without anyone adding them by hand.
 */
class Monitor {
    public const ROUTE_NAMESPACE = 'content-mcp-bridge/v1';

    public const ROUTE = '/health';

    public const HEADER = 'X-Monitor-Key';

    public const ENROLL_HOOK = 'content_mcp_bridge_monitor_enroll';

    private const KEY_OPTION = 'content_mcp_bridge_monitor_key';

    private const ENROLLMENT_OPTION = 'content_mcp_bridge_monitor_enrollment';

    public function __construct() {
        add_action('rest_api_init', [$this, 'registerRoute']);
        add_action('init', [$this, 'scheduleEnrollment']);
        add_action(self::ENROLL_HOOK, [self::class, 'enroll'], 10, 0);
    }

    public static function onDeactivate(): void {
        wp_unschedule_hook(self::ENROLL_HOOK);
    }

    /**
     * Reads a setting from a wp-config.php constant, the $_ENV superglobal
     * (e.g. via vlucas/phpdotenv) or getenv(), in that order — the same
     * sources Server::currentKey() accepts.
     */
    public static function config(string $name): string {
        if (defined($name) && constant($name) !== '') {
            return (string)constant($name);
        }

        $fromEnv = $_ENV[$name] ?? '';

        if ($fromEnv !== '') {
            return (string)$fromEnv;
        }

        $fromGetenv = getenv($name);

        return $fromGetenv !== false ? $fromGetenv : '';
    }

    /**
     * The configured CONTENT_MCP_BRIDGE_MONITOR_KEY, or else a key this
     * site generated for itself on first use. A configured key that isn't
     * a valid key falls back to the generated one too, so a typo in .env
     * doesn't take the health route down; the settings page warns instead.
     */
    public static function currentKey(): string {
        $configured = self::config('CONTENT_MCP_BRIDGE_MONITOR_KEY');

        return preg_match(Settings::KEY_PATTERN, $configured) ? $configured : self::storedKey();
    }

    public static function keyIsGenerated(): bool {
        return !preg_match(Settings::KEY_PATTERN, self::config('CONTENT_MCP_BRIDGE_MONITOR_KEY'));
    }

    public static function configuredKeyIsInvalid(): bool {
        return self::config('CONTENT_MCP_BRIDGE_MONITOR_KEY') !== '' && self::keyIsGenerated();
    }

    /**
     * The site's own key, generated on first use. Uses INSERT IGNORE rather
     * than add_option(), which overwrites on a duplicate: when two first
     * requests race, both must end up with the key that was saved first.
     */
    private static function storedKey(): string {
        global $wpdb;

        $stored = (string)get_option(self::KEY_OPTION, '');

        if (preg_match(Settings::KEY_PATTERN, $stored)) {
            return $stored;
        }

        if ($stored !== '') {
            delete_option(self::KEY_OPTION);
        }

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::KEY_OPTION,
            wp_generate_password(48, false)
        ));

        wp_cache_delete(self::KEY_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');

        return (string)$wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::KEY_OPTION
        ));
    }

    public static function dashboardUrl(): string {
        return rtrim(self::config('CONTENT_MCP_BRIDGE_DASHBOARD_URL'), '/');
    }

    public static function enrollmentConfigured(): bool {
        return self::dashboardUrl() !== '' && self::config('CONTENT_MCP_BRIDGE_ENROLL_TOKEN') !== '';
    }

    /**
     * @return array{time: int, ok: bool, message: string, key?: string}|null
     */
    public static function lastEnrollment(): ?array {
        $result = get_option(self::ENROLLMENT_OPTION);

        return is_array($result) ? $result : null;
    }

    public function registerRoute(): void {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE, [
            'methods'             => 'GET',
            'callback'            => fn (): array => Report::build(),
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize(WP_REST_Request $request): bool {
        $given = (string)$request->get_header(self::HEADER);

        return $given !== '' && hash_equals(self::currentKey(), $given);
    }

    /**
     * Keeps the daily enrollment event in step with the configuration:
     * scheduled while a dashboard is configured, removed once it isn't.
     *
     * Also enrolls again right away (on the next cron run) when the key
     * changed since the last attempt, so the dashboard isn't left checking
     * with a stale key until the next daily run. Only looked at in admin
     * and cron requests, to keep option reads off the front end.
     */
    public function scheduleEnrollment(): void {
        if (!self::enrollmentConfigured()) {
            if (wp_next_scheduled(self::ENROLL_HOOK)) {
                wp_unschedule_hook(self::ENROLL_HOOK);
            }

            return;
        }

        if (!wp_next_scheduled(self::ENROLL_HOOK)) {
            wp_schedule_event(time(), 'daily', self::ENROLL_HOOK);
        }

        if (!is_admin() && !wp_doing_cron()) {
            return;
        }

        $last = self::lastEnrollment();

        if ($last !== null && ($last['key'] ?? '') !== self::keyFingerprint() && !wp_next_scheduled(self::ENROLL_HOOK, ['key_changed'])) {
            wp_schedule_single_event(time(), self::ENROLL_HOOK, ['key_changed']);
        }
    }

    private static function keyFingerprint(): string {
        return hash('sha256', self::currentKey());
    }

    /**
     * Announces this site to the dashboard. Only production sites enroll,
     * so local and staging copies with the same environment don't, as long
     * as they set WP_ENVIRONMENT_TYPE: WordPress treats a site without it
     * as production. The dashboard verifies the key by calling the health route back before
     * it accepts it.
     */
    public static function enroll(): void {
        if (!self::enrollmentConfigured()) {
            return;
        }

        if (wp_get_environment_type() !== 'production') {
            self::recordEnrollment(false, 'Skipped: environment type is "'.wp_get_environment_type().'", not production.');

            return;
        }

        $response = wp_remote_post(self::dashboardUrl().'/api/enroll', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer '.self::config('CONTENT_MCP_BRIDGE_ENROLL_TOKEN'),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => (string)wp_json_encode([
                'url'            => home_url(),
                'name'           => get_bloginfo('name'),
                'monitor_key'    => self::currentKey(),
                'plugin_version' => get_file_data(CONTENT_MCP_BRIDGE_FILE, ['Version' => 'Version'])['Version'],
                'wp_version'     => get_bloginfo('version'),
            ]),
        ]);

        if (is_wp_error($response)) {
            self::recordEnrollment(false, $response->get_error_message());

            return;
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            self::recordEnrollment(true, 'Dashboard status: '.($body['status'] ?? 'unknown').'.');
        } else {
            self::recordEnrollment(false, "HTTP {$code}: ".($body['message'] ?? 'no message'));
        }
    }

    private static function recordEnrollment(bool $ok, string $message): void {
        update_option(self::ENROLLMENT_OPTION, [
            'time'    => time(),
            'ok'      => $ok,
            'message' => mb_substr($message, 0, 500),
            'key'     => self::keyFingerprint(),
        ], false);
    }
}
