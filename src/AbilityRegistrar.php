<?php
namespace ContentMcpBridge;

use ContentMcpBridge\Abilities\AbilityGroup;
use ContentMcpBridge\Abilities\Diagnostics;
use ContentMcpBridge\Abilities\GravityForms;
use ContentMcpBridge\Abilities\Media;
use ContentMcpBridge\Abilities\Menus;
use ContentMcpBridge\Abilities\PostFields;
use ContentMcpBridge\Abilities\Posts;
use ContentMcpBridge\Abilities\Seo;
use ContentMcpBridge\Abilities\SiteSettings;
use ContentMcpBridge\Abilities\Strings;
use ContentMcpBridge\Abilities\Taxonomies;
use ContentMcpBridge\Abilities\Translations;

class AbilityRegistrar {
    public function __construct() {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAll']);
    }

    public function registerCategory(): void {
        wp_register_ability_category('content-mcp-bridge', [
            'label'       => 'Content MCP Bridge',
            'description' => 'Content-editing abilities exposed to an MCP client.',
        ]);
    }

    public function registerAll(): void {
        $settings = Settings::get();
        $readOnly = $settings['read_only'];
        $enabled  = $settings['integrations'];

        $this->register(new Posts(), $readOnly);
        $this->register(new Taxonomies(), $readOnly);
        $this->register(new Media(), $readOnly);
        $this->register(new Diagnostics(), $readOnly);

        if (!empty($enabled['acf_fields'])) {
            $this->register(new PostFields(), $readOnly);
        }

        if (!empty($enabled['acf_site_settings'])) {
            $this->register(new SiteSettings(), $readOnly);
        }

        if (!empty($enabled['rank_math'])) {
            $this->register(new Seo(), $readOnly);
        }

        if (!empty($enabled['wpml'])) {
            $this->register(new Menus(), $readOnly);
            $this->register(new Translations(), $readOnly);
        }

        if (!empty($enabled['wpml_string_translation'])) {
            $this->register(new Strings(), $readOnly);
        }

        if (!empty($enabled['gravity_forms'])) {
            $this->register(new GravityForms(), $readOnly);
        }
    }

    private function register(AbilityGroup $ability, bool $readOnly): void {
        $ability->registerReadOnly();

        if (!$readOnly) {
            $ability->registerWrite();
        }
    }
}
