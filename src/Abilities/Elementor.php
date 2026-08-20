<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use WP_Error;

/**
 * MCP abilities for Elementor page content.
 *
 * Elementor stores an entire page as one JSON tree in the protected
 * `_elementor_data` meta key, so the generic get-post/update-post abilities
 * cannot see it (they skip underscore-prefixed keys) and cannot write it
 * (they reject protected meta outright). Dumping that tree wholesale is no
 * use either — a real page is routinely megabytes of layout scaffolding
 * wrapping a few hundred words of text.
 *
 * These abilities therefore flatten the tree to just its text-bearing
 * settings, each addressed by a path built from Elementor's own element
 * ids (`4a1b2c3d.title`) rather than array indexes, because ids survive the
 * reordering and drag-and-drop that indexes do not. Writes replace one leaf
 * string and re-encode the tree otherwise untouched, so layout, styling and
 * every widget setting this plugin does not understand pass through
 * unchanged.
 */
class Elementor implements AbilityGroup {
    private const META_KEY = '_elementor_data';

    private const CSS_META_KEY = '_elementor_css';

    /**
     * Widget settings that hold human-readable text. Anything outside this
     * list (URLs, sizes, colors, css ids, structural flags) stays
     * unreachable: an editing client should not be able to repoint a link
     * or break a layout through a "text" ability, and a typo'd path should
     * fail loudly rather than silently land somewhere harmful.
     */
    private const TEXT_KEYS = [
        'title',
        'editor',
        'text',
        'description_text',
        'title_text',
        'caption',
        'html',
        'tab_title',
        'tab_content',
        'inner_text',
        'testimonial_content',
        'testimonial_name',
        'testimonial_job',
        'alert_title',
        'alert_description',
        'before_text',
        'highlighted_text',
        'rotating_text',
        'after_text',
    ];

    public function registerReadOnly(): void {
        $this->registerGetElementorContent();
    }

    public function registerWrite(): void {
        $this->registerUpdateElementorText();
    }

    private function registerGetElementorContent(): void {
        wp_register_ability('content-mcp-bridge/get-elementor-content', [
            'label'               => 'Get Elementor content',
            'description'         => 'Lists the editable text nodes of an Elementor page, each with a stable path and its current value. Layout, styling and non-text settings are omitted. Always call this before update-elementor-text to see the available paths.',
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
                    'id'    => ['type' => 'integer'],
                    'nodes' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'path'       => ['type' => 'string'],
                                'widget'     => ['type' => 'string'],
                                'setting'    => ['type' => 'string'],
                                'value'      => ['type' => 'string'],
                                'is_html'    => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-elementor-content', [$this, 'getElementorContent']),
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

    private function registerUpdateElementorText(): void {
        wp_register_ability('content-mcp-bridge/update-elementor-text', [
            'label'               => 'Update Elementor text',
            'description'         => 'Replaces the text of one Elementor node, addressed by the path that get-elementor-content returns. Only text settings can be written; layout and styling are untouched. Always call get-elementor-content first.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the post (language version) to update.',
                    ],
                    'path'    => [
                        'type'        => 'string',
                        'description' => 'Node path as returned by get-elementor-content, e.g. "4a1b2c3d.title". The first segment is Elementor\'s element id, the second the setting name.',
                    ],
                    'value'   => [
                        'type'        => 'string',
                        'description' => 'The new text. Nodes reported with is_html true accept inline HTML markup; the others are stored as plain text.',
                    ],
                ],
                'required'             => [
                    'post_id', 'path', 'value'
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'      => ['type' => 'integer'],
                    'path'    => ['type' => 'string'],
                    'value'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-elementor-text', [$this, 'updateElementorText']),
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
    public function getElementorContent(array $input) {
        $post = PostGuard::resolve((int)(    $input['post_id'] ?? 0    ));

        if (is_wp_error($post)) {
            return $post;
        }

        $tree = $this->readTree($post->ID);

        if (is_wp_error($tree)) {
            return $tree;
        }

        $nodes = [];
        $this->collect($tree, $nodes);

        return [
            'id'    => $post->ID,
            'nodes' => $nodes,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function updateElementorText(array $input) {
        $post = PostGuard::resolve((int)(    $input['post_id'] ?? 0    ));

        if (is_wp_error($post)) {
            return $post;
        }

        $postId = $post->ID; // phpcs:ignore Zend.NamingConventions.ValidVariableName
        $path   = trim((string)(    $input['path'] ?? ''    ));
        $value  = (string)(    $input['value'] ?? ''    );

        if ($path === '') {
            return new WP_Error('missing_path', 'Node path is required.');
        }

        $segments = explode('.', $path);

        if (count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            return new WP_Error('invalid_path', "Path '{$path}' is not in the expected 'element_id.setting' form. Check it against get-elementor-content output.");
        }

        [$elementId, $setting] = $segments;

        if (!in_array($setting, self::TEXT_KEYS, true)) {
            return new WP_Error('unsupported_setting', "Setting '{$setting}' is not an editable text setting. Only the settings that get-elementor-content reports can be written.");
        }

        $tree = $this->readTree($postId);

        if (is_wp_error($tree)) {
            return $tree;
        }

        // Preserve the node's own markup convention: a value that was HTML
        // stays HTML, a plain-text one is escaped, so a text edit can never
        // introduce markup into a node that never had any.
        $existing = $this->findValue($tree, $elementId, $setting);

        if ($existing === null) {
            return new WP_Error('unknown_node', "No text node found at '{$path}' on post {$postId}. Check the path against get-elementor-content output.");
        }

        $stored  = $this->isHtml($setting) ? wp_kses_post($value) : sanitize_textarea_field($value);
        $applied = $this->replace($tree, $elementId, $setting, $stored);

        if (!$applied) {
            return new WP_Error('update_failed', "Could not update '{$path}' on post {$postId}.");
        }

        $written = $this->writeTree($postId, $tree);

        if (is_wp_error($written)) {
            return $written;
        }

        $this->flushCache($postId);

        return [
            'id'    => $postId,
            'path'  => $path,
            'value' => $stored,
        ];
    }

    /**
     * Elementor stores the tree as a JSON string inside meta. Reading it
     * with get_post_meta() rather than Elementor's own document API keeps
     * this working when the page is edited outside the Elementor editor.
     *
     * @return array<int, mixed>|WP_Error
     */
    private function readTree(int $postId) {
        $raw = get_post_meta($postId, self::META_KEY, true);

        if (!is_string($raw) || trim($raw) === '') {
            return new WP_Error('not_elementor', "Post {$postId} has no Elementor content.");
        }

        $tree = json_decode($raw, true);

        if (!is_array($tree)) {
            return new WP_Error('invalid_elementor_data', "The Elementor data on post {$postId} is not valid JSON and was left untouched.");
        }

        return $tree;
    }

    /**
     * Re-encodes with the same flags Elementor itself uses. Unescaped
     * slashes and unicode matter: re-encoding them differently would
     * rewrite every string in the tree, turning a one-word edit into a
     * whole-page diff.
     *
     * @param array<int, mixed> $tree
     * @return true|WP_Error
     */
    private function writeTree(int $postId, array $tree) {
        $json = wp_json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($json)) {
            return new WP_Error('encode_failed', 'Could not re-encode the Elementor data; the post was left untouched.');
        }

        // update_post_meta() runs the value through stripslashes, which
        // would corrupt escaped sequences inside the JSON, so the slashes
        // are added back first — the same round-trip Elementor performs.
        update_post_meta($postId, self::META_KEY, wp_slash($json));

        return true;
    }

    /**
     * Drops the generated CSS so the next page view regenerates it from the
     * new content. Without this the edit is stored but stale CSS keeps
     * rendering the old text in some widgets.
     */
    private function flushCache(int $postId): void {
        delete_post_meta($postId, self::CSS_META_KEY);

        if (class_exists('\Elementor\Plugin')) {
            $plugin = \Elementor\Plugin::$instance;

            if (isset($plugin->files_manager)) {
                $plugin->files_manager->clear_cache();
            }
        }
    }

    /**
     * Walks the tree depth-first, emitting one entry per text-bearing
     * setting. Elements nest arbitrarily (section > column > widget, plus
     * inner sections and containers), so recursion follows `elements`
     * wherever it appears rather than assuming a fixed depth.
     *
     * @param array<int, mixed> $elements
     * @param array<int, array<string, mixed>> $nodes
     */
    private function collect(array $elements, array &$nodes): void {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $id       = (string)(    $element['id'] ?? ''    );
            $settings = $element['settings'] ?? [];

            if ($id !== '' && is_array($settings)) {
                foreach (self::TEXT_KEYS as $key) {
                    $value = $settings[$key] ?? null;

                    if (!is_string($value) || trim($value) === '') {
                        continue;
                    }

                    $nodes[] = [
                        'path'    => $id.'.'.$key,
                        'widget'  => (string)(    $element['widgetType'] ?? $element['elType'] ?? ''    ),
                        'setting' => $key,
                        'value'   => $value,
                        'is_html' => $this->isHtml($key),
                    ];
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->collect($element['elements'], $nodes);
            }
        }
    }

    /**
     * @param array<int, mixed> $elements
     */
    private function findValue(array $elements, string $elementId, string $setting): ?string {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if ((string)(    $element['id'] ?? ''    ) === $elementId) {
                $value = $element['settings'][$setting] ?? null;

                return is_string($value) ? $value : null;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $found = $this->findValue($element['elements'], $elementId, $setting);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Replaces one leaf value in place. Takes the tree by reference and
     * touches nothing else, so every sibling setting and the element order
     * survive the write byte-for-byte.
     *
     * @param array<int, mixed> $elements
     */
    private function replace(array &$elements, string $elementId, string $setting, string $value): bool {
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }

            if ((string)(    $element['id'] ?? ''    ) === $elementId) {
                if (!isset($element['settings'][$setting]) || !is_string($element['settings'][$setting])) {
                    return false;
                }

                $element['settings'][$setting] = $value;

                return true;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                if ($this->replace($element['elements'], $elementId, $setting, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The rich-text settings, whose stored value is markup produced by the
     * WYSIWYG editor. The rest are single-line labels Elementor renders as
     * plain text.
     */
    private function isHtml(string $setting): bool {
        return in_array($setting, [
            'editor', 'html', 'testimonial_content', 'alert_description', 'tab_content',
        ], true);
    }
}
