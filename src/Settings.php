<?php
namespace ContentMcpBridge;

class Settings {
    public const OPTION_KEY = 'content_mcp_bridge_settings';

    public const KEY_PATTERN = '/^[A-Za-z0-9-]{32,}$/';

    public const INTEGRATIONS = [
        'wpml'              => 'WPML (translations & menu translation)',
        'rank_math'         => 'Rank Math SEO',
        'acf_fields'        => 'ACF post fields',
        'acf_site_settings' => 'ACF site settings options page',
        'gravity_forms'     => 'Gravity Forms (read entries)',
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

    /**
     * Post type slugs a site owner can actually choose to expose over MCP —
     * excludes attachments (media has its own ability group) and ACF/ACF
     * Extended's own internal storage post types (field groups, dynamic
     * post types/taxonomies/options pages, forms, templates), which hold
     * the site's data-model configuration rather than content and would
     * let an AI restructure the site rather than just edit it.
     *
     * @return string[]
     */
    public static function manageablePostTypes(): array {
        $types = get_post_types(['show_ui' => true], 'names');

        return array_values(array_filter($types, static function (string $type): bool {
            if ($type === 'attachment') {
                return false;
            }

            return !str_starts_with($type, 'acf-') && !str_starts_with($type, 'acfe-');
        }));
    }

    public static function isIntegrationDetected(string $integration): bool {
        return match ($integration) {
            'wpml'              => has_filter('wpml_object_id') !== false,
            'rank_math'         => class_exists('RankMath'),
            'acf_fields'        => function_exists('get_fields'),
            'acf_site_settings' => function_exists('acf_add_options_page'),
            'gravity_forms'     => class_exists('GFAPI'),
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
        $validTypes  = self::manageablePostTypes();
        $postedTypes = array_map('sanitize_key', (array)(    $input['enabled_post_types'] ?? []    ));

        $userId = (int)(    $input['ai_user_id'] ?? 0    );
        $user   = $userId ? get_user_by('id', $userId) : false;

        if ($userId && (!$user || !$user->has_cap(Role::SLUG))) {
            $userId = 0;

            add_settings_error(
                self::OPTION_KEY,
                'ai_user_invalid',
                sprintf(
                    'The AI content user must have the "%s" role. Assign that role to a dedicated account under Users, then select it here.',
                    Role::LABEL
                )
            );
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
        $postTypes = array_filter(array_map('get_post_type_object', self::manageablePostTypes()));
        ?>
        <div class="wrap">
            <h1>Content MCP Bridge</h1>

            <?php $this->renderMcpServerSection(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('content_mcp_bridge'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cmb-ai-user">AI Content User</label></th>
                        <td>
                            <?php if (get_users(['role' => Role::SLUG, 'number' => 1, 'fields' => 'ID'])) { ?>
                                <?php
                                wp_dropdown_users([
                                    'name'             => self::OPTION_KEY.'[ai_user_id]',
                                    'id'               => 'cmb-ai-user',
                                    'selected'         => $settings['ai_user_id'],
                                    'show_option_none' => 'Select a User…',
                                    'role'             => Role::SLUG,
                                ]);
                                ?>
                            <?php } else { ?>
                                <p>
                                    No user has the <strong><?= esc_html(Role::LABEL); ?></strong> role yet.
                                    Go to <a href="<?= esc_url(admin_url('user-new.php')); ?>">Users → Add New</a>
                                    (or edit an existing account) and assign that role, then come back here to select it.
                                </p>
                            <?php } ?>
                            <p class="description">Every MCP request is executed as this WordPress user. Only accounts with the dedicated "<?= esc_html(Role::LABEL); ?>" role are offered here. A leaked server URL grants whatever this user can do — if you've granted this account extra capabilities (e.g. <code>manage_options</code>) to reach abilities from other plugins, that risk now extends to whatever those capabilities unlock, so treat the server URL accordingly.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Allowed Post Types</th>
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
                    <tr>
                        <th scope="row">Read-Only Mode</th>
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
                </table>
                <?php submit_button(); ?>
            </form>

            <?php $this->renderAuditLog(); ?>
        </div>
        <?php
    }

    private function renderMcpServerSection(): void {
        $url = Server::urlForKey(Server::currentKey());
        ?>
        <h2>MCP Server</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Secret Key</th>
                <td>
                    <input type="text" id="cmb-generated-key" class="regular-text" readonly
                        onclick="this.select();"
                        placeholder="Click Generate, then copy into CONTENT_MCP_BRIDGE_KEY in wp-config.php or your environment">
                    <button type="button" class="button" id="cmb-generate-key">Generate a New Key</button>
                    <p class="description">
                        Generated locally in your browser — never sent to or stored by this site.
                        Copy it into a <code>CONTENT_MCP_BRIDGE_KEY</code> constant in
                        <code>wp-config.php</code>, or into the environment variable of the same
                        name (your host's environment settings, or a local <code>.env</code> file),
                        then reload this page. Changing it immediately invalidates the server URL
                        below for everyone currently using it.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">Server URL</th>
                <td>
                    <?php if ($url) { ?>
                        <input type="text" class="regular-text" style="width:100%;max-width:640px;" readonly
                            onclick="this.select();" value="<?= esc_attr($url); ?>">
                        <p class="description">
                            Paste this into Claude's "Remote MCP server URL" field. Treat it like a
                            password — anyone with this URL can act as the AI content user configured
                            below.
                        </p>
                    <?php } else { ?>
                        <p class="description">
                            Not active yet: set <code>CONTENT_MCP_BRIDGE_KEY</code> in
                            <code>wp-config.php</code> or your environment (32+ characters — use the
                            generator above) and choose an AI content user
                            below to activate the server and see its URL here.
                        </p>
                    <?php } ?>
                </td>
            </tr>
        </table>
        <script>
        document.getElementById('cmb-generate-key').addEventListener('click', function () {
            var bytes = new Uint8Array(32);
            window.crypto.getRandomValues(bytes);
            var hex = Array.from(bytes, function (b) {
                return b.toString(16).padStart(2, '0');
            }).join('');
            var field = document.getElementById('cmb-generated-key');
            field.value = hex;
            field.select();
        });
        </script>
        <?php
    }

    private function renderAuditLog(): void {
        $entries = AuditLog::recent(20);

        if (!$entries) {
            return;
        }
        ?>
        <h2>Recent MCP Activity</h2>
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
