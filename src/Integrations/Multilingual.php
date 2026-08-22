<?php
namespace ContentMcpBridge\Integrations;

/**
 * Static facade over whichever multilingual plugin the site runs — WPML or
 * Polylang. Ability code talks to this class for everything both engines can
 * express; the few places where the engines genuinely differ (translation
 * linking, string stores) branch on engine() at the call site.
 */
class Multilingual {
    public const ENGINE_WPML = 'wpml';

    public const ENGINE_POLYLANG = 'polylang';

    public static function engine(): string {
        if (Wpml::isEnabled()) {
            return self::ENGINE_WPML;
        }

        if (Polylang::isEnabled()) {
            return self::ENGINE_POLYLANG;
        }

        return '';
    }

    public static function isEnabled(): bool {
        return self::engine() !== '';
    }

    public static function isPolylang(): bool {
        return self::engine() === self::ENGINE_POLYLANG;
    }

    public static function getDefaultLanguage(): string {
        return self::isPolylang() ? Polylang::getDefaultLanguage() : Wpml::getDefaultLanguage();
    }

    public static function getCurrentLanguage(): string {
        return self::isPolylang() ? Polylang::getCurrentLanguage() : Wpml::getCurrentLanguage();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getAllActiveLanguages(): array {
        return self::isPolylang() ? Polylang::getAllActiveLanguages() : Wpml::getAllActiveLanguages();
    }

    public static function switchLanguage(string $language): void {
        if (self::isPolylang()) {
            Polylang::switchLanguage($language);
        } else {
            Wpml::switchLanguage($language);
        }
    }

    public static function getPostLanguage(int $ID, string $postType): string {
        if (self::isPolylang()) {
            return Polylang::getPostLanguage($ID);
        }

        return (string)Wpml::getPostLanguage($ID, $postType);
    }

    public static function getTermLanguage(int $termId, string $taxonomy): string {
        if (self::isPolylang()) {
            return Polylang::getTermLanguage($termId);
        }

        return (string)apply_filters('wpml_element_language_code', null, [
            'element_id'   => $termId,
            'element_type' => 'tax_'.$taxonomy,
        ]);
    }

    public static function translatedPostId(int $ID, string $postType, string $language): int {
        if (self::isPolylang()) {
            return Polylang::translatedPostId($ID, $language);
        }

        if (!Wpml::isEnabled()) {
            return 0;
        }

        return (int)apply_filters('wpml_object_id', $ID, $postType, false, $language);
    }

    /**
     * Assigns a language to a freshly created, untranslated post
     * (create-post, upload-media). Linking a post INTO an existing
     * translation group is engine-specific and stays in Translations.
     */
    public static function setNewPostLanguage(int $ID, string $postType, string $language): void {
        if (self::isPolylang()) {
            Polylang::setPostLanguage($ID, $language);

            return;
        }

        if (!Wpml::isEnabled()) {
            return;
        }

        do_action('wpml_set_element_language_details', [
            'element_id'           => $ID,
            'element_type'         => 'post_'.$postType,
            'trid'                 => false,
            'language_code'        => $language,
            'source_language_code' => null,
        ]);
    }

    /**
     * Extra WP_Query args that constrain results to one language.
     *
     * WPML rewrites queries itself once switchLanguage() has run. Polylang's
     * own 'lang' handling is not hooked in REST requests, so the language
     * taxonomy is spelled out instead — it works in every context because it
     * is plain taxonomy data.
     *
     * @return array<string, mixed>
     */
    public static function languageQueryArgs(string $language): array {
        if (!self::isPolylang() || $language === '') {
            return [];
        }

        return [
            'tax_query' => [
                [
                    'taxonomy' => 'language',
                    'field'    => 'slug',
                    'terms'    => $language,
                ],
            ],
        ];
    }

    /**
     * Whether a term belongs to a language, for filtering get_terms results:
     * WPML already constrained the query via switchLanguage, Polylang did not.
     */
    public static function termMatchesLanguage(int $termId, string $language): bool {
        if (!self::isPolylang() || $language === '') {
            return true;
        }

        return Polylang::getTermLanguage($termId) === $language;
    }
}
