<?php
namespace ContentMcpBridge;

class AuditLog {
    private static function tableName(): string {
        global $wpdb;

        return $wpdb->prefix.'content_mcp_bridge_log';
    }

    public static function onActivate(): void {
        global $wpdb;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $table           = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            ability_id VARCHAR(191) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            message TEXT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        dbDelta($sql);
    }

    /**
     * Wraps an ability's execute_callback so every call is recorded.
     */
    public static function wrap(string $abilityId, callable $callback): callable {
        return function (array $input) use ($abilityId, $callback) {
            $result = $callback($input);

            self::record($abilityId, get_current_user_id(), $result);

            return $result;
        };
    }

    private static function record(string $abilityId, int $userId, $result): void {
        global $wpdb;

        $success = !is_wp_error($result);
        $message = $success ? '' : $result->get_error_message();

        $wpdb->insert(self::tableName(), [
            'created_at' => current_time('mysql'),
            'ability_id' => $abilityId,
            'user_id'    => $userId,
            'success'    => $success ? 1 : 0,
            'message'    => mb_substr($message, 0, 1000),
        ]);
    }

    /**
     * @return array<int, object>
     */
    public static function recent(int $limit = 20): array {
        global $wpdb;

        $table = self::tableName();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT created_at, ability_id, user_id, success, message FROM {$table} ORDER BY id DESC LIMIT %d",
            $limit
        )) ?: [];
    }
}
