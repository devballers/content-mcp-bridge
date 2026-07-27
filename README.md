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

### From the private Bitbucket repo (recommended)

This repo lives at `bitbucket.org/codeballers/content-mcp-bridge`, tagged
starting at `v0.1.0`. Point a `vcs` repository at the SSH URL and require it
like any other package:

```json
"repositories": [
    { "type": "vcs", "url": "git@bitbucket.org:codeballers/content-mcp-bridge.git" }
],
"require": {
    "devballers/content-mcp-bridge": "^0.1"
}
```

Run `composer update devballers/content-mcp-bridge`.

This requires the machine running Composer (your dev machine, and any CI
agent) to have SSH read access to the repo — either your own key (if you
have access to the `codeballers` workspace) or a dedicated deploy key added
to the repo under **Repository settings → Access keys**. There's nothing to
configure on the Composer side beyond the `vcs` URL above; it shells out to
`git` and uses whatever SSH key is already available.

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

1. Activate **MCP Adapter**, then **Content MCP Bridge**, in wp-admin → Plugins.
2. Set `CONTENT_MCP_BRIDGE_KEY` in your environment — a random string, 32+
   characters (e.g. `openssl rand -hex 32`). This becomes part of the MCP
   server's secret URL; without it, no server is registered.
3. Visit **Settings → Content MCP Bridge** and configure:
   - The AI content user (a dedicated, low-privilege WordPress account).
   - Which post types can be listed/read/edited over MCP.
   - Read-only mode, if you only want the AI to read content for now.
   - Which detected integrations (WPML, Rank Math, ACF) to expose.
