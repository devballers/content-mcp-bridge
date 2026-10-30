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
 * should only ever be able to read their health. The route is not
 * registered at all when CONTENT_MCP_BRIDGE_MONITOR_KEY is missing.
 */
class Monitor {
    public const ROUTE_NAMESPACE = 'content-mcp-bridge/v1';

    public const ROUTE = '/health';

    public const HEADER = 'X-Monitor-Key';

    public function __construct() {
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    /**
     * Reads CONTENT_MCP_BRIDGE_MONITOR_KEY from a wp-config.php constant,
     * the $_ENV superglobal (e.g. via vlucas/phpdotenv) or getenv(), in
     * that order, the same sources Server::currentKey() accepts.
     */
    public static function currentKey(): string {
        if (defined('CONTENT_MCP_BRIDGE_MONITOR_KEY') && constant('CONTENT_MCP_BRIDGE_MONITOR_KEY') !== '') {
            return (string)constant('CONTENT_MCP_BRIDGE_MONITOR_KEY');
        }

        $fromEnv = $_ENV['CONTENT_MCP_BRIDGE_MONITOR_KEY'] ?? '';

        if ($fromEnv !== '') {
            return (string)$fromEnv;
        }

        $fromGetenv = getenv('CONTENT_MCP_BRIDGE_MONITOR_KEY');

        return $fromGetenv !== false ? $fromGetenv : '';
    }

    public function registerRoute(): void {
        if (!preg_match(Settings::KEY_PATTERN, self::currentKey())) {
            return;
        }

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
}
