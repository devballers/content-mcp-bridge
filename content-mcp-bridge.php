<?php
/**
 * Plugin Name: Content MCP Bridge
 * Description: Exposes WordPress content-editing abilities to an MCP client, scoped to one user, chosen post types and detected integrations.
 * Version: 0.6.0
 * Requires PHP: 8.0
 * Author: Ballers
 * Text Domain: content-mcp-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CONTENT_MCP_BRIDGE_FILE', __FILE__);
define('CONTENT_MCP_BRIDGE_DIR', __DIR__.DIRECTORY_SEPARATOR);

spl_autoload_register(function (string $class): void {
    $prefix = 'ContentMcpBridge\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path     = CONTENT_MCP_BRIDGE_DIR.'src'.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';

    if (is_readable($path)) {
        require $path;
    }
});

register_activation_hook(CONTENT_MCP_BRIDGE_FILE, ['ContentMcpBridge\\AuditLog', 'onActivate']);
register_activation_hook(CONTENT_MCP_BRIDGE_FILE, ['ContentMcpBridge\\Role', 'onActivate']);
register_deactivation_hook(CONTENT_MCP_BRIDGE_FILE, ['ContentMcpBridge\\Monitor', 'onDeactivate']);

add_action('plugins_loaded', function (): void {
    // Monitoring works on its own, so the plugin can be installed on any
    // site just for the dashboard; everything MCP needs MCP Adapter.
    new ContentMcpBridge\Monitor();

    if (!class_exists('WP\\MCP\\Core\\McpAdapter')) {
        // Only nag when MCP is actually meant to be used on this site.
        if (ContentMcpBridge\Server::currentKey() === '') {
            return;
        }

        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>'
                .esc_html__('Content MCP Bridge requires the "MCP Adapter" plugin to be installed and active.', 'content-mcp-bridge')
                .'</p></div>';
        });

        return;
    }

    new ContentMcpBridge\Plugin();
});
