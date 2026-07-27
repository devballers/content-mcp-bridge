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

## Prerequisites for developers (once per machine)

Composer installs this package over SSH, using your own personal Bitbucket
access — there's no shared token or deploy key to distribute. Before your
first install of *any* project that depends on this repo:

1. Make sure you have an SSH key added to your own Bitbucket account
   (**Personal settings → SSH keys**), and that your account has been given
   access to the `codeballers` workspace.
2. Establish SSH trust with Bitbucket once:
   ```
   ssh -T git@bitbucket.org
   ```
   - If this is the **first time** your machine has talked to `bitbucket.org`
     over SSH, you'll see `The authenticity of host 'bitbucket.org' can't be
     established... Are you sure you want to continue connecting (yes/no)?`
     — this is normal and expected for any new SSH host. Check the fingerprint
     against Bitbucket's published SSH host key fingerprints (see Atlassian's
     Bitbucket Cloud documentation), then type `yes`.
   - If instead you see `WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!` /
     `Host key verification failed`, your machine has a **stale** cached key
     from before Bitbucket last rotated its SSH host keys. Fix it with:
     ```
     ssh-keygen -R bitbucket.org
     ssh -T git@bitbucket.org
     ```
     then verify the new fingerprint shown against Bitbucket's published one
     before accepting, since this warning is also what a real
     man-in-the-middle attempt would look like.

This is a **one-time, per-machine** step — it's not specific to this plugin
or to any single project. Once `bitbucket.org` is trusted, every future
`composer install`/`update` that touches this repo (on any project) works
silently, with no prompts. If you skip this and jump straight to
`composer require`, Composer's underlying `git` call will fail the same way
and Composer will confusingly fall back to asking for Bitbucket OAuth
credentials — that prompt is a symptom of the SSH step above not having
happened yet, not something to actually go through.

CI/build agents are a separate case: they should use their own dedicated SSH
deploy key (added under the repo's **Repository settings → Access keys**)
rather than any individual developer's personal key.

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
starting at `v0.1.0`. Point a `git` repository (not `vcs` — an explicit
`git` type skips Bitbucket's API-based driver entirely and sticks to plain
`git`/SSH, which avoids Composer ever prompting for Bitbucket OAuth
credentials) at the SSH URL and require it like any other package:

```json
"repositories": [
    { "type": "git", "url": "git@bitbucket.org:codeballers/content-mcp-bridge.git" }
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
