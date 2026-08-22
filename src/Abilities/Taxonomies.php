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
}
