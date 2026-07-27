<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use WP_Error;

/**
 * MCP ability for translating navigation menus with WPML.
 *
 * A menu-item translation needs more than a translated post: the item must be
 * assigned to a translated nav_menu term, point at the translated target page,
 * keep its order/hierarchy, and be linked into the WPML translation group of
 * the source item. This ability does the whole flow, like WPML's own Menu
 * Synchronisation, and is idempotent — already-translated items are skipped.
 */
class Menus implements AbilityGroup {
    public function registerReadOnly(): void {
        // No readonly ability for this group.
    }

    public function registerWrite(): void {
        wp_register_ability('content-mcp-bridge/translate-menu', [
            'label'               => 'Translate navigation menu',
            'description'         => 'Creates a WPML translation of a navigation menu: a translated menu term plus menu items linked to the translated pages, preserving order and hierarchy. Same-site custom links (e.g. homepage anchors) are rewritten to the target-language URL. Pass translated labels via the titles map; items whose target page has no translation in that language are skipped and reported. Safe to re-run — existing translated items are kept and their custom-link URLs repaired.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'menu'     => [
                        'type'        => 'string',
                        'description' => 'Menu name, slug or ID of the source menu, e.g. "Main menu".',
                    ],
                    'language' => [
                        'type'        => 'string',
                        'description' => 'WPML language code to translate the menu into, e.g. fi.',
                    ],
                    'titles'   => [
                        'type'        => 'object',
                        'description' => 'Optional map of source menu item ID to translated label, e.g. {"66": "Tietoa meistä"}. Items without an entry keep their source label.',
                    ],
                ],
                'required'             => [
                    'menu', 'language'
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'menu_id'  => ['type' => 'integer'],
                    'language' => ['type' => 'string'],
                    'created'  => [
                        'type'  => 'array',
                        'items' => ['type' => 'object'],
                    ],
                    'updated'  => [
                        'type'  => 'array',
                        'items' => ['type' => 'object'],
                    ],
                    'skipped'  => [
                        'type'  => 'array',
                        'items' => ['type' => 'object'],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/translate-menu', [$this, 'translateMenu']),
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
    public function translateMenu(array $input) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity
        if (!has_filter('wpml_object_id')) {
            return new WP_Error('wpml_missing', 'WPML is not active on this site.');
        }

        $language = (string)(    $input['language'] ?? ''    );
        $activeLanguages = (array)apply_filters('wpml_active_languages', null, '');

        if (!array_key_exists($language, $activeLanguages)) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        $menu = wp_get_nav_menu_object((string)(    $input['menu'] ?? ''    ));

        if (!$menu) {
            return new WP_Error('menu_not_found', 'Menu was not found. Use the menu name, slug or ID.');
        }

        $sourceLanguage = (string)apply_filters('wpml_element_language_code', null, [
            'element_id'   => $menu->term_id, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'element_type' => 'nav_menu',
        ]);

        if ($sourceLanguage === $language) {
            return new WP_Error('same_language', "Menu '{$menu->name}' is already in '{$language}'.");
        }

        $items = wp_get_nav_menu_items($menu->term_id); // phpcs:ignore Zend.NamingConventions.ValidVariableName

        if (!$items) {
            return new WP_Error('menu_empty', "Menu '{$menu->name}' has no items.");
        }

        $titles = isset($input['titles']) && is_array($input['titles']) ? $input['titles'] : [];

        $targetMenuId = $this->ensureTranslatedMenu($menu, $language, $sourceLanguage);

        if (is_wp_error($targetMenuId)) {
            return $targetMenuId;
        }

        $created   = [];
        $updated   = [];
        $skipped   = [];
        $parentMap = [];

        foreach ($items as $item) {
            $existing = (int)apply_filters('wpml_object_id', $item->ID, 'nav_menu_item', false, $language);

            if ($existing && $existing !== (int)$item->ID) {
                $parentMap[(int)$item->ID] = $existing;

                $repairedUrl = $this->repairCustomLink($existing, $item, $language);

                if ($repairedUrl) {
                    $updated[] = [
                        'id'        => $existing, 'source_id' => (int)$item->ID, 'url' => $repairedUrl
                    ];
                } else {
                    $skipped[] = [
                        'id'     => (int)$item->ID, 'title' => $item->title, 'reason' => 'already translated'
                    ];
                }

                continue;
            }

            $itemArgs = [
                'menu-item-type'       => $item->type,
                'menu-item-title'      => (string)(    $titles[(string)$item->ID] ?? $titles[(int)$item->ID] ?? $item->title    ),
                'menu-item-status'     => 'publish',
                'menu-item-position'   => (int)$item->menu_order, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'menu-item-parent-id'  => $parentMap[(int)$item->menu_item_parent] ?? 0, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'menu-item-target'     => $item->target,
                'menu-item-classes'    => implode(' ', (array)$item->classes),
                'menu-item-xfn'        => $item->xfn,
                'menu-item-attr-title' => $item->attr_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            ];

            if ($item->type === 'custom') {
                $itemArgs['menu-item-url'] = $this->convertUrlToLanguage($item->url, $language);
            } else {
                $translatedObjectId = (int)apply_filters('wpml_object_id', (int)$item->object_id, $item->object, false, $language); // phpcs:ignore Zend.NamingConventions.ValidVariableName

                if (!$translatedObjectId || $translatedObjectId === (int)$item->object_id) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
                    $skipped[] = [
                        'id'     => (int)$item->ID, 'title' => $item->title, 'reason' => "target '{$item->object}' {$item->object_id} has no '{$language}' translation"
                    ];

                    continue;
                }

                $itemArgs['menu-item-object']    = $item->object;
                $itemArgs['menu-item-object-id'] = $translatedObjectId;
            }

            $newItemId = wp_update_nav_menu_item($targetMenuId, 0, $itemArgs);

            if (is_wp_error($newItemId)) {
                $skipped[] = [
                    'id'     => (int)$item->ID, 'title' => $item->title, 'reason' => $newItemId->get_error_message()
                ];

                continue;
            }

            $this->linkTranslation((int)$newItemId, (int)$item->ID, 'post_nav_menu_item', $language, $sourceLanguage);

            $parentMap[(int)$item->ID] = (int)$newItemId;
            $created[]                 = [
                'id'        => (int)$newItemId, 'source_id' => (int)$item->ID, 'title' => $itemArgs['menu-item-title'], 'url' => $itemArgs['menu-item-url'] ?? ''
            ];
        }

        return [
            'menu_id'  => (int)$targetMenuId,
            'language' => $language,
            'created'  => $created,
            'updated'  => $updated,
            'skipped'  => $skipped,
        ];
    }

    /**
     * Rewrites a same-site URL to its target-language version via WPML,
     * keeping any #fragment. External URLs are returned unchanged.
     */
    private function convertUrlToLanguage(string $url, string $language): string {
        if (!$url || $url[0] === '#') {
            return $url;
        }

        $homeHost = (string)wp_parse_url(home_url(), PHP_URL_HOST);
        $urlHost  = (string)wp_parse_url($url, PHP_URL_HOST);

        if ($urlHost && $urlHost !== $homeHost) {
            return $url;
        }

        $fragment = '';
        $base     = $url;

        if (str_contains($url, '#')) {
            [
                $base, $fragment
            ] = explode('#', $url, 2);
        }

        if (!$base) {
            return $url;
        }

        $converted = (string)apply_filters('wpml_permalink', $base, $language);

        return $converted.(    $fragment !== '' ? '#'.$fragment : ''    );
    }

    /**
     * Updates the URL of an existing translated custom menu item when it
     * still points at the source-language URL. Returns the new URL when a
     * repair happened, '' otherwise.
     *
     * @param object $sourceItem
     */
    private function repairCustomLink(int $translatedItemId, $sourceItem, string $language): string {
        if ($sourceItem->type !== 'custom') {
            return '';
        }

        $convertedUrl = $this->convertUrlToLanguage((string)$sourceItem->url, $language);
        $currentUrl   = (string)get_post_meta($translatedItemId, '_menu_item_url', true);

        if ($convertedUrl === $currentUrl || $convertedUrl === (string)$sourceItem->url) {
            return '';
        }

        update_post_meta($translatedItemId, '_menu_item_url', esc_url_raw($convertedUrl));

        return $convertedUrl;
    }

    /**
     * Finds or creates the translated nav_menu term, linked to the source
     * menu's WPML translation group.
     *
     * @param \WP_Term $menu
     * @return int|WP_Error
     */
    private function ensureTranslatedMenu($menu, string $language, string $sourceLanguage) {
        $existing = (int)apply_filters('wpml_object_id', $menu->term_id, 'nav_menu', false, $language); // phpcs:ignore Zend.NamingConventions.ValidVariableName

        if ($existing && $existing !== (int)$menu->term_id) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return $existing;
        }

        $targetMenuId = wp_create_nav_menu($menu->name.' ('.strtoupper($language).')');

        if (is_wp_error($targetMenuId)) {
            return $targetMenuId;
        }

        $this->linkTranslation((int)$targetMenuId, (int)$menu->term_id, 'tax_nav_menu', $language, $sourceLanguage); // phpcs:ignore Zend.NamingConventions.ValidVariableName

        return (int)$targetMenuId;
    }

    private function linkTranslation(int $elementId, int $sourceId, string $elementType, string $language, string $sourceLanguage): void {
        $trid = apply_filters('wpml_element_trid', null, $sourceId, $elementType);

        do_action('wpml_set_element_language_details', [
            'element_id'           => $elementId,
            'element_type'         => $elementType,
            'trid'                 => $trid,
            'language_code'        => $language,
            'source_language_code' => $sourceLanguage ?: null,
        ]);
    }
}
