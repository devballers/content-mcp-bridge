<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Integrations\Multilingual;
use ContentMcpBridge\Integrations\Polylang;
use WP_Error;

/**
 * MCP abilities for Polylang string translations.
 *
 * Registers the same ability names as the WPML Strings group — only one
 * engine is ever active on a site, so the names never clash — but the model
 * differs: Polylang strings have no database id, they are keyed by their
 * original text, and translations live in each language's polylang_mo store.
 *
 * The list of *registered* strings (pll_register_string) is only collected in
 * admin requests, so over MCP the reliable inventory is the union of the
 * per-language MO stores plus whatever registrations are visible. A string
 * never saved on the Languages → Translations screen may therefore be missing
 * from list-strings — translate-string still works for it, because writing
 * only needs the original text.
 */
class PolylangStrings implements AbilityGroup {
    private const MAX_LIMIT = 200;

    public function registerReadOnly(): void {
        $this->registerListStringContexts();
        $this->registerListStrings();
    }

    public function registerWrite(): void {
        $this->registerTranslateString();
    }

    public static function isAvailable(): bool {
        return Multilingual::isPolylang() && class_exists('PLL_MO');
    }

    private function registerListStringContexts(): void {
        wp_register_ability('content-mcp-bridge/list-string-contexts', [
            'label'               => 'List string contexts',
            'description'         => 'Lists the Polylang string translation groups (contexts) with the number of registered strings in each. Contexts come from strings registered via pll_register_string; strings that only exist in the translation store are grouped under "(stored)".',
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
            'description'         => 'Lists Polylang translatable strings with their original value and per-language translation status. Strings are identified by their original text — pass that text to translate-string. Filter by search, language or translation status.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'context'  => [
                        'type'        => 'string',
                        'description' => 'Only strings registered under this context/group. Use list-string-contexts to see them.',
                    ],
                    'search'   => [
                        'type'        => 'string',
                        'description' => 'Free-text search over the string name and original value.',
                    ],
                    'language' => [
                        'type'        => 'string',
                        'description' => 'Report status against this language only, e.g. et. Omit to report every language.',
                    ],
                    'status'   => [
                        'type'        => 'string',
                        'enum'        => [
                            'all',
                            'untranslated',
                            'translated',
                        ],
                        'description' => 'Only strings in this state for the requested language(s). Default all.',
                    ],
                    'limit'    => [
                        'type'        => 'integer',
                        'description' => 'Maximum strings to return, 1-200. Default 50.',
                    ],
                    'offset'   => [
                        'type'        => 'integer',
                        'description' => 'Number of strings to skip, for paging. Default 0.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'    => ['type' => 'integer'],
                    'returned' => ['type' => 'integer'],
                    'strings'  => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'context'      => ['type' => 'string'],
                                'name'         => ['type' => 'string'],
                                'value'        => ['type' => 'string'],
                                'translations' => ['type' => 'object'],
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
            'description'         => 'Saves a Polylang string translation for one language, exactly as saving it on the Languages → Translations screen would. The string is identified by its original text (the "value" from list-strings). Re-running with a new value overwrites the previous translation.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'original'    => [
                        'type'        => 'string',
                        'description' => 'The original (source-language) text of the string, exactly as registered.',
                    ],
                    'language'    => [
                        'type'        => 'string',
                        'description' => 'Target language code, e.g. et, fi, sv.',
                    ],
                    'translation' => [
                        'type'        => 'string',
                        'description' => 'The translated text.',
                    ],
                ],
                'required'             => [
                    'original',
                    'language',
                    'translation',
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'original'    => ['type' => 'string'],
                    'language'    => ['type' => 'string'],
                    'translation' => ['type' => 'string'],
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

        $counts = [];

        foreach (Polylang::registeredStrings() as $string) {
            $context = (string)(    $string['context'] ?? ''    ) ?: '(stored)';

            $counts[$context] = (    $counts[$context] ?? 0    ) + 1;
        }

        $registeredOriginals = array_column(Polylang::registeredStrings(), 'string');
        $storedOnly          = 0;

        foreach ($this->storedOriginals() as $original) {
            if (!in_array($original, $registeredOriginals, true)) {
                $storedOnly++;
            }
        }

        if ($storedOnly) {
            $counts['(stored)'] = (    $counts['(stored)'] ?? 0    ) + $storedOnly;
        }

        ksort($counts);

        $contexts = [];

        foreach ($counts as $context => $count) {
            $contexts[] = [
                'context' => $context,
                'count'   => $count,
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

        $context = (string)(    $input['context'] ?? ''    );
        $search  = trim((string)(    $input['search'] ?? ''    ));
        $status  = (string)(    $input['status'] ?? 'all'    );
        $limit   = min(self::MAX_LIMIT, max(1, (int)(    $input['limit'] ?? 50    )));
        $offset  = max(0, (int)(    $input['offset'] ?? 0    ));

        // Inventory: registered strings first (they carry name and context),
        // then any stored originals that were never registered this request.
        $strings = [];

        foreach (Polylang::registeredStrings() as $string) {
            $original = (string)(    $string['string'] ?? ''    );

            if ($original === '') {
                continue;
            }

            $strings[$original] = [
                'context' => (string)(    $string['context'] ?? ''    ),
                'name'    => (string)(    $string['name'] ?? ''    ),
                'value'   => $original,
            ];
        }

        foreach ($this->storedOriginals() as $original) {
            if (!isset($strings[$original])) {
                $strings[$original] = [
                    'context' => '(stored)',
                    'name'    => '',
                    'value'   => $original,
                ];
            }
        }

        $translationsByLanguage = [];

        foreach ($languages as $code) {
            $translationsByLanguage[$code] = Polylang::stringTranslations($code);
        }

        $matches = [];

        foreach ($strings as $original => $string) {
            if ($context !== '' && $string['context'] !== $context) {
                continue;
            }

            if ($search !== ''
                && stripos($original, $search) === false
                && stripos($string['name'], $search) === false
            ) {
                continue;
            }

            $map           = [];
            $hasTranslated = false;
            $hasGap        = false;

            foreach ($languages as $code) {
                $translation = (string)(    $translationsByLanguage[$code][$original] ?? ''    );

                // Polylang seeds the store with translation = original, which
                // on the admin screen still reads as "not translated yet".
                $isTranslated = $translation !== '' && $translation !== $original;

                $map[$code] = [
                    'translation' => $translation,
                    'status'      => $isTranslated ? 'translated' : 'not translated',
                ];

                $isTranslated ? $hasTranslated = true : $hasGap = true;
            }

            if (($status === 'untranslated' && !$hasGap) || ($status === 'translated' && !$hasTranslated)) {
                continue;
            }

            $string['translations'] = $map;
            $matches[]              = $string;
        }

        return [
            'total'    => count($matches),
            'returned' => count(array_slice($matches, $offset, $limit)),
            'strings'  => array_slice($matches, $offset, $limit),
        ];
    }

    public function translateString(array $input) {
        if (!self::isAvailable()) {
            return self::unavailable();
        }

        $original    = (string)(    $input['original'] ?? ''    );
        $language    = (string)(    $input['language'] ?? ''    );
        $translation = (string)(    $input['translation'] ?? ''    );

        if ($original === '') {
            return new WP_Error('invalid_parameter', 'original cannot be empty.');
        }

        if (trim($translation) === '') {
            return new WP_Error('invalid_parameter', 'translation cannot be empty.');
        }

        if (!array_key_exists($language, Multilingual::getAllActiveLanguages())) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        if (!Polylang::saveStringTranslation($original, $translation, $language)) {
            return new WP_Error('save_failed', "Could not save the '{$language}' translation.");
        }

        return [
            'original'    => $original,
            'language'    => $language,
            'translation' => $translation,
        ];
    }

    /**
     * Originals present in any language's MO store.
     *
     * @return string[]
     */
    private function storedOriginals(): array {
        $originals = [];

        foreach (array_keys(Multilingual::getAllActiveLanguages()) as $code) {
            foreach (array_keys(Polylang::stringTranslations($code)) as $original) {
                $originals[$original] = true;
            }
        }

        return array_keys($originals);
    }

    /**
     * @return string[]|WP_Error
     */
    private function resolveLanguages(string $language) {
        $active = Multilingual::getAllActiveLanguages();

        if ($language === '') {
            return array_keys($active);
        }

        if (!array_key_exists($language, $active)) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        return [$language];
    }

    private static function unavailable(): WP_Error {
        return new WP_Error(
            'polylang_not_active',
            'Polylang is not active on this site.'
        );
    }
}
