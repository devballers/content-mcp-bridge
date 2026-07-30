<?php
namespace ContentMcpBridge;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * Registers the MCP server with a secret URL.
 *
 * claude.ai web connectors only support OAuth 2.1 or unauthenticated servers,
 * so this server skips WordPress auth and instead relies on a long random key
 * in the URL. Every request is mapped to the WordPress user chosen in
 * Settings → Content MCP Bridge.
 *
 * Requires CONTENT_MCP_BRIDGE_KEY (32+ chars) as a wp-config.php constant
 * or in the environment, and an AI
 * user configured in the plugin settings; the server is not created when
 * either is missing.
 */
class Server {
    private const ROUTE_NAMESPACE = 'mcp';

    public function __construct() {
        add_action('mcp_adapter_init', [$this, 'registerServer']);
        add_filter('rest_post_dispatch', [$this, 'hideFromRestIndex'], 10, 1);
    }

    /**
     * WordPress's REST index (/wp-json/ and /wp-json/mcp/) publicly lists
     * every registered route by default, with no authentication — and our
     * route's path IS the secret key, so leaving it in the index defeats
     * the entire point of a secret URL. Strips any route matching our
     * namespace/slug prefix out of an index response before it's served.
     */
    public function hideFromRestIndex($response) {
        if (!$response instanceof \WP_REST_Response) {
            return $response;
        }

        $data = $response->get_data();

        if (!is_array($data) || !isset($data['routes']) || !is_array($data['routes'])) {
            return $response;
        }

        $prefix = '/'.self::ROUTE_NAMESPACE.'/bridge-';

        foreach (array_keys($data['routes']) as $route) {
            if (is_string($route) && str_contains($route, $prefix)) {
                unset($data['routes'][$route]);
            }
        }

        $response->set_data($data);

        return $response;
    }

    /**
     * Reads CONTENT_MCP_BRIDGE_KEY from whichever mechanism a project uses
     * to configure it — a wp-config.php constant, the $_ENV superglobal
     * (e.g. via vlucas/phpdotenv) or a plain putenv()/host-panel env var,
     * which only shows up via getenv() and never touches $_ENV. The
     * constant wins when both are set, being the most explicit of the
     * three.
     */
    public static function currentKey(): string {
        if (defined('CONTENT_MCP_BRIDGE_KEY') && constant('CONTENT_MCP_BRIDGE_KEY') !== '') {
            return (string)constant('CONTENT_MCP_BRIDGE_KEY');
        }

        $fromEnv = $_ENV['CONTENT_MCP_BRIDGE_KEY'] ?? '';

        if ($fromEnv !== '') {
            return (string)$fromEnv;
        }

        $fromGetenv = getenv('CONTENT_MCP_BRIDGE_KEY');

        return $fromGetenv !== false ? $fromGetenv : '';
    }

    /**
     * Builds the public MCP server URL for a given secret key, or null if
     * the key or the configured AI user aren't valid yet. Used both to
     * register the server and to display its URL on the settings page.
     */
    public static function urlForKey(string $key): ?string {
        if (!preg_match(Settings::KEY_PATTERN, $key)) {
            return null;
        }

        $userId = Settings::get()['ai_user_id'];

        if (!$userId || !get_user_by('id', $userId)) {
            return null;
        }

        return home_url('/wp-json/'.self::ROUTE_NAMESPACE.'/'.self::routeSlug($key));
    }

    private static function routeSlug(string $key): string {
        return 'bridge-'.$key;
    }

    public function registerServer(McpAdapter $adapter): void {
        $key = self::currentKey();

        if (!self::urlForKey($key) || !class_exists(HttpTransport::class)) {
            return;
        }

        $userId = Settings::get()['ai_user_id'];

        $adapter->create_server(
            'content-mcp-bridge',
            self::ROUTE_NAMESPACE,
            self::routeSlug($key),
            'Content MCP Bridge',
            'Content-editing abilities exposed to an MCP client.',
            'v1.0.0',
            [HttpTransport::class],
            ErrorLogMcpErrorHandler::class,
            null,
            [
                'mcp-adapter/discover-abilities',
                'mcp-adapter/get-ability-info',
                'mcp-adapter/execute-ability',
            ],
            [],
            [],
            function () use ($userId) {
                if (!get_user_by('id', $userId)) {
                    return false;
                }

                wp_set_current_user($userId);

                if (!empty(Settings::get()['integrations']['wpml'])) {
                    $this->allowMenuItemEditing($userId);
                }

                return true;
            }
        );
    }

    /**
     * Lets the mapped user edit nav_menu_item posts (menu labels).
     *
     * WordPress maps editing menu items to edit_theme_options, which a
     * limited role may lack. Remap it to edit_posts — only for this user,
     * only for nav_menu_item, and only on this server's requests (the filter
     * is registered inside the auth callback). Deleting stays blocked.
     */
    private function allowMenuItemEditing(int $userId): void {
        add_filter('map_meta_cap', function (array $caps, string $cap, int $capUserId, array $args) use ($userId): array {
            if ($capUserId !== $userId || $cap !== 'edit_post') {
                return $caps;
            }

            $postId = (int)(    $args[0] ?? 0    );

            if ($postId && get_post_type($postId) === 'nav_menu_item') {
                return ['edit_posts'];
            }

            return $caps;
        }, 10, 4);
    }
}
