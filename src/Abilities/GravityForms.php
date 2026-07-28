<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use GFAPI;
use WP_Error;

/**
 * Read-only access to Gravity Forms entries. Gravity Forms is a global,
 * unnamespaced plugin (no vendor prefix on GFAPI), detected the same way
 * Rank Math is elsewhere in this codebase — via class_exists().
 */
class GravityForms implements AbilityGroup {
    private const STATUSES = ['active', 'spam', 'trash'];

    public function registerReadOnly(): void {
        $this->registerListForms();
        $this->registerListFormEntries();
    }

    public function registerWrite(): void {
        // No write ability for this group.
    }

    private function registerListForms(): void {
        wp_register_ability('content-mcp-bridge/list-gravity-forms', [
            'label'               => 'List Gravity Forms',
            'description'         => 'Lists Gravity Forms forms with their ID, title and active entry count.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'forms' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'          => ['type' => 'integer'],
                                'title'       => ['type' => 'string'],
                                'entry_count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-gravity-forms', [$this, 'listForms']),
            'permission_callback' => function (): bool {
                return current_user_can('gravity_forms_view_entries');
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

    private function registerListFormEntries(): void {
        wp_register_ability('content-mcp-bridge/list-form-entries', [
            'label'               => 'List Gravity Forms entries',
            'description'         => 'Lists submitted entries for a Gravity Forms form, with field values keyed by field label.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'form_id'   => [
                        'type'        => 'integer',
                        'description' => 'ID of the form, from list-gravity-forms.',
                    ],
                    'status'    => [
                        'type'        => 'string',
                        'enum'        => self::STATUSES,
                        'description' => 'Entry status filter. Default active.',
                    ],
                    'date_from' => [
                        'type'        => 'string',
                        'description' => 'Only entries created on or after this date (YYYY-MM-DD).',
                    ],
                    'date_to'   => [
                        'type'        => 'string',
                        'description' => 'Only entries created on or before this date (YYYY-MM-DD).',
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
                'required'             => ['form_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'form_id'     => ['type' => 'integer'],
                    'form_title'  => ['type' => 'string'],
                    'total'       => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                    'entries'     => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'           => ['type' => 'integer'],
                                'date_created' => ['type' => 'string'],
                                'status'       => ['type' => 'string'],
                                'source_url'   => ['type' => 'string'],
                                'fields'       => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-form-entries', [$this, 'listFormEntries']),
            'permission_callback' => function (): bool {
                return current_user_can('gravity_forms_view_entries');
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

    public function listForms(array $input) {
        if (!class_exists(GFAPI::class)) {
            return new WP_Error('gravity_forms_missing', 'Gravity Forms is not active on this site.');
        }

        $forms  = GFAPI::get_forms();
        $result = [];

        foreach ($forms as $form) {
            $result[] = [
                'id'          => (int)$form['id'],
                'title'       => (string)$form['title'],
                'entry_count' => (int)GFAPI::count_entries((int)$form['id']),
            ];
        }

        return ['forms' => $result];
    }

    public function listFormEntries(array $input) {
        if (!class_exists(GFAPI::class)) {
            return new WP_Error('gravity_forms_missing', 'Gravity Forms is not active on this site.');
        }

        $formId = (int)(    $input['form_id'] ?? 0    );
        $form   = $formId ? GFAPI::get_form($formId) : false;

        if (!$form) {
            return new WP_Error('form_not_found', "Form {$formId} was not found.");
        }

        $status = $input['status'] ?? 'active';

        if (!in_array($status, self::STATUSES, true)) {
            return new WP_Error('invalid_parameter', 'status must be one of: '.implode(', ', self::STATUSES).'.');
        }

        $searchCriteria = ['status' => $status];

        if (!empty($input['date_from'])) {
            $searchCriteria['start_date'] = $input['date_from'];
        }

        if (!empty($input['date_to'])) {
            $searchCriteria['end_date'] = $input['date_to'];
        }

        $perPage = min(max((int)(    $input['per_page'] ?? 20    ), 1), 100);
        $page    = max((int)(    $input['page'] ?? 1    ), 1);

        $paging = [
            'offset'    => ($page - 1) * $perPage,
            'page_size' => $perPage,
        ];

        $total = (int)GFAPI::count_entries($formId, $searchCriteria);
        $rows  = GFAPI::get_entries($formId, $searchCriteria, ['key' => 'date_created', 'direction' => 'DESC'], $paging);

        $fields  = $form['fields'] ?? [];
        $entries = [];

        foreach ($rows as $entry) {
            $values = [];

            foreach ($fields as $field) {
                $values = array_merge($values, $this->fieldValues($field, $entry));
            }

            $entries[] = [
                'id'           => (int)$entry['id'],
                'date_created' => (string)$entry['date_created'],
                'status'       => (string)$entry['status'],
                'source_url'   => (string)(    $entry['source_url'] ?? ''    ),
                'fields'       => (object)$values,
            ];
        }

        return [
            'form_id'     => $formId,
            'form_title'  => (string)(    $form['title'] ?? ''    ),
            'total'       => $total,
            'total_pages' => $perPage ? (int)ceil($total / $perPage) : 0,
            'entries'     => $entries,
        ];
    }

    /**
     * Reads one field's value(s) out of an entry, keyed by label.
     *
     * Multi-input fields (Name, Address, Checkbox, ...) store one value per
     * sub-input under IDs like "1.3"; single-value fields store directly
     * under their own field ID. Empty values are omitted rather than
     * returned as "".
     *
     * @param object $field GF_Field instance from the form definition.
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function fieldValues($field, array $entry): array {
        $values = [];

        if (!empty($field->inputs) && is_array($field->inputs)) {
            foreach ($field->inputs as $input) {
                $value = $entry[(string)$input['id']] ?? '';

                if ($value === '') {
                    continue;
                }

                $label = trim((string)(    $input['label'] ?? ''    )) ?: (string)$field->label;
                $values[$label] = $value;
            }

            return $values;
        }

        $value = $entry[(string)$field->id] ?? '';

        return $value === '' ? [] : [(string)$field->label => $value];
    }
}
