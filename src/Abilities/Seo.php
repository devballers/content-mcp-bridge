<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Settings;
use WP_Error;

class Seo implements AbilityGroup {
    public function registerReadOnly(): void {
        // No readonly ability for this group.
    }

    public function registerWrite(): void {
        wp_register_ability('content-mcp-bridge/update-post-seo', [
            'label'               => 'Update post SEO fields',
            'description'         => 'Updates Rank Math focus keyword, SEO title and meta description of a post.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id'          => [
                        'type'        => 'integer',
                        'description' => 'ID of the post (language version) to update.',
                    ],
                    'focus_keyword'    => [
                        'type'        => 'string',
                        'description' => 'Rank Math focus keyword. Separate multiple keywords with commas.',
                    ],
                    'seo_title'        => [
                        'type'        => 'string',
                        'description' => 'Rank Math SEO title shown in search results.',
                    ],
                    'meta_description' => [
                        'type'        => 'string',
                        'description' => 'Rank Math meta description shown in search results.',
                    ],
                ],
                'required'             => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'             => ['type' => 'integer'],
                    'updated_fields' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-post-seo', [$this, 'updatePostSeo']),
            'permission_callback' => function (array $input): bool {
                $postId = isset($input['post_id']) ? (int)$input['post_id'] : 0;

                return current_user_can('edit_post', $postId)
                    && current_user_can('rank_math_onpage_general')
                    && Settings::isPostTypeAllowed((string)get_post_type($postId));
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

    public function updatePostSeo(array $input) {
        $postId = isset($input['post_id']) ? (int)$input['post_id'] : 0;

        if (!get_post($postId)) {
            return new WP_Error('post_not_found', "Post {$postId} was not found.");
        }

        $fieldMap = [
            'focus_keyword'    => 'rank_math_focus_keyword',
            'seo_title'        => 'rank_math_title',
            'meta_description' => 'rank_math_description',
        ];

        $updatedFields = [];

        foreach ($fieldMap as $inputKey => $metaKey) {
            if (!isset($input[$inputKey])) {
                continue;
            }

            update_post_meta($postId, $metaKey, sanitize_text_field($input[$inputKey]));
            $updatedFields[] = $inputKey;
        }

        if (!$updatedFields) {
            return new WP_Error('nothing_to_update', 'No SEO fields were provided to update.');
        }

        return [
            'id'             => $postId,
            'updated_fields' => $updatedFields,
        ];
    }
}
