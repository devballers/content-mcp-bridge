<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Integrations\Multilingual;
use ContentMcpBridge\Settings;
use WP_Error;
use WP_Query;

class Posts implements AbilityGroup
{
    public function registerReadOnly(): void
    {
        $this->registerListPosts();
        $this->registerGetPost();
    }

    public function registerWrite(): void
    {
        $this->registerCreatePost();
        $this->registerUpdatePost();
    }

    private function registerListPosts(): void
    {
        wp_register_ability(
            'content-mcp-bridge/list-posts', [
            'label'               => 'List posts',
            'description'         => 'Lists posts of a post type, filtered by language (WPML or Polylang), status or search term.',
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
                        'description' => 'Language code, e.g. en, fi, sv. Omit for the default language.',
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
            ]
        );
    }

    private function registerGetPost(): void
    {
        wp_register_ability(
            'content-mcp-bridge/get-post', [
            'label'               => 'Get post content',
            'description'         => 'Returns a post for editing: title, slug, body, excerpt, status, language, terms, meta.',
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
                    'slug'              => ['type' => 'string'],
                    'content'           => ['type' => 'string'],
                    'excerpt'           => ['type' => 'string'],
                    'url'               => ['type' => 'string'],
                    'featured_image_id' => ['type' => 'integer'],
                    'has_acf_content'   => ['type' => 'boolean'],
                    'terms'             => ['type' => 'object'],
                    'meta'              => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-post', [$this, 'getPost']),
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
            ]
        );
    }

    private function registerCreatePost(): void
    {
        wp_register_ability(
            'content-mcp-bridge/create-post', [
            'label'               => 'Create post',
            'description'         => 'Creates a new post of an enabled post type. Defaults to draft status.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Post type slug, e.g. page, post. Default page.',
                    ],
                    'title'     => [
                        'type'        => 'string',
                        'description' => 'Post title.',
                    ],
                    'slug'      => [
                        'type'        => 'string',
                        'description' => 'URL slug. Generated from the title when omitted. If already taken, WordPress appends a suffix; check the returned slug.',
                    ],
                    'content'   => [
                        'type'        => 'string',
                        'description' => 'Post body (HTML allowed).',
                    ],
                    'excerpt'   => [
                        'type'        => 'string',
                        'description' => 'Post excerpt.',
                    ],
                    'status'    => [
                        'type'        => 'string',
                        'enum'        => [
                            'publish',
                            'draft',
                            'pending',
                            'private',
                        ],
                        'description' => 'Post status. Default draft.',
                    ],
                    'language'  => [
                        'type'        => 'string',
                        'description' => 'Language code, e.g. en, et. Omit for the default language.',
                    ],
                    'parent_id' => [
                        'type'        => 'integer',
                        'description' => 'Parent post ID for hierarchical post types.',
                    ],
                    'meta'      => [
                        'type'        => 'object',
                        'description' => 'Text custom fields as string key-value pairs. Cannot restructure repeaters.',
                    ],
                    'terms'     => [
                        'type'        => 'object',
                        'description' => 'Taxonomy terms to assign, keyed by taxonomy slug, each an array of existing term IDs, slugs or names, e.g. {"category": ["rye-bread", 12]}. Use list-terms to discover terms.',
                    ],
                ],
                'required'             => ['title'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'       => ['type' => 'integer'],
                    'type'     => ['type' => 'string'],
                    'status'   => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'title'    => ['type' => 'string'],
                    'slug'     => ['type' => 'string'],
                    'url'      => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/create-post', [$this, 'createPost']),
            'permission_callback' => function (): bool {
                return current_user_can('edit_posts');
            },
            'meta'                => [
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
            ]
        );
    }

    private function registerUpdatePost(): void
    {
        wp_register_ability(
            'content-mcp-bridge/update-post', [
            'label'               => 'Update post',
            'description'         => 'Updates title, slug, body, excerpt, status, taxonomy terms or custom fields of a post by its ID.',
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
                    'slug'    => [
                        'type'        => 'string',
                        'description' => 'New URL slug. Changing a published post\'s slug changes its URL — old links keep working through WordPress\'s old-slug redirect. If the slug is already taken, WordPress appends a suffix; check the returned slug.',
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
                    'terms'   => [
                        'type'        => 'object',
                        'description' => 'Taxonomy terms to assign, keyed by taxonomy slug, each an array of existing term IDs, slugs or names. Replaces the post\'s current terms in each given taxonomy; other taxonomies are untouched.',
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
                    'slug'           => ['type' => 'string'],
                    'url'            => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-post', [$this, 'updatePost']),
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
            ]
        );
    }

    public function listPosts(array $input)
    {
        $postType = $input['post_type'] ?? 'page';
        $language = $input['language'] ?? '';
        $perPage  = min(max((int)(    $input['per_page'] ?? 20    ), 1), 100);

        if (!post_type_exists($postType)) {
            return new WP_Error('invalid_parameter', "Post type '{$postType}' does not exist.");
        }

        if (!Settings::isPostTypeAllowed($postType)) {
            return new WP_Error('permission_denied', "The '{$postType}' post type is not enabled for MCP access.");
        }

        $previousLanguage = '';

        if ($language && Multilingual::isEnabled()) {
            if (!array_key_exists($language, Multilingual::getAllActiveLanguages())) {
                return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
            }

            $previousLanguage = Multilingual::getCurrentLanguage();
            Multilingual::switchLanguage($language);
        }

        $query = new WP_Query(
            [
            'post_type'      => $postType,
            'post_status'    => $input['status'] ?? 'any',
            's'              => $input['search'] ?? '',
            'paged'          => max((int)(    $input['page'] ?? 1    ), 1),
            'posts_per_page' => $perPage,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            ] + Multilingual::languageQueryArgs($language)
        );

        $posts = [];

        foreach ($query->posts as $post) {
            $posts[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'status'       => $post->post_status, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'language'     => (string)Multilingual::getPostLanguage($post->ID, $post->post_type), // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'url'          => (string)get_permalink($post->ID),
                'modified_gmt' => $post->post_modified_gmt, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            ];
        }

        if ($previousLanguage) {
            Multilingual::switchLanguage($previousLanguage);
        }

        return [
            'total'       => (int)$query->found_posts, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'total_pages' => (int)$query->max_num_pages, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'posts'       => $posts,
        ];
    }

    public function getPost(array $input)
    {
        $post = PostGuard::resolve((int)(    $input['post_id'] ?? 0    ));

        if (is_wp_error($post)) {
            return $post;
        }

        $result = [
            'id'                => $post->ID,
            'type'              => $post->post_type, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'status'            => $post->post_status, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'language'          => (string)Multilingual::getPostLanguage($post->ID, $post->post_type), // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'title'             => $post->post_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'slug'              => $post->post_name, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'content'           => $post->post_content, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'excerpt'           => $post->post_excerpt, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'url'               => (string)get_permalink($post->ID),
            'featured_image_id' => (int)get_post_thumbnail_id($post->ID),
            'has_acf_content'   => false,
            'terms'             => new \stdClass(),
            'meta'              => new \stdClass(),
        ];

        $postTerms = [];

        foreach (get_object_taxonomies($post->post_type) as $taxonomy) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            $terms = get_the_terms($post->ID, $taxonomy);

            if (!$terms || is_wp_error($terms)) {
                continue;
            }

            $postTerms[$taxonomy] = array_map(
                static function ($term): array {
                    return [
                    'id'   => (int)$term->term_id, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                    'name' => $term->name,
                    'slug' => $term->slug,
                    ];
                }, array_values($terms)
            );
        }

        if ($postTerms) {
            $result['terms'] = (object)$postTerms;
        }

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
    
    private function resolveTermsInput(array $termsInput, string $postType, string $language)
    {
        $previousLanguage = '';

        if ($language && Multilingual::isEnabled()) {
            $previousLanguage = Multilingual::getCurrentLanguage();
            Multilingual::switchLanguage($language);
        }

        $resolved = [];
        $error    = null;

        foreach ($termsInput as $taxonomy => $terms) {
            $taxonomyObject = Taxonomies::resolve((string)$taxonomy);

            if (is_wp_error($taxonomyObject)) {
                $error = $taxonomyObject;

                break;
            }

            if (!in_array($postType, (array)$taxonomyObject->object_type, true)) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
                $error = new WP_Error(
                    'invalid_parameter',
                    "Taxonomy '{$taxonomyObject->name}' is not attached to post type '{$postType}'."
                );

                break;
            }

            if (!current_user_can($taxonomyObject->cap->assign_terms)) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
                $error = new WP_Error(
                    'cannot_assign_terms',
                    "You are not allowed to assign '{$taxonomyObject->name}' terms."
                );

                break;
            }

            if (!is_array($terms)) {
                $error = new WP_Error(
                    'invalid_parameter',
                    "Terms for taxonomy '{$taxonomyObject->name}' must be an array of term IDs, slugs or names."
                );

                break;
            }

            $termIds = Taxonomies::resolveTermIds($taxonomyObject->name, $terms);

            if (is_wp_error($termIds)) {
                $error = $termIds;

                break;
            }

            $resolved[$taxonomyObject->name] = $termIds;
        }

        if ($previousLanguage) {
            Multilingual::switchLanguage($previousLanguage);
        }

        return $error ?? $resolved;
    }

    public function createPost(array $input)
    {
        $postType = $input['post_type'] ?? 'page';
        $title    = trim((string)(    $input['title'] ?? ''    ));
        $status   = $input['status'] ?? 'draft';
        $language = (string)(    $input['language'] ?? ''    );
        $parentId = (int)(    $input['parent_id'] ?? 0    );

        if ($title === '') {
            return new WP_Error('invalid_parameter', 'title is required.');
        }

        if (!post_type_exists($postType)) {
            return new WP_Error('invalid_parameter', "Post type '{$postType}' does not exist.");
        }

        if (!Settings::isPostTypeAllowed($postType)) {
            return new WP_Error('permission_denied', "The '{$postType}' post type is not enabled for MCP access.");
        }

        $postTypeObject = get_post_type_object($postType);

        if (!$postTypeObject || !current_user_can($postTypeObject->cap->create_posts)) {
            return new WP_Error('cannot_create', 'You are not allowed to create posts of this type.');
        }

        if (in_array($status, ['publish', 'private'], true) && !current_user_can($postTypeObject->cap->publish_posts)) {
            return new WP_Error('cannot_publish', 'You are not allowed to publish posts of this type.');
        }

        if ($language && Multilingual::isEnabled() && !array_key_exists($language, Multilingual::getAllActiveLanguages())) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        if ($parentId) {
            $parent = get_post($parentId);

            if (!$parent || $parent->post_type !== $postType) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
                return new WP_Error('invalid_parent', "Parent post {$parentId} was not found for post type '{$postType}'.");
            }

            if (!Settings::isPostTypeAllowed($parent->post_type)) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
                return new WP_Error('permission_denied', "The '{$parent->post_type}' post type is not enabled for MCP access.");
            }
        }

        $resolvedTerms = [];

        if (!empty($input['terms']) && is_array($input['terms'])) {
            $resolvedTerms = $this->resolveTermsInput($input['terms'], $postType, $language);

            if (is_wp_error($resolvedTerms)) {
                return $resolvedTerms;
            }
        }

        $previousLanguage = '';

        if ($language && Multilingual::isEnabled()) {
            $previousLanguage = Multilingual::getCurrentLanguage();
            Multilingual::switchLanguage($language);
        }

        $newId = wp_insert_post(
            [
            'post_type'    => $postType,
            'post_title'   => $title,
            'post_name'    => isset($input['slug']) ? sanitize_title((string)$input['slug']) : '',
            'post_content' => $input['content'] ?? '',
            'post_excerpt' => $input['excerpt'] ?? '',
            'post_status'  => $status,
            'post_author'  => get_current_user_id(),
            'post_parent'  => $parentId,
            ], true
        );

        if ($previousLanguage) {
            Multilingual::switchLanguage($previousLanguage);
        }

        if (is_wp_error($newId)) {
            return $newId;
        }

        if ($language && Multilingual::isEnabled()) {
            Multilingual::setNewPostLanguage($newId, $postType, $language);
        }

        if (!empty($input['meta']) && is_array($input['meta'])) {
            foreach ($input['meta'] as $key => $value) {
                if (str_starts_with($key, '_') || is_protected_meta($key, 'post')) {
                    wp_delete_post($newId, true);

                    return new WP_Error('protected_meta', "Meta key '{$key}' is protected and cannot be set.");
                }

                if (!is_string($value) && !is_numeric($value)) {
                    wp_delete_post($newId, true);

                    return new WP_Error('invalid_meta_value', "Meta key '{$key}' must have a string value.");
                }

                update_post_meta($newId, $key, $value);
            }
        }

        foreach ($resolvedTerms as $taxonomy => $termIds) {
            $assigned = wp_set_object_terms($newId, $termIds, $taxonomy);

            if (is_wp_error($assigned)) {
                wp_delete_post($newId, true);

                return $assigned;
            }
        }

        clean_post_cache($newId);

        $created = get_post($newId);

        return [
            'id'       => $newId,
            'type'     => $created->post_type, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'status'   => $created->post_status, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'language' => (string)Multilingual::getPostLanguage($newId, $postType) ?: $language,
            'title'    => $created->post_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'slug'     => $created->post_name, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'url'      => (string)get_permalink($newId),
        ];
    }

    public function updatePost(array $input)
    {
        $post = PostGuard::resolve((int)(    $input['post_id'] ?? 0    ));

        if (is_wp_error($post)) {
            return $post;
        }

        $postId       = $post->ID; // phpcs:ignore Zend.NamingConventions.ValidVariableName
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

        if (isset($input['slug'])) {
            $slug = sanitize_title((string)$input['slug']);

            if ($slug === '') {
                return new WP_Error('invalid_parameter', 'slug cannot be empty.');
            }

            $postFields['post_name'] = $slug;
            $updatedFields[]         = 'slug';
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

        if (!empty($input['terms']) && is_array($input['terms'])) {
            $postLanguage  = (string)Multilingual::getPostLanguage($postId, $post->post_type); // phpcs:ignore Zend.NamingConventions.ValidVariableName
            $resolvedTerms = $this->resolveTermsInput($input['terms'], $post->post_type, $postLanguage); // phpcs:ignore Zend.NamingConventions.ValidVariableName

            if (is_wp_error($resolvedTerms)) {
                return $resolvedTerms;
            }

            foreach ($resolvedTerms as $taxonomy => $termIds) {
                $assigned = wp_set_object_terms($postId, $termIds, $taxonomy);

                if (is_wp_error($assigned)) {
                    return $assigned;
                }

                $updatedFields[] = "terms:{$taxonomy}";
            }
        }

        if (!$updatedFields) {
            return new WP_Error('nothing_to_update', 'No fields were provided to update.');
        }

        clean_post_cache($postId);

        return [
            'id'             => $postId,
            'updated_fields' => $updatedFields,
            'slug'           => (string)get_post_field('post_name', $postId),
            'url'            => (string)get_permalink($postId),
        ];
    }
}
