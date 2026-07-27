<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use WP_Error;

/**
 * MCP abilities for ACF fields on posts and pages.
 *
 * The generic get-post/update-post abilities only handle plain string meta,
 * so structured ACF content (flexible sections, link/button fields, repeaters,
 * groups) is invisible to them. These abilities read and write ACF values
 * through the ACF API, which handles the serialized storage format.
 */
class PostFields implements AbilityGroup {
    public function registerReadOnly(): void {
        $this->registerGetPostFields();
    }

    public function registerWrite(): void {
        $this->registerUpdatePostField();
    }

    private function registerGetPostFields(): void {
        wp_register_ability('content-mcp-bridge/get-post-fields', [
            'label'               => 'Get post ACF fields',
            'description'         => 'Reads all ACF field values of a post or page, including flexible content sections, buttons (link fields), repeaters and groups. Use this to see the content structure before updating a field.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the post (language version) to read.',
                    ],
                ],
                'required'             => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'     => ['type' => 'integer'],
                    'fields' => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-post-fields', [$this, 'getPostFields']),
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

    private function registerUpdatePostField(): void {
        wp_register_ability('content-mcp-bridge/update-post-field', [
            'label'               => 'Update post ACF field',
            'description'         => 'Updates one ACF field value on a post or page. Supports nested fields inside flexible content sections and repeaters via a dot-notation path. Always call get-post-fields first to see the structure and current values. Link/button fields take an object like {"title": "Read more", "url": "/about/", "target": ""}.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id'    => [
                        'type'        => 'integer',
                        'description' => 'ID of the post (language version) to update.',
                    ],
                    'path'       => [
                        'type'        => 'string',
                        'description' => 'Field path in dot notation, mirroring the structure that get-post-fields returns: field names, 0-based row indexes for flexible content and repeaters, and group names, e.g. "sections.0.content_group.button_primary".',
                    ],
                    'value_json' => [
                        'type'        => 'string',
                        'description' => 'The new value, JSON-encoded. A plain string value must be JSON-quoted, e.g. "\"New title\"". A link/button field takes an object, e.g. {"title":"Read more","url":"/about/","target":""}.',
                    ],
                ],
                'required'             => [
                    'post_id', 'path', 'value_json'
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'   => ['type' => 'integer'],
                    'path' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-post-field', [$this, 'updatePostField']),
            'permission_callback' => function (): bool {
                return current_user_can('edit_posts');
            },
            'meta'                => [
                'annotations' => [
                    'readonly'    => false,
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

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function getPostFields(array $input) {
        if (!function_exists('get_fields')) {
            return new WP_Error('acf_missing', 'ACF is not active.');
        }

        $post = PostGuard::resolve((int)(    $input['post_id'] ?? 0    ));

        if (is_wp_error($post)) {
            return $post;
        }

        $fields = get_fields($post->ID);

        return [
            'id'     => $post->ID,
            'fields' => (object)(    is_array($fields) ? $fields : []    ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function updatePostField(array $input) {
        if (!function_exists('update_field')) {
            return new WP_Error('acf_missing', 'ACF is not active.');
        }

        $post = PostGuard::resolve((int)(    $input['post_id'] ?? 0    ));

        if (is_wp_error($post)) {
            return $post;
        }

        $postId = $post->ID; // phpcs:ignore Zend.NamingConventions.ValidVariableName
        $path   = trim((string)(    $input['path'] ?? ''    ));

        if ($path === '') {
            return new WP_Error('missing_path', 'Field path is required.');
        }

        $value = json_decode((string)(    $input['value_json'] ?? ''    ), true);

        if ($value === null && trim((string)$input['value_json']) !== 'null') {
            return new WP_Error('invalid_value', 'value_json is not valid JSON.');
        }

        // ACF stores every nested value under a flat meta name
        // (sections_0_content_group_button_primary) with a field-key
        // reference, so update_field resolves any nesting depth — including
        // groups, which update_sub_field selectors cannot traverse.
        $selector = str_replace('.', '_', $path);

        if (!acf_maybe_get_field($selector, $postId, false)) {
            return new WP_Error('unknown_field', "No ACF field found at '{$path}' on post {$postId}. Check the path against get-post-fields output.");
        }

        $updated = update_field($selector, $value, $postId);

        if (!$updated) {
            return new WP_Error(
                'update_failed',
                "Could not update '{$path}'. Check the path against get-post-fields output; the value may also be identical to the current one."
            );
        }

        return [
            'id'   => $postId,
            'path' => $path,
        ];
    }
}
