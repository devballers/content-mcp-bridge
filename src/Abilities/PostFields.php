<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use WP_Error;

/**
 * MCP abilities for ACF fields on posts and pages.
 *
 * The generic get-post/update-post abilities only handle plain string meta,
 * so structured ACF content (flexible sections, link/button fields, repeaters,
 * groups) is invisible to them. These abilities read and write ACF values
 * through the ACF API, which handles the serialized storage format.
 */
class PostFields implements AbilityGroup {
    public function registerReadOnly(): void {
        $this->registerGetPostFields();
    }

    public function registerWrite(): void {
        $this->registerUpdatePostField();
    }

    private function registerGetPostFields(): void {
        wp_register_ability('content-mcp-bridge/get-post-fields', [
            'label'               => 'Get post ACF fields',
            'description'         => 'Reads all ACF field values of a post or page, including flexible content sections, buttons (link fields), repeaters and groups. Use this to see the content structure before updating a field.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the post (language version) to read.',
                    ],
                ],
                'required'             => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'     => ['type' => 'integer'],
                    'fields' => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-post-fields', [$this, 'getPostFields']),
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

    private function registerUpdatePostField(): void {
        wp_register_ability('content-mcp-bridge/update-post-field', [
            'label'               => 'Update post ACF field',
            'description'         => 'Updates one ACF field value on a post or page. Supports nested fields inside flexible content sections, repeaters and groups via a dot-notation path. Always call get-post-fields first to see the structure and current values. Repeater rows: address a sub-field as "phone_repeater.0.phone_number", set a whole row with an object at "phone_repeater.0", append a row by using the index one past the last, delete a row by setting it to null. Link/button fields take an object like {"title": "Read more", "url": "/about/", "target": ""}.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id'    => [
                        'type'        => 'integer',
                        'description' => 'ID of the post (language version) to update.',
                    ],
                    'path'       => [
                        'type'        => 'string',
                        'description' => 'Field path in dot notation, mirroring the structure that get-post-fields returns: field names, 0-based row indexes for flexible content and repeaters, and group names, e.g. "sections.0.content_group.button_primary".',
                    ],
                    'value_json' => [
                        'type'        => 'string',
                        'description' => 'The new value, JSON-encoded. A plain string value must be JSON-quoted, e.g. "\"New title\"". A link/button field takes an object, e.g. {"title":"Read more","url":"/about/","target":""}.',
                    ],
                ],
                'required'             => [
                    'post_id', 'path', 'value_json'
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'   => ['type' => 'integer'],
                    'path' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-post-field', [$this, 'updatePostField']),
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
    public function getPostFields(array $input) {
        if (!function_exists('get_fields')) {
            return new WP_Error('acf_missing', 'ACF is not active.');
        }

        $post = PostGuard::resolve((int)(    $input['post_id'] ?? 0    ));

        if (is_wp_error($post)) {
            return $post;
        }

        $fields = get_fields($post->ID);

        return [
            'id'     => $post->ID,
            'fields' => (object)(    is_array($fields) ? $fields : []    ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function updatePostField(array $input) {
        if (!function_exists('update_field')) {
            return new WP_Error('acf_missing', 'ACF is not active.');
        }

        $post = PostGuard::resolve((int)(    $input['post_id'] ?? 0    ));

        if (is_wp_error($post)) {
            return $post;
        }

        $postId = $post->ID; // phpcs:ignore Zend.NamingConventions.ValidVariableName
        $path   = trim((string)(    $input['path'] ?? ''    ), " \t\n\r.");

        if ($path === '') {
            return new WP_Error('missing_path', 'Field path is required.');
        }

        $value = json_decode((string)(    $input['value_json'] ?? ''    ), true);

        if ($value === null && trim((string)$input['value_json']) !== 'null') {
            return new WP_Error('invalid_value', 'value_json is not valid JSON.');
        }

        $parts    = explode('.', $path);
        $rootName = array_shift($parts);

        $rootField = acf_maybe_get_field($rootName, $postId, false);

        if (!is_array($rootField) || empty($rootField['key'])) {
            return new WP_Error('unknown_field', "No ACF field named '{$rootName}' on post {$postId}. Check the path against get-post-fields output.");
        }

        if ($parts === []) {
            $newValue = $value;
        } else {
            // Rewrite the whole root value instead of the flat meta name
            // (sections_0_button) so ACF maintains repeater row counts and
            // renumbers rows itself — flat writes cannot add or remove rows,
            // and they fail entirely on posts whose meta was never saved.
            // The unformatted value keys rows by field key, so path segments
            // are resolved against the field definitions along the way.
            $rootValue = get_field($rootField['key'], $postId, false);
            $newValue  = $this->setNestedValue($rootField, is_array($rootValue) ? $rootValue : [], $parts, $value, $path);

            if (is_wp_error($newValue)) {
                return $newValue;
            }
        }

        $updated = update_field($rootField['key'], $newValue, $postId);

        // update_field reports false when the stored value did not change;
        // only treat it as a failure when the value really was not written.
        if (!$updated && get_field($rootField['key'], $postId, false) != $newValue) { // phpcs:ignore Universal.Operators.StrictComparisons
            return new WP_Error(
                'update_failed',
                "Could not update '{$path}'. Check the path against get-post-fields output."
            );
        }

        return [
            'id'   => $postId,
            'path' => $path,
        ];
    }

    /**
     * Sets a value inside the unformatted value of a repeater, flexible
     * content or group field, following the remaining dot-notation segments.
     *
     * @param array<string, mixed> $field Field definition the value belongs to.
     * @param mixed                $node  Current unformatted value of that field.
     * @param string[]             $parts Remaining path segments.
     * @param mixed                $value New value for the final segment.
     * @return mixed|WP_Error
     */
    private function setNestedValue(array $field, $node, array $parts, $value, string $path) {
        $segment = array_shift($parts);
        $type    = $field['type'] ?? '';

        if ($type === 'repeater' || $type === 'flexible_content') {
            if (!preg_match('/^\d+$/', (string)$segment)) {
                return new WP_Error('invalid_path', "'{$field['name']}' is a {$type} field, so '{$path}' must address a 0-based row index after it, not '{$segment}'.");
            }

            $rows  = is_array($node) ? array_values($node) : [];
            $count = count($rows);
            $index = (int)$segment;

            if ($index > $count) {
                return new WP_Error('row_out_of_range', "'{$field['name']}' has {$count} row(s); row {$index} does not exist. Use index {$count} to append a new row.");
            }

            if ($parts === []) {
                if ($value === null) {
                    array_splice($rows, $index, 1);
                    return $rows;
                }

                if (!is_array($value)) {
                    return new WP_Error('invalid_value', "A whole row of '{$field['name']}' takes an object of sub-field values, or null to delete the row.");
                }

                if ($type === 'flexible_content' && empty($value['acf_fc_layout']) && empty($rows[$index]['acf_fc_layout'])) {
                    return new WP_Error('missing_layout', "A new '{$field['name']}' section needs an 'acf_fc_layout' key naming its layout.");
                }

                $rows[$index] = ($value['acf_fc_layout'] ?? null) === null && isset($rows[$index]['acf_fc_layout'])
                    ? array_merge(['acf_fc_layout' => $rows[$index]['acf_fc_layout']], $value)
                    : $value;

                return $rows;
            }

            $row       = isset($rows[$index]) && is_array($rows[$index]) ? $rows[$index] : [];
            $subFields = $this->rowSubFields($field, $row);

            if (is_wp_error($subFields)) {
                return $subFields;
            }

            $row = $this->setInRow($subFields, $row, $parts, $value, $path);

            if (is_wp_error($row)) {
                return $row;
            }

            $rows[$index] = $row;

            return $rows;
        }

        if ($type === 'group') {
            array_unshift($parts, $segment);

            return $this->setInRow($field['sub_fields'] ?? [], is_array($node) ? $node : [], $parts, $value, $path);
        }

        return new WP_Error('invalid_path', "'{$field['name']}' ({$type}) has no nested fields, but '{$path}' continues past it.");
    }

    /**
     * Sets a value inside one repeater/flexible row or group value, where the
     * next path segment names a sub-field.
     *
     * @param array<int, array<string, mixed>> $subFields Sub-field definitions of the row.
     * @param array<string, mixed>             $row       Unformatted row value, keyed by field key.
     * @param string[]                         $parts     Remaining path segments.
     * @param mixed                            $value     New value for the final segment.
     * @return array<string, mixed>|WP_Error
     */
    private function setInRow(array $subFields, array $row, array $parts, $value, string $path) {
        $name = array_shift($parts);
        $sub  = null;

        foreach ($subFields as $candidate) {
            if (($candidate['name'] ?? '') === $name || ($candidate['key'] ?? '') === $name) {
                $sub = $candidate;
                break;
            }
        }

        if ($sub === null) {
            $names = implode(', ', array_filter(array_column($subFields, 'name')));

            return new WP_Error('unknown_field', "No sub-field '{$name}' in '{$path}'. Available sub-fields: {$names}.");
        }

        // Unformatted rows key values by field key; drop a stale name-keyed
        // duplicate so ACF cannot prefer it over the value written below.
        $current = $row[$sub['key']] ?? ($row[$sub['name']] ?? null);
        unset($row[$sub['name']]);

        if ($parts === []) {
            $row[$sub['key']] = $value;

            return $row;
        }

        $nested = $this->setNestedValue($sub, $current, $parts, $value, $path);

        if (is_wp_error($nested)) {
            return $nested;
        }

        $row[$sub['key']] = $nested;

        return $row;
    }

    /**
     * Resolves the sub-field definitions of one row: a repeater's own
     * sub-fields, or the sub-fields of the layout a flexible content row uses.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function rowSubFields(array $field, array $row) {
        if (($field['type'] ?? '') !== 'flexible_content') {
            return $field['sub_fields'] ?? [];
        }

        $layoutName = $row['acf_fc_layout'] ?? '';

        foreach ($field['layouts'] ?? [] as $layout) {
            if (($layout['name'] ?? '') === $layoutName) {
                return $layout['sub_fields'] ?? [];
            }
        }

        return new WP_Error('missing_layout', "Cannot resolve the layout of this '{$field['name']}' row. To create a new section, set the whole row with an 'acf_fc_layout' key instead.");
    }
}
