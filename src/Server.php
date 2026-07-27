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
 * Requires CONTENT_MCP_BRIDGE_KEY (32+ chars) in the environment and an AI
 * user configured in the plugin settings; the server is not created when
 * either is missing.
 */
class Server {
    public function __construct() {
        add_action('mcp_adapter_init', [$this, 'registerServer']);
    }

    public function registerServer(McpAdapter $adapter): void {
        $key = $_ENV['CONTENT_MCP_BRIDGE_KEY'] ?? '';

        if (!preg_match('/^[A-Za-z0-9-]{32,}$/', $key) || !class_exists(HttpTransport::class)) {
            return;
        }

        $userId = Settings::get()['ai_user_id'];

        if (!$userId || !get_user_by('id', $userId)) {
            return;
        }

        $adapter->create_server(
            'content-mcp-bridge',
            'mcp',
            'bridge-'.$key,
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
