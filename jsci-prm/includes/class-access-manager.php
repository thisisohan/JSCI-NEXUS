<?php
/**
 * Access management helpers.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Class Access_Manager
 */
final class Access_Manager {

    /**
     * Production entry transaction types supported by access management.
     */
    private const PRODUCTION_ENTRY_TYPES = [ 'receive', 'produce', 'send', 'send_outside_factory', 'reject', 'accept_transfers' ];

    /**
     * User-level order management access keys.
     */
    private const ORDER_MANAGEMENT_ACCESS = [ 'create', 'edit', 'change_status', 'void' ];

    /**
     * User-level transaction management access keys.
     */
    private const TRANSACTION_MANAGEMENT_ACCESS = [ 'edit', 'void' ];

    private const Message_META_KEY = 'jsci_prm_Message_access';

    /**
     * User meta key for order management access.
     */
    private const ORDER_MANAGEMENT_META_KEY = 'jsci_prm_order_management_access';

    /**
     * User meta key for transaction management access.
     */
    private const TRANSACTION_MANAGEMENT_META_KEY = 'jsci_prm_transaction_management_access';

    /**
     * Returns structured production entry access for a user.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_user_production_entry_access( int $user_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT permission.*, department.name AS department_name
                FROM `" . Database::users_permissions() . "` permission
                INNER JOIN `" . Database::departments() . "` department
                    ON department.id = permission.dept_id
                WHERE permission.user_id = %d
                AND permission.dept_id IS NOT NULL
                AND department.is_active = 1
                ORDER BY department.workflow_order ASC, department.id ASC
                ",
                $user_id
            )
        ) ?: [];

        $access = [];

        foreach ( $rows as $row ) {
            $line_access = self::decode_line_access( $row->line_access ?? '' );
            $entry_types = self::decode_entry_types( $row->entry_types ?? '' );

            $access[ (int) $row->dept_id ] = [
                'dept_id'         => (int) $row->dept_id,
                'department_name' => $row->department_name,
                'dept_access'     => (bool) $row->dept_access,
                'line_access'     => $line_access,
                'entry_types'     => $entry_types,
                'history_access'  => ! empty( $row->history_access ),
                'department_history_access' => ! empty( $row->department_history_access ),
            ];
        }

        return $access;
    }

    /**
     * Replace all production entry access rows for a user.
     *
     * @param array<int,array<string,mixed>> $departments
     */
    public static function save_user_production_entry_access( int $user_id, array $departments ): true|WP_Error {
        global $wpdb;

        if ( ! self::permissions_table_has_required_columns() ) {
            return new WP_Error(
                'jsci_access_schema_missing',
                __( 'Access management schema is not ready yet. Reload the plugin or run the database upgrade.', 'jsci-prm' ),
                [ 'status' => 500 ]
            );
        }

        $existing = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `" . Database::users_permissions() . "` WHERE user_id = %d",
                $user_id
            )
        ) ?: [];

        $existing_by_dept = [];

        foreach ( $existing as $row ) {
            $existing_by_dept[ (int) $row->dept_id ] = $row;
        }

        $incoming_dept_ids = [];

        foreach ( $departments as $department ) {
            $dept_id = (int) ( $department['dept_id'] ?? 0 );

            if ( ! $dept_id ) {
                continue;
            }

            $incoming_dept_ids[] = $dept_id;

            $dept_access = ! empty( $department['dept_access'] ) ? 1 : 0;
            $line_access = self::encode_line_access( $department['line_access'] ?? [] );
            $entry_types = wp_json_encode( self::sanitize_entry_types( $department['entry_types'] ?? [] ) );
            $history_access = ! empty( $department['history_access'] ) ? 1 : 0;
            $department_history_access = ! empty( $department['department_history_access'] ) ? 1 : 0;

            $payload = [
                'user_id'                   => $user_id,
                'dept_id'                   => $dept_id,
                'role'                      => 'operator',
                'dept_access'               => $dept_access,
                'line_access'               => $line_access,
                'entry_types'               => $entry_types,
                'history_access'            => $history_access,
                'department_history_access' => $department_history_access,
                'can_edit'                  => 0,
                'can_report'                => 0,
                'created_by'                => get_current_user_id(),
            ];

            if ( isset( $existing_by_dept[ $dept_id ] ) ) {
                $updated = $wpdb->update(
                    Database::users_permissions(),
                    [
                        'role'                      => 'operator',
                        'dept_access'               => $dept_access,
                        'line_access'               => $line_access,
                        'entry_types'               => $entry_types,
                        'history_access'            => $history_access,
                        'department_history_access' => $department_history_access,
                    ],
                    [ 'id' => (int) $existing_by_dept[ $dept_id ]->id ],
                    [ '%s', '%d', '%s', '%s', '%d', '%d' ],
                    [ '%d' ]
                );

                if ( false === $updated ) {
                    return new WP_Error(
                        'jsci_access_save_failed',
                        $wpdb->last_error ?: __( 'Could not update user access.', 'jsci-prm' ),
                        [ 'status' => 500 ]
                    );
                }
            } else {
                $inserted = $wpdb->insert(
                    Database::users_permissions(),
                    $payload,
                    [ '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d' ]
                );

                if ( false === $inserted ) {
                    return new WP_Error(
                        'jsci_access_save_failed',
                        $wpdb->last_error ?: __( 'Could not insert user access.', 'jsci-prm' ),
                        [ 'status' => 500 ]
                    );
                }
            }
        }

        foreach ( $existing_by_dept as $dept_id => $row ) {
            if ( in_array( $dept_id, $incoming_dept_ids, true ) ) {
                continue;
            }

            $deleted = $wpdb->delete(
                Database::users_permissions(),
                [ 'id' => (int) $row->id ],
                [ '%d' ]
            );

            if ( false === $deleted ) {
                return new WP_Error(
                    'jsci_access_save_failed',
                    $wpdb->last_error ?: __( 'Could not remove old user access.', 'jsci-prm' ),
                    [ 'status' => 500 ]
                );
            }
        }

        return true;
    }

    /**
     * Returns accessible department ids keyed by id.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_accessible_departments_for_user( int $user_id ): array {
        $access = self::get_user_production_entry_access( $user_id );

        return array_filter(
            $access,
            static fn( array $department ): bool => ! empty( $department['dept_access'] )
        );
    }

    /**
     * Returns whether the user may enter the given transaction type for a department.
     */
    public static function user_can_access_department_type( int $user_id, int $department_id, string $tx_type ): bool {
        $access = self::get_user_production_entry_access( $user_id );

        if ( empty( $access[ $department_id ]['dept_access'] ) ) {
            return false;
        }

        return in_array( $tx_type, $access[ $department_id ]['entry_types'], true );
    }

    /**
     * Returns whether the user may use the given line for the department.
     */
    public static function user_can_access_department_line( int $user_id, int $department_id, string $line ): bool {
        $access = self::get_user_production_entry_access( $user_id );

        if ( empty( $access[ $department_id ]['dept_access'] ) ) {
            return false;
        }

        return in_array( $line, $access[ $department_id ]['line_access'], true );
    }

    /**
     * Returns whether the user may view own history for the department.
     */
    public static function user_can_access_department_history( int $user_id, int $department_id ): bool {
        $access = self::get_user_production_entry_access( $user_id );

        if ( empty( $access[ $department_id ]['dept_access'] ) ) {
            return false;
        }

        return ! empty( $access[ $department_id ]['history_access'] );
    }

    /**
     * Returns whether the user may view department history for the department.
     */
    public static function user_can_access_department_history_scope( int $user_id, int $department_id ): bool {
        $access = self::get_user_production_entry_access( $user_id );

        if ( empty( $access[ $department_id ]['dept_access'] ) ) {
            return false;
        }

        return ! empty( $access[ $department_id ]['department_history_access'] );
    }

    /**
     * Returns saved order management access for a user.
     *
     * @return array<string,bool>
     */
    public static function get_user_order_management_access( int $user_id ): array {
        $saved = get_user_meta( $user_id, self::ORDER_MANAGEMENT_META_KEY, true );
        $saved = is_array( $saved ) ? $saved : [];
        $access = [];

        foreach ( self::ORDER_MANAGEMENT_ACCESS as $key ) {
            $access[ $key ] = ! empty( $saved[ $key ] );
        }

        return $access;
    }

    /**
     * Save order management access for a user.
     *
     * @param array<string,mixed> $access
     */
    public static function save_user_order_management_access( int $user_id, array $access ): bool {
        $normalized = [];

        foreach ( self::ORDER_MANAGEMENT_ACCESS as $key ) {
            $normalized[ $key ] = ! empty( $access[ $key ] );
        }

        return (bool) update_user_meta( $user_id, self::ORDER_MANAGEMENT_META_KEY, $normalized );
    }

    /**
     * Returns whether the user may perform an order management action.
     */
    public static function user_can_manage_order_action( int $user_id, string $action ): bool {
        $user = get_userdata( $user_id );

        if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
            return true;
        }

        $action = sanitize_key( $action );

        if ( ! in_array( $action, self::ORDER_MANAGEMENT_ACCESS, true ) ) {
            return false;
        }

        $access = self::get_user_order_management_access( $user_id );

        return ! empty( $access[ $action ] );
    }

    /**
     * Returns available order management access keys.
     *
     * @return string[]
     */
    public static function order_management_access_keys(): array {
        return self::ORDER_MANAGEMENT_ACCESS;
    }

    /**
     * Returns saved transaction management access for a user.
     *
     * @return array<string,bool>
     */
    public static function get_user_transaction_management_access( int $user_id ): array {
        $saved = get_user_meta( $user_id, self::TRANSACTION_MANAGEMENT_META_KEY, true );
        $saved = is_array( $saved ) ? $saved : [];
        $access = [];

        foreach ( self::TRANSACTION_MANAGEMENT_ACCESS as $key ) {
            $access[ $key ] = ! empty( $saved[ $key ] );
        }

        return $access;
    }

    /**
     * Save transaction management access for a user.
     *
     * @param array<string,mixed> $access
     */
    public static function save_user_transaction_management_access( int $user_id, array $access ): bool {
        $normalized = [];

        foreach ( self::TRANSACTION_MANAGEMENT_ACCESS as $key ) {
            $normalized[ $key ] = ! empty( $access[ $key ] );
        }

        return (bool) update_user_meta( $user_id, self::TRANSACTION_MANAGEMENT_META_KEY, $normalized );
    }

    /**
     * Returns whether the user may perform a transaction management action.
     */
    public static function user_can_manage_transaction_action( int $user_id, string $action ): bool {
        $user = get_userdata( $user_id );

        if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
            return true;
        }

        $action = sanitize_key( $action );

        if ( ! in_array( $action, self::TRANSACTION_MANAGEMENT_ACCESS, true ) ) {
            return false;
        }

        $access = self::get_user_transaction_management_access( $user_id );

        return ! empty( $access[ $action ] );
    }

    /**
     * Returns available transaction management access keys.
     *
     * @return string[]
     */
    public static function transaction_management_access_keys(): array {
        return self::TRANSACTION_MANAGEMENT_ACCESS;
    }

    public static function user_can_manage_Messages( int $user_id ): bool {
        $user = get_userdata( $user_id );

        if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
            return true;
        }

        return (bool) get_user_meta( $user_id, self::Message_META_KEY, true );
    }

    public static function save_user_Message_access( int $user_id, bool $has_access ): bool {
        return (bool) update_user_meta( $user_id, self::Message_META_KEY, $has_access ? '1' : '0' );
    }

    /**
     * Remove all saved app access for a user.
     */
    public static function clear_user_access( int $user_id ): bool {
        global $wpdb;

        $deleted = $wpdb->delete(
            Database::users_permissions(),
            [ 'user_id' => $user_id ],
            [ '%d' ]
        );

        delete_user_meta( $user_id, self::ORDER_MANAGEMENT_META_KEY );
        delete_user_meta( $user_id, self::TRANSACTION_MANAGEMENT_META_KEY );
        delete_user_meta( $user_id, self::Message_META_KEY );

        return false !== $deleted;
    }

    /**
     * Returns available production entry types.
     *
     * @return string[]
     */
    public static function production_entry_types(): array {
        return self::PRODUCTION_ENTRY_TYPES;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function sanitize_entry_types( $value ): array {
        $types = is_array( $value ) ? $value : [];
        $types = array_map( 'sanitize_key', $types );

        return array_values( array_intersect( self::PRODUCTION_ENTRY_TYPES, $types ) );
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function decode_entry_types( $value ): array {
        $decoded = json_decode( (string) $value, true );

        if ( ! is_array( $decoded ) ) {
            return [];
        }

        return self::sanitize_entry_types( $decoded );
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function decode_line_access( $value ): array {
        $decoded = json_decode( (string) $value, true );

        if ( ! is_array( $decoded ) ) {
            return [];
        }

        $lines = array_map( 'sanitize_text_field', $decoded );
        $lines = array_values( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );

        return array_values( array_unique( $lines ) );
    }

    /**
     * @param mixed $value
     */
    private static function encode_line_access( $value ): string {
        $lines = is_array( $value ) ? $value : [];
        $lines = array_map( 'sanitize_text_field', $lines );
        $lines = array_values( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );

        return (string) wp_json_encode( array_values( array_unique( $lines ) ) );
    }

    /**
     * Verify the permissions table has the columns this feature needs.
     */
    private static function permissions_table_has_required_columns(): bool {
        global $wpdb;

        $table = Database::users_permissions();
        $required = [ 'dept_access', 'line_access', 'entry_types', 'history_access', 'department_history_access' ];

        foreach ( $required as $column ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SHOW COLUMNS FROM `{$table}` LIKE %s",
                $column
            ) );

            if ( ! $exists ) {
                return false;
            }
        }

        return true;
    }
}
