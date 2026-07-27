<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;

/**
 * Introspection ability for diagnosing permission issues: which WordPress
 * user/role the bridge is actually authenticating as, and whether that
 * user holds a given set of capabilities. Useful because a leaked or
 * misconfigured mapping (wrong user, missing capability) otherwise looks
 * identical to a correctly-scoped denial — this makes it inspectable in
 * one call instead of guessing from which abilities succeed or fail.
 */
class Diagnostics implements AbilityGroup {
    private const DEFAULT_CAPABILITIES = [
        'manage_options',
        'edit_posts',
        'upload_files',
        'delete_posts',
    ];

    public function registerReadOnly(): void {
        wp_register_ability('content-mcp-bridge/whoami', [
            'label'               => 'Who am I',
            'description'         => 'Returns the WordPress user the MCP bridge is currently authenticating as, their roles, and whether they hold specific capabilities. Pass capability names to check beyond the default set, e.g. to test a third-party plugin capability like rank_math_site_analysis.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'capabilities' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Additional capability names to check, on top of the default set (manage_options, edit_posts, upload_files, delete_posts).',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'user_id'      => ['type' => 'integer'],
                    'user_login'   => ['type' => 'string'],
                    'roles'        => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'capabilities' => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/whoami', [$this, 'whoami']),
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

    public function whoami(array $input) {
        $user = wp_get_current_user();

        $capabilityNames = array_unique(array_merge(
            self::DEFAULT_CAPABILITIES,
            array_map('sanitize_key', (array)(    $input['capabilities'] ?? []    ))
        ));

        $capabilities = [];

        foreach ($capabilityNames as $capability) {
            $capabilities[$capability] = current_user_can($capability);
        }

        return [
            'user_id'      => $user->ID,
            'user_login'   => $user->user_login,
            'roles'        => array_values($user->roles),
            'capabilities' => (object)$capabilities,
        ];
    }
}
