<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Health\Report;

/**
 * Site health over MCP: the same report the monitoring dashboard pulls
 * through the monitor REST route, so Claude can answer "is anything wrong
 * with this site?" and then fix it with the other abilities.
 */
class Health implements AbilityGroup {
    public function registerReadOnly(): void {
        wp_register_ability('content-mcp-bridge/get-site-health', [
            'label'               => 'Get site health',
            'description'         => 'Returns a health report for the site: WordPress, PHP and database versions, installed plugins with available updates, and checks for common misconfigurations (search engines discouraged, debug mode on, placeholder emails, Gravity Forms notifications and spam protection, ACF field groups waiting for sync, WP Rocket, Rank Math sitemap, Imagify). Each check has a status of ok, warning, critical or unknown.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'plugin_version' => ['type' => 'string'],
                    'generated_at'   => ['type' => 'string'],
                    'environment'    => ['type' => 'object'],
                    'plugins'        => ['type' => 'array'],
                    'checks'         => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'key'     => ['type' => 'string'],
                                'label'   => ['type' => 'string'],
                                'status'  => ['type' => 'string', 'enum' => ['ok', 'warning', 'critical', 'unknown']],
                                'message' => ['type' => 'string'],
                                'details' => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-site-health', fn (array $input): array => Report::build()),
            'permission_callback' => function (): bool {
                return current_user_can('edit_posts');
            },
            'meta'                => [
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    public function registerWrite(): void {
        // No write ability in this group.
    }
}
