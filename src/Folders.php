<?php
namespace ContentMcpBridge;

use WP_Error;

/**
 * Server folders the MCP client may read for debugging (log directories, a
 * theme's source, ...). Nothing on the filesystem is reachable unless it's
 * listed here.
 *
 * The list is deliberately read from CONTENT_MCP_BRIDGE_FOLDERS — a
 * wp-config.php constant or the environment — and never from the database:
 * the server URL is the only credential an MCP client needs, so widening
 * what it can read on disk should take access to the server's own config,
 * not just a wp-admin login or a writable options table.
 *
 * Accepted shapes:
 *   define('CONTENT_MCP_BRIDGE_FOLDERS', ['logs' => '/var/log/site', 'theme' => WP_CONTENT_DIR.'/themes/x']);
 *   define('CONTENT_MCP_BRIDGE_FOLDERS', 'logs=/var/log/site,theme=wp-content/themes/x');
 *   CONTENT_MCP_BRIDGE_FOLDERS=logs=/var/log/site,/var/www/shared/debug     (.env / environment)
 *
 * A path without a name is named after its last segment; a relative path is
 * resolved against ABSPATH.
 */
class Folders {
    public const CONFIG = 'CONTENT_MCP_BRIDGE_FOLDERS';

    /**
     * File names that are never listed, read or searched, even inside an
     * allowed folder: they hold credentials rather than anything useful for
     * debugging, and an allowed folder is often a whole project root.
     */
    private const SENSITIVE_PATTERNS = [
        '/^\.env(\..*)?$/i',
        '/^wp-config.*\.php$/i',
        '/^\.htpasswd$/i',
        '/^auth\.json$/i',
        '/^\.npmrc$/i',
        '/^\.netrc$/i',
        '/^id_(rsa|dsa|ecdsa|ed25519)(\.pub)?$/i',
        '/\.(pem|key|p12|pfx|kdbx)$/i',
    ];

    /**
     * Directories that are skipped wholesale for the same reason.
     */
    private const SENSITIVE_DIRECTORIES = ['.git', '.ssh', '.aws', '.gnupg'];

    /** @var array{folders: array<string, string>, rejected: array<string, string>}|null */
    private static ?array $parsed = null;

    /**
     * @return array<string, string> Folder name => resolved absolute path.
     */
    public static function all(): array {
        return self::parse()['folders'];
    }

    /**
     * Configured entries that can't be used, keyed by what was written in
     * the config, with the reason — shown on the settings page so a typo
     * doesn't silently expose nothing.
     *
     * @return array<string, string>
     */
    public static function rejected(): array {
        return self::parse()['rejected'];
    }

    public static function isConfigured(): bool {
        return self::all() !== [];
    }

    /**
     * Resolves a path inside a named folder to a real path on disk,
     * refusing anything that ends up outside the folder (../ segments, or a
     * symlink pointing elsewhere) or that is a credentials file.
     *
     * @return string|WP_Error
     */
    public static function resolve(string $folder, string $relative = '') {
        $root = self::all()[$folder] ?? null;

        if ($root === null) {
            $names = implode(', ', array_keys(self::all()));

            return new WP_Error('invalid_parameter', "Folder '{$folder}' is not configured. Available folders: {$names}.");
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');

        if (str_contains($relative, "\0")) {
            return new WP_Error('invalid_parameter', 'Invalid path.');
        }

        $real = realpath($relative === '' ? $root : $root.DIRECTORY_SEPARATOR.$relative);

        if ($real === false) {
            return new WP_Error('not_found', "'{$relative}' does not exist in folder '{$folder}'.");
        }

        if ($real !== $root && !str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return new WP_Error('permission_denied', "'{$relative}' resolves to a location outside folder '{$folder}'.");
        }

        if (self::isSensitive($real, $root)) {
            return new WP_Error('permission_denied', "'{$relative}' is a credentials file or directory and is never exposed.");
        }

        return $real;
    }

    /**
     * Whether any segment of a path below its folder root is a credentials
     * file or directory.
     */
    public static function isSensitive(string $path, string $root): bool {
        $below = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);

        if ($below === '') {
            return false;
        }

        $segments = explode(DIRECTORY_SEPARATOR, $below);
        $basename = array_pop($segments);

        if (array_intersect(self::SENSITIVE_DIRECTORIES, array_merge($segments, [$basename]))) {
            return true;
        }

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $basename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The path of a resolved file relative to its folder root, with forward
     * slashes — the form every ability takes and returns.
     */
    public static function relativePath(string $path, string $root): string {
        return str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR));
    }

    /**
     * @return array{folders: array<string, string>, rejected: array<string, string>}
     */
    private static function parse(): array {
        if (self::$parsed !== null) {
            return self::$parsed;
        }

        $folders  = [];
        $rejected = [];

        foreach (self::configuredEntries() as $name => $path) {
            $label = is_string($name) ? "{$name}={$path}" : $path;

            if ($path === '') {
                continue;
            }

            if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
                $path = ABSPATH.$path;
            }

            $real = realpath($path);

            if ($real === false || !is_dir($real)) {
                $rejected[$label] = 'not an existing directory';

                continue;
            }

            if (!is_readable($real)) {
                $rejected[$label] = 'not readable by PHP';

                continue;
            }

            if (dirname($real) === $real) {
                $rejected[$label] = 'the filesystem root can\'t be exposed';

                continue;
            }

            $name = sanitize_key(is_string($name) ? $name : basename($real));

            if ($name === '' || isset($folders[$name])) {
                $rejected[$label] = $name === '' ? 'needs a name (name=path)' : "the name '{$name}' is already used";

                continue;
            }

            $folders[$name] = $real;
        }

        return self::$parsed = ['folders' => $folders, 'rejected' => $rejected];
    }

    /**
     * @return array<int|string, string> Name => path where a name was given, otherwise a plain list entry.
     */
    private static function configuredEntries(): array {
        $entries = [];

        if (defined(self::CONFIG) && is_array(constant(self::CONFIG))) {
            foreach (constant(self::CONFIG) as $name => $path) {
                if (!is_string($path)) {
                    continue;
                }

                if (is_string($name)) {
                    $entries[$name] = trim($path);
                } else {
                    $entries[] = trim($path);
                }
            }

            return $entries;
        }

        foreach (explode(',', Monitor::config(self::CONFIG)) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            // "name=path"; a Windows drive letter or a bare path has no name part.
            if (preg_match('/^([A-Za-z0-9_-]+)=(.+)$/', $entry, $match)) {
                $entries[$match[1]] = trim($match[2]);
            } else {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
