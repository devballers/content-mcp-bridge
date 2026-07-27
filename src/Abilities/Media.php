<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use ContentMcpBridge\Integrations\Wpml;
use WP_Error;
use WP_Query;

class Media implements AbilityGroup {
    public function registerReadOnly(): void {
        $this->registerListMedia();
    }

    public function registerWrite(): void {
        $this->registerUploadMedia();
        $this->registerReplaceMediaFile();
        $this->registerUpdateMediaMeta();
        $this->registerSetFeaturedImage();
        $this->registerDeleteMedia();
    }

    private function registerListMedia(): void {
        wp_register_ability('content-mcp-bridge/list-media', [
            'label'               => 'List media',
            'description'         => 'Lists media library items with their alt text, mime type and URL.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'search'   => [
                        'type'        => 'string',
                        'description' => 'Free-text search over media titles and file names.',
                    ],
                    'mime'     => [
                        'type'        => 'string',
                        'description' => 'Mime type filter, e.g. image, image/png, application/pdf.',
                    ],
                    'language' => [
                        'type'        => 'string',
                        'description' => 'WPML language code filter, e.g. en, fi, sv.',
                    ],
                    'page'     => [
                        'type'        => 'integer',
                        'description' => 'Result page number, starting from 1.',
                    ],
                    'per_page' => [
                        'type'        => 'integer',
                        'description' => 'Results per page, 1-100. Default 20.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total'       => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                    'items'       => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'       => ['type' => 'integer'],
                                'title'    => ['type' => 'string'],
                                'alt'      => ['type' => 'string'],
                                'caption'  => ['type' => 'string'],
                                'mime'     => ['type' => 'string'],
                                'language' => ['type' => 'string'],
                                'url'      => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-media', [$this, 'listMedia']),
            'permission_callback' => function (): bool {
                return current_user_can('upload_files');
            },
            'meta'                => [
                'annotations' => [
                    'readonly'   => true,
                    'idempotent' => true,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    private function registerUploadMedia(): void {
        wp_register_ability('content-mcp-bridge/upload-media', [
            'label'               => 'Upload media from URL',
            'description'         => 'Downloads a file from a URL into the media library.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'url'      => [
                        'type'        => 'string',
                        'description' => 'Public URL of the file to download.',
                    ],
                    'filename' => [
                        'type'        => 'string',
                        'description' => 'Optional file name override, e.g. hero-image-fi.jpg.',
                    ],
                    'title'    => [
                        'type'        => 'string',
                        'description' => 'Media title.',
                    ],
                    'alt'      => [
                        'type'        => 'string',
                        'description' => 'Image alt text.',
                    ],
                    'caption'  => [
                        'type'        => 'string',
                        'description' => 'Media caption.',
                    ],
                    'language' => [
                        'type'        => 'string',
                        'description' => 'WPML language of the new media item, e.g. en, fi, sv.',
                    ],
                ],
                'required'             => ['url'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'   => ['type' => 'integer'],
                    'url'  => ['type' => 'string'],
                    'mime' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/upload-media', [$this, 'uploadMedia']),
            'permission_callback' => function (): bool {
                return current_user_can('upload_files');
            },
            'meta'                => [
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    private function registerReplaceMediaFile(): void {
        wp_register_ability('content-mcp-bridge/replace-media-file', [
            'label'               => 'Replace media file',
            'description'         => 'Replaces the file of an existing media item, keeping its ID and usages.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'attachment_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the media item to replace.',
                    ],
                    'url'           => [
                        'type'        => 'string',
                        'description' => 'Public URL of the new file. Must be the same file type as the original.',
                    ],
                ],
                'required'             => [
                    'attachment_id',
                    'url',
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'  => ['type' => 'integer'],
                    'url' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/replace-media-file', [$this, 'replaceMediaFile']),
            'permission_callback' => function (array $input): bool {
                $attachmentId = isset($input['attachment_id']) ? (int)$input['attachment_id'] : 0;

                return current_user_can('edit_post', $attachmentId);
            },
            'meta'                => [
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    private function registerUpdateMediaMeta(): void {
        wp_register_ability('content-mcp-bridge/update-media-meta', [
            'label'               => 'Update media texts',
            'description'         => 'Updates alt text, title, caption or description of a media item by its ID.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'attachment_id' => [
                        'type'        => 'integer',
                        'description' => 'Media item ID. WPML language versions of media have their own IDs.',
                    ],
                    'alt'           => [
                        'type'        => 'string',
                        'description' => 'Image alt text.',
                    ],
                    'title'         => [
                        'type'        => 'string',
                        'description' => 'Media title.',
                    ],
                    'caption'       => [
                        'type'        => 'string',
                        'description' => 'Media caption.',
                    ],
                    'description'   => [
                        'type'        => 'string',
                        'description' => 'Media description.',
                    ],
                ],
                'required'             => ['attachment_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'             => ['type' => 'integer'],
                    'updated_fields' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/update-media-meta', [$this, 'updateMediaMeta']),
            'permission_callback' => function (array $input): bool {
                $attachmentId = isset($input['attachment_id']) ? (int)$input['attachment_id'] : 0;

                return current_user_can('edit_post', $attachmentId);
            },
            'meta'                => [
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    private function registerSetFeaturedImage(): void {
        wp_register_ability('content-mcp-bridge/set-featured-image', [
            'label'               => 'Set featured image',
            'description'         => 'Sets or removes the featured image of a post.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'post_id'       => [
                        'type'        => 'integer',
                        'description' => 'ID of the post to update.',
                    ],
                    'attachment_id' => [
                        'type'        => 'integer',
                        'description' => 'Media item ID to set as featured image. Pass 0 to remove it.',
                    ],
                ],
                'required'             => [
                    'post_id',
                    'attachment_id',
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'post_id'           => ['type' => 'integer'],
                    'featured_image_id' => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/set-featured-image', [$this, 'setFeaturedImage']),
            'permission_callback' => function (array $input): bool {
                $postId = isset($input['post_id']) ? (int)$input['post_id'] : 0;

                return current_user_can('edit_post', $postId);
            },
            'meta'                => [
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    private function registerDeleteMedia(): void {
        wp_register_ability('content-mcp-bridge/delete-media', [
            'label'               => 'Delete media',
            'description'         => 'Permanently deletes a media item and its files. This cannot be undone.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'attachment_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the media item to delete permanently.',
                    ],
                ],
                'required'             => ['attachment_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'deleted' => ['type' => 'boolean'],
                    'id'      => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/delete-media', [$this, 'deleteMedia']),
            'permission_callback' => function (array $input): bool {
                $attachmentId = isset($input['attachment_id']) ? (int)$input['attachment_id'] : 0;

                return current_user_can('delete_post', $attachmentId);
            },
            'meta'                => [
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
                'mcp'         => [
                    'public' => true,
                    'type'   => 'tool',
                ],
            ],
        ]);
    }

    public function listMedia(array $input) {
        $perPage          = min(max(isset($input['per_page']) ? (int)$input['per_page'] : 20, 1), 100);
        $language         = (string)(    $input['language'] ?? ''    );
        $previousLanguage = '';

        if ($language && Wpml::isEnabled()) {
            if (!array_key_exists($language, Wpml::getAllActiveLanguages())) {
                return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
            }

            $previousLanguage = Wpml::getCurrentLanguage();
            Wpml::switchLanguage($language);
        }

        $query = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            's'              => $input['search'] ?? '',
            'post_mime_type' => $input['mime'] ?? '',
            'paged'          => max(isset($input['page']) ? (int)$input['page'] : 1, 1),
            'posts_per_page' => $perPage,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $items = [];

        foreach ($query->posts as $attachment) {
            $items[] = [
                'id'       => $attachment->ID,
                'title'    => $attachment->post_title, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'alt'      => (string)get_post_meta($attachment->ID, '_wp_attachment_image_alt', true),
                'caption'  => $attachment->post_excerpt, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'mime'     => $attachment->post_mime_type, // phpcs:ignore Zend.NamingConventions.ValidVariableName
                'language' => (string)Wpml::getPostLanguage($attachment->ID, 'attachment'),
                'url'      => (string)wp_get_attachment_url($attachment->ID),
            ];
        }

        if ($previousLanguage) {
            Wpml::switchLanguage($previousLanguage);
        }

        return [
            'total'       => (int)$query->found_posts, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'total_pages' => (int)$query->max_num_pages, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'items'       => $items,
        ];
    }

    public function uploadMedia(array $input) {
        $url      = (string)(    $input['url'] ?? ''    );
        $language = (string)(    $input['language'] ?? ''    );

        if (!wp_http_validate_url($url)) {
            return new WP_Error('invalid_url', "The URL '{$url}' is not valid or not allowed.");
        }

        if ($language && Wpml::isEnabled() && !array_key_exists($language, Wpml::getAllActiveLanguages())) {
            return new WP_Error('invalid_language', "Language '{$language}' is not active on this site.");
        }

        $this->loadMediaIncludes();

        $temporaryFile = download_url($url);

        if (is_wp_error($temporaryFile)) {
            return $temporaryFile;
        }

        $filename = $input['filename'] ?? basename((string)wp_parse_url($url, PHP_URL_PATH));

        if (!$filename) {
            $filename = 'upload-'.time();
        }

        $attachmentId = media_handle_sideload(
            [
                'name'     => sanitize_file_name($filename),
                'tmp_name' => $temporaryFile,
            ],
            0,
            null,
            ['post_title' => $input['title'] ?? '']
        );

        if (is_wp_error($attachmentId)) {
            @unlink($temporaryFile);

            return $attachmentId;
        }

        if (isset($input['alt'])) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', sanitize_text_field($input['alt']));
        }

        if (isset($input['caption'])) {
            wp_update_post([
                'ID'           => $attachmentId,
                'post_excerpt' => $input['caption'],
            ]);
        }

        if ($language && Wpml::isEnabled()) {
            do_action('wpml_set_element_language_details', [
                'element_id'    => $attachmentId,
                'element_type'  => 'post_attachment',
                'trid'          => false,
                'language_code' => $language,
            ]);
        }

        return [
            'id'   => $attachmentId,
            'url'  => (string)wp_get_attachment_url($attachmentId),
            'mime' => (string)get_post_mime_type($attachmentId),
        ];
    }

    public function replaceMediaFile(array $input) {
        $attachmentId = isset($input['attachment_id']) ? (int)$input['attachment_id'] : 0;
        $url          = (string)(    $input['url'] ?? ''    );
        $attachment   = get_post($attachmentId);

        if (!$attachment || $attachment->post_type !== 'attachment') { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return new WP_Error('media_not_found', "Media item {$attachmentId} was not found.");
        }

        if (!wp_http_validate_url($url)) {
            return new WP_Error('invalid_url', "The URL '{$url}' is not valid or not allowed.");
        }

        $currentPath = get_attached_file($attachmentId);

        if (!$currentPath || !file_exists($currentPath)) {
            return new WP_Error('file_not_found', "The original file of media item {$attachmentId} is missing.");
        }

        $this->loadMediaIncludes();

        $temporaryFile = download_url($url);

        if (is_wp_error($temporaryFile)) {
            return $temporaryFile;
        }

        $newType = wp_check_filetype_and_ext($temporaryFile, basename($currentPath));

        if (empty($newType['type']) || $newType['type'] !== $attachment->post_mime_type) { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            @unlink($temporaryFile);

            return new WP_Error('type_mismatch', 'The new file content must be the same file type as the original.');
        }

        $backupPath = $currentPath.'.mcp-backup';

        if (!copy($currentPath, $backupPath)) {
            @unlink($temporaryFile);

            return new WP_Error('backup_failed', 'Could not create a backup of the original file.');
        }

        if (!copy($temporaryFile, $currentPath)) {
            copy($backupPath, $currentPath);
            @unlink($backupPath);
            @unlink($temporaryFile);

            return new WP_Error('copy_failed', 'Could not overwrite the original file. The original was kept.');
        }

        @unlink($backupPath);
        @unlink($temporaryFile);

        $metadata = wp_generate_attachment_metadata($attachmentId, $currentPath);

        if ($metadata) {
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        clean_post_cache($attachmentId);

        return [
            'id'  => $attachmentId,
            'url' => (string)wp_get_attachment_url($attachmentId),
        ];
    }

    public function updateMediaMeta(array $input) {
        $attachmentId = isset($input['attachment_id']) ? (int)$input['attachment_id'] : 0;
        $attachment   = get_post($attachmentId);

        if (!$attachment || $attachment->post_type !== 'attachment') { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return new WP_Error('media_not_found', "Media item {$attachmentId} was not found.");
        }

        $updatedFields = [];
        $postFields    = [];

        if (isset($input['alt'])) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', sanitize_text_field($input['alt']));
            $updatedFields[] = 'alt';
        }

        if (isset($input['title'])) {
            $postFields['post_title'] = $input['title'];
            $updatedFields[]          = 'title';
        }

        if (isset($input['caption'])) {
            $postFields['post_excerpt'] = $input['caption'];
            $updatedFields[]            = 'caption';
        }

        if (isset($input['description'])) {
            $postFields['post_content'] = $input['description'];
            $updatedFields[]            = 'description';
        }

        if ($postFields) {
            $postFields['ID'] = $attachmentId;
            $result           = wp_update_post($postFields, true);

            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (!$updatedFields) {
            return new WP_Error('nothing_to_update', 'No fields were provided to update.');
        }

        return [
            'id'             => $attachmentId,
            'updated_fields' => $updatedFields,
        ];
    }

    public function setFeaturedImage(array $input) {
        $postId       = isset($input['post_id']) ? (int)$input['post_id'] : 0;
        $attachmentId = isset($input['attachment_id']) ? (int)$input['attachment_id'] : 0;

        if (!get_post($postId)) {
            return new WP_Error('post_not_found', "Post {$postId} was not found.");
        }

        if ($attachmentId === 0) {
            delete_post_thumbnail($postId);

            return [
                'post_id'           => $postId,
                'featured_image_id' => 0,
            ];
        }

        $attachment = get_post($attachmentId);

        if (!$attachment || $attachment->post_type !== 'attachment') { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return new WP_Error('media_not_found', "Media item {$attachmentId} was not found.");
        }

        if (!wp_attachment_is_image($attachmentId)) {
            return new WP_Error('not_an_image', "Media item {$attachmentId} is not an image.");
        }

        if (!set_post_thumbnail($postId, $attachmentId)) {
            return new WP_Error('set_failed', 'Could not set the featured image.');
        }

        return [
            'post_id'           => $postId,
            'featured_image_id' => $attachmentId,
        ];
    }

    public function deleteMedia(array $input) {
        $attachmentId = isset($input['attachment_id']) ? (int)$input['attachment_id'] : 0;
        $attachment   = get_post($attachmentId);

        if (!$attachment || $attachment->post_type !== 'attachment') { // phpcs:ignore Zend.NamingConventions.ValidVariableName
            return new WP_Error('media_not_found', "Media item {$attachmentId} was not found.");
        }

        $deleted = wp_delete_attachment($attachmentId, true);

        if (!$deleted) {
            return new WP_Error('delete_failed', "Could not delete media item {$attachmentId}.");
        }

        return [
            'deleted' => true,
            'id'      => $attachmentId,
        ];
    }

    private function loadMediaIncludes(): void {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
    }
}
