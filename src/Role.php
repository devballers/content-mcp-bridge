<?php
namespace ContentMcpBridge;

/**
 * A dedicated "AI Content Editor" role, kept in sync with exactly the
 * capabilities the currently-enabled post types and integrations need —
 * nothing more, nothing left over from a setting that was later turned off.
 *
 * Recomputed on activation, immediately after settings are saved, and on
 * admin_init as a safety net (e.g. after another plugin registers a new
 * custom post type on the same site).
 */
class Role {
    public const SLUG = 'ai_content_editor';
    public const LABEL = 'AI Content Editor';

    public function __construct() {
        add_action('admin_init', [self::class, 'sync']);
        add_action('update_option_'.Settings::OPTION_KEY, [self::class, 'sync']);
    }

    public static function onActivate(): void {
        self::ensureRoleExists();
        self::sync();
    }

    private static function ensureRoleExists(): void {
        if (!get_role(self::SLUG)) {
            add_role(self::SLUG, self::LABEL, ['read' => true]);
        }
    }

    public static function sync(): void {
        self::ensureRoleExists();

        $role = get_role(self::SLUG);

        if (!$role) {
            return;
        }

        $needed = self::neededCapabilities();

        foreach (array_keys($role->capabilities) as $cap) {
            if ($cap !== 'read' && !in_array($cap, $needed, true)) {
                $role->remove_cap($cap);
            }
        }

        foreach ($needed as $cap) {
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }
    }

    /**
     * @return string[]
     */
    private static function neededCapabilities(): array {
        $settings = Settings::get();

        // Media abilities always operate on attachments regardless of the
        // enabled_post_types setting, so their capabilities are unconditional.
        $caps = array_merge(
            ['read', 'edit_posts', 'upload_files'],
            self::postTypeCapabilities('attachment')
        );

        foreach ($settings['enabled_post_types'] as $postType) {
            $caps = array_merge($caps, self::postTypeCapabilities($postType));
        }

        if (!empty($settings['integrations']['rank_math'])) {
            $caps[] = 'rank_math_onpage_general';
        }

        if (!empty($settings['integrations']['gravity_forms'])) {
            $caps[] = 'gravity_forms_view_entries';
        }

        $caps = array_merge($caps, self::thirdPartyCapabilities());

        return array_values(array_unique(array_filter($caps)));
    }

    /**
     * Capabilities needed to reach other plugins' own MCP abilities (a
     * separate "rank-math"/"wp-rocket"/"imagify" ability namespace, not
     * anything content-mcp-bridge registers itself). Each block is gated
     * on that specific plugin actually being active, so nothing phantom
     * gets granted for a plugin a given project doesn't use. These are
     * narrow, plugin-scoped capabilities — unlike manage_options, which
     * stays a deliberate, explicit, per-project decision (see Settings.php)
     * rather than something this method grants automatically.
     *
     * @return string[]
     */
    private static function thirdPartyCapabilities(): array {
        $caps = [];

        if (class_exists('RankMath')) {
            $caps = array_merge($caps, [
                'rank_math_site_analysis',
                'rank_math_link_builder',
                'rank_math_analytics',
                'rank_math_onpage_analysis',
            ]);
        }

        if (defined('WP_ROCKET_VERSION')) {
            $caps[] = 'rocket_manage_options';
        }

        if (defined('IMAGIFY_VERSION')) {
            $caps[] = 'imagify_optimize_files';
        }

        return $caps;
    }

    /**
     * Pulls every capability string a post type's registration maps to
     * (edit_posts, edit_others_posts, publish_posts, delete_posts, ...),
     * so custom post types with their own capability_type are handled the
     * same way as built-in ones — no hardcoded capability names needed.
     *
     * @return string[]
     */
    private static function postTypeCapabilities(string $postType): array {
        $object = get_post_type_object($postType);

        if (!$object) {
            return [];
        }

        return array_values(array_unique(array_map('strval', (array)$object->cap)));
    }
}
