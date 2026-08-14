<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Integrations\Wpml;
use WP_Error;

/**
 * MCP abilities for WPML String Translation.
 *
 * Strings (theme texts, plugin strings, admin_texts, widget titles) live in
 * icl_strings / icl_string_translations rather than in posts, so none of the
 * post- or term-translation abilities reach them. These two read and write
 * that store the same way the String Translation admin screen does, going
 * through icl_add_string_translation() so WPML's own caches, status
 * bookkeeping and hooks all fire.
 */
class Strings implements AbilityGroup {
    /**
     * WPML's ICL_TM_* status constants, as translation-progress values on a
     * single string. Spelled out rather than referenced as constants so the
     * ability keeps working if String Translation is deactivated mid-request.
     */
    private const STATUS_NOT_TRANSLATED = 0;

    private const STATUS_COMPLETE = 10;

    private const STATUS_NEEDS_UPDATE = 3;

    private const STATUS_LABELS = [
        0  => 'not translated',
        1  => 'waiting for translator',
        2  => 'in progress',
        3  => 'needs update',
        10 => 'complete',
    ];

    private const MAX_LIMIT = 200;

    public function registerReadOnly(): void {
        $this->registerListStringContexts();
        $this->registerListStrings();
    }

    public function registerWrite(): void {
        $this->registerTranslateString();
    }

    public static function isAvailable(): bool {
        return Wpml::isEnabled() && function_exists('icl_add_string_translation');
    }

    private function registerListStringContexts(): void {
        wp_register_ability('content-mcp-bridge/list-string-contexts', [
            'label'               => 'List string contexts',
            'description'         => 'Lists the WPML String Translation contexts (domains) on this site with the number of strings in each, e.g. "theme-name", "admin_texts_options", "Widgets". Use this first to find the context to pass to list-strings.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'contexts' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'context' => ['type' => 'string'],
                                'count'   => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-string-contexts', [$this, 'listStringContexts']),
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

    private function registerListStrings(): void {
        wp_register_ability('content-mcp-bridge/list-strings', [
            'label'               => 'List strings',
            'description'         => 'Lists WPML String Translation strings with their original value and per-language translation status. Filter by context (domain), free-text search, or translation status to find exactly what still needs translating.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'context'      => [
                        'type'        => 'string',
                        'description' => 'String context/domain, e.g. "theme-name" or "admin_texts_options". Use list-string-contexts to see the available ones. Omit for all contexts.',
                    ],
                    'search'       => [
                        'type'        => 'string',
                        'description' => 'Free-text search over the string name and original value.',
                    ],
                    'language'     => [
                        'type'        => 'string',
                        'description' => 'Report status against this language only, e.g. ru. Omit to report every active language.',
                    ],
                    'status'       => [
                        'type'        => 'string',
                        'enum'        => [
                            'all',
                            'untranslated',
                            'translated',
                            'needs_update',
                        ],
                        'description' => 'Only strings in this state for the requested language(s). "untranslated" also covers empty translations. Default all.',
                    ],
                    'limit'        => [
                        'type'        => 'integer',
                        'description' => 'Maximum strings to return, 1-200. Default 50.',
                    ],
                    'offset'       => [
                        'type'        => 'integer',
                        'description' => 'Number of strings to skip, for paging through a large context. Default 0.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'   => ['type' => 'integer'],
                    'returned' => ['type' => 'integer'],
                    'strings' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'string_id'    => ['type' => 'integer'],
                                'context'      => ['type' => 'string'],
                                'name'         => ['type' => 'string'],
                                'value'        => ['type' => 'string'],
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
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-strings', [$this, 'listStrings']),
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

    private function registerTranslateString(): void {
        wp_register_ability('content-mcp-bridge/translate-string', [
            'label'               => 'Translate string',
            'description'         => 'Saves a WPML String Translation for one string in one language and marks it complete, exactly as saving it on the String Translation screen would. Re-running with a new value overwrites the previous translation.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'string_id'   => [
                        'type'        => 'integer',
                        'description' => 'ID of the string, as returned by list-strings.',
                    ],
                    'language'    => [
                        'type'        => 'string',
                        'description' => 'Target WPML language code, e.g. ru, fi, et.',
                    ],
                    'translation' => [
                        'type'        => 'string',
                        'description' => 'The translated text.',
                    ],
                    'status'      => [
                        'type'        => 'string',
                        'enum'        => [
                            'complete',
                            'needs_update',
                        ],
                        'description' => 'Translation status to store. Default complete.',
                    ],
                ],
                'required'             => [
                    'string_id',
                    'language',
                    'translation',
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'string_id'   => ['type' => 'integer'],
                    'language'    => ['type' => 'string'],
                    'original'    => ['type' => 'string'],
                    'translation' => ['type' => 'string'],
                    'status'      => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/translate-string', [$this, 'translateString']),
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

    public function listStringContexts(array $input) {
        if (!self::isAvailable()) {
            return self::unavailable();
        }

        global $wpdb;

        $table = $wpdb->prefix.'icl_strings';
        $rows  = $wpdb->get_results(
            "SELECT context, COUNT(*) AS total FROM {$table} WHERE TRIM(value) <> '' GROUP BY context ORDER BY context ASC"
        );

        $contexts = [];

        foreach ((array)$rows as $row) {
            $contexts[] = [
                'context' => (string)$row->context,
                'count'   => (int)$row->total,
            ];
        }

        return ['contexts' => $contexts];
    }

    public function listStrings(array $input) {
        if (!self::isAvailable()) {
            return self::unavailable();
        }

        $languages = $this->resolveLanguages((string)(    $input['language'] ?? ''    ));

        if (is_wp_error($languages)) {
            return $languages;
        }

        global $wpdb;

        $stringsTable = $wpdb->prefix.'icl_strings';
        $limit        = min(self::MAX_LIMIT, max(1, (int)(    $input['limit'] ?? 50    )));
        $offset       = max(0, (int)(    $input['offset'] ?? 0    ));

        $where  = ["TRIM(s.value) <> ''"];
        $params = [];

        if ($context = (string)(    $input['context'] ?? ''    )) {
            $where[]  = 's.context = %s';
            $params[] = $context;
        }

        if ($search = trim((string)(    $input['search'] ?? ''    ))) {
            $like     = '%'.$wpdb->esc_like($search).'%';
            $where[]  = '(s.value LIKE %s OR s.name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $statusFilter = (string)(    $input['status'] ?? 'all'    );
        $statusWhere  = $this->statusCondition($statusFilter, $languages, $params);

        if ($statusWhere) {
            $where[] = $statusWhere;
        }

        $whereSql = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$stringsTable} s WHERE {$whereSql}";
        $rowsSql  = "SELECT s.id, s.context, s.name, s.value, s.language
            FROM {$stringsTable} s
            WHERE {$whereSql}
            ORDER BY s.context ASC, s.id ASC
            LIMIT %d OFFSET %d";

        $total = (int)$wpdb->get_var($params ? $wpdb->prepare($countSql, $params) : $countSql);
        $rows  = (array)$wpdb->get_results($wpdb->prepare($rowsSql, array_merge($params, [$limit, $offset])));

        $translations = $this->translationsFor(array_map(static function ($row): int {
            return (int)$row->id;
        }, $rows));

        $strings = [];

        foreach ($rows as $row) {
            $stringId = (int)$row->id;
            $map      = [];

            foreach ($languages as $code) {
                $translation = $translations[$stringId][$code] ?? null;
                $value       = (string)(    $translation->value ?? ''    );
                $status      = $translation ? (int)$translation->status : self::STATUS_NOT_TRANSLATED;

                $map[$code] = [
                    'translation' => $value,
                    'status'      => self::STATUS_LABELS[$status] ?? 'unknown',
                ];
            }

            $strings[] = [
                'string_id'    => $stringId,
                'context'      => (string)$row->context,
                'name'         => (string)$row->name,
                'value'        => (string)$row->value,
                'language'     => (string)$row->language,
                'translations' => $map,
            ];
        }

        return [
            'total'    => $total,
            'returned' => count($strings),
            'strings'  => $strings,
        ];
    }

    public function translateString(array $input) {
        if (!self::isAvailable()) {
            return self::unavailable();
        }

        $stringId = (int)(    $input['string_id'] ?? 0    );
        $language = (string)(    $input['language'] ?? ''    );

        global $wpdb;

        $table  = $wpdb->prefix.'icl_strings';
        $string = $wpdb->get_row($wpdb->prepare(
            "SELECT id, value, language FROM {$table} WHERE id = %d",
            $stringId
        ));

        if (!$string) {
            return new WP_Error('string_not_found', "String {$stringId} was not found.");
        }

        if (!array_key_exists($language, Wpml::getAllActiveLanguages())) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        if ($language === (string)$string->language) {
            return new WP_Error(
                'same_language',
                "String {$stringId} is already in language '{$language}' — that is its original, not a translation."
            );
        }

        $translation = (string)(    $input['translation'] ?? ''    );

        if (trim($translation) === '') {
            return new WP_Error('invalid_parameter', 'translation cannot be empty.');
        }

        $status = (string)(    $input['status'] ?? 'complete'    ) === 'needs_update'
            ? self::STATUS_NEEDS_UPDATE
            : self::STATUS_COMPLETE;

        $saved = icl_add_string_translation($stringId, $language, $translation, $status);

        if (!$saved) {
            return new WP_Error('save_failed', "Could not save the '{$language}' translation of string {$stringId}.");
        }

        if (function_exists('icl_cache_clear')) {
            icl_cache_clear();
        }

        return [
            'string_id'   => $stringId,
            'language'    => $language,
            'original'    => (string)$string->value,
            'translation' => $translation,
            'status'      => self::STATUS_LABELS[$status],
        ];
    }

    /**
     * @return string[]|WP_Error
     */
    private function resolveLanguages(string $language) {
        $active = Wpml::getAllActiveLanguages();

        if ($language === '') {
            return array_keys($active);
        }

        if (!array_key_exists($language, $active)) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        return [$language];
    }

    /**
     * One query for every string on the page rather than one per string —
     * a context like admin_texts_options can hold thousands of rows.
     *
     * @param int[] $stringIds
     * @return array<int, array<string, object>>
     */
    private function translationsFor(array $stringIds): array {
        if (!$stringIds) {
            return [];
        }

        global $wpdb;

        $table        = $wpdb->prefix.'icl_string_translations';
        $placeholders = implode(',', array_fill(0, count($stringIds), '%d'));
        $rows         = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT string_id, language, value, status FROM {$table} WHERE string_id IN ({$placeholders})",
            $stringIds
        ));

        $byString = [];

        foreach ($rows as $row) {
            $byString[(int)$row->string_id][(string)$row->language] = $row;
        }

        return $byString;
    }

    /**
     * Builds the status filter as a SQL condition so it applies BEFORE
     * LIMIT/OFFSET. Filtering the fetched page in PHP instead would make the
     * filter lie: with the default ordering (oldest ids first), untranslated
     * strings past the first page would simply never appear in any page, and
     * `total` would count unfiltered rows.
     *
     * A string matches when any of the requested languages is in the wanted
     * state — filtering "untranslated" across all languages surfaces
     * everything with at least one gap, which is what you work through.
     *
     * @param string[] $languages
     * @param array<int, string|int> $params Appended to in matching order.
     */
    private function statusCondition(string $filter, array $languages, array &$params): string {
        if ($filter === '' || $filter === 'all' || !$languages) {
            return '';
        }

        global $wpdb;

        $table    = $wpdb->prefix.'icl_string_translations';
        $complete = self::STATUS_COMPLETE;
        $needs    = self::STATUS_NEEDS_UPDATE;
        $perLang  = [];

        foreach ($languages as $code) {
            switch ($filter) {
                case 'untranslated':
                    // No complete translation and not merely stale either —
                    // and never counting the string's own original language
                    // as an untranslated gap.
                    $perLang[] = "(s.language <> %s AND NOT EXISTS (
                        SELECT 1 FROM {$table} t
                        WHERE t.string_id = s.id AND t.language = %s
                            AND ((t.status = {$complete} AND t.value IS NOT NULL AND TRIM(t.value) <> '') OR t.status = {$needs})
                    ))";
                    $params[]  = $code;
                    $params[]  = $code;
                    break;

                case 'translated':
                    $perLang[] = "(s.language <> %s AND EXISTS (
                        SELECT 1 FROM {$table} t
                        WHERE t.string_id = s.id AND t.language = %s
                            AND t.status = {$complete} AND t.value IS NOT NULL AND TRIM(t.value) <> ''
                    ))";
                    $params[]  = $code;
                    $params[]  = $code;
                    break;

                case 'needs_update':
                    $perLang[] = "(s.language <> %s AND EXISTS (
                        SELECT 1 FROM {$table} t
                        WHERE t.string_id = s.id AND t.language = %s AND t.status = {$needs}
                    ))";
                    $params[]  = $code;
                    $params[]  = $code;
                    break;
            }
        }

        return $perLang ? '('.implode(' OR ', $perLang).')' : '';
    }

    private static function unavailable(): WP_Error {
        return new WP_Error(
            'wpml_st_not_active',
            'WPML String Translation is not active on this site.'
        );
    }
}
