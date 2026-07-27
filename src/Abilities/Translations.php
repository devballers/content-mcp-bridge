<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Integrations\Wpml;
use ContentMcpBridge\Settings;
use WP_Error;

class Translations implements AbilityGroup {
    public function registerReadOnly(): void {
        $this->registerGetPostTranslations();
    }

    public function registerWrite(): void {
        $this->registerCreatePostTranslation();
    }

    private function registerGetPostTranslations(): void {
        wp_register_ability('content-mcp-bridge/get-post-translations', [
            'label'               => 'Get post translations',
            'description'         => 'Returns all WPML language versions of a post with status, title and URL.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the post in any language.',
                    ],
                ],
                'required'             => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post_id'           => ['type' => 'integer'],
                    'post_type'         => ['type' => 'string'],
                    'original_language' => ['type' => 'string'],
                    'translations'      => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'language'      => ['type' => 'string'],
                                'language_name' => ['type' => 'string'],
                                'post_id'       => [
                                    'type' => [
                                        'integer',
                                        'null',
                                    ],
                                ],
                                'status'        => ['type' => 'string'],
                                'title'         => ['type' => 'string'],
                                'modified_gmt'  => ['type' => 'string'],
                                'url'           => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-post-translations', [$this, 'getPostTranslations']),
            'permission_callback' => function (array $input): bool {
                $postId = (int)(    $input['post_id'] ?? 0    );

                return current_user_can('edit_posts') && Settings::isPostTypeAllowed((string)get_post_type($postId));
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

    private function registerCreatePostTranslation(): void {
        wp_register_ability('content-mcp-bridge/create-post-translation', [
            'label'               => 'Create post translation',
            'description'         => 'Creates a WPML translation of a post, linked to the original translation group.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'source_post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the post to translate from.',
                    ],
                    'language'       => [
                        'type'        => 'string',
                        'description' => 'Target WPML language code, e.g. fi, sv, et.',
                    ],
                    'title'          => [
                        'type'        => 'string',
                        'description' => 'Translated title. Defaults to the source title.',
                    ],
                    'content'        => [
                        'type'        => 'string',
                        'description' => 'Translated body. Defaults to the source body.',
                    ],
                    'excerpt'        => [
                        'type'        => 'string',
                        'description' => 'Translated excerpt. Defaults to the source excerpt.',
                    ],
                    'status'         => [
                        'type'        => 'string',
                        'enum'        => [
                            'publish',
                            'draft',
                            'pending',
                            'private',
                        ],
                        'description' => 'Status of the new translation. Default draft.',
                    ],
                    'copy_meta'      => [
                        'type'        => 'boolean',
                        'description' => 'Copy custom fields (ACF sections) from the source. Default true.',
                    ],
                ],
                'required'             => [
                    'source_post_id',
                    'language',
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'          => ['type' => 'integer'],
                    'language'    => ['type' => 'string'],
                    'status'      => ['type' => 'string'],
                    'title'       => ['type' => 'string'],
                    'url'         => ['type' => 'string'],
                    'copied_meta' => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/create-post-translation', [$this, 'createPostTranslation']),
            'permission_callback' => function (array $input): bool {
                $sourceId = (int)(    $input['source_post_id'] ?? 0    );

                return current_user_can('edit_post', $sourceId) && Settings::isPostTypeAllowed((string)get_post_type($sourceId));
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
        ]);
    }

    public function getPostTranslations(array $input) {
        if (!Wpml::isEnabled()) {
            return new WP_Error('wpml_not_active', 'WPML is not active on this site.');
        }

        $postId = isset($input['post_id']) ? (int)$input['post_id'] : 0;
        $post   = get_post($postId);

        if (!$post) {
            return new WP_Error('post_not_found', "Post {$postId} was not found.");
        }

        $postType     = $post->post_type; // phpcs:ignore Zend.NamingConventions.ValidVariableName
        $translations = [];

        foreach (Wpml::getAllActiveLanguages() as $code => $language) {
            $languageName   = $language['native_name'] ?? $language['display_name'] ?? $code;
            $translatedId   = (int)apply_filters('wpml_object_id', $postId, $postType, false, $code);
            $translatedPost = $translatedId ? get_post($translatedId) : null;

            if ($translatedPost) {
                $translations[] = [
                    'language'      => $code,
                    'language_name' => $languageName,
                    'post_id'       => $translatedId,
                    'status'        => $translatedPost->post_status, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                    'title'         => get_the_title($translatedId),
                    'modified_gmt'  => $translatedPost->post_modified_gmt, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                    'url'           => (string)get_permalink($translatedId),
                ];
            } else {
                $translations[] = [
                    'language'      => $code,
                    'language_name' => $languageName,
                    'post_id'       => null,
                    'status'        => 'missing',
                    'title'         => '',
                    'modified_gmt'  => '',
                    'url'           => '',
                ];
            }
        }

        return [
            'post_id'           => $postId,
            'post_type'         => $postType,
            'original_language' => (string)Wpml::getPostLanguage($postId, $postType),
            'translations'      => $translations,
        ];
    }

    public function createPostTranslation(array $input) {
        $sourceId = (int)(    $input['source_post_id'] ?? 0    );
        $language = (string)(    $input['language'] ?? ''    );
        $status   = $input['status'] ?? 'draft';
        $source   = get_post($sourceId);

        $validationError = $this->validateTranslationRequest($source, $sourceId, $language, $status);

        if (is_wp_error($validationError)) {
            return $validationError;
        }

        $elementType      = 'post_'.$source->post_type; // phpcs:ignore Zend.NamingConventions.ValidVariableName
        $sourceLanguage   = (string)Wpml::getPostLanguage($sourceId, $source->post_type); // phpcs:ignore Zend.NamingConventions.ValidVariableName
        $translatedParent = $this->resolveTranslatedParent($source, $language);

        if (!$translatedParent && $source->post_parent && in_array($status, ['publish', 'private'], true)) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return new WP_Error(
                'missing_parent_translation',
                "Parent post {$source->post_parent} has no '{$language}' translation. Translate it first."
            );
        }

        $trid  = (int)apply_filters('wpml_element_trid', null, $sourceId, $elementType);
        $newId = wp_insert_post([
            'post_type'    => $source->post_type, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'post_title'   => $input['title'] ?? $source->post_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'post_content' => $input['content'] ?? $source->post_content, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'post_excerpt' => $input['excerpt'] ?? $source->post_excerpt, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'post_status'  => $status,
            'post_author'  => get_current_user_id(),
            'post_parent'  => $translatedParent,
            'menu_order'   => $source->menu_order, // phpcs:ignore Zend.NamingConventions.ValidVariableName
        ], true);

        if (is_wp_error($newId)) {
            return $newId;
        }

        $copiedMeta = 0;

        if ($input['copy_meta'] ?? true) {
            $copiedMeta = $this->copySourceMeta($sourceId, $newId);
        }

        $linked = $this->linkTranslation($newId, $elementType, $trid, $language, $sourceLanguage);

        if (is_wp_error($linked)) {
            return $linked;
        }

        clean_post_cache($newId);

        if (function_exists('icl_cache_clear')) {
            icl_cache_clear();
        }

        $created = get_post($newId);

        return [
            'id'          => $newId,
            'language'    => $language,
            'status'      => $created->post_status, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'title'       => $created->post_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'url'         => (string)get_permalink($newId),
            'copied_meta' => $copiedMeta,
        ];
    }

    private function validateTranslationRequest($source, int $sourceId, string $language, string $status) {
        if (!Wpml::isEnabled()) {
            return new WP_Error('wpml_not_active', 'WPML is not active on this site.');
        }

        if (!$source) {
            return new WP_Error('post_not_found', "Post {$sourceId} was not found.");
        }

        if (!array_key_exists($language, Wpml::getAllActiveLanguages())) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        if ($language === (string)Wpml::getPostLanguage($sourceId, $source->post_type)) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return new WP_Error('same_language', "Post {$sourceId} is already in language '{$language}'.");
        }

        $existing = (int)apply_filters('wpml_object_id', $sourceId, $source->post_type, false, $language); // phpcs:ignore Zend.NamingConventions.ValidVariableName

        if ($existing && $existing !== $sourceId && get_post($existing)) {
            return new WP_Error('translation_exists', "A '{$language}' translation already exists: post {$existing}.");
        }

        $postTypeObject = get_post_type_object($source->post_type); // phpcs:ignore Zend.NamingConventions.ValidVariableName

        if (!$postTypeObject || !current_user_can($postTypeObject->cap->create_posts)) {
            return new WP_Error('cannot_create', 'You are not allowed to create posts of this type.');
        }

        if (in_array($status, ['publish', 'private'], true) && !current_user_can($postTypeObject->cap->publish_posts)) {
            return new WP_Error('cannot_publish', 'You are not allowed to publish posts of this type.');
        }

        return true;
    }

    private function resolveTranslatedParent($source, string $language): int {
        if (!$source->post_parent) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return 0;
        }

        return (int)apply_filters('wpml_object_id', $source->post_parent, $source->post_type, false, $language); // phpcs:ignore Zend.NamingConventions.ValidVariableName
    }

    private function copySourceMeta(int $sourceId, int $targetId): int {
        $skipKeys = [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_trash_meta_status',
            '_wp_trash_meta_time',
            '_wp_desired_post_slug',
        ];

        $copied = 0;

        foreach (get_post_meta($sourceId) as $key => $values) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }

            foreach ($values as $value) {
                add_post_meta($targetId, $key, maybe_unserialize($value));
                $copied++;
            }
        }

        return $copied;
    }

    /**
     * Links the new post into the source post's WPML translation group.
     *
     * icl_translations can contain orphan rows (element_id NULL) for a trid+language
     * combination, left over from previously deleted translations. Calling
     * wpml_set_element_language_details then fails on the trid+language unique key,
     * so an existing orphan row is adopted instead.
     */
    private function linkTranslation(
        int $newId,
        string $elementType,
        int $trid,
        string $language,
        string $sourceLanguage
    ) {
        global $wpdb;

        $table = $wpdb->prefix.'icl_translations';
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT translation_id, element_id FROM {$table} WHERE trid = %d AND language_code = %s",
            $trid,
            $language
        ));

        if ($row && $row->element_id && (int)$row->element_id !== $newId) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            wp_delete_post($newId, true);

            return new WP_Error('translation_exists', "A '{$language}' translation already exists in this group.");
        }

        if ($row && !$row->element_id) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            $wpdb->delete($table, [
                'element_id'   => $newId,
                'element_type' => $elementType,
            ]);
            $updated = $wpdb->update(
                $table,
                [
                    'element_id'           => $newId,
                    'source_language_code' => $sourceLanguage,
                ],
                ['translation_id' => $row->translation_id] // phpcs:ignore Zend.NamingConventions.ValidVariableName
            );

            if ($updated === false) {
                wp_delete_post($newId, true);

                return new WP_Error('link_failed', 'Could not link the translation. The new post was removed.');
            }

            return true;
        }

        do_action('wpml_set_element_language_details', [
            'element_id'           => $newId,
            'element_type'         => $elementType,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $sourceLanguage,
        ]);

        $linkedRow = $wpdb->get_var($wpdb->prepare(
            "SELECT translation_id FROM {$table} WHERE element_id = %d AND element_type = %s AND trid = %d",
            $newId,
            $elementType,
            $trid
        ));

        if (!$linkedRow) {
            wp_delete_post($newId, true);

            return new WP_Error('link_failed', 'Could not link the translation. The new post was removed.');
        }

        return true;
    }
}
