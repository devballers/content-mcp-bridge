<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Folders;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use WP_Error;

/**
 * Read-only access to the server folders listed in CONTENT_MCP_BRIDGE_FOLDERS,
 * for debugging: tailing a log, reading a template, grepping for an error.
 * Only registered when at least one folder is configured, and there is no
 * write counterpart — see Folders for what is and isn't reachable.
 */
class Files implements AbilityGroup {
    private const MAX_ENTRIES = 500;

    private const DEFAULT_LINES = 200;
    private const MAX_LINES = 2000;

    /** Upper bound on the text one read-file call returns. */
    private const MAX_READ_BYTES = 262144;

    private const MAX_LINE_LENGTH = 500;

    private const DEFAULT_MATCHES = 50;
    private const MAX_MATCHES = 200;

    /** Search stops after this long so a huge folder can't hang the request. */
    private const SEARCH_SECONDS = 10;

    private const SKIPPED_DIRECTORIES = ['node_modules', 'vendor'];

    public function registerReadOnly(): void {
        $this->registerListFiles();
        $this->registerReadFile();
        $this->registerSearchFiles();
    }

    public function registerWrite(): void {
        // Folders are exposed for reading only.
    }

    private function registerListFiles(): void {
        wp_register_ability('content-mcp-bridge/list-files', [
            'label'               => 'List files',
            'description'         => 'Lists the server folders made available for debugging, or the files and subdirectories inside one of them. Call it without arguments first to see which folders exist. Credentials files (.env, wp-config.php, keys) are never listed.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'folder' => [
                        'type'        => 'string',
                        'description' => 'Folder name, as returned when called without arguments. Omit to list the available folders.',
                    ],
                    'path'   => [
                        'type'        => 'string',
                        'description' => 'Subdirectory inside the folder, relative to its root. Omit for the root.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'folders'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'path' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'folder'    => ['type' => 'string'],
                    'path'      => ['type' => 'string'],
                    'entries'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'name'     => ['type' => 'string'],
                                'path'     => ['type' => 'string'],
                                'type'     => ['type' => 'string', 'enum' => ['file', 'directory']],
                                'size'     => ['type' => 'integer'],
                                'modified' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'truncated' => ['type' => 'boolean'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-files', [$this, 'listFiles']),
            'permission_callback' => [$this, 'canRead'],
            'meta'                => $this->meta(),
        ]);
    }

    private function registerReadFile(): void {
        wp_register_ability('content-mcp-bridge/read-file', [
            'label'               => 'Read file',
            'description'         => 'Reads a text file from one of the debugging folders, by line range. Use tail to read the end of a log file, or offset and limit to page through a file from the top. Returns at most 2000 lines / 256 KB per call; check has_more.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'folder' => [
                        'type'        => 'string',
                        'description' => 'Folder name, from list-files.',
                    ],
                    'path'   => [
                        'type'        => 'string',
                        'description' => 'File path relative to the folder root, e.g. debug.log or inc/setup.php.',
                    ],
                    'tail'   => [
                        'type'        => 'integer',
                        'description' => 'Return the last N lines instead of reading from the top (1-2000). Use this for log files. Takes precedence over offset and limit.',
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Line number to start from, 1-based. Default 1.',
                    ],
                    'limit'  => [
                        'type'        => 'integer',
                        'description' => 'Number of lines to return, 1-2000. Default 200.',
                    ],
                ],
                'required'             => ['folder', 'path'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'folder'     => ['type' => 'string'],
                    'path'       => ['type' => 'string'],
                    'size'       => ['type' => 'integer'],
                    'modified'   => ['type' => 'string'],
                    'start_line' => ['type' => 'integer'],
                    'end_line'   => ['type' => 'integer'],
                    'has_more'   => ['type' => 'boolean'],
                    'content'    => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/read-file', [$this, 'readFile']),
            'permission_callback' => [$this, 'canRead'],
            'meta'                => $this->meta(),
        ]);
    }

    private function registerSearchFiles(): void {
        wp_register_ability('content-mcp-bridge/search-files', [
            'label'               => 'Search files',
            'description'         => 'Searches the text files in a debugging folder for a string (case-insensitive, not a regular expression) and returns the matching lines with their file and line number. Skips binary files, credentials files, and node_modules/vendor directories.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'folder'      => [
                        'type'        => 'string',
                        'description' => 'Folder name, from list-files.',
                    ],
                    'query'       => [
                        'type'        => 'string',
                        'description' => 'Text to look for.',
                    ],
                    'path'        => [
                        'type'        => 'string',
                        'description' => 'Limit the search to one subdirectory or one file, relative to the folder root.',
                    ],
                    'extension'   => [
                        'type'        => 'string',
                        'description' => 'Only search files with this extension, e.g. php or log.',
                    ],
                    'max_results' => [
                        'type'        => 'integer',
                        'description' => 'Maximum matching lines to return, 1-200. Default 50.',
                    ],
                ],
                'required'             => ['folder', 'query'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'folder'         => ['type' => 'string'],
                    'matches'        => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'path' => ['type' => 'string'],
                                'line' => ['type' => 'integer'],
                                'text' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'files_searched' => ['type' => 'integer'],
                    'truncated'      => ['type' => 'boolean'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/search-files', [$this, 'searchFiles']),
            'permission_callback' => [$this, 'canRead'],
            'meta'                => $this->meta(),
        ]);
    }

    public function canRead(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array {
        return [
            'annotations' => [
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ],
            'mcp'         => [
                'public' => true,
                'type'   => 'tool',
            ],
        ];
    }

    public function listFiles(array $input) {
        $folder = (string)(    $input['folder'] ?? ''    );

        if ($folder === '') {
            $folders = [];

            foreach (Folders::all() as $name => $path) {
                $folders[] = ['name' => $name, 'path' => $path];
            }

            return ['folders' => $folders];
        }

        $root      = Folders::all()[$folder] ?? '';
        $directory = Folders::resolve($folder, (string)(    $input['path'] ?? ''    ));

        if (is_wp_error($directory)) {
            return $directory;
        }

        if (!is_dir($directory)) {
            return new WP_Error('invalid_parameter', 'That path is a file. Use read-file to read it.');
        }

        $names = scandir($directory);

        if ($names === false) {
            return new WP_Error('read_failed', 'The directory could not be read.');
        }

        $entries   = [];
        $truncated = false;

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$name;

            // A symlink is only listed when it stays inside the folder.
            if (is_wp_error(Folders::resolve($folder, Folders::relativePath($path, $root)))) {
                continue;
            }

            if (count($entries) >= self::MAX_ENTRIES) {
                $truncated = true;

                break;
            }

            $isDirectory = is_dir($path);
            $entry       = [
                'name'     => $name,
                'path'     => Folders::relativePath($path, $root),
                'type'     => $isDirectory ? 'directory' : 'file',
                'modified' => gmdate('c', (int)filemtime($path)),
            ];

            if (!$isDirectory) {
                $entry['size'] = (int)filesize($path);
            }

            $entries[] = $entry;
        }

        return [
            'folder'    => $folder,
            'path'      => Folders::relativePath($directory, $root),
            'entries'   => $entries,
            'truncated' => $truncated,
        ];
    }

    public function readFile(array $input) {
        $folder = (string)(    $input['folder'] ?? ''    );
        $file   = $this->resolveFile($folder, (string)(    $input['path'] ?? ''    ));

        if (is_wp_error($file)) {
            return $file;
        }

        $tail  = (int)(    $input['tail'] ?? 0    );
        $lines = $tail > 0
            ? $this->tailLines($file, min($tail, self::MAX_LINES))
            : $this->lineRange(
                $file,
                max(1, (int)(    $input['offset'] ?? 1    )),
                min(self::MAX_LINES, max(1, (int)(    $input['limit'] ?? self::DEFAULT_LINES    )))
            );

        if (is_wp_error($lines)) {
            return $lines;
        }

        return [
            'folder'     => $folder,
            'path'       => Folders::relativePath($file, Folders::all()[$folder]),
            'size'       => (int)filesize($file),
            'modified'   => gmdate('c', (int)filemtime($file)),
            'start_line' => $lines['start_line'],
            'end_line'   => $lines['start_line'] + count($lines['lines']) - 1,
            'has_more'   => $lines['has_more'],
            'content'    => implode("\n", $lines['lines']),
        ];
    }

    public function searchFiles(array $input) {
        $folder = (string)(    $input['folder'] ?? ''    );
        $query  = (string)(    $input['query'] ?? ''    );

        if ($query === '') {
            return new WP_Error('invalid_parameter', 'query must not be empty.');
        }

        $start = Folders::resolve($folder, (string)(    $input['path'] ?? ''    ));

        if (is_wp_error($start)) {
            return $start;
        }

        $root       = Folders::all()[$folder];
        $extension  = strtolower(ltrim((string)(    $input['extension'] ?? ''    ), '.'));
        $maxResults = min(self::MAX_MATCHES, max(1, (int)(    $input['max_results'] ?? self::DEFAULT_MATCHES    )));
        $deadline   = microtime(true) + self::SEARCH_SECONDS;

        $matches   = [];
        $searched  = 0;
        $truncated = false;

        foreach ($this->filesUnder($start, $root) as $file) {
            if ($extension !== '' && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== $extension) {
                continue;
            }

            $handle = $this->openText($file);

            if (!$handle) {
                continue;
            }

            $searched++;
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;

                if (stripos($line, $query) === false) {
                    continue;
                }

                if (count($matches) >= $maxResults) {
                    $truncated = true;

                    break;
                }

                $matches[] = [
                    'path' => Folders::relativePath($file, $root),
                    'line' => $lineNumber,
                    'text' => $this->clip(rtrim($line, "\r\n")),
                ];
            }

            fclose($handle);

            if (!$truncated && microtime(true) > $deadline) {
                $truncated = true;
            }

            if ($truncated) {
                break;
            }
        }

        return [
            'folder'         => $folder,
            'matches'        => $matches,
            'files_searched' => $searched,
            'truncated'      => $truncated,
        ];
    }

    /**
     * @return string|WP_Error
     */
    private function resolveFile(string $folder, string $path) {
        $file = Folders::resolve($folder, $path);

        if (is_wp_error($file)) {
            return $file;
        }

        if (!is_file($file)) {
            return new WP_Error('invalid_parameter', 'That path is a directory. Use list-files to see what it contains.');
        }

        if (!is_readable($file)) {
            return new WP_Error('read_failed', 'The file is not readable by PHP.');
        }

        return $file;
    }

    /**
     * Opens a file for reading unless its first block looks binary.
     *
     * @return resource|null
     */
    private function openText(string $file) {
        $handle = @fopen($file, 'rb');

        if (!$handle) {
            return null;
        }

        if (str_contains((string)fread($handle, 8192), "\0")) {
            fclose($handle);

            return null;
        }

        rewind($handle);

        return $handle;
    }

    /**
     * @return array{start_line: int, lines: string[], has_more: bool}|WP_Error
     */
    private function lineRange(string $file, int $offset, int $limit) {
        $handle = $this->openText($file);

        if (!$handle) {
            return new WP_Error('invalid_parameter', 'The file is binary or could not be opened.');
        }

        $lines      = [];
        $bytes      = 0;
        $lineNumber = 0;
        $hasMore    = false;

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;

            if ($lineNumber < $offset) {
                continue;
            }

            $bytes += strlen($line);

            if (count($lines) >= $limit || ($lines && $bytes > self::MAX_READ_BYTES)) {
                $hasMore = true;

                break;
            }

            $lines[] = $this->toUtf8(rtrim($line, "\r\n"));
        }

        fclose($handle);

        return ['start_line' => $offset, 'lines' => $lines, 'has_more' => $hasMore];
    }

    /**
     * Reads the last lines of a file from its end, without walking a log
     * that may be gigabytes long. Line numbers are counted from the top only
     * when the whole file fit in the read window; otherwise start_line is 0.
     *
     * @return array{start_line: int, lines: string[], has_more: bool}|WP_Error
     */
    private function tailLines(string $file, int $count) {
        $handle = $this->openText($file);

        if (!$handle) {
            return new WP_Error('invalid_parameter', 'The file is binary or could not be opened.');
        }

        $size   = (int)filesize($file);
        $window = min($size, self::MAX_READ_BYTES);

        fseek($handle, $size - $window);

        $chunk = $window > 0 ? (string)fread($handle, $window) : '';

        fclose($handle);

        $lines = $chunk === '' ? [] : explode("\n", rtrim($chunk, "\r\n"));

        // The window usually starts mid-line; drop that partial first line.
        if ($window < $size && count($lines) > 1) {
            array_shift($lines);
        }

        $total   = count($lines);
        $lines   = array_slice($lines, -$count);
        $hasMore = $window < $size || $total > count($lines);

        return [
            'start_line' => $window < $size ? 0 : $total - count($lines) + 1,
            'lines'      => array_map(fn (string $line): string => $this->toUtf8(rtrim($line, "\r")), $lines),
            'has_more'   => $hasMore,
        ];
    }

    /**
     * Every readable file at or below a path, skipping symlinks, credentials
     * files and dependency directories.
     *
     * @return iterable<string>
     */
    private function filesUnder(string $start, string $root): iterable {
        if (is_file($start)) {
            yield $start;

            return;
        }

        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $entry) use ($root): bool {
                if ($entry->isLink() || Folders::isSensitive($entry->getPathname(), $root)) {
                    return false;
                }

                return !($entry->isDir() && in_array($entry->getFilename(), self::SKIPPED_DIRECTORIES, true));
            }
        );

        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->isReadable()) {
                yield $entry->getPathname();
            }
        }
    }

    private function clip(string $line): string {
        $line = $this->toUtf8($line);

        return mb_strlen($line) > self::MAX_LINE_LENGTH ? mb_substr($line, 0, self::MAX_LINE_LENGTH).'…' : $line;
    }

    /**
     * Abilities return JSON, which can't carry invalid UTF-8 — a log line in
     * another encoding would otherwise fail the whole response.
     */
    private function toUtf8(string $text): string {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }
}
