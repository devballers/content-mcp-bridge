<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use GFAPI;
use WP_Error;

/**
 * Gravity Forms access via GFAPI. Read abilities list forms/entries and return
 * form definitions; write abilities create, update and duplicate forms.
 * Detected the same way Rank Math is elsewhere — via class_exists().
 */
class GravityForms implements AbilityGroup {
    private const STATUSES = ['active', 'spam', 'trash'];

    private const FIELD_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'id'           => ['type' => 'integer'],
            'type'         => ['type' => 'string'],
            'label'        => ['type' => 'string'],
            'admin_label'  => ['type' => 'string'],
            'description'  => ['type' => 'string'],
            'is_required'  => ['type' => 'boolean'],
            'placeholder'  => ['type' => 'string'],
            'default_value'=> ['type' => 'string'],
            'choices'      => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'text'       => ['type' => 'string'],
                        'value'      => ['type' => 'string'],
                        'isSelected' => ['type' => 'boolean'],
                    ],
                ],
            ],
            'inputs'       => ['type' => 'array'],
            'visibility'   => ['type' => 'string'],
            'cssClass'     => ['type' => 'string'],
        ],
    ];

    public function registerReadOnly(): void {
        $this->registerListForms();
        $this->registerGetForm();
        $this->registerListFormEntries();
    }

    public function registerWrite(): void {
        $this->registerCreateForm();
        $this->registerUpdateForm();
        $this->registerDuplicateForm();
    }

    private function registerListForms(): void {
        wp_register_ability('content-mcp-bridge/list-gravity-forms', [
            'label'               => 'List Gravity Forms',
            'description'         => 'Lists Gravity Forms forms with their ID, title, active flag and active entry count.',
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
                                'is_active'   => ['type' => 'boolean'],
                                'entry_count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-gravity-forms', [$this, 'listForms']),
            'permission_callback' => function (): bool {
                return current_user_can('gravityforms_view_entries')
                    || current_user_can('gravityforms_edit_forms');
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

    private function registerGetForm(): void {
        wp_register_ability('content-mcp-bridge/get-gravity-form', [
            'label'               => 'Get Gravity Form',
            'description'         => 'Returns a Gravity Forms form definition: title, description, active flag and fields (id, type, label, required, choices, …). Use before update-gravity-form.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'form_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the form, from list-gravity-forms.',
                    ],
                ],
                'required'             => ['form_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'          => ['type' => 'integer'],
                    'title'       => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'is_active'   => ['type' => 'boolean'],
                    'fields'      => [
                        'type'  => 'array',
                        'items' => self::FIELD_SCHEMA,
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-gravity-form', [$this, 'getForm']),
            'permission_callback' => function (): bool {
                return current_user_can('gravityforms_edit_forms')
                    || current_user_can('gravityforms_view_entries');
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
                return current_user_can('gravityforms_view_entries');
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

    private function registerCreateForm(): void {
        wp_register_ability('content-mcp-bridge/create-gravity-form', [
            'label'               => 'Create Gravity Form',
            'description'         => 'Creates a new Gravity Forms form. Pass fields as an array of field objects (type + label required per field; id is assigned when omitted).',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'title'       => [
                        'type'        => 'string',
                        'description' => 'Form title.',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Form description shown above the fields.',
                    ],
                    'fields'      => [
                        'type'        => 'array',
                        'description' => 'Field definitions. Each needs at least type and label (e.g. text, email, phone, textarea, checkbox, select, radio, name, hidden).',
                        'items'       => self::FIELD_SCHEMA,
                    ],
                ],
                'required'             => ['title'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'     => ['type' => 'integer'],
                    'title'  => ['type' => 'string'],
                    'fields' => [
                        'type'  => 'array',
                        'items' => self::FIELD_SCHEMA,
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/create-gravity-form', [$this, 'createForm']),
            'permission_callback' => function (): bool {
                return current_user_can('gravityforms_create_form');
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

    private function registerUpdateForm(): void {
        wp_register_ability('content-mcp-bridge/update-gravity-form', [
            'label'               => 'Update Gravity Form',
            'description'         => 'Updates a Gravity Forms form title, description, active flag and/or fields. Omitted properties are left unchanged. When fields is provided it replaces the full field list — get-gravity-form first, edit, then send the complete fields array back.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'form_id'     => [
                        'type'        => 'integer',
                        'description' => 'ID of the form to update.',
                    ],
                    'title'       => [
                        'type'        => 'string',
                        'description' => 'New form title.',
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'New form description.',
                    ],
                    'is_active'   => [
                        'type'        => 'boolean',
                        'description' => 'Whether the form accepts submissions.',
                    ],
                    'fields'      => [
                        'type'        => 'array',
                        'description' => 'Complete replacement field list. Include existing field ids to keep them; omit an id to add a new field.',
                        'items'       => self::FIELD_SCHEMA,
                    ],
                ],
                'required'             => ['form_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'            => ['type' => 'integer'],
                    'title'         => ['type' => 'string'],
                    'description'   => ['type' => 'string'],
                    'is_active'     => ['type' => 'boolean'],
                    'fields'        => [
                        'type'  => 'array',
                        'items' => self::FIELD_SCHEMA,
                    ],
                    'updated_fields'=> [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-gravity-form', [$this, 'updateForm']),
            'permission_callback' => function (): bool {
                return current_user_can('gravityforms_edit_forms');
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

    private function registerDuplicateForm(): void {
        wp_register_ability('content-mcp-bridge/duplicate-gravity-form', [
            'label'               => 'Duplicate Gravity Form',
            'description'         => 'Duplicates an existing Gravity Forms form, including its fields, confirmations and notifications. Optionally rename the copy.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'form_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the form to duplicate.',
                    ],
                    'title'   => [
                        'type'        => 'string',
                        'description' => 'Title for the copy. Defaults to the source title with " (Copy)" appended.',
                    ],
                ],
                'required'             => ['form_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'          => ['type' => 'integer'],
                    'title'       => ['type' => 'string'],
                    'source_id'   => ['type' => 'integer'],
                    'fields'      => [
                        'type'  => 'array',
                        'items' => self::FIELD_SCHEMA,
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/duplicate-gravity-form', [$this, 'duplicateForm']),
            'permission_callback' => function (): bool {
                return current_user_can('gravityforms_create_form')
                    && current_user_can('gravityforms_edit_forms');
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

    public function listForms(array $input) {
        if (!class_exists(GFAPI::class)) {
            return new WP_Error('gravity_forms_missing', 'Gravity Forms is not active on this site.');
        }

        $forms  = GFAPI::get_forms();
        $result = [];

        foreach ($forms as $form) {
            $id = (int)$this->formValue($form, 'id');

            if (!$id) {
                continue;
            }

            $isActive = $this->formValue($form, 'is_active');

            $result[] = [
                'id'          => $id,
                'title'       => (string)$this->formValue($form, 'title'),
                'is_active'   => $isActive === null ? true : (bool)$isActive,
                'entry_count' => (int)GFAPI::count_entries($id),
            ];
        }

        return ['forms' => $result];
    }

    public function getForm(array $input) {
        if (!class_exists(GFAPI::class)) {
            return new WP_Error('gravity_forms_missing', 'Gravity Forms is not active on this site.');
        }

        $form = $this->loadForm((int)(    $input['form_id'] ?? 0    ));

        if (is_wp_error($form)) {
            return $form;
        }

        return $this->summarizeForm($form);
    }

    public function listFormEntries(array $input) {
        if (!class_exists(GFAPI::class)) {
            return new WP_Error('gravity_forms_missing', 'Gravity Forms is not active on this site.');
        }

        $formId = (int)(    $input['form_id'] ?? 0    );
        $form   = $formId ? GFAPI::get_form($formId) : false;

        if (!$form || is_wp_error($form)) {
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

    public function createForm(array $input) {
        if (!class_exists(GFAPI::class)) {
            return new WP_Error('gravity_forms_missing', 'Gravity Forms is not active on this site.');
        }

        $title = trim((string)(    $input['title'] ?? ''    ));

        if ($title === '') {
            return new WP_Error('invalid_parameter', 'title is required.');
        }

        $fields = $this->normalizeFieldsInput($input['fields'] ?? []);

        if (is_wp_error($fields)) {
            return $fields;
        }

        $form = [
            'title'       => $title,
            'description' => (string)(    $input['description'] ?? ''    ),
            'fields'      => $fields,
            'button'      => [
                'type' => 'text',
                'text' => 'Submit',
            ],
        ];

        $formId = GFAPI::add_form($form);

        if (is_wp_error($formId)) {
            return $formId;
        }

        $created = GFAPI::get_form((int)$formId);

        if (!$created || is_wp_error($created)) {
            return new WP_Error('form_create_failed', 'Form was created but could not be reloaded.');
        }

        $summary = $this->summarizeForm($created);

        return [
            'id'     => $summary['id'],
            'title'  => $summary['title'],
            'fields' => $summary['fields'],
        ];
    }

    public function updateForm(array $input) {
        if (!class_exists(GFAPI::class)) {
            return new WP_Error('gravity_forms_missing', 'Gravity Forms is not active on this site.');
        }

        $form = $this->loadForm((int)(    $input['form_id'] ?? 0    ));

        if (is_wp_error($form)) {
            return $form;
        }

        $updatedFields = [];

        if (array_key_exists('title', $input)) {
            $title = trim((string)$input['title']);

            if ($title === '') {
                return new WP_Error('invalid_parameter', 'title cannot be empty.');
            }

            $form['title']     = $title;
            $updatedFields[]   = 'title';
        }

        if (array_key_exists('description', $input)) {
            $form['description'] = (string)$input['description'];
            $updatedFields[]     = 'description';
        }

        if (array_key_exists('is_active', $input)) {
            $form['is_active'] = (bool)$input['is_active'];
            $updatedFields[]   = 'is_active';
        }

        if (array_key_exists('fields', $input)) {
            if (!is_array($input['fields'])) {
                return new WP_Error('invalid_parameter', 'fields must be an array of field objects.');
            }

            $fields = $this->normalizeFieldsInput($input['fields']);

            if (is_wp_error($fields)) {
                return $fields;
            }

            $form['fields']  = $fields;
            $updatedFields[] = 'fields';
        }

        if (!$updatedFields) {
            return new WP_Error('nothing_to_update', 'No fields were provided to update.');
        }

        $result = GFAPI::update_form($form);

        if (is_wp_error($result)) {
            return $result;
        }

        // is_active lives on the forms table, not only in form meta.
        if (in_array('is_active', $updatedFields, true)) {
            $propertyResult = GFAPI::update_form_property(
                (int)$form['id'],
                'is_active',
                $form['is_active'] ? '1' : '0'
            );

            if (is_wp_error($propertyResult)) {
                return $propertyResult;
            }
        }

        if (in_array('title', $updatedFields, true)) {
            $propertyResult = GFAPI::update_form_property((int)$form['id'], 'title', $form['title']);

            if (is_wp_error($propertyResult)) {
                return $propertyResult;
            }
        }

        $reloaded = GFAPI::get_form((int)$form['id']);

        if (!$reloaded || is_wp_error($reloaded)) {
            return new WP_Error('form_update_failed', 'Form was updated but could not be reloaded.');
        }

        $summary                   = $this->summarizeForm($reloaded);
        $summary['updated_fields'] = $updatedFields;

        return $summary;
    }

    public function duplicateForm(array $input) {
        if (!class_exists(GFAPI::class)) {
            return new WP_Error('gravity_forms_missing', 'Gravity Forms is not active on this site.');
        }

        $sourceId = (int)(    $input['form_id'] ?? 0    );
        $source   = $this->loadForm($sourceId);

        if (is_wp_error($source)) {
            return $source;
        }

        $newId = GFAPI::duplicate_form($sourceId);

        if (is_wp_error($newId)) {
            return $newId;
        }

        $title = trim((string)(    $input['title'] ?? ''    ));

        if ($title === '') {
            $title = (string)(    $source['title'] ?? 'Form'    ).' (Copy)';
        }

        $propertyResult = GFAPI::update_form_property((int)$newId, 'title', $title);

        if (is_wp_error($propertyResult)) {
            return $propertyResult;
        }

        $copy = GFAPI::get_form((int)$newId);

        if (!$copy || is_wp_error($copy)) {
            return new WP_Error('form_duplicate_failed', 'Form was duplicated but could not be reloaded.');
        }

        $summary = $this->summarizeForm($copy);

        return [
            'id'        => $summary['id'],
            'title'     => $summary['title'],
            'source_id' => $sourceId,
            'fields'    => $summary['fields'],
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function loadForm(int $formId) {
        if (!$formId) {
            return new WP_Error('invalid_parameter', 'form_id is required.');
        }

        $form = GFAPI::get_form($formId);

        if (!$form || is_wp_error($form)) {
            return new WP_Error('form_not_found', "Form {$formId} was not found.");
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $form
     * @return array{id: int, title: string, description: string, is_active: bool, fields: array<int, array<string, mixed>>}
     */
    private function summarizeForm(array $form): array {
        $fields = [];

        foreach ($form['fields'] ?? [] as $field) {
            $fields[] = $this->serializeField($field);
        }

        return [
            'id'          => (int)$form['id'],
            'title'       => (string)(    $form['title'] ?? ''    ),
            'description' => (string)(    $form['description'] ?? ''    ),
            'is_active'   => !isset($form['is_active']) || (bool)$form['is_active'],
            'fields'      => $fields,
        ];
    }

    /**
     * GFAPI::get_forms() returns lightweight stdClass rows in some versions
     * and full form arrays in others — read a property from either shape.
     *
     * @param array<string, mixed>|object $form
     * @return mixed
     */
    private function formValue($form, string $key) {
        if (is_array($form)) {
            return $form[$key] ?? null;
        }

        return $form->{$key} ?? null;
    }

    /**
     * @param array<int, mixed> $fields
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function normalizeFieldsInput(array $fields) {
        $normalized = [];
        $nextId     = 1;

        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                return new WP_Error(
                    'invalid_parameter',
                    "fields[{$index}] must be an object with at least type and label."
                );
            }

            $type  = trim((string)(    $field['type'] ?? ''    ));
            $label = trim((string)(    $field['label'] ?? ''    ));

            if ($type === '' || $label === '') {
                return new WP_Error(
                    'invalid_parameter',
                    "fields[{$index}] requires both type and label."
                );
            }

            $id = isset($field['id']) ? (int)$field['id'] : 0;

            if ($id < 1) {
                $id = $nextId;
            }

            $nextId = max($nextId, $id + 1);

            $row = [
                'id'    => $id,
                'type'  => $type,
                'label' => $label,
            ];

            $optional = [
                'adminLabel'   => 'admin_label',
                'description'  => 'description',
                'isRequired'   => 'is_required',
                'placeholder'  => 'placeholder',
                'defaultValue' => 'default_value',
                'cssClass'     => 'cssClass',
                'visibility'   => 'visibility',
            ];

            foreach ($optional as $gfKey => $inputKey) {
                if (array_key_exists($inputKey, $field)) {
                    $row[$gfKey] = $field[$inputKey];
                } elseif (array_key_exists($gfKey, $field)) {
                    $row[$gfKey] = $field[$gfKey];
                }
            }

            if (array_key_exists('is_required', $field)) {
                $row['isRequired'] = (bool)$field['is_required'];
            }

            if (!empty($field['choices']) && is_array($field['choices'])) {
                $row['choices'] = array_values(array_map(static function ($choice) {
                    if (!is_array($choice)) {
                        return ['text' => (string)$choice, 'value' => (string)$choice];
                    }

                    $text = (string)(    $choice['text'] ?? $choice['value'] ?? ''    );

                    return [
                        'text'       => $text,
                        'value'      => (string)(    $choice['value'] ?? $text    ),
                        'isSelected' => (bool)(    $choice['isSelected'] ?? false    ),
                    ];
                }, $field['choices']));
            }

            if (!empty($field['inputs']) && is_array($field['inputs'])) {
                $row['inputs'] = $field['inputs'];
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * @param object|array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function serializeField($field): array {
        $get = static function (string $key, $default = null) use ($field) {
            if (is_array($field)) {
                return $field[$key] ?? $default;
            }

            return $field->{$key} ?? $default;
        };

        $serialized = [
            'id'            => (int)$get('id'),
            'type'          => (string)$get('type', ''),
            'label'         => (string)$get('label', ''),
            'admin_label'   => (string)$get('adminLabel', ''),
            'description'   => (string)$get('description', ''),
            'is_required'   => (bool)$get('isRequired', false),
            'placeholder'   => (string)$get('placeholder', ''),
            'default_value' => (string)$get('defaultValue', ''),
            'visibility'    => (string)$get('visibility', 'visible'),
            'cssClass'      => (string)$get('cssClass', ''),
        ];

        $choices = $get('choices');

        if (is_array($choices) && $choices) {
            $serialized['choices'] = array_values(array_map(static function ($choice) {
                if (!is_array($choice)) {
                    return ['text' => (string)$choice, 'value' => (string)$choice];
                }

                return [
                    'text'       => (string)(    $choice['text'] ?? ''    ),
                    'value'      => (string)(    $choice['value'] ?? ''    ),
                    'isSelected' => (bool)(    $choice['isSelected'] ?? false    ),
                ];
            }, $choices));
        }

        $inputs = $get('inputs');

        if (is_array($inputs) && $inputs) {
            $serialized['inputs'] = $inputs;
        }

        return $serialized;
    }

    /**
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
