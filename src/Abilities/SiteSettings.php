<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Integrations\Multilingual;
use WP_Error;

/**
 * MCP abilities for the ACF "Options Page" — whatever it's used for on the
 * consuming site. The script-injection fields below are deliberately
 * excluded from both reading and writing: exposing them over MCP would let
 * anyone holding the server URL inject arbitrary JavaScript site-wide.
 *
 * These names match the base-project convention (global_header_script,
 * global_body_script, global_footer_script). A site using different field
 * names for script injection should adjust this list accordingly.
 */
class SiteSettings implements AbilityGroup {
    private const BLOCKED_FIELDS = [
        'global_header_script',
        'global_body_script',
        'global_footer_script',
    ];

    public function registerReadOnly(): void {
        $this->registerGetSiteSettings();
    }

    public function registerWrite(): void {
        $this->registerUpdateSiteSettings();
    }

    private function registerGetSiteSettings(): void {
        wp_register_ability('content-mcp-bridge/get-site-settings', [
            'label'               => 'Get site settings',
            'description'         => 'Reads fields from the ACF options page (global site content, e.g. footer, contacts, social icons). Field names containing "script" are never exposed.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'field'    => [
                        'type'        => 'string',
                        'description' => 'Optional single field name to fetch. Omit to get all fields.',
                    ],
                    'language' => [
                        'type'        => 'string',
                        'description' => 'Language code to read the settings in, e.g. en, fi. Omit for the default language.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'fields' => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-site-settings', [$this, 'getSiteSettings']),
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

    private function registerUpdateSiteSettings(): void {
        wp_register_ability('content-mcp-bridge/update-site-settings', [
            'label'               => 'Update site settings',
            'description'         => 'Updates fields on the ACF options page by field name. Use get-site-settings first to see current values and structure. Field names containing "script" cannot be updated.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'fields'   => [
                        'type'        => 'object',
                        'description' => 'Map of ACF field name to new value. Repeater/group fields accept the same array structure that get-site-settings returns.',
                    ],
                    'language' => [
                        'type'        => 'string',
                        'description' => 'Language code whose settings to update, e.g. en, fi. Omit for the default language.',
                    ],
                ],
                'required'             => ['fields'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'updated_fields' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-site-settings', [$this, 'updateSiteSettings']),
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
    public function getSiteSettings(array $input) {
        if (!function_exists('get_fields')) {
            return new WP_Error('acf_missing', 'ACF is not active.');
        }

        $previousLanguage = $this->switchToLanguage((string)(    $input['language'] ?? ''    ));

        if (is_wp_error($previousLanguage)) {
            return $previousLanguage;
        }

        $field = isset($input['field']) ? (string)$input['field'] : '';

        if ($field && in_array($field, self::BLOCKED_FIELDS, true)) {
            $this->restoreLanguage($previousLanguage);

            return new WP_Error('field_not_allowed', "Field '{$field}' is not exposed over MCP.");
        }

        if ($field) {
            $fields = [$field => get_field($field, 'option')];
        } else {
            $fields = get_fields('option');
            $fields = is_array($fields) ? $fields : [];

            foreach (self::BLOCKED_FIELDS as $blocked) {
                unset($fields[$blocked]);
            }
        }

        $this->restoreLanguage($previousLanguage);

        return ['fields' => (object)$fields];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function updateSiteSettings(array $input) {
        if (!function_exists('update_field')) {
            return new WP_Error('acf_missing', 'ACF is not active.');
        }

        $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];

        if (!$fields) {
            return new WP_Error('nothing_to_update', 'No fields were provided to update.');
        }

        foreach (array_keys($fields) as $name) {
            if (in_array($name, self::BLOCKED_FIELDS, true)) {
                return new WP_Error('field_not_allowed', "Field '{$name}' cannot be updated over MCP.");
            }

            if (!acf_get_field((string)$name)) {
                return new WP_Error('unknown_field', "Field '{$name}' does not exist on the options page.");
            }
        }

        $previousLanguage = $this->switchToLanguage((string)(    $input['language'] ?? ''    ));

        if (is_wp_error($previousLanguage)) {
            return $previousLanguage;
        }

        $updatedFields = [];

        foreach ($fields as $name => $value) {
            update_field((string)$name, $value, 'option');
            $updatedFields[] = (string)$name;
        }

        $this->restoreLanguage($previousLanguage);

        return ['updated_fields' => $updatedFields];
    }

    /**
     * @return string|WP_Error
     */
    private function switchToLanguage(string $language) {
        if (!$language || !Multilingual::isEnabled()) {
            return '';
        }

        if (!array_key_exists($language, Multilingual::getAllActiveLanguages())) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        $previousLanguage = Multilingual::getCurrentLanguage();
        Multilingual::switchLanguage($language);

        return $previousLanguage;
    }

    private function restoreLanguage(string $previousLanguage): void {
        if ($previousLanguage) {
            Multilingual::switchLanguage($previousLanguage);
        }
    }
}
