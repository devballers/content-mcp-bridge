# Content MCP Bridge

Exposes WordPress content-editing abilities (posts, media, ACF fields, Rank
Math SEO, WPML translations) to an MCP client such as Claude, scoped to one
WordPress user, a chosen set of post types, and whichever integrations you
enable.

## Requirements

- WordPress 6.5+ (for the `Requires Plugins` dependency check)
- PHP 8.0+
- The **[MCP Adapter](https://github.com/wordpress/mcp-adapter)** plugin, installed and active.
  This plugin declares it as a Composer dependency, so requiring this plugin pulls it in
  automatically — but it still needs to be **activated separately** in wp-admin.

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

### From the Bitbucket repo (recommended)

This repo lives at `bitbucket.org/codeballers/content-mcp-bridge`, tagged
starting at `v0.1.0`. Point a `git` repository (not `vcs` — an explicit
`git` type skips Bitbucket's API-based driver entirely and sticks to a plain
`git` clone) at the SSH URL and require it like any other package:

```json
"repositories": [
    { "type": "git", "url": "git@bitbucket.org:codeballers/content-mcp-bridge.git" }
],
"require": {
    "devballers/content-mcp-bridge": "^0.1"
}
```

Run `composer update devballers/content-mcp-bridge`.

**Use SSH, not HTTPS, even though the repo is public.** Bitbucket doesn't
offer a truly anonymous/generic HTTPS clone URL — its HTTPS clone links are
tied to a specific authenticated account, which means every machine or CI
agent using it depends on one person's individual Bitbucket access rather
than something project-owned. SSH avoids that entirely via a **deploy key**:
a keypair scoped to this one repo (read-only), not tied to any individual's
account, added under **this repo's own Repository settings → Access keys**
(public half) and then as the private key wherever Composer needs to clone
it — your own machine's SSH agent for local dev, or the consuming project's
CI configuration for automated builds. See that project's own setup docs
for exactly where the private key goes in CI; on a dev machine, it just
needs to be loaded into your SSH agent (`ssh-add`) the same way any other
key would be.

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
   (also add `"wordpress/mcp-adapter"` to your project's own Composer
   `require` — see Requirements above).
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
     integrations (WPML, Rank Math, ACF) to expose.
   - Once a key and user are both set, the page shows the live **Server
     URL** — paste that into Claude's "Remote MCP server URL" field.

The AI Content Editor role's actual WordPress capabilities are computed
automatically from whichever post types/integrations you enable above — it
only ever has exactly what's needed for the currently-enabled settings, and
is kept in sync whenever you change them.
