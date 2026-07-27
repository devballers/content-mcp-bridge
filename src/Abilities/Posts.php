<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Integrations\Wpml;
use ContentMcpBridge\Settings;
use WP_Error;
use WP_Query;

class Posts implements AbilityGroup {
    public function registerReadOnly(): void {
        $this->registerListPosts();
        $this->registerGetPost();
    }

    public function registerWrite(): void {
        $this->registerUpdatePost();
    }

    private function registerListPosts(): void {
        wp_register_ability('content-mcp-bridge/list-posts', [
            'label'               => 'List posts',
            'description'         => 'Lists posts of a post type, filtered by WPML language, status or search term.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Post type slug, e.g. page, post. Default page.',
                    ],
                    'language'  => [
                        'type'        => 'string',
                        'description' => 'WPML language code, e.g. en, fi, sv. Omit for the default language.',
                    ],
                    'status'    => [
                        'type'        => 'string',
                        'description' => 'Post status filter: publish, draft, pending, private or any. Default any.',
                    ],
                    'search'    => [
                        'type'        => 'string',
                        'description' => 'Free-text search over titles and content.',
                    ],
                    'page'      => [
                        'type'        => 'integer',
                        'description' => 'Result page number, starting from 1.',
                    ],
                    'per_page'  => [
                        'type'        => 'integer',
                        'description' => 'Results per page, 1-100. Default 20.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'       => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                    'posts'       => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'           => ['type' => 'integer'],
                                'title'        => ['type' => 'string'],
                                'status'       => ['type' => 'string'],
                                'language'     => ['type' => 'string'],
                                'url'          => ['type' => 'string'],
                                'modified_gmt' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-posts', [$this, 'listPosts']),
            'permission_callback' => function (array $input): bool {
                $postType = $input['post_type'] ?? 'page';

                return current_user_can('edit_posts') && Settings::isPostTypeAllowed($postType);
            },
            'meta'                => [
                'annotations' => [
                    'readonly'   => true,
                    'idempotent' => true,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    private function registerGetPost(): void {
        wp_register_ability('content-mcp-bridge/get-post', [
            'label'               => 'Get post content',
            'description'         => 'Returns a post for editing: title, body, excerpt, status, language, meta.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id'      => [
                        'type'        => 'integer',
                        'description' => 'ID of the post to read.',
                    ],
                    'include_meta' => [
                        'type'        => 'boolean',
                        'description' => 'When true, includes text custom fields. Structural fields are excluded.',
                    ],
                ],
                'required'             => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'                => ['type' => 'integer'],
                    'type'              => ['type' => 'string'],
                    'status'            => ['type' => 'string'],
                    'language'          => ['type' => 'string'],
                    'title'             => ['type' => 'string'],
                    'content'           => ['type' => 'string'],
                    'excerpt'           => ['type' => 'string'],
                    'url'               => ['type' => 'string'],
                    'featured_image_id' => ['type' => 'integer'],
                    'has_acf_content'   => ['type' => 'boolean'],
                    'meta'              => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-post', [$this, 'getPost']),
            'permission_callback' => function (array $input): bool {
                $postId = (int)(    $input['post_id'] ?? 0    );

                return current_user_can('edit_post', $postId) && Settings::isPostTypeAllowed((string)get_post_type($postId));
            },
            'meta'                => [
                'annotations' => [
                    'readonly'   => true,
                    'idempotent' => true,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    private function registerUpdatePost(): void {
        wp_register_ability('content-mcp-bridge/update-post', [
            'label'               => 'Update post',
            'description'         => 'Updates title, body, excerpt, status or custom fields of a post by its ID.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the post (language version) to update.',
                    ],
                    'title'   => [
                        'type'        => 'string',
                        'description' => 'New post title.',
                    ],
                    'content' => [
                        'type'        => 'string',
                        'description' => 'New post body (HTML allowed).',
                    ],
                    'excerpt' => [
                        'type'        => 'string',
                        'description' => 'New post excerpt.',
                    ],
                    'status'  => [
                        'type'        => 'string',
                        'enum'        => [
                            'publish',
                            'draft',
                            'pending',
                            'private',
                        ],
                        'description' => 'New post status.',
                    ],
                    'meta'    => [
                        'type'        => 'object',
                        'description' => 'Text custom fields as string key-value pairs. Cannot restructure repeaters.',
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
                    'url'            => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-post', [$this, 'updatePost']),
            'permission_callback' => function (array $input): bool {
                $postId = (int)(    $input['post_id'] ?? 0    );

                return current_user_can('edit_post', $postId) && Settings::isPostTypeAllowed((string)get_post_type($postId));
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

    public function listPosts(array $input) {
        $postType = $input['post_type'] ?? 'page';
        $language = $input['language'] ?? '';
        $perPage  = min(max((int)(    $input['per_page'] ?? 20    ), 1), 100);

        if (!post_type_exists($postType)) {
            return new WP_Error('invalid_post_type', "Post type '{$postType}' does not exist.");
        }

        $previousLanguage = '';

        if ($language && Wpml::isEnabled()) {
            if (!array_key_exists($language, Wpml::getAllActiveLanguages())) {
                return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
            }

            $previousLanguage = Wpml::getCurrentLanguage();
            Wpml::switchLanguage($language);
        }

        $query = new WP_Query([
            'post_type'      => $postType,
            'post_status'    => $input['status'] ?? 'any',
            's'              => $input['search'] ?? '',
            'paged'          => max((int)(    $input['page'] ?? 1    ), 1),
            'posts_per_page' => $perPage,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);

        $posts = [];

        foreach ($query->posts as $post) {
            $posts[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'status'       => $post->post_status, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'language'     => (string)Wpml::getPostLanguage($post->ID, $post->post_type), // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'url'          => (string)get_permalink($post->ID),
                'modified_gmt' => $post->post_modified_gmt, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            ];
        }

        if ($previousLanguage) {
            Wpml::switchLanguage($previousLanguage);
        }

        return [
            'total'       => (int)$query->found_posts, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'total_pages' => (int)$query->max_num_pages, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'posts'       => $posts,
        ];
    }

    public function getPost(array $input) {
        $postId = (int)(    $input['post_id'] ?? 0    );
        $post   = get_post($postId);

        if (!$post) {
            return new WP_Error('post_not_found', "Post {$postId} was not found.");
        }

        $result = [
            'id'                => $post->ID,
            'type'              => $post->post_type, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'status'            => $post->post_status, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'language'          => (string)Wpml::getPostLanguage($post->ID, $post->post_type), // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'title'             => $post->post_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'content'           => $post->post_content, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'excerpt'           => $post->post_excerpt, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'url'               => (string)get_permalink($post->ID),
            'featured_image_id' => (int)get_post_thumbnail_id($post->ID),
            'has_acf_content'   => false,
            'meta'              => new \stdClass(),
        ];

        $allMeta = get_post_meta($post->ID);
        $public  = [];

        foreach ($allMeta as $key => $values) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            $value = $values[0] ?? '';

            if (is_string($value) && !is_serialized($value)) {
                $public[$key] = $value;
            }
        }

        if (!trim($post->post_content) && $public) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            $result['has_acf_content'] = true;
        }

        if (!empty($input['include_meta'])) {
            $result['meta'] = (object)$public;
        }

        return $result;
    }

    public function updatePost(array $input) {
        $postId = (int)(    $input['post_id'] ?? 0    );
        $post   = get_post($postId);

        if (!$post) {
            return new WP_Error('post_not_found', "Post {$postId} was not found.");
        }

        $statusChange = isset($input['status']) ? (string)$input['status'] : '';

        if (in_array($statusChange, ['publish', 'private'], true)) {
            $postTypeObject = get_post_type_object($post->post_type); // phpcs:ignore Zend.NamingConventions.ValidVariableName

            if (!$postTypeObject || !current_user_can($postTypeObject->cap->publish_posts)) {
                return new WP_Error('cannot_publish', 'You are not allowed to publish posts of this type.');
            }
        }

        $updatedFields = [];
        $postFields    = [];

        if (isset($input['title'])) {
            $postFields['post_title'] = $input['title'];
            $updatedFields[]          = 'title';
        }

        if (isset($input['content'])) {
            $postFields['post_content'] = $input['content'];
            $updatedFields[]            = 'content';
        }

        if (isset($input['excerpt'])) {
            $postFields['post_excerpt'] = $input['excerpt'];
            $updatedFields[]            = 'excerpt';
        }

        if (isset($input['status'])) {
            $postFields['post_status'] = $input['status'];
            $updatedFields[]           = 'status';
        }

        if ($postFields) {
            $postFields['ID'] = $postId;
            $result           = wp_update_post($postFields, true);

            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (!empty($input['meta']) && is_array($input['meta'])) {
            foreach ($input['meta'] as $key => $value) {
                if (str_starts_with($key, '_') || is_protected_meta($key, 'post')) {
                    return new WP_Error('protected_meta', "Meta key '{$key}' is protected and cannot be updated.");
                }

                if (!is_string($value) && !is_numeric($value)) {
                    return new WP_Error('invalid_meta_value', "Meta key '{$key}' must have a string value.");
                }

                update_post_meta($postId, $key, $value);
                $updatedFields[] = "meta:{$key}";
            }
        }

        if (!$updatedFields) {
            return new WP_Error('nothing_to_update', 'No fields were provided to update.');
        }

        clean_post_cache($postId);

        return [
            'id'             => $postId,
            'updated_fields' => $updatedFields,
            'url'            => (string)get_permalink($postId),
        ];
    }
}
