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

### Before this repo has a remote (local development)

Point a `path` repository at your local clone:

```json
"repositories": [
    { "type": "path", "url": "/absolute/path/to/content-mcp-bridge" }
],
"require": {
    "devballers/content-mcp-bridge": "@dev"
}
```

### Once pushed and tagged on Bitbucket

Point a `vcs` repository at the git URL instead, and pin a version:

```json
"repositories": [
    { "type": "vcs", "url": "git@bitbucket.org:yourorg/content-mcp-bridge.git" }
],
"require": {
    "devballers/content-mcp-bridge": "^1.0"
}
```

Run `composer update devballers/content-mcp-bridge`.

If your CI needs to clone this from a **private** Bitbucket repo, it needs
read access — either an SSH deploy key on the build agent, or an app-password
entry in `auth.json` (the same mechanism used for other private packages in
this ecosystem, e.g. `composer config http-basic.bitbucket.org <user> <app-password>`).

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
