# Content MCP Bridge

Exposes WordPress content-editing abilities (posts, media, ACF fields, Rank
Math SEO, WPML translations, Gravity Forms entries) to an MCP client such as
Claude, scoped to one WordPress user, a chosen set of post types, and
whichever integrations you enable.

## Requirements

- WordPress 6.5+ (for the `Requires Plugins` dependency check)
- PHP 8.0+
- The **[MCP Adapter](https://github.com/wordpress/mcp-adapter)** plugin, installed and active.
  This plugin doesn't declare it as a Composer dependency (see
  [Installing MCP Adapter](#installing-mcp-adapter) below for why) — add it to
  your own project's `composer.json` `require`, and activate it separately in
  wp-admin.

### Installing MCP Adapter

Resolving `wordpress/mcp-adapter` from Packagist normally (`"wordpress/mcp-adapter":
"^0.5.0"` with no extra repository) installs it from its raw git source — which
depends on `wordpress/php-mcp-schema` and needs its own internal
`composer install` run inside its installed folder (`--working-dir=wp/plugins/mcp-adapter`
or wherever it lands) before it'll actually load. Skip that step entirely by
requiring MCP Adapter's own pre-built release zip instead — the same artifact
its README tells you to download and drop into `wp-content/plugins` directly,
already self-contained with its dependencies bundled:

```json
"repositories": [
    {
        "type": "package",
        "package": {
            "name": "wordpress/mcp-adapter",
            "version": "0.5.0",
            "type": "wordpress-plugin",
            "dist": {
                "type": "zip",
                "url": "https://github.com/WordPress/mcp-adapter/releases/download/v0.5.0/mcp-adapter.zip"
            },
            "require": {
                "composer/installers": "^2.2.0"
            }
        }
    }
],
"require": {
    "wordpress/mcp-adapter": "*"
}
```

Because this is declared inline (not resolved from Packagist), it needs updating
by hand — version number and download URL both — whenever you want a newer MCP
Adapter release; same maintenance shape as any other pinned zip-based package
(e.g. WPML's packages in this ecosystem's typical `composer.json`).

## Installing via Composer

This repo doesn't merge into your project's own `vendor/autoload.php` — like
`wordpress/mcp-adapter`, it's installed as its own WordPress plugin directory
(`wp-content/plugins/content-mcp-bridge`) via Composer's `wordpress-plugin`
installer type. Your project's `composer.json` needs a matching
`installer-paths` entry (most Bedrock-style setups already have one):

```json
"extra": {
    "installer-paths": {
        "wp-content/plugins/{$name}/": ["type:wordpress-plugin"]
    }
}
```

### From the GitHub repo (recommended)

This repo lives at `github.com/devballers/content-mcp-bridge`, tagged
starting at `v0.1.0`. GitHub's HTTPS clone URLs are genuinely anonymous even
for a plain `git` repository type, so requiring it is a single block with no
deploy key or SSH setup involved:

```json
"repositories": [
    { "type": "git", "url": "https://github.com/devballers/content-mcp-bridge.git" }
],
"require": {
    "devballers/content-mcp-bridge": "^0.2"
}
```

Run `composer update devballers/content-mcp-bridge`.

### Installing a specific version without Composer

Every tagged release also publishes a self-contained zip — the same pattern
as [MCP Adapter's own releases](#installing-mcp-adapter) above. Grab
`content-mcp-bridge.zip` from that tag's page under
[Releases](https://github.com/devballers/content-mcp-bridge/releases) and
drop it straight into `wp-content/plugins/`.

## Versioning

Releases are tagged `vX.Y.Z` on GitHub. Each tag publishes its own zip at a
version-specific URL:

```
https://github.com/devballers/content-mcp-bridge/releases/download/vX.Y.Z/content-mcp-bridge.zip
```

Composer resolves the same tags automatically through the `git` repository
above — `^0.2` pins to the latest `0.2.x` release, an exact tag like `0.2.2`
pins to that build precisely. A release workflow rejects any tag that
doesn't match the `Version:` header in `content-mcp-bridge.php`, so the
header, the git tag, and the release URL can never drift apart.

### Local development against an uncommitted clone

If you're editing the plugin itself alongside a consuming project, a `path`
repository is faster than round-tripping through git:

```json
"repositories": [
    { "type": "path", "url": "/absolute/path/to/content-mcp-bridge" }
],
"require": {
    "devballers/content-mcp-bridge": "@dev"
}
```

## Setup after activation

1. Activate **MCP Adapter**, then **Content MCP Bridge**, in wp-admin → Plugins
   (see [Installing MCP Adapter](#installing-mcp-adapter) above for the
   recommended way to require it).
2. Go to **Users → Add New** (or edit an existing account) and assign it the
   **AI Content Editor** role that this plugin creates. This is the account
   every MCP request runs as — never assign it Administrator.
3. Visit **Settings → Content MCP Bridge**:
   - Click **Generate a new key** and set the value as `CONTENT_MCP_BRIDGE_KEY`,
     then reload the page. Where that goes depends on how your project loads
     its environment:
     - **Projects with a `.env` file** (e.g. via `vlucas/phpdotenv`): add
       `CONTENT_MCP_BRIDGE_KEY=<value>` there — this populates `$_ENV`, which
       the plugin reads directly.
     - **Projects without one, configuring things in `wp-config.php`**: set
       the superglobal explicitly — `$_ENV['CONTENT_MCP_BRIDGE_KEY'] =
       '<value>';` — before `wp-settings.php` is loaded. A plain
       `define('CONTENT_MCP_BRIDGE_KEY', ...)` won't work (the plugin doesn't
       read constants), and `putenv(...)` alone won't either (it populates
       `getenv()`, not `$_ENV` — though the plugin does also check `getenv()`
       as a fallback, so a host panel that sets a real process env var works
       too).
   - Pick the AI content user you created in step 2 from the dropdown (only
     accounts with the AI Content Editor role are listed).
   - Choose which post types can be listed/read/edited over MCP, whether
     read-only mode should disable all write abilities, and which detected
     integrations (WPML, Rank Math, ACF, Gravity Forms) to expose.
   - Once a key and user are both set, the page shows the live **Server
     URL** — paste that into Claude's "Remote MCP server URL" field.

The AI Content Editor role's actual WordPress capabilities are computed
automatically from whichever post types/integrations you enable above — it
only ever has exactly what's needed for the currently-enabled settings, and
is kept in sync whenever you change them.
