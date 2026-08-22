<?php
namespace ContentMcpBridge\Integrations;

/**
 * Minimal Polylang wrapper: only the calls the abilities actually need,
 * mirroring the Wpml wrapper's surface where the concepts overlap.
 *
 * Language lists are normalized to the same shape WPML returns
 * (code => array with native_name/display_name) so ability code can stay
 * engine-agnostic.
 */
class Polylang {
    public static function isEnabled(): bool {
        return function_exists('PLL') && function_exists('pll_default_language');
    }

    public static function getDefaultLanguage(): string {
        if (!self::isEnabled()) {
            return get_locale();
        }

        return (string)pll_default_language();
    }

    public static function getCurrentLanguage(): string {
        if (!self::isEnabled()) {
            return get_locale();
        }

        return (string)(    pll_current_language() ?: pll_default_language()    );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getAllActiveLanguages(): array {
        if (!self::isEnabled()) {
            return [];
        }

        $languages = [];

        foreach (PLL()->model->get_languages_list() as $language) {
            $languages[$language->slug] = [
                'code'         => $language->slug,
                'native_name'  => $language->name,
                'display_name' => $language->name,
                'default'      => !empty($language->is_default),
            ];
        }

        $defaultLanguage = self::getDefaultLanguage();

        if (isset($languages[$defaultLanguage]) && key($languages) !== $defaultLanguage) {
            $languages = [$defaultLanguage => $languages[$defaultLanguage]] + $languages;
        }

        return $languages;
    }

    /**
     * Sets Polylang's current-language context. Unlike WPML this does not
     * rewrite queries in REST requests, so listing abilities must constrain
     * queries explicitly (see Multilingual::languageQueryArgs) — the switch
     * still matters for permalink generation and pll__() lookups.
     */
    public static function switchLanguage(string $language): void {
        if (!self::isEnabled()) {
            return;
        }

        $languageObject = PLL()->model->get_language($language);

        if ($languageObject) {
            PLL()->curlang = $languageObject;
        }
    }

    public static function getPostLanguage(int $ID): string {
        if (!self::isEnabled()) {
            return '';
        }

        return (string)pll_get_post_language($ID);
    }

    public static function getTermLanguage(int $termId): string {
        if (!self::isEnabled()) {
            return '';
        }

        return (string)pll_get_term_language($termId);
    }

    public static function translatedPostId(int $ID, string $language): int {
        if (!self::isEnabled()) {
            return 0;
        }

        return (int)pll_get_post($ID, $language);
    }

    public static function translatedTermId(int $termId, string $language): int {
        if (!self::isEnabled()) {
            return 0;
        }

        return (int)pll_get_term($termId, $language);
    }

    public static function setPostLanguage(int $ID, string $language): void {
        if (self::isEnabled()) {
            pll_set_post_language($ID, $language);
        }
    }

    public static function setTermLanguage(int $termId, string $language): void {
        if (self::isEnabled()) {
            pll_set_term_language($termId, $language);
        }
    }

    /**
     * Joins a new translation into the source post's translation group,
     * preserving every language already linked there.
     */
    public static function linkPostTranslations(int $sourceId, int $newId, string $language): void {
        if (!self::isEnabled()) {
            return;
        }

        $group          = (array)pll_get_post_translations($sourceId);
        $sourceLanguage = self::getPostLanguage($sourceId);

        if ($sourceLanguage) {
            $group[$sourceLanguage] = $sourceId;
        }

        $group[$language] = $newId;

        pll_save_post_translations($group);
    }

    public static function linkTermTranslations(int $sourceId, int $newId, string $language): void {
        if (!self::isEnabled()) {
            return;
        }

        $group          = (array)pll_get_term_translations($sourceId);
        $sourceLanguage = self::getTermLanguage($sourceId);

        if ($sourceLanguage) {
            $group[$sourceLanguage] = $sourceId;
        }

        $group[$language] = $newId;

        pll_save_term_translations($group);
    }

    /**
     * Strings registered via pll_register_string, grouped for listing.
     * Only populated in admin-ish contexts: pll_register_string is a no-op
     * outside PLL_Admin_Base, so REST requests usually see none — the
     * MO-store translations below are the reliable source there.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function registeredStrings(): array {
        if (!self::isEnabled() || !class_exists('PLL_Admin_Strings')) {
            return [];
        }

        return array_values(\PLL_Admin_Strings::get_strings());
    }

    /**
     * All stored string translations of one language, original => translation.
     *
     * @return array<string, string>
     */
    public static function stringTranslations(string $language): array {
        $languageObject = self::languageObject($language);

        if (!$languageObject || !class_exists('PLL_MO')) {
            return [];
        }

        $mo = new \PLL_MO();
        $mo->import_from_db($languageObject);

        $map = [];

        foreach ($mo->entries as $entry) {
            if (is_string($entry->singular) && $entry->singular !== '') {
                $map[$entry->singular] = (string)(    $entry->translations[0] ?? ''    );
            }
        }

        return $map;
    }

    /**
     * Stores one string translation the same way the Languages → Translations
     * screen does: into the language's polylang_mo store. add_entry replaces
     * an existing entry for the same original, so re-running overwrites.
     */
    public static function saveStringTranslation(string $original, string $translation, string $language): bool {
        $languageObject = self::languageObject($language);

        if (!$languageObject || !class_exists('PLL_MO')) {
            return false;
        }

        $mo = new \PLL_MO();
        $mo->import_from_db($languageObject);
        $mo->add_entry($mo->make_entry($original, $translation));
        $mo->export_to_db($languageObject);

        return true;
    }

    /**
     * @return object|null
     */
    private static function languageObject(string $language) {
        if (!self::isEnabled()) {
            return null;
        }

        return PLL()->model->get_language($language) ?: null;
    }
}
