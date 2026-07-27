<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\Settings;
use WP_Error;
use WP_Post;

/**
 * Resolves and authorizes a post_id in one place, returning a distinctly
 * coded WP_Error for each failure reason (missing parameter, post not
 * found, lacking capability, post type not enabled for MCP) instead of a
 * single generic denial.
 *
 * Deliberately called from execute_callback, not permission_callback: a
 * WP_Error returned from execute_callback is a proven, already-used
 * pattern (post_not_found etc. throughout this plugin). Returning WP_Error
 * from permission_callback instead would rely on mcp-adapter checking
 * is_wp_error() rather than just truthiness — unverified, and a WP_Error
 * object is truthy in PHP, so getting that assumption wrong could
 * accidentally grant access rather than deny it. permission_callback
 * therefore stays a simple, coarse current_user_can() check.
 */
class PostGuard {
    /**
     * @return WP_Post|WP_Error
     */
    public static function resolve(int $postId, string $metaCapability = 'edit_post') {
        if (!$postId) {
            return new WP_Error('invalid_parameter', 'post_id is required.');
        }

        $post = get_post($postId);

        if (!$post) {
            return new WP_Error('post_not_found', "Post {$postId} was not found.");
        }

        if (!current_user_can($metaCapability, $postId)) {
            return new WP_Error('permission_denied', 'You do not have permission to access this post.');
        }

        if (!Settings::isPostTypeAllowed($post->post_type)) {
            return new WP_Error(
                'permission_denied',
                "The '{$post->post_type}' post type is not enabled for MCP access."
            );
        }

        return $post;
    }
}
