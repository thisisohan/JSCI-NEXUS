<?php
/**
 * Permanent audit logger.
 *
 * Every create / update / delete action inside JSCI PRM must call
 * Logger::log() so there is an immutable audit trail.
 * Log rows are never soft-deleted; only a direct DB operation can remove them.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 */
final class Logger {

    // ── Action constants ──────────────────────────────────────────────────────

    public const ACTION_CREATE  = 'create';
    public const ACTION_UPDATE  = 'update';
    public const ACTION_DELETE  = 'delete';
    public const ACTION_RESTORE = 'restore';
    public const ACTION_CONFIRM = 'confirm';
    public const ACTION_LOCK    = 'lock';
    public const ACTION_UNLOCK  = 'unlock';
    public const ACTION_VOID    = 'void';
    public const ACTION_LOGIN   = 'login';
    public const ACTION_EXPORT  = 'export';

    // ── Object type constants ─────────────────────────────────────────────────

    public const TYPE_ORDER       = 'order';
    public const TYPE_ORDER_SIZE  = 'order_size';
    public const TYPE_TRANSACTION = 'transaction';
    public const TYPE_TX_ITEM     = 'transaction_item';
    public const TYPE_PERMISSION  = 'permission';
    public const TYPE_DEPARTMENT  = 'department';
    public const TYPE_SETTING     = 'setting';
    public const TYPE_Message     = 'Message';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Write an audit entry.
     *
     * @param string     $action      One of the ACTION_* constants.
     * @param string     $object_type One of the TYPE_* constants.
     * @param int|null   $object_id   PK of the affected row (null for bulk / settings).
     * @param mixed      $old_value   Snapshot before change (will be JSON-encoded).
     * @param mixed      $new_value   Snapshot after change (will be JSON-encoded).
     * @param int        $user_id     Defaults to current user.
     */
    public static function log(
        string $action,
        string $object_type,
        ?int   $object_id  = null,
        mixed  $old_value  = null,
        mixed  $new_value  = null,
        int    $user_id    = 0
    ): void {
        global $wpdb;

        $user_id = $user_id ?: get_current_user_id();

        $wpdb->insert(
            Database::logs(),
            [
                'user_id'     => $user_id,
                'action'      => $action,
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'old_value'   => $old_value !== null ? wp_json_encode( $old_value ) : null,
                'new_value'   => $new_value !== null ? wp_json_encode( $new_value ) : null,
                'ip_address'  => self::get_ip(),
                'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] )
                    ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
                    : null,
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Retrieve log entries with optional filters.
     *
     * @param array{
     *   user_id?:     int,
     *   action?:      string,
     *   object_type?: string,
     *   object_id?:   int,
     *   date_from?:   string,
     *   date_to?:     string,
     *   limit?:       int,
     *   offset?:      int,
     * } $args
     * @return object[]
     */
    public static function query( array $args = [] ): array {
        global $wpdb;

        $table  = Database::logs();
        $where  = [];
        $params = [];

        if ( ! empty( $args['user_id'] ) ) {
            $where[]  = 'user_id = %d';
            $params[] = $args['user_id'];
        }
        if ( ! empty( $args['action'] ) ) {
            $where[]  = 'action = %s';
            $params[] = $args['action'];
        }
        if ( ! empty( $args['object_type'] ) ) {
            $where[]  = 'object_type = %s';
            $params[] = $args['object_type'];
        }
        if ( ! empty( $args['object_id'] ) ) {
            $where[]  = 'object_id = %d';
            $params[] = $args['object_id'];
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = $args['date_from'];
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = $args['date_to'];
        }

        $sql   = "SELECT * FROM `{$table}`";
        $sql  .= $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $sql  .= ' ORDER BY created_at DESC';
        $sql  .= ' LIMIT %d OFFSET %d';
        $params[] = isset( $args['limit']  ) ? (int) $args['limit']  : 50;
        $params[] = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function get_ip(): string {
        foreach ( [ 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // Take the first IP if comma-separated (X-Forwarded-For).
                return explode( ',', $ip )[0];
            }
        }
        return '0.0.0.0';
    }
}
