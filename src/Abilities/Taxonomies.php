<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Integrations\Multilingual;
use ContentMcpBridge\Settings;
use WP_Error;
use WP_Taxonomy;

class Taxonomies implements AbilityGroup {
    public function registerReadOnly(): void {
        $this->registerListTaxonomies();
        $this->registerListTerms();
    }

    public function registerWrite(): void {
        $this->registerCreateTerm();
        $this->registerUpdateTerm();
        $this->registerDeleteTerm();
    }

    public static function resolve(string $taxonomy) {
        if ($taxonomy === '') {
            return new WP_Error('invalid_parameter', 'taxonomy is required.');
        }

        $taxonomyObject = get_taxonomy($taxonomy);

        if (!$taxonomyObject) {
            return new WP_Error('invalid_parameter', "Taxonomy '{$taxonomy}' does not exist.");
        }

        $allowed = array_filter(
            (array)$taxonomyObject->object_type, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            [Settings::class, 'isPostTypeAllowed']
        );

        if (!$allowed) {
            return new WP_Error(
                'permission_denied',
                "Taxonomy '{$taxonomy}' is not attached to any post type enabled for MCP access."
            );
        }

        return $taxonomyObject;
    }

    public static function resolveTermIds(string $taxonomy, array $terms) {
        $termIds = [];

        foreach ($terms as $identifier) {
            if (is_int($identifier) || (is_string($identifier) && ctype_digit($identifier))) {
                $term = get_term((int)$identifier, $taxonomy);

                if (!$term || is_wp_error($term)) {
                    return new WP_Error('term_not_found', "Term {$identifier} was not found in taxonomy '{$taxonomy}'.");
                }

                $termIds[] = (int)$term->term_id; // phpcs:ignore Zend.NamingConventions.ValidVariableName

                continue;
            }

            if (!is_string($identifier) || trim($identifier) === '') {
                return new WP_Error('invalid_parameter', 'Terms must be term IDs, slugs or names.');
            }

            $term = get_term_by('slug', $identifier, $taxonomy) ?: get_term_by('name', $identifier, $taxonomy);

            if (!$term) {
                return new WP_Error(
                    'term_not_found',
                    "Term '{$identifier}' was not found in taxonomy '{$taxonomy}'. Use list-terms to see available terms or create-term to add it."
                );
            }

            $termIds[] = (int)$term->term_id; // phpcs:ignore Zend.NamingConventions.ValidVariableName
        }

        return array_values(array_unique($termIds));
    }

    private function registerListTaxonomies(): void {
        wp_register_ability('content-mcp-bridge/list-taxonomies', [
            'label'               => 'List taxonomies',
            'description'         => 'Lists taxonomies attached to the post types enabled for MCP access.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Only taxonomies attached to this post type. Omit for all enabled post types.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'taxonomies' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'name'         => ['type' => 'string'],
                                'label'        => ['type' => 'string'],
                                'hierarchical' => ['type' => 'boolean'],
                                'post_types'   => [
                                    'type'  => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-taxonomies', [$this, 'listTaxonomies']),
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

    private function registerListTerms(): void {
        wp_register_ability('content-mcp-bridge/list-terms', [
            'label'               => 'List terms',
            'description'         => 'Lists terms of a taxonomy, optionally filtered by language (WPML or Polylang) or search term. Set with_translations to also get each term\'s description and its translation status per active language.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'taxonomy'          => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug, e.g. category, product-category.',
                    ],
                    'language'          => [
                        'type'        => 'string',
                        'description' => 'Language code, e.g. en, et. Omit for the default language.',
                    ],
                    'search'            => [
                        'type'        => 'string',
                        'description' => 'Free-text search over term names.',
                    ],
                    'with_translations' => [
                        'type'        => 'boolean',
                        'description' => 'Include the term description and a per-language translation map. Default false.',
                    ],
                ],
                'required'             => ['taxonomy'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'terms' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'           => ['type' => 'integer'],
                                'name'         => ['type' => 'string'],
                                'slug'         => ['type' => 'string'],
                                'parent_id'    => ['type' => 'integer'],
                                'count'        => ['type' => 'integer'],
                                'description'  => ['type' => 'string'],
                                'language'     => ['type' => 'string'],
                                'translations' => [
                                    'type'  => 'object',
                                    'items' => ['type' => 'object'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-terms', [$this, 'listTerms']),
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

    private function registerCreateTerm(): void {
        wp_register_ability('content-mcp-bridge/create-term', [
            'label'               => 'Create term',
            'description'         => 'Creates a new term in a taxonomy attached to an enabled post type.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'taxonomy'    => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug, e.g. category, product-category.',
                    ],
                    'name'        => [
                        'type'        => 'string',
                        'description' => 'Term name.',
                    ],
                    'slug'        => [
                        'type'        => 'string',
                        'description' => 'Term slug. Generated from the name when omitted.',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Term description.',
                    ],
                    'parent_id'   => [
                        'type'        => 'integer',
                        'description' => 'Parent term ID for hierarchical taxonomies.',
                    ],
                    'language'    => [
                        'type'        => 'string',
                        'description' => 'Language code, e.g. en, et. Omit for the default language.',
                    ],
                ],
                'required'             => ['taxonomy', 'name'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'   => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/create-term', [$this, 'createTerm']),
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

    private function registerUpdateTerm(): void {
        wp_register_ability('content-mcp-bridge/update-term', [
            'label'               => 'Update term',
            'description'         => 'Updates the name, slug, description and/or parent of an existing term. Only the fields given are changed. On a multilingual site the term is edited in its own language, so pass the ID of the exact language version you mean (see get-term-translations); the parent must be in the same language.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'taxonomy'    => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug, e.g. category, product-category.',
                    ],
                    'term_id'     => [
                        'type'        => 'integer',
                        'description' => 'ID of the term to update.',
                    ],
                    'name'        => [
                        'type'        => 'string',
                        'description' => 'New term name.',
                    ],
                    'slug'        => [
                        'type'        => 'string',
                        'description' => 'New term slug. Omit to keep the current one.',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'New term description.',
                    ],
                    'parent_id'   => [
                        'type'        => 'integer',
                        'description' => 'New parent term ID for hierarchical taxonomies. Pass 0 to make the term top-level.',
                    ],
                ],
                'required'             => ['taxonomy', 'term_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'          => ['type' => 'integer'],
                    'name'        => ['type' => 'string'],
                    'slug'        => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'parent_id'   => ['type' => 'integer'],
                    'language'    => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-term', [$this, 'updateTerm']),
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

    private function registerDeleteTerm(): void {
        wp_register_ability('content-mcp-bridge/delete-term', [
            'label'               => 'Delete term',
            'description'         => 'Permanently deletes a term. Objects tagged with it are moved to reassign_to when given (otherwise they simply lose the term, or fall back to the default category for category). Child terms are re-attached to the deleted term\'s parent. On a multilingual site only the given language version is deleted; delete each translation separately. This cannot be undone.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'taxonomy'    => [
                        'type'        => 'string',
                        'description' => 'Taxonomy slug, e.g. category, product-category.',
                    ],
                    'term_id'     => [
                        'type'        => 'integer',
                        'description' => 'ID of the term to delete.',
                    ],
                    'reassign_to' => [
                        'type'        => 'integer',
                        'description' => 'ID of a term in the same taxonomy (and language) that every object of the deleted term is assigned to instead.',
                    ],
                ],
                'required'             => ['taxonomy', 'term_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'deleted'            => ['type' => 'boolean'],
                    'id'                 => ['type' => 'integer'],
                    'name'               => ['type' => 'string'],
                    'reassigned_to'      => ['type' => 'integer'],
                    'reassigned_objects' => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/delete-term', [$this, 'deleteTerm']),
            'permission_callback' => function (): bool {
                return current_user_can('edit_posts');
            },
            'meta'                => [
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    public function listTaxonomies(array $input) {
        $postType = (string)(    $input['post_type'] ?? ''    );

        if ($postType && !post_type_exists($postType)) {
            return new WP_Error('invalid_parameter', "Post type '{$postType}' does not exist.");
        }

        if ($postType && !Settings::isPostTypeAllowed($postType)) {
            return new WP_Error('permission_denied', "The '{$postType}' post type is not enabled for MCP access.");
        }

        $taxonomies = [];

        foreach (get_taxonomies(['show_ui' => true], 'objects') as $taxonomyObject) {
            $enabledFor = array_values(array_filter(
                (array)$taxonomyObject->object_type, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                [Settings::class, 'isPostTypeAllowed']
            ));

            if (!$enabledFor) {
                continue;
            }

            if ($postType && !in_array($postType, $enabledFor, true)) {
                continue;
            }

            $taxonomies[] = [
                'name'         => $taxonomyObject->name,
                'label'        => (string)(    $taxonomyObject->labels->name ?? $taxonomyObject->name    ),
                'hierarchical' => (bool)$taxonomyObject->hierarchical,
                'post_types'   => $enabledFor,
            ];
        }

        return ['taxonomies' => $taxonomies];
    }

    public function listTerms(array $input) {
        $taxonomyObject = self::resolve((string)(    $input['taxonomy'] ?? ''    ));

        if (is_wp_error($taxonomyObject)) {
            return $taxonomyObject;
        }

        $language         = (string)(    $input['language'] ?? ''    );
        $previousLanguage = '';

        if ($language && Multilingual::isEnabled()) {
            if (!array_key_exists($language, Multilingual::getAllActiveLanguages())) {
                return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
            }

            $previousLanguage = Multilingual::getCurrentLanguage();
            Multilingual::switchLanguage($language);
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomyObject->name,
            'search'     => (string)(    $input['search'] ?? ''    ),
            'hide_empty' => false,
        ]);

        if ($previousLanguage) {
            Multilingual::switchLanguage($previousLanguage);
        }

        if (is_wp_error($terms)) {
            return $terms;
        }

        // WPML constrained get_terms through the language switch above;
        // Polylang did not, so its terms are filtered here instead.
        if ($language && Multilingual::isEnabled()) {
            $terms = array_values(array_filter($terms, static function ($term) use ($language): bool {
                return Multilingual::termMatchesLanguage((int)$term->term_id, $language); // phpcs:ignore Zend.NamingConventions.ValidVariableName
            }));
        }

        $withTranslations = !empty($input['with_translations']) && Multilingual::isEnabled();
        $activeLanguages  = $withTranslations ? Multilingual::getAllActiveLanguages() : [];
        $result           = [];

        foreach ($terms as $term) {
            $termId = (int)$term->term_id; // phpcs:ignore Zend.NamingConventions.ValidVariableName
            $entry  = [
                'id'        => $termId,
                'name'      => $term->name,
                'slug'      => $term->slug,
                'parent_id' => (int)$term->parent,
                'count'     => (int)$term->count,
            ];

            if ($withTranslations) {
                $entry['description']  = (string)$term->description;
                $entry['language']     = Multilingual::getTermLanguage($termId, $taxonomyObject->name);
                $entry['translations'] = $this->translationMap($termId, $taxonomyObject->name, $activeLanguages);
            }

            $result[] = $entry;
        }

        return ['terms' => $result];
    }

    /**
     * @param array<string, mixed> $activeLanguages
     * @return array<string, array<string, mixed>>
     */
    private function translationMap(int $termId, string $taxonomy, array $activeLanguages): array {
        $map = [];

        foreach (array_keys($activeLanguages) as $code) {
            $translatedId   = Translations::findTranslatedTermId($termId, $taxonomy, $code);
            $translatedTerm = $translatedId ? Translations::rawTerm($translatedId, $taxonomy) : null;

            $map[$code] = [
                'term_id' => $translatedTerm ? $translatedId : null,
                'status'  => $translatedTerm ? 'translated' : 'missing',
                'name'    => $translatedTerm ? $translatedTerm->name : '',
            ];
        }

        return $map;
    }

    public function createTerm(array $input) {
        $taxonomyObject = self::resolve((string)(    $input['taxonomy'] ?? ''    ));

        if (is_wp_error($taxonomyObject)) {
            return $taxonomyObject;
        }

        if (!current_user_can($taxonomyObject->cap->edit_terms)) {
            return new WP_Error('cannot_create', 'You are not allowed to create terms in this taxonomy.');
        }

        $name = trim((string)(    $input['name'] ?? ''    ));

        if ($name === '') {
            return new WP_Error('invalid_parameter', 'name is required.');
        }

        $language = (string)(    $input['language'] ?? ''    );

        if ($language && Multilingual::isEnabled() && !array_key_exists($language, Multilingual::getAllActiveLanguages())) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        $parentId = (int)(    $input['parent_id'] ?? 0    );

        if ($parentId) {
            if (!$taxonomyObject->hierarchical) {
                return new WP_Error('invalid_parameter', "Taxonomy '{$taxonomyObject->name}' is not hierarchical.");
            }

            $parent = get_term($parentId, $taxonomyObject->name);

            if (!$parent || is_wp_error($parent)) {
                return new WP_Error('invalid_parent', "Parent term {$parentId} was not found in taxonomy '{$taxonomyObject->name}'.");
            }
        }

        $previousLanguage = '';

        if ($language && Multilingual::isEnabled()) {
            $previousLanguage = Multilingual::getCurrentLanguage();
            Multilingual::switchLanguage($language);
        }

        $created = wp_insert_term($name, $taxonomyObject->name, [
            'slug'        => (string)(    $input['slug'] ?? ''    ),
            'description' => (string)(    $input['description'] ?? ''    ),
            'parent'      => $parentId,
        ]);

        if ($previousLanguage) {
            Multilingual::switchLanguage($previousLanguage);
        }

        if (is_wp_error($created)) {
            return $created;
        }

        $term = get_term((int)$created['term_id'], $taxonomyObject->name);

        return [
            'id'   => (int)$term->term_id, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'name' => $term->name,
            'slug' => $term->slug,
        ];
    }

    public function updateTerm(array $input) {
        $taxonomyObject = self::resolve((string)(    $input['taxonomy'] ?? ''    ));

        if (is_wp_error($taxonomyObject)) {
            return $taxonomyObject;
        }

        $taxonomy = $taxonomyObject->name;
        $termId   = (int)(    $input['term_id'] ?? 0    );
        $term     = Translations::rawTerm($termId, $taxonomy);

        if (!$term) {
            return new WP_Error('term_not_found', "Term {$termId} was not found in taxonomy '{$taxonomy}'.");
        }

        if (!current_user_can($taxonomyObject->cap->edit_terms) || !current_user_can('edit_term', $termId)) {
            return new WP_Error('cannot_update', 'You are not allowed to edit terms in this taxonomy.');
        }

        $args = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string)$input['name']);

            if ($name === '') {
                return new WP_Error('invalid_parameter', 'name cannot be empty.');
            }

            $args['name'] = $name;
        }

        // An omitted or empty slug means "leave the existing one alone" —
        // passing '' would make wp_update_term regenerate it from the name.
        if (isset($input['slug']) && trim((string)$input['slug']) !== '') {
            $args['slug'] = sanitize_title((string)$input['slug']);
        }

        if (array_key_exists('description', $input)) {
            $args['description'] = (string)$input['description'];
        }

        $language = Multilingual::isEnabled() ? Translations::termLanguage($termId, $taxonomy) : '';

        if (array_key_exists('parent_id', $input)) {
            $parentId = (int)$input['parent_id'];

            if ($parentId && !$taxonomyObject->hierarchical) {
                return new WP_Error('invalid_parameter', "Taxonomy '{$taxonomy}' is not hierarchical.");
            }

            if ($parentId) {
                $parent = Translations::rawTerm($parentId, $taxonomy);

                if (!$parent) {
                    return new WP_Error('invalid_parent', "Parent term {$parentId} was not found in taxonomy '{$taxonomy}'.");
                }

                if ($parentId === $termId || in_array($termId, self::ancestorIds($parentId, $taxonomy), true)) {
                    return new WP_Error('invalid_parent', 'A term cannot be its own ancestor.');
                }

                $parentLanguage = $language ? Translations::termLanguage($parentId, $taxonomy) : '';

                if ($language && $parentLanguage && $parentLanguage !== $language) {
                    return new WP_Error(
                        'invalid_parent',
                        "Parent term {$parentId} is in language '{$parentLanguage}' but term {$termId} is in '{$language}'. Use its '{$language}' translation as the parent instead."
                    );
                }
            }

            $args['parent'] = $parentId;
        }

        if (!$args) {
            return new WP_Error('invalid_parameter', 'Nothing to update: pass at least one of name, slug, description or parent_id.');
        }

        // Run inside the term's own language: WPML's auto-adjust rewrites
        // get_term() to the current language's version, so updating a ru
        // term from an et request context would otherwise hit the et one.
        $previousLanguage = '';

        if ($language) {
            $previousLanguage = Multilingual::getCurrentLanguage();
            Multilingual::switchLanguage($language);
        }

        $updated = wp_update_term($termId, $taxonomy, $args);

        if ($previousLanguage) {
            Multilingual::switchLanguage($previousLanguage);
        }

        if (is_wp_error($updated)) {
            return $updated;
        }

        clean_term_cache($termId, $taxonomy);

        if (function_exists('icl_cache_clear')) {
            icl_cache_clear();
        }

        $term = Translations::rawTerm($termId, $taxonomy);

        return [
            'id'          => $termId,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => (string)$term->description,
            'parent_id'   => (int)$term->parent,
            'language'    => $language,
        ];
    }

    public function deleteTerm(array $input) {
        $taxonomyObject = self::resolve((string)(    $input['taxonomy'] ?? ''    ));

        if (is_wp_error($taxonomyObject)) {
            return $taxonomyObject;
        }

        $taxonomy = $taxonomyObject->name;
        $termId   = (int)(    $input['term_id'] ?? 0    );
        $term     = Translations::rawTerm($termId, $taxonomy);

        if (!$term) {
            return new WP_Error('term_not_found', "Term {$termId} was not found in taxonomy '{$taxonomy}'.");
        }

        if (!current_user_can($taxonomyObject->cap->delete_terms) || !current_user_can('delete_term', $termId)) {
            return new WP_Error('cannot_delete', 'You are not allowed to delete terms in this taxonomy.');
        }

        if ($taxonomy === 'category' && $termId === (int)get_option('default_category')) {
            return new WP_Error('cannot_delete', 'The default category cannot be deleted.');
        }

        $language   = Multilingual::isEnabled() ? Translations::termLanguage($termId, $taxonomy) : '';
        $reassignTo = (int)(    $input['reassign_to'] ?? 0    );
        $deleteArgs = [];

        if ($reassignTo) {
            if ($reassignTo === $termId) {
                return new WP_Error('invalid_parameter', 'reassign_to must be a different term.');
            }

            $target = Translations::rawTerm($reassignTo, $taxonomy);

            if (!$target) {
                return new WP_Error('term_not_found', "Term {$reassignTo} was not found in taxonomy '{$taxonomy}'.");
            }

            $targetLanguage = $language ? Translations::termLanguage($reassignTo, $taxonomy) : '';

            if ($language && $targetLanguage && $targetLanguage !== $language) {
                return new WP_Error(
                    'invalid_parameter',
                    "reassign_to term {$reassignTo} is in language '{$targetLanguage}' but term {$termId} is in '{$language}'."
                );
            }

            $deleteArgs = [
                'default'       => $reassignTo,
                'force_default' => true,
            ];
        }

        $objectCount = (int)$term->count;
        $name        = $term->name;

        $previousLanguage = '';

        if ($language) {
            $previousLanguage = Multilingual::getCurrentLanguage();
            Multilingual::switchLanguage($language);
        }

        $deleted = wp_delete_term($termId, $taxonomy, $deleteArgs);

        if ($previousLanguage) {
            Multilingual::switchLanguage($previousLanguage);
        }

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        if (!$deleted) {
            return new WP_Error('cannot_delete', "Term {$termId} could not be deleted.");
        }

        if ($reassignTo) {
            clean_term_cache($reassignTo, $taxonomy);
        }

        if (function_exists('icl_cache_clear')) {
            icl_cache_clear();
        }

        return [
            'deleted'            => true,
            'id'                 => $termId,
            'name'               => $name,
            'reassigned_to'      => $reassignTo,
            'reassigned_objects' => $reassignTo ? $objectCount : 0,
        ];
    }

    /**
     * Ancestor chain read straight from term_taxonomy, bypassing get_term()
     * for the same WPML auto-adjust reason as Translations::rawTerm().
     *
     * @return int[]
     */
    private static function ancestorIds(int $termId, string $taxonomy): array {
        $ancestors = [];
        $current   = $termId;

        while ($current && count($ancestors) < 100) {
            $term = Translations::rawTerm($current, $taxonomy);

            if (!$term || !(int)$term->parent || in_array((int)$term->parent, $ancestors, true)) {
                break;
            }

            $ancestors[] = (int)$term->parent;
            $current     = (int)$term->parent;
        }

        return $ancestors;
    }
}
