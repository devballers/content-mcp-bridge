<?php
namespace ContentMcpBridge\Integrations;

/**
 * Minimal WPML wrapper: only the calls the abilities actually need.
 * Ported from the consuming theme's Ballers\Plugins\WPML — everything else
 * on that class is frontend presentation and stays there.
 */
class Wpml {
    public static function isEnabled(): bool {
        // has_filter('wpml_object_id') alone is not enough: Polylang ships a
        // WPML compatibility layer registering the same filters, so a Polylang
        // site would be mistaken for WPML and fatal on the null $sitepress.
        return defined('ICL_SITEPRESS_VERSION') && !empty($GLOBALS['sitepress']);
    }

    public static function getDefaultLanguage(): string {
        if (!self::isEnabled()) {
            return get_locale();
        }

        global $sitepress;

        return $sitepress->get_default_language();
    }

    public static function getCurrentLanguage(): string {
        if (!self::isEnabled()) {
            return get_locale();
        }

        global $sitepress;

        return $sitepress->get_current_language();
    }

    /**
     * @return array<string, mixed>
     */
    public static function getAllActiveLanguages(): array {
        if (!self::isEnabled()) {
            return [];
        }

        global $sitepress;

        $activeLanguages = $sitepress->get_active_languages();
        $defaultLanguage = self::getDefaultLanguage();

        if (isset($activeLanguages[$defaultLanguage]) && key($activeLanguages) !== $defaultLanguage) {
            $defaultLang = [$defaultLanguage => $activeLanguages[$defaultLanguage]];
            unset($activeLanguages[$defaultLanguage]);

            $activeLanguages = $defaultLang + $activeLanguages;
        }

        return $activeLanguages;
    }

    public static function switchLanguage(string $language): void {
        if (!self::isEnabled()) {
            return;
        }

        do_action('wpml_switch_language', $language);
    }

    public static function getPostLanguage(int $ID, string $elementType) {
        if (!self::isEnabled()) {
            return '';
        }

        global $sitepress;

        return $sitepress->get_language_for_element($ID, "post_{$elementType}");
    }
}
