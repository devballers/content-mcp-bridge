<?php
namespace ContentMcpBridge;

class Settings {
    public const OPTION_KEY = 'content_mcp_bridge_settings';

    public const INTEGRATIONS = [
        'wpml'              => 'WPML (translations & menu translation)',
        'rank_math'         => 'Rank Math SEO',
        'acf_fields'        => 'ACF post fields',
        'acf_site_settings' => 'ACF site settings options page',
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'registerPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * @return array{
     *     ai_user_id: int,
     *     enabled_post_types: string[],
     *     read_only: bool,
     *     integrations: array<string, bool>
     * }
     */
    public static function get(): array {
        $defaults = [
            'ai_user_id'         => 0,
            'enabled_post_types' => [],
            'read_only'          => false,
            'integrations'       => array_fill_keys(array_keys(self::INTEGRATIONS), false),
        ];

        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $merged                 = array_merge($defaults, $stored);
        $merged['integrations'] = array_merge($defaults['integrations'], (array)(    $stored['integrations'] ?? []    ));

        return $merged;
    }

    public static function isPostTypeAllowed(string $postType): bool {
        return in_array($postType, self::get()['enabled_post_types'], true);
    }

    public static function isIntegrationDetected(string $integration): bool {
        return match ($integration) {
            'wpml'              => has_filter('wpml_object_id') !== false,
            'rank_math'         => class_exists('RankMath'),
            'acf_fields'        => function_exists('get_fields'),
            'acf_site_settings' => function_exists('acf_add_options_page'),
            default             => false,
        };
    }

    public function registerPage(): void {
        add_options_page(
            'Content MCP Bridge',
            'Content MCP Bridge',
            'manage_options',
            'content-mcp-bridge',
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void {
        register_setting('content_mcp_bridge', self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => self::get(),
        ]);
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitize($input): array {
        $input       = is_array($input) ? $input : [];
        $validTypes  = array_keys(get_post_types(['show_ui' => true], 'names'));
        $validTypes  = array_diff($validTypes, ['attachment']);
        $postedTypes = array_map('sanitize_key', (array)(    $input['enabled_post_types'] ?? []    ));

        $userId = (int)(    $input['ai_user_id'] ?? 0    );

        if ($userId && !get_user_by('id', $userId)) {
            $userId = 0;
        }

        $integrations = [];

        foreach (array_keys(self::INTEGRATIONS) as $key) {
            $requested             = !empty($input['integrations'][$key]);
            $integrations[$key]    = $requested && self::isIntegrationDetected($key);
        }

        return [
            'ai_user_id'         => $userId,
            'enabled_post_types' => array_values(array_intersect($postedTypes, $validTypes)),
            'read_only'          => !empty($input['read_only']),
            'integrations'       => $integrations,
        ];
    }

    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings  = self::get();
        $postTypes = get_post_types(['show_ui' => true], 'objects');
        unset($postTypes['attachment']);
        ?>
        <div class="wrap">
            <h1>Content MCP Bridge</h1>
            <form method="post" action="options.php">
                <?php settings_fields('content_mcp_bridge'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cmb-ai-user">AI content user</label></th>
                        <td>
                            <?php
                            wp_dropdown_users([
                                'name'             => self::OPTION_KEY.'[ai_user_id]',
                                'id'               => 'cmb-ai-user',
                                'selected'         => $settings['ai_user_id'],
                                'show_option_none' => 'Select a user…',
                            ]);
                            ?>
                            <p class="description">Every MCP request is executed as this WordPress user. Use a dedicated, low-privilege account.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Allowed post types</th>
                        <td>
                            <?php foreach ($postTypes as $postType) { ?>
                                <label style="display:block;">
                                    <input
                                        type="checkbox"
                                        name="<?= esc_attr(self::OPTION_KEY); ?>[enabled_post_types][]"
                                        value="<?= esc_attr($postType->name); ?>"
                                        <?php checked(in_array($postType->name, $settings['enabled_post_types'], true)); ?>
                                    >
                                    <?= esc_html($postType->labels->singular_name ?? $postType->name); ?>
                                    <code><?= esc_html($postType->name); ?></code>
                                </label>
                            <?php } ?>
                            <p class="description">Only checked post types can be listed, read or edited over MCP.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Read-only mode</th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?= esc_attr(self::OPTION_KEY); ?>[read_only]"
                                    value="1"
                                    <?php checked($settings['read_only']); ?>
                                >
                                Disable every ability that creates, updates or deletes content
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Integrations</th>
                        <td>
                            <?php foreach (self::INTEGRATIONS as $key => $label) {
                                $detected = self::isIntegrationDetected($key);
                                ?>
                                <label style="display:block;">
                                    <input
                                        type="checkbox"
                                        name="<?= esc_attr(self::OPTION_KEY); ?>[integrations][<?= esc_attr($key); ?>]"
                                        value="1"
                                        <?php checked(!empty($settings['integrations'][$key])); ?>
                                        <?php disabled(!$detected); ?>
                                    >
                                    <?= esc_html($label); ?>
                                    <?php if (!$detected) { ?>
                                        <em>(plugin not detected)</em>
                                    <?php } ?>
                                </label>
                            <?php } ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php $this->renderAuditLog(); ?>
        </div>
        <?php
    }

    private function renderAuditLog(): void {
        $entries = AuditLog::recent(20);

        if (!$entries) {
            return;
        }
        ?>
        <h2>Recent MCP activity</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Ability</th>
                    <th>User</th>
                    <th>Result</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry) {
                    $user = get_userdata((int)$entry->user_id);
                    ?>
                    <tr>
                        <td><?= esc_html($entry->created_at); ?></td>
                        <td><code><?= esc_html($entry->ability_id); ?></code></td>
                        <td><?= esc_html($user ? $user->user_login : (string)$entry->user_id); ?></td>
                        <td><?= $entry->success ? 'Success' : 'Error'; ?></td>
                        <td><?= esc_html((string)$entry->message); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php
    }
}
