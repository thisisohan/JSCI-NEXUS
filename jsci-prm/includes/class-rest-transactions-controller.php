<?php
/**
 * REST controller – Transactions.
 *
 * Endpoints:
 *   GET    /jsci-prm/v1/transactions
 *   POST   /jsci-prm/v1/transactions
 *   GET    /jsci-prm/v1/transactions/{id}
 *   PUT    /jsci-prm/v1/transactions/{id}
 *   POST   /jsci-prm/v1/transactions/{id}/update
 *   POST   /jsci-prm/v1/transactions/{id}/confirm
 *   POST   /jsci-prm/v1/transactions/{id}/void
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Class REST_Transactions_Controller
 */
class REST_Transactions_Controller {

    protected string $namespace = REST_Router::NAMESPACE;
    protected string $rest_base = 'transactions';

    public function register_routes(): void {

        register_rest_route( $this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_items' ],
                'permission_callback' => fn() => Roles::current_user_can( 'jsci_view_dashboard' ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_item' ],
                'permission_callback' => fn() => Roles::current_user_can( 'jsci_create_transaction' ),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_item' ],
                'permission_callback' => fn() => Roles::current_user_can( 'jsci_view_dashboard' ),
            ],
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [ $this, 'update_item' ],
                'permission_callback' => fn() => Access_Manager::user_can_manage_transaction_action( get_current_user_id(), 'edit' ),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/update', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'update_item' ],
                'permission_callback' => fn() => Access_Manager::user_can_manage_transaction_action( get_current_user_id(), 'edit' ),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/confirm', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'confirm_item' ],
                'permission_callback' => fn() => Roles::current_user_can( 'jsci_confirm_transaction' ),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/decline', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'decline_item' ],
                'permission_callback' => fn() => Roles::current_user_can( 'jsci_confirm_transaction' ),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/void', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'void_item' ],
                'permission_callback' => fn() => Access_Manager::user_can_manage_transaction_action( get_current_user_id(), 'void' ),
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function get_items( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $table    = Database::transactions();
        $orders_table = Database::orders();
        $departments_table = Database::departments();
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $offset   = ( $page - 1 ) * $per_page;
        $include_voided = rest_sanitize_boolean( $request->get_param( 'include_voided' ) );
        $declined_only = rest_sanitize_boolean( $request->get_param( 'declined_only' ) );
        $exclude_declined = rest_sanitize_boolean( $request->get_param( 'exclude_declined' ) );
        $department_id = (int) ( $request->get_param( 'department_id' ) ?: 0 );
        $created_date  = sanitize_text_field( (string) $request->get_param( 'created_date' ) );

        $where  = $include_voided ? 'WHERE 1=1' : "WHERE tx.deleted_at IS NULL";
        $params = [];

        if ( $v = $request->get_param( 'order_id' ) ) {
            $where   .= " AND tx.order_id = %d";
            $params[] = (int) $v;
        }
        if ( $v = $request->get_param( 'status' ) ) {
            $where   .= " AND tx.status = %s";
            $params[] = sanitize_key( $v );
        }
        if ( $v = $request->get_param( 'from_dept_id' ) ) {
            $where   .= " AND tx.from_dept_id = %d";
            $params[] = (int) $v;
        }
        if ( $v = $request->get_param( 'to_dept_id' ) ) {
            $where   .= " AND tx.to_dept_id = %d";
            $params[] = (int) $v;
        }
        if ( $v = $request->get_param( 'tx_type' ) ) {
            $where   .= " AND tx.tx_type = %s";
            $params[] = sanitize_key( $v );
        }
        if ( $department_id > 0 ) {
            $where   .= " AND ( tx.from_dept_id = %d OR tx.to_dept_id = %d )";
            $params[] = $department_id;
            $params[] = $department_id;
        }
        if ( '' !== $created_date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $created_date ) ) {
            $where   .= " AND DATE(tx.created_at) = %s";
            $params[] = $created_date;
        }
        if ( $declined_only ) {
            $where   .= " AND tx.id IN (
                SELECT log.object_id
                FROM `" . Database::logs() . "` log
                WHERE log.object_type = %s
                AND log.action = %s
                AND log.new_value LIKE %s
            )";
            $params[] = Logger::TYPE_TRANSACTION;
            $params[] = Logger::ACTION_VOID;
            $params[] = '%\"reason\":\"declined\"%';
        } elseif ( $exclude_declined ) {
            $where   .= " AND tx.id NOT IN (
                SELECT log.object_id
                FROM `" . Database::logs() . "` log
                WHERE log.object_type = %s
                AND log.action = %s
                AND log.new_value LIKE %s
            )";
            $params[] = Logger::TYPE_TRANSACTION;
            $params[] = Logger::ACTION_VOID;
            $params[] = '%\"reason\":\"declined\"%';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var(
            $params
                ? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` tx {$where}", ...$params )
                : "SELECT COUNT(*) FROM `{$table}` tx {$where}"
        );

        $query_params   = array_merge( $params, [ $per_page, $offset ] );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "
            SELECT
                tx.*,
                o.order_number,
                o.buyer_name,
                from_dept.name AS from_department_name,
                to_dept.name AS to_department_name
            FROM `{$table}` tx
            LEFT JOIN `{$orders_table}` o
                ON o.id = tx.order_id
            LEFT JOIN `{$departments_table}` from_dept
                ON from_dept.id = tx.from_dept_id
            LEFT JOIN `{$departments_table}` to_dept
                ON to_dept.id = tx.to_dept_id
            {$where}
            ORDER BY tx.created_at DESC
            LIMIT %d OFFSET %d
            ",
            ...$query_params
        ) );

        $response = rest_ensure_response( $rows );
        $response->header( 'X-WP-Total',      $total );
        $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

        return $response;
    }

    public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $include_voided = rest_sanitize_boolean( $request->get_param( 'include_voided' ) );

        // Old //
        // $tx = $wpdb->get_row( $wpdb->prepare(
        //     "SELECT * FROM `" . Database::transactions() . "` WHERE id = %d AND deleted_at IS NULL",
        //     $id
        // ) );


        // New //
        $tx = $wpdb->get_row( $wpdb->prepare(
            "
            SELECT
                tx.*,
                o.order_number,
                o.buyer_name,

                from_dept.name AS from_department_name,
                to_dept.name AS to_department_name,
                created_user.display_name AS created_by_name,
                confirmed_user.display_name AS confirmed_by_name

            FROM `" . Database::transactions() . "` tx

            LEFT JOIN `" . Database::orders() . "` o
                ON o.id = tx.order_id

            LEFT JOIN `" . Database::departments() . "` from_dept
                ON from_dept.id = tx.from_dept_id

             LEFT JOIN `" . Database::departments() . "` to_dept
                 ON to_dept.id = tx.to_dept_id

             LEFT JOIN `{$wpdb->users}` created_user
                 ON created_user.ID = tx.created_by

             LEFT JOIN `{$wpdb->users}` confirmed_user
                 ON confirmed_user.ID = tx.confirmed_by

             WHERE tx.id = %d
             " . ( $include_voided ? '' : 'AND tx.deleted_at IS NULL' ) . "
             ",
             $id
        ) );


        if ( ! $tx ) {
            return new WP_Error( 'jsci_not_found', __( 'Transaction not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        $tx->items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `" . Database::transaction_items() . "` WHERE transaction_id = %d",
            $id
        ) );

        $tx->declined_by_name = null;
        $tx->voided_by_name   = null;
        $tx->voided_at        = $tx->deleted_at ?: null;

        if ( 'voided' === $tx->status ) {
            $void_log = $wpdb->get_row( $wpdb->prepare(
                "
                SELECT user.display_name, log.created_at
                FROM `" . Database::logs() . "` log
                LEFT JOIN `{$wpdb->users}` user
                    ON user.ID = log.user_id
                WHERE log.object_type = %s
                AND log.object_id = %d
                AND log.action = %s
                ORDER BY log.created_at DESC, log.id DESC
                LIMIT 1
                ",
                Logger::TYPE_TRANSACTION,
                $id,
                Logger::ACTION_VOID
            ) );

            if ( $void_log ) {
                $tx->voided_by_name = $void_log->display_name ?: null;
                $tx->voided_at      = $void_log->created_at ?: $tx->voided_at;
            }
        }

        if ( 'send' === $tx->tx_type && 'voided' === $tx->status ) {
            $declined_by = $wpdb->get_var( $wpdb->prepare(
                "
                SELECT user.display_name
                FROM `" . Database::logs() . "` log
                LEFT JOIN `{$wpdb->users}` user
                    ON user.ID = log.user_id
                WHERE log.object_type = %s
                AND log.object_id = %d
                AND log.action = %s
                AND log.new_value LIKE %s
                ORDER BY log.created_at DESC, log.id DESC
                LIMIT 1
                ",
                Logger::TYPE_TRANSACTION,
                $id,
                Logger::ACTION_VOID,
                '%\"reason\":\"declined\"%'
            ) );

            if ( $declined_by ) {
                $tx->declined_by_name = $declined_by;
            }
        }

        return rest_ensure_response( $tx );
    }

    public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $order_id    = (int) $request->get_param( 'order_id' );
        $from_dept   = (int) ( $request->get_param( 'from_dept_id' ) ?: 0 );
        $to_dept     = (int) ( $request->get_param( 'to_dept_id' )   ?: 0 );
        $tx_type     = sanitize_key( $request->get_param( 'tx_type' ) );
        $entry_mode  = sanitize_key( $request->get_param( 'entry_mode' ) ?: 'size' );
        $fabric_used_kg = max( 0, (float) ( $request->get_param( 'fabric_used_kg' ) ?: 0 ) );
        $line_number = sanitize_text_field( $request->get_param( 'line_number' ) ?: '' );
        $reject_stage = sanitize_key( $request->get_param( 'reject_stage' ) ?: '' );
        $send_outside_purpose = sanitize_key( $request->get_param( 'send_outside_purpose' ) ?: '' );
        $external_organization_name = sanitize_text_field( $request->get_param( 'external_organization_name' ) ?: '' );
        $notes       = sanitize_textarea_field( $request->get_param( 'notes' )   ?: '' );
        $items       = $request->get_param( 'items' ) ?: [];

        if ( ! $order_id || ! $tx_type ) {
            return new WP_Error( 'jsci_missing_field', __( 'order_id and tx_type are required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( ! in_array( $tx_type, [ 'receive', 'manual_receive', 'produce', 'send', 'send_outside_factory', 'reject', 'return' ], true ) ) {
            return new WP_Error( 'jsci_invalid_type', __( 'Invalid transaction type.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( ! in_array( $entry_mode, [ 'size', 'kg', 'cutting' ], true ) ) {
            $entry_mode = 'size';
        }

        if ( 'cutting' !== $entry_mode ) {
            $fabric_used_kg = 0;
        } elseif ( 'produce' !== $tx_type ) {
            return new WP_Error( 'jsci_invalid_entry_mode', __( 'Fabric to Piece conversion form is only available for production entries.', 'jsci-prm' ), [ 'status' => 422 ] );
        } elseif ( $fabric_used_kg <= 0 ) {
            return new WP_Error( 'jsci_missing_fabric_used', __( 'Amount of fabric used (KG) is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( 'reject' !== $tx_type ) {
            $reject_stage = '';
        } elseif ( ! in_array( $reject_stage, [ 'before_production', 'after_production' ], true ) ) {
            return new WP_Error( 'jsci_missing_field', __( 'Reject stage is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        } elseif ( ! $this->department_can_reject_stage( $to_dept, $reject_stage, $entry_mode ) ) {
            return new WP_Error( 'jsci_department_reject_stage_forbidden', __( 'This department is not allowed to use the selected Reject Stage.', 'jsci-prm' ), [ 'status' => 403 ] );
        }

        if ( 'send_outside_factory' === $tx_type ) {
            if ( ! in_array( $send_outside_purpose, [ 'embroidery', 'dyeing', 'shipment' ], true ) ) {
                return new WP_Error( 'jsci_missing_field', __( 'Send outside purpose is required.', 'jsci-prm' ), [ 'status' => 422 ] );
            }

            if ( ! $this->department_can_send_outside_purpose( $from_dept, $send_outside_purpose, $entry_mode ) ) {
                return new WP_Error( 'jsci_department_send_outside_purpose_forbidden', __( 'This department is not allowed to use the selected Send Outside Purpose.', 'jsci-prm' ), [ 'status' => 403 ] );
            }

            if ( 'shipment' === $send_outside_purpose ) {
                $external_organization_name = $this->get_order_buyer_name( $order_id );
            }

            if ( '' === $external_organization_name ) {
                return new WP_Error( 'jsci_missing_field', __( 'External organization name is required.', 'jsci-prm' ), [ 'status' => 422 ] );
            }
        } else {
            $send_outside_purpose = '';
            if ( ! in_array( $tx_type, [ 'receive', 'manual_receive' ], true ) ) {
                $external_organization_name = '';
            }
        }

        $access_check = $this->validate_production_entry_access( get_current_user_id(), $tx_type, $from_dept, $to_dept, $line_number, $entry_mode );
        if ( is_wp_error( $access_check ) ) {
            return $access_check;
        }

        // Resolve department prefix for the sequence.
        $dept_prefix = 'GEN';
        $sequence_dept = $from_dept ?: $to_dept;

        if ( $sequence_dept ) {
            $dept = $wpdb->get_row( $wpdb->prepare(
                "SELECT tx_prefix FROM `" . Database::departments() . "` WHERE id = %d",
                $sequence_dept
            ) );
            if ( $dept ) {
                $dept_prefix = $dept->tx_prefix;
            }
        }

        $type_abbrev = match ( $tx_type ) {
            'manual_receive'       => 'MANR',
            'send_outside_factory' => 'SOF',
            default                => strtoupper( substr( $tx_type, 0, 4 ) ),
        };
        $tx_number   = Sequence::next( $dept_prefix, $type_abbrev, $line_number ?: null );

        $transaction_data = [
            'tx_number'   => $tx_number,
            'order_id'    => $order_id,
            'from_dept_id' => $from_dept ?: null,
            'to_dept_id'   => $to_dept   ?: null,
            'tx_type'     => $tx_type,
            'entry_mode'  => $entry_mode,
            'fabric_used_kg' => $fabric_used_kg,
            'line_number' => $line_number ?: null,
            'reject_stage' => $reject_stage ?: null,
            'send_outside_purpose' => $send_outside_purpose ?: null,
            'external_organization_name' => $external_organization_name ?: null,
            'status'      => 'pending',
            'notes'       => $notes,
            'created_by'  => get_current_user_id(),
        ];
        $transaction_format = [ '%s', '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ];

        if ( ! empty( $GLOBALS['jsci_prm_auto_confirm_rest_request'] ) ) {
            $transaction_data['status']       = 'confirmed';
            $transaction_data['confirmed_by'] = get_current_user_id();
            $transaction_data['confirmed_at'] = current_time( 'mysql', true );
            $transaction_format[]             = '%d';
            $transaction_format[]             = '%s';
        }

        $wpdb->insert( Database::transactions(), $transaction_data, $transaction_format );

        $tx_id = (int) $wpdb->insert_id;
        if ( ! $tx_id ) {
            return new WP_Error( 'jsci_db_error', __( 'Could not create transaction.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        // Insert size-wise items.
        $saved_items = [];

        foreach ( $items as $item ) {
            $saved_item = [
                'transaction_id' => $tx_id,
                'order_size_id'  => (int) $item['order_size_id'],
                'size_label'     => sanitize_text_field( $item['size_label'] ),
                'quantity'       => (float) $item['quantity'],
            ];

            $wpdb->insert( Database::transaction_items(), $saved_item, [ '%d', '%d', '%s', '%f' ] );
            $saved_items[] = $saved_item;
        }

        Logger::log(
            Logger::ACTION_CREATE,
            Logger::TYPE_TRANSACTION,
            $tx_id,
            null,
            [
                'id'           => $tx_id,
                'tx_number'    => $tx_number,
                'order_id'     => $order_id,
                'from_dept_id' => $from_dept ?: null,
                'to_dept_id'   => $to_dept ?: null,
                'tx_type'      => $tx_type,
                'entry_mode'   => $entry_mode,
                'fabric_used_kg' => $fabric_used_kg,
                'line_number'  => $line_number ?: null,
                'reject_stage' => $reject_stage ?: null,
                'send_outside_purpose' => $send_outside_purpose ?: null,
                'external_organization_name' => $external_organization_name ?: null,
                'status'       => $transaction_data['status'],
                'notes'        => $notes,
                'created_by'   => get_current_user_id(),
                'confirmed_by' => $transaction_data['confirmed_by'] ?? null,
                'confirmed_at' => $transaction_data['confirmed_at'] ?? null,
                'items'        => $saved_items,
            ]
        );

        if ( in_array( $tx_type, [ 'receive', 'manual_receive' ], true ) && $to_dept && $this->department_auto_produces_after_receive( $to_dept, $entry_mode ) ) {
            $this->create_auto_production_transaction(
                $order_id,
                $to_dept,
                $saved_items,
                $entry_mode,
                __( 'Auto production after receive.', 'jsci-prm' )
            );
        }

        $new_request = new \WP_REST_Request( 'GET' );
        $new_request->set_url_params( [ 'id' => $tx_id ] );

        return rest_ensure_response( $this->get_item( $new_request ) );
    }

    public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $tx = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::transactions() . "` WHERE id = %d AND deleted_at IS NULL",
            $id
        ) );

        if ( ! $tx ) {
            return new WP_Error( 'jsci_not_found', __( 'Transaction not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        if ( ! in_array( $tx->status, [ 'pending', 'confirmed' ], true ) && ! Roles::current_user_can( 'jsci_unlock_transaction' ) ) {
            return new WP_Error( 'jsci_invalid_status', __( 'Only pending or confirmed transactions can be edited.', 'jsci-prm' ), [ 'status' => 409 ] );
        }

        $order_id    = (int) $request->get_param( 'order_id' );
        $from_dept   = (int) ( $request->get_param( 'from_dept_id' ) ?: 0 );
        $to_dept     = (int) ( $request->get_param( 'to_dept_id' )   ?: 0 );
        $tx_type     = sanitize_key( $request->get_param( 'tx_type' ) );
        $entry_mode  = sanitize_key( $request->get_param( 'entry_mode' ) ?: ( $tx->entry_mode ?? 'size' ) );
        $fabric_used_kg = max( 0, (float) ( $request->get_param( 'fabric_used_kg' ) ?: 0 ) );
        $line_number = sanitize_text_field( $request->get_param( 'line_number' ) ?: '' );
        $reject_stage = sanitize_key( $request->get_param( 'reject_stage' ) ?: '' );
        $send_outside_purpose = sanitize_key( $request->get_param( 'send_outside_purpose' ) ?: '' );
        $external_organization_name = sanitize_text_field( $request->get_param( 'external_organization_name' ) ?: '' );
        $notes       = sanitize_textarea_field( $request->get_param( 'notes' )   ?: '' );
        $items       = $request->get_param( 'items' ) ?: [];

        if ( ! $order_id || ! $tx_type ) {
            return new WP_Error( 'jsci_missing_field', __( 'order_id and tx_type are required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( ! in_array( $tx_type, [ 'receive', 'manual_receive', 'produce', 'send', 'send_outside_factory', 'reject', 'return' ], true ) ) {
            return new WP_Error( 'jsci_invalid_type', __( 'Invalid transaction type.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( ! in_array( $entry_mode, [ 'size', 'kg', 'cutting' ], true ) ) {
            $entry_mode = 'size';
        }

        if ( 'cutting' !== $entry_mode ) {
            $fabric_used_kg = 0;
        } elseif ( 'produce' !== $tx_type ) {
            return new WP_Error( 'jsci_invalid_entry_mode', __( 'Fabric to Piece conversion form is only available for production entries.', 'jsci-prm' ), [ 'status' => 422 ] );
        } elseif ( $fabric_used_kg <= 0 ) {
            return new WP_Error( 'jsci_missing_fabric_used', __( 'Amount of fabric used (KG) is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( 'reject' !== $tx_type ) {
            $reject_stage = '';
        } elseif ( ! in_array( $reject_stage, [ 'before_production', 'after_production' ], true ) ) {
            return new WP_Error( 'jsci_missing_field', __( 'Reject stage is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        } elseif ( ! $this->department_can_reject_stage( $to_dept, $reject_stage, $entry_mode ) ) {
            return new WP_Error( 'jsci_department_reject_stage_forbidden', __( 'This department is not allowed to use the selected Reject Stage.', 'jsci-prm' ), [ 'status' => 403 ] );
        }

        if ( 'send_outside_factory' === $tx_type ) {
            if ( ! in_array( $send_outside_purpose, [ 'embroidery', 'dyeing', 'shipment' ], true ) ) {
                return new WP_Error( 'jsci_missing_field', __( 'Send outside purpose is required.', 'jsci-prm' ), [ 'status' => 422 ] );
            }

            if ( ! $this->department_can_send_outside_purpose( $from_dept, $send_outside_purpose, $entry_mode ) ) {
                return new WP_Error( 'jsci_department_send_outside_purpose_forbidden', __( 'This department is not allowed to use the selected Send Outside Purpose.', 'jsci-prm' ), [ 'status' => 403 ] );
            }

            if ( 'shipment' === $send_outside_purpose ) {
                $external_organization_name = $this->get_order_buyer_name( $order_id );
            }

            if ( '' === $external_organization_name ) {
                return new WP_Error( 'jsci_missing_field', __( 'External organization name is required.', 'jsci-prm' ), [ 'status' => 422 ] );
            }
        } else {
            $send_outside_purpose = '';
            if ( ! in_array( $tx_type, [ 'receive', 'manual_receive' ], true ) ) {
                $external_organization_name = '';
            }
        }

        $behavior_check = $this->validate_department_behavior( $tx_type, $from_dept, $to_dept, $entry_mode );
        if ( is_wp_error( $behavior_check ) ) {
            return $behavior_check;
        }

        if ( 'produce' === $tx_type && $this->department_has_active_lines( $to_dept ) && '' === $line_number ) {
            return new WP_Error( 'jsci_missing_line', __( 'Production line is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $old_items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `" . Database::transaction_items() . "` WHERE transaction_id = %d",
            $id
        ) );

        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->update(
            Database::transactions(),
            [
                'order_id'      => $order_id,
                'from_dept_id'  => $from_dept ?: null,
                'to_dept_id'    => $to_dept   ?: null,
                'tx_type'       => $tx_type,
                'entry_mode'    => $entry_mode,
                'fabric_used_kg' => $fabric_used_kg,
                'line_number'   => $line_number ?: null,
                'reject_stage'  => $reject_stage ?: null,
                'send_outside_purpose' => $send_outside_purpose ?: null,
                'external_organization_name' => $external_organization_name ?: null,
                'notes'         => $notes,
            ],
            [ 'id' => $id ],
            [ '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'jsci_db_error', __( 'Could not update transaction.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM `" . Database::transaction_items() . "` WHERE transaction_id = %d",
            $id
        ) );

        if ( false === $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'jsci_db_error', __( 'Could not update transaction items.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        foreach ( $items as $item ) {
            $quantity = max( 0, (float) ( $item['quantity'] ?? 0 ) );

            if ( 0 === $quantity ) {
                continue;
            }

            $inserted = $wpdb->insert( Database::transaction_items(), [
                'transaction_id' => $id,
                'order_size_id'  => (int) ( $item['order_size_id'] ?? 0 ),
                'size_label'     => sanitize_text_field( $item['size_label'] ?? '' ),
                'quantity'       => $quantity,
            ], [ '%d', '%d', '%s', '%f' ] );

            if ( false === $inserted ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'jsci_db_error', __( 'Could not update transaction items.', 'jsci-prm' ), [ 'status' => 500 ] );
            }
        }

        $wpdb->query( 'COMMIT' );

        Logger::log(
            Logger::ACTION_UPDATE,
            Logger::TYPE_TRANSACTION,
            $id,
            [ 'transaction' => $tx, 'items' => $old_items ],
            [
                'order_id'      => $order_id,
                'from_dept_id'  => $from_dept ?: null,
                'to_dept_id'    => $to_dept   ?: null,
                'tx_type'       => $tx_type,
                'entry_mode'    => $entry_mode,
                'fabric_used_kg' => $fabric_used_kg,
                'line_number'   => $line_number ?: null,
                'reject_stage'  => $reject_stage ?: null,
                'send_outside_purpose' => $send_outside_purpose ?: null,
                'external_organization_name' => $external_organization_name ?: null,
                'notes'         => $notes,
                'items'         => $items,
            ]
        );

        return $this->get_item( $request );
    }

    public function confirm_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $tx = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::transactions() . "` WHERE id = %d AND deleted_at IS NULL",
            $id
        ) );

        if ( ! $tx ) {
            return new WP_Error( 'jsci_not_found', __( 'Transaction not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }
        if ( 'pending' !== $tx->status ) {
            return new WP_Error( 'jsci_invalid_status', __( 'Only pending transactions can be confirmed.', 'jsci-prm' ), [ 'status' => 409 ] );
        }
        if (
            'send' === $tx->tx_type
            && ! Access_Manager::user_can_access_department_type( get_current_user_id(), (int) $tx->to_dept_id, 'accept_transfers' )
        ) {
            return new WP_Error(
                'jsci_forbidden_transfer_accept',
                __( 'You do not have access to accept incoming transfers for this department.', 'jsci-prm' ),
                [ 'status' => 403 ]
            );
        }

        $now = current_time( 'mysql', true );
        $wpdb->update(
            Database::transactions(),
            [
                'status'       => 'confirmed',
                'confirmed_by' => get_current_user_id(),
                'confirmed_at' => $now,
                'locked_at'    => $now,
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s', '%s' ], [ '%d' ]
        );

        Logger::log( Logger::ACTION_CONFIRM, Logger::TYPE_TRANSACTION, $id, [ 'status' => 'pending' ], [ 'status' => 'confirmed' ] );

        $entry_mode = in_array( $tx->entry_mode ?? 'size', [ 'size', 'kg', 'cutting' ], true ) ? $tx->entry_mode : 'size';

        if ( 'send' === $tx->tx_type && $tx->to_dept_id && $this->department_auto_produces_after_receive( (int) $tx->to_dept_id, $entry_mode ) ) {
            $items = $wpdb->get_results( $wpdb->prepare(
                "SELECT order_size_id, size_label, quantity FROM `" . Database::transaction_items() . "` WHERE transaction_id = %d",
                $id
            ), ARRAY_A ) ?: [];

            $this->create_auto_production_transaction(
                (int) $tx->order_id,
                (int) $tx->to_dept_id,
                $items,
                $entry_mode,
                __( 'Auto production after accepted transfer.', 'jsci-prm' )
            );
        }

        return rest_ensure_response( [ 'confirmed' => true, 'id' => $id, 'tx_number' => $tx->tx_number ] );
    }

    public function decline_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $tx = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::transactions() . "` WHERE id = %d AND deleted_at IS NULL",
            $id
        ) );

        if ( ! $tx ) {
            return new WP_Error( 'jsci_not_found', __( 'Transaction not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        if ( 'send' !== $tx->tx_type || 'pending' !== $tx->status ) {
            return new WP_Error( 'jsci_invalid_status', __( 'Only pending transfer transactions can be declined.', 'jsci-prm' ), [ 'status' => 409 ] );
        }
        if ( ! Access_Manager::user_can_access_department_type( get_current_user_id(), (int) $tx->to_dept_id, 'accept_transfers' ) ) {
            return new WP_Error(
                'jsci_forbidden_transfer_decline',
                __( 'You do not have access to accept incoming transfers for this department.', 'jsci-prm' ),
                [ 'status' => 403 ]
            );
        }

        $wpdb->update(
            Database::transactions(),
            [ 'status' => 'voided', 'deleted_at' => current_time( 'mysql', true ) ],
            [ 'id'     => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        Logger::log( Logger::ACTION_VOID, Logger::TYPE_TRANSACTION, $id, [ 'status' => 'pending' ], [ 'status' => 'voided', 'reason' => 'declined' ] );

        return rest_ensure_response( [ 'declined' => true, 'id' => $id, 'tx_number' => $tx->tx_number ] );
    }

    public function void_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $tx = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::transactions() . "` WHERE id = %d AND deleted_at IS NULL",
            $id
        ) );

        if ( ! $tx ) {
            return new WP_Error( 'jsci_not_found', __( 'Transaction not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }
        if ( in_array( $tx->status, [ 'locked', 'voided' ], true ) && ! Roles::current_user_can( 'jsci_unlock_transaction' ) ) {
            return new WP_Error( 'jsci_forbidden', __( 'Cannot void a locked transaction.', 'jsci-prm' ), [ 'status' => 403 ] );
        }

        $wpdb->update(
            Database::transactions(),
            [ 'status' => 'voided', 'deleted_at' => current_time( 'mysql', true ) ],
            [ 'id'     => $id ],
            [ '%s', '%s' ], [ '%d' ]
        );

        Logger::log( Logger::ACTION_VOID, Logger::TYPE_TRANSACTION, $id, [ 'status' => $tx->status ], [ 'status' => 'voided' ] );

        return rest_ensure_response( [ 'voided' => true, 'id' => $id ] );
    }

    /**
     * Keep transaction writes inside the user's saved Production Entry access.
     */
    private function validate_production_entry_access( int $user_id, string $tx_type, int $from_dept, int $to_dept, string $line_number, string $entry_mode = 'size' ): true|WP_Error {
        if ( ! class_exists( Access_Manager::class ) ) {
            return true;
        }

        if ( ! in_array( $tx_type, [ 'receive', 'manual_receive', 'produce', 'send', 'send_outside_factory', 'reject' ], true ) ) {
            return new WP_Error( 'jsci_invalid_type', __( 'Invalid transaction type.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $access_tx_type = 'manual_receive' === $tx_type ? 'receive' : $tx_type;
        $department_id = in_array( $tx_type, [ 'send', 'send_outside_factory' ], true ) ? $from_dept : $to_dept;

        if ( ! $department_id ) {
            return new WP_Error( 'jsci_missing_department', __( 'Department is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $behavior_check = $this->validate_department_behavior( $tx_type, $from_dept, $to_dept, $entry_mode );
        if ( is_wp_error( $behavior_check ) ) {
            return $behavior_check;
        }

        if ( ! Access_Manager::user_can_access_department_type( $user_id, $department_id, $access_tx_type ) ) {
            return new WP_Error(
                'jsci_forbidden_entry_type',
                __( 'You do not have access to this entry type for the selected department.', 'jsci-prm' ),
                [ 'status' => 403 ]
            );
        }

        if ( 'produce' !== $tx_type || ! $this->department_has_active_lines( $department_id ) ) {
            return true;
        }

        if ( '' === $line_number ) {
            return new WP_Error( 'jsci_missing_line', __( 'Production line is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( ! Access_Manager::user_can_access_department_line( $user_id, $department_id, $line_number ) ) {
            return new WP_Error(
                'jsci_forbidden_line',
                __( 'You do not have access to the selected line entry.', 'jsci-prm' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    private function department_has_active_lines( int $department_id ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `" . Database::department_lines() . "` WHERE department_id = %d AND is_active = 1 LIMIT 1",
            $department_id
        ) );
    }

    private function validate_department_behavior( string $tx_type, int $from_dept, int $to_dept, string $entry_mode = 'size' ): true|WP_Error {
        $department_id = in_array( $tx_type, [ 'send', 'send_outside_factory' ], true ) ? $from_dept : $to_dept;
        $department = $this->get_department_behavior( $department_id );

        if ( ! $department ) {
            return new WP_Error( 'jsci_missing_department', __( 'Department is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( 'cutting' === $entry_mode ) {
            $allowed = match ( $tx_type ) {
                'produce' => ! empty( $department->allow_fabric_to_piece_conversion_entry ),
                default => false,
            };
        } elseif ( 'kg' === $entry_mode ) {
            $allowed = match ( $tx_type ) {
                'receive', 'manual_receive' => ! empty( $department->allow_manual_entry_kg ),
                'produce' => ! empty( $department->allow_production_entry_kg ),
                'reject' => ! empty( $department->allow_reject_entry_kg ),
                'send' => ! empty( $department->allow_send_entry_kg ),
                'send_outside_factory' => ! empty( $department->allow_send_outside_factory_entry_kg ),
                default => true,
            };
        } else {
            $allowed = match ( $tx_type ) {
                'receive', 'manual_receive' => ! empty( $department->allow_manual_entry ),
                'produce' => ! empty( $department->allow_production_entry ),
                'reject' => ! empty( $department->allow_reject_entry ),
                'send' => ! empty( $department->allow_send_entry ),
                'send_outside_factory' => ! empty( $department->allow_send_outside_factory_entry ),
                default => true,
            };
        }

        if ( ! $allowed ) {
            return new WP_Error(
                'jsci_department_behavior_forbidden',
                __( 'This department is not configured for the selected entry type.', 'jsci-prm' ),
                [ 'status' => 403 ]
            );
        }

        if ( 'send' === $tx_type && ! $this->department_can_send_to( $department, $to_dept, $entry_mode ) ) {
            return new WP_Error(
                'jsci_department_send_destination_forbidden',
                __( 'This department is not allowed to send items to the selected department.', 'jsci-prm' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    private function get_department_behavior( int $department_id ): ?object {
        global $wpdb;

        if ( ! $department_id ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, tx_prefix, allow_manual_entry, allow_production_entry, allow_fabric_to_piece_conversion_entry, auto_produce_on_receive, allow_reject_entry, reject_stages, allow_send_entry, allow_send_outside_factory_entry, send_outside_purposes, allow_manual_entry_kg, allow_production_entry_kg, auto_produce_on_receive_kg, allow_reject_entry_kg, reject_stages_kg, allow_send_entry_kg, allow_send_outside_factory_entry_kg, send_outside_purposes_kg, send_to_department_ids, send_to_department_ids_kg FROM `" . Database::departments() . "` WHERE id = %d AND is_active = 1",
            $department_id
        ) );
    }

    private function department_can_reject_stage( int $department_id, string $stage, string $entry_mode = 'size' ): bool {
        $department = $this->get_department_behavior( $department_id );

        if ( ! $department ) {
            return false;
        }

        $raw_stages = 'kg' === $entry_mode
            ? ( $department->reject_stages_kg ?? null )
            : ( $department->reject_stages ?? null );

        if ( null === $raw_stages || '' === $raw_stages || 'null' === $raw_stages ) {
            return in_array( $stage, [ 'before_production', 'after_production' ], true );
        }

        $allowed_stages = json_decode( $raw_stages, true );
        $allowed_stages = is_array( $allowed_stages )
            ? array_map( 'sanitize_key', $allowed_stages )
            : [];

        return in_array( $stage, $allowed_stages, true );
    }

    private function department_can_send_outside_purpose( int $department_id, string $purpose, string $entry_mode = 'size' ): bool {
        $department = $this->get_department_behavior( $department_id );

        if ( ! $department ) {
            return false;
        }

        $raw_purposes = 'kg' === $entry_mode
            ? ( $department->send_outside_purposes_kg ?? null )
            : ( $department->send_outside_purposes ?? null );

        if ( null === $raw_purposes || '' === $raw_purposes || 'null' === $raw_purposes ) {
            return in_array( $purpose, [ 'embroidery', 'dyeing', 'shipment' ], true );
        }

        $allowed_purposes = json_decode( $raw_purposes, true );
        $allowed_purposes = is_array( $allowed_purposes )
            ? array_map( 'sanitize_key', $allowed_purposes )
            : [];

        return in_array( $purpose, $allowed_purposes, true );
    }

    private function get_order_buyer_name( int $order_id ): string {
        global $wpdb;

        if ( ! $order_id ) {
            return '';
        }

        return sanitize_text_field( (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT buyer_name FROM `" . Database::orders() . "` WHERE id = %d AND deleted_at IS NULL",
            $order_id
        ) ) );
    }

    private function department_can_send_to( object $department, int $to_department_id, string $entry_mode = 'size' ): bool {
        if ( ! $to_department_id || (int) $department->id === $to_department_id ) {
            return false;
        }

        $send_to_department_ids = 'kg' === $entry_mode
            ? ( $department->send_to_department_ids_kg ?? null )
            : ( $department->send_to_department_ids ?? null );

        if ( null === $send_to_department_ids || '' === $send_to_department_ids || 'null' === $send_to_department_ids ) {
            return true;
        }

        $allowed_departments = json_decode( $send_to_department_ids, true );
        $allowed_departments = is_array( $allowed_departments )
            ? array_map( 'absint', $allowed_departments )
            : [];

        return in_array( $to_department_id, $allowed_departments, true );
    }

    private function department_auto_produces_after_receive( int $department_id, string $entry_mode = 'size' ): bool {
        $department = $this->get_department_behavior( $department_id );

        if ( ! $department ) {
            return false;
        }

        return 'kg' === $entry_mode
            ? ! empty( $department->auto_produce_on_receive_kg )
            : ! empty( $department->auto_produce_on_receive );
    }

    private function create_auto_production_transaction( int $order_id, int $department_id, array $items, string $entry_mode, string $notes ): void {
        global $wpdb;

        if ( ! $items ) {
            return;
        }

        $department = $this->get_department_behavior( $department_id );
        $dept_prefix = $department && ! empty( $department->tx_prefix ) ? $department->tx_prefix : 'GEN';
        $tx_number = Sequence::next( $dept_prefix, 'PROD' );
        $now = current_time( 'mysql', true );

        $wpdb->insert(
            Database::transactions(),
            [
                'tx_number'    => $tx_number,
                'order_id'     => $order_id,
                'from_dept_id' => null,
                'to_dept_id'   => $department_id,
                'tx_type'      => 'produce',
                'entry_mode'   => $entry_mode,
                'line_number'  => null,
                'reject_stage' => null,
                'status'       => 'confirmed',
                'notes'        => $notes,
                'created_by'   => get_current_user_id(),
                'confirmed_by' => get_current_user_id(),
                'confirmed_at' => $now,
            ],
            [ '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ]
        );

        $tx_id = (int) $wpdb->insert_id;

        if ( ! $tx_id ) {
            return;
        }

        $saved_items = [];

        foreach ( $items as $item ) {
            $saved_item = [
                'transaction_id' => $tx_id,
                'order_size_id'  => (int) ( $item['order_size_id'] ?? 0 ),
                'size_label'     => sanitize_text_field( $item['size_label'] ?? '' ),
                'quantity'       => (float) ( $item['quantity'] ?? 0 ),
            ];

            if ( $saved_item['quantity'] <= 0 || ( 'kg' !== $entry_mode && ! $saved_item['order_size_id'] ) ) {
                continue;
            }

            $wpdb->insert( Database::transaction_items(), $saved_item, [ '%d', '%d', '%s', '%f' ] );
            $saved_items[] = $saved_item;
        }

        Logger::log(
            Logger::ACTION_CREATE,
            Logger::TYPE_TRANSACTION,
            $tx_id,
            null,
            [
                'id'         => $tx_id,
                'tx_number'  => $tx_number,
                'order_id'   => $order_id,
                'to_dept_id' => $department_id,
                'tx_type'    => 'produce',
                'entry_mode' => $entry_mode,
                'status'     => 'confirmed',
                'notes'      => $notes,
                'items'      => $saved_items,
            ]
        );
    }
}
