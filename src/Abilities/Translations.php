<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Integrations\Wpml;
use ContentMcpBridge\Settings;
use WP_Error;

class Translations implements AbilityGroup {
    public function registerReadOnly(): void {
        $this->registerGetPostTranslations();
        $this->registerGetTermTranslations();
    }

    public function registerWrite(): void {
        $this->registerCreatePostTranslation();
        $this->registerCreateTermTranslation();
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

    private function registerCreatePostTranslation(): void {
        wp_register_ability('content-mcp-bridge/create-post-translation', [
            'label'               => 'Create post translation',
            'description'         => 'Creates a WPML translation of a post, linked to the original translation group. With backfill_meta true it also repairs an existing translation whose custom fields are missing, instead of failing.',
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
                    'backfill_meta'  => [
                        'type'        => 'boolean',
                        'description' => 'When a translation already exists, refill its custom fields from the source instead of failing with translation_exists. Only meta is written; the existing title, body and status are left alone. Default false.',
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
                    'backfilled'  => ['type' => 'boolean'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/create-post-translation', [$this, 'createPostTranslation']),
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
        ]);
    }

    private function registerGetTermTranslations(): void {
        wp_register_ability('content-mcp-bridge/get-term-translations', [
            'label'               => 'Get term translations',
            'description'         => 'Returns all WPML language versions of a taxonomy term with name, slug, description and parent.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'term_id'  => [
                        'type'        => 'integer',
                        'description' => 'ID of the term in any language.',
                    ],
                    'taxonomy' => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug, e.g. destination, category.',
                    ],
                ],
                'required'             => ['term_id', 'taxonomy'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'term_id'           => ['type' => 'integer'],
                    'taxonomy'          => ['type' => 'string'],
                    'original_language' => ['type' => 'string'],
                    'translations'      => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'language'      => ['type' => 'string'],
                                'language_name' => ['type' => 'string'],
                                'term_id'       => [
                                    'type' => [
                                        'integer',
                                        'null',
                                    ],
                                ],
                                'status'        => ['type' => 'string'],
                                'name'          => ['type' => 'string'],
                                'slug'          => ['type' => 'string'],
                                'description'   => ['type' => 'string'],
                                'parent_id'     => ['type' => 'integer'],
                                'count'         => ['type' => 'integer'],
                                'url'           => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-term-translations', [$this, 'getTermTranslations']),
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

    private function registerCreateTermTranslation(): void {
        wp_register_ability('content-mcp-bridge/create-term-translation', [
            'label'               => 'Create term translation',
            'description'         => 'Creates a WPML translation of a taxonomy term, linked to the original translation group. The translated parent is resolved automatically so hierarchy is preserved. Safe to re-run: an existing translation is updated instead of duplicated.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'term_id'     => [
                        'type'        => 'integer',
                        'description' => 'ID of the term to translate from.',
                    ],
                    'taxonomy'    => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug, e.g. destination, category.',
                    ],
                    'language'    => [
                        'type'        => 'string',
                        'description' => 'Target WPML language code, e.g. ru, fi, et.',
                    ],
                    'name'        => [
                        'type'        => 'string',
                        'description' => 'Translated term name. Defaults to the source name.',
                    ],
                    'slug'        => [
                        'type'        => 'string',
                        'description' => 'Translated slug. Generated from the translated name when omitted.',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Translated term description. Defaults to the source description.',
                    ],
                ],
                'required'             => [
                    'term_id',
                    'taxonomy',
                    'language',
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'term_id'   => ['type' => 'integer'],
                    'taxonomy'  => ['type' => 'string'],
                    'language'  => ['type' => 'string'],
                    'name'      => ['type' => 'string'],
                    'slug'      => ['type' => 'string'],
                    'parent_id' => ['type' => 'integer'],
                    'created'   => ['type' => 'boolean'],
                    'url'       => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/create-term-translation', [$this, 'createTermTranslation']),
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

    public function getPostTranslations(array $input) {
        if (!Wpml::isEnabled()) {
            return new WP_Error('wpml_not_active', 'WPML is not active on this site.');
        }

        $post = PostGuard::resolve(isset($input['post_id']) ? (int)$input['post_id'] : 0, 'edit_post');

        if (is_wp_error($post)) {
            return $post;
        }

        $postId       = $post->ID; // phpcs:ignore Zend.NamingConventions.ValidVariableName
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

        $backfill        = !empty($input['backfill_meta']);
        $validationError = $this->validateTranslationRequest($source, $sourceId, $language, $status, $backfill);

        if (is_wp_error($validationError)) {
            return $validationError;
        }

        // Repairing an existing translation is a different job from creating
        // one: the translated title, body and status are somebody's work and
        // must survive, so only the custom fields are rewritten.
        if ($backfill) {
            $existingId = (int)apply_filters('wpml_object_id', $sourceId, $source->post_type, false, $language); // phpcs:ignore Zend.NamingConventions.ValidVariableName

            if ($existingId && $existingId !== $sourceId && get_post($existingId)) {
                return $this->backfillTranslationMeta($sourceId, $existingId, $language);
            }
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
            'backfilled'  => false,
        ];
    }

    /**
     * Refills an existing translation's custom fields from its source.
     *
     * Existing values are replaced rather than appended to: copySourceMeta
     * uses add_post_meta, which on a post that already has the key would
     * leave two values behind and make ACF read the stale one.
     *
     * @return array<string, mixed>
     */
    private function backfillTranslationMeta(int $sourceId, int $targetId, string $language): array {
        $skipKeys = [
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_trash_meta_status',
            '_wp_trash_meta_time',
            '_wp_desired_post_slug',
        ];

        $written = 0;

        foreach (get_post_meta($sourceId) as $key => $values) {
            if (in_array($key, $skipKeys, true)) {
                continue;
            }

            delete_post_meta($targetId, $key);

            foreach ($values as $value) {
                add_post_meta($targetId, $key, maybe_unserialize($value));
                $written++;
            }
        }

        clean_post_cache($targetId);

        if (function_exists('icl_cache_clear')) {
            icl_cache_clear();
        }

        $target = get_post($targetId);

        return [
            'id'          => $targetId,
            'language'    => $language,
            'status'      => $target->post_status, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'title'       => $target->post_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'url'         => (string)get_permalink($targetId),
            'copied_meta' => $written,
            'backfilled'  => true,
        ];
    }

    public function getTermTranslations(array $input) {
        if (!Wpml::isEnabled()) {
            return new WP_Error('wpml_not_active', 'WPML is not active on this site.');
        }

        $taxonomyObject = Taxonomies::resolve((string)(    $input['taxonomy'] ?? ''    ));

        if (is_wp_error($taxonomyObject)) {
            return $taxonomyObject;
        }

        $taxonomy = $taxonomyObject->name;
        $termId   = (int)(    $input['term_id'] ?? 0    );
        $term     = self::rawTerm($termId, $taxonomy);

        if (!$term) {
            return new WP_Error('term_not_found', "Term {$termId} was not found in taxonomy '{$taxonomy}'.");
        }

        $translations = [];

        foreach (Wpml::getAllActiveLanguages() as $code => $language) {
            $languageName   = $language['native_name'] ?? $language['display_name'] ?? $code;
            $translatedId   = self::findTranslatedTermId($termId, $taxonomy, $code);
            $translatedTerm = $translatedId ? self::rawTerm($translatedId, $taxonomy) : null;

            if ($translatedTerm) {
                $translations[] = [
                    'language'      => $code,
                    'language_name' => $languageName,
                    'term_id'       => $translatedId,
                    'status'        => 'translated',
                    'name'          => $translatedTerm->name,
                    'slug'          => $translatedTerm->slug,
                    'description'   => (string)$translatedTerm->description,
                    'parent_id'     => (int)$translatedTerm->parent,
                    'count'         => (int)$translatedTerm->count,
                    'url'           => self::termUrlInLanguage($translatedId, $taxonomy, $code),
                ];
            } else {
                $translations[] = [
                    'language'      => $code,
                    'language_name' => $languageName,
                    'term_id'       => null,
                    'status'        => 'missing',
                    'name'          => '',
                    'slug'          => '',
                    'description'   => '',
                    'parent_id'     => 0,
                    'count'         => 0,
                    'url'           => '',
                ];
            }
        }

        return [
            'term_id'           => $termId,
            'taxonomy'          => $taxonomy,
            'original_language' => self::termLanguage($termId, $taxonomy),
            'translations'      => $translations,
        ];
    }

    public function createTermTranslation(array $input) {
        if (!Wpml::isEnabled()) {
            return new WP_Error('wpml_not_active', 'WPML is not active on this site.');
        }

        $taxonomyObject = Taxonomies::resolve((string)(    $input['taxonomy'] ?? ''    ));

        if (is_wp_error($taxonomyObject)) {
            return $taxonomyObject;
        }

        if (!current_user_can($taxonomyObject->cap->edit_terms)) {
            return new WP_Error('cannot_create', 'You are not allowed to create terms in this taxonomy.');
        }

        $taxonomy = $taxonomyObject->name;
        $sourceId = (int)(    $input['term_id'] ?? 0    );
        $language = (string)(    $input['language'] ?? ''    );
        $source   = self::rawTerm($sourceId, $taxonomy);

        if (!$source) {
            return new WP_Error('term_not_found', "Term {$sourceId} was not found in taxonomy '{$taxonomy}'.");
        }

        if (!array_key_exists($language, Wpml::getAllActiveLanguages())) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        $sourceLanguage = self::termLanguage($sourceId, $taxonomy);

        if ($language === $sourceLanguage) {
            return new WP_Error('same_language', "Term {$sourceId} is already in language '{$language}'.");
        }

        $name        = trim((string)(    $input['name'] ?? $source->name    ));
        $description = (string)(    $input['description'] ?? $source->description    );
        $slug        = (string)(    $input['slug'] ?? ''    );

        if ($name === '') {
            return new WP_Error('invalid_parameter', 'name cannot be empty.');
        }

        $parentId = $this->resolveTranslatedTermParent($source, $taxonomy, $language);

        if (is_wp_error($parentId)) {
            return $parentId;
        }

        $existingId = self::findTranslatedTermId($sourceId, $taxonomy, $language);
        $existing   = $existingId && $existingId !== $sourceId ? self::rawTerm($existingId, $taxonomy) : null;

        // Both branches run inside the target language: WPML's auto-adjust
        // rewrites every get_term() call to the current language's version of
        // the term, so updating term 1402 (ru) from an et request context
        // would silently operate on its et counterpart instead — and creating
        // would collide with the ru slug that "wasn't there".
        $previousLanguage = Wpml::getCurrentLanguage();
        Wpml::switchLanguage($language);

        if ($existing) {
            $args = [
                'name'        => $name,
                'description' => $description,
                'parent'      => $parentId,
            ];

            // An omitted slug means "leave the existing one alone" — passing
            // '' would make wp_update_term regenerate it from the name.
            if ($slug !== '') {
                $args['slug'] = $slug;
            }

            $updated = wp_update_term($existingId, $taxonomy, $args);

            if ($previousLanguage) {
                Wpml::switchLanguage($previousLanguage);
            }

            if (is_wp_error($updated)) {
                return $updated;
            }

            return $this->termTranslationResult((int)$updated['term_id'], $taxonomy, $language, false);
        }

        $created = wp_insert_term($name, $taxonomy, [
            'slug'        => $slug,
            'description' => $description,
            'parent'      => $parentId,
        ]);

        if ($previousLanguage) {
            Wpml::switchLanguage($previousLanguage);
        }

        if (is_wp_error($created)) {
            return $created;
        }

        $newId = (int)$created['term_id'];
        $trid  = (int)apply_filters(
            'wpml_element_trid',
            null,
            self::termTaxonomyId($sourceId, $taxonomy),
            'tax_'.$taxonomy
        );

        do_action('wpml_set_element_language_details', [
            'element_id'           => (int)$created['term_taxonomy_id'],
            'element_type'         => 'tax_'.$taxonomy,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $sourceLanguage ?: null,
        ]);

        clean_term_cache($newId, $taxonomy);

        if (function_exists('icl_cache_clear')) {
            icl_cache_clear();
        }

        return $this->termTranslationResult($newId, $taxonomy, $language, true);
    }

    /**
     * WPML's term APIs disagree about what an "element id" is, and getting it
     * wrong fails silently — a lookup returns nothing, the caller concludes no
     * translation exists and tries to create a duplicate:
     *
     *   wpml_object_id                    term_id,          bare taxonomy name
     *   wpml_element_language_code        term_taxonomy_id, bare taxonomy name
     *   wpml_element_trid                 term_taxonomy_id, tax_<taxonomy>
     *   wpml_set_element_language_details term_taxonomy_id, tax_<taxonomy>
     *
     * icl_translations.element_id holds the term_taxonomy_id for terms, so
     * everything except wpml_object_id needs the conversion below.
     */
    private static function termTaxonomyId(int $termId, string $taxonomy): int {
        $term = self::rawTerm($termId, $taxonomy);

        return $term ? (int)$term->term_taxonomy_id : 0; // phpcs:ignore Zend.NamingConventions.ValidVariableName
    }

    /**
     * Resolves a term's translation in a language.
     *
     * wpml_object_id alone isn't enough: it returns null for any taxonomy not
     * set to "translatable" in WPML's settings, even when icl_translations
     * already holds a perfectly good translation group for it. Treating that
     * null as "no translation exists" makes the caller try to create one and
     * hit a duplicate-slug error, so the translation group is read directly
     * as a fallback.
     */
    public static function findTranslatedTermId(int $sourceId, string $taxonomy, string $language): int {
        $viaApi = (int)apply_filters('wpml_object_id', $sourceId, $taxonomy, false, $language);

        if ($viaApi && $viaApi !== $sourceId) {
            return $viaApi;
        }

        global $wpdb;

        $sourceTtid = self::termTaxonomyId($sourceId, $taxonomy);

        if (!$sourceTtid) {
            return 0;
        }

        $table = $wpdb->prefix.'icl_translations';
        $ttid  = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT target.element_id
                FROM {$table} AS source
                JOIN {$table} AS target
                    ON target.trid = source.trid
                    AND target.element_type = source.element_type
                WHERE source.element_id = %d
                    AND source.element_type = %s
                    AND target.language_code = %s
                    AND target.element_id IS NOT NULL",
            $sourceTtid,
            'tax_'.$taxonomy,
            $language
        ));

        if (!$ttid) {
            return 0;
        }

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
            $ttid
        ));
    }

    private static function termLanguage(int $termId, string $taxonomy): string {
        return (string)apply_filters('wpml_element_language_code', null, [
            'element_id'   => self::termTaxonomyId($termId, $taxonomy),
            'element_type' => $taxonomy,
        ]);
    }

    /**
     * @param \WP_Term $source
     * @return int|WP_Error
     */
    private function resolveTranslatedTermParent($source, string $taxonomy, string $language) {
        if (!$source->parent) {
            return 0;
        }

        $translatedParent = self::findTranslatedTermId((int)$source->parent, $taxonomy, $language);

        if (!$translatedParent || $translatedParent === (int)$source->parent) {
            return new WP_Error(
                'missing_parent_translation',
                "Parent term {$source->parent} has no '{$language}' translation. Translate it first."
            );
        }

        return $translatedParent;
    }

    /**
     * @return array<string, mixed>
     */
    private function termTranslationResult(int $termId, string $taxonomy, string $language, bool $created): array {
        $term = self::rawTerm($termId, $taxonomy);

        return [
            'term_id'   => $termId,
            'taxonomy'  => $taxonomy,
            'language'  => $language,
            'name'      => $term->name,
            'slug'      => $term->slug,
            'parent_id' => (int)$term->parent,
            'created'   => $created,
            'url'       => self::termUrlInLanguage($termId, $taxonomy, $language),
        ];
    }

    /**
     * Reads a term straight from the terms tables, bypassing get_term().
     *
     * WPML's "adjust ids" feature rewrites get_term() results to the current
     * language — asking for the ru term from an et request context silently
     * returns the et term — so any code that must see the exact term it asked
     * for (translation detection, reporting a translation's own name/slug)
     * has to go around it.
     */
    public static function rawTerm(int $termId, string $taxonomy): ?object {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT t.term_id, tt.term_taxonomy_id, t.name, t.slug, tt.description, tt.parent, tt.count
                FROM {$wpdb->terms} AS t
                JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id
                WHERE t.term_id = %d AND tt.taxonomy = %s",
            $termId,
            $taxonomy
        )) ?: null;
    }

    /**
     * get_term_link() resolves through get_term(), so it needs the right
     * language context for the same auto-adjust reason as rawTerm().
     */
    private static function termUrlInLanguage(int $termId, string $taxonomy, string $language): string {
        $previousLanguage = Wpml::getCurrentLanguage();
        Wpml::switchLanguage($language);

        $link = get_term_link($termId, $taxonomy);

        if ($previousLanguage) {
            Wpml::switchLanguage($previousLanguage);
        }

        return is_wp_error($link) ? '' : (string)$link;
    }

    private function validateTranslationRequest($source, int $sourceId, string $language, string $status, bool $allowExisting = false) {
        if (!Wpml::isEnabled()) {
            return new WP_Error('wpml_not_active', 'WPML is not active on this site.');
        }

        if (!$source) {
            return new WP_Error('post_not_found', "Post {$sourceId} was not found.");
        }

        if (!current_user_can('edit_post', $sourceId)) {
            return new WP_Error('permission_denied', 'You do not have permission to translate this post.');
        }

        if (!Settings::isPostTypeAllowed($source->post_type)) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return new WP_Error(
                'permission_denied',
                "The '{$source->post_type}' post type is not enabled for MCP access." // phpcs:ignore Zend.NamingConventions.ValidVariableName
            );
        }

        if (!array_key_exists($language, Wpml::getAllActiveLanguages())) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        if ($language === (string)Wpml::getPostLanguage($sourceId, $source->post_type)) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return new WP_Error('same_language', "Post {$sourceId} is already in language '{$language}'.");
        }

        $existing = (int)apply_filters('wpml_object_id', $sourceId, $source->post_type, false, $language); // phpcs:ignore Zend.NamingConventions.ValidVariableName

        if ($existing && $existing !== $sourceId && get_post($existing) && !$allowExisting) {
            return new WP_Error(
                'translation_exists',
                "A '{$language}' translation already exists: post {$existing}. Pass backfill_meta true to refill its custom fields instead."
            );
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
