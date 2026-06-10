<?php
/**
 * REST controller – Orders.
 *
 * Endpoints:
 *   GET    /jsci-prm/v1/orders
 *   POST   /jsci-prm/v1/orders
 *   GET    /jsci-prm/v1/orders/{id}
 *   PUT    /jsci-prm/v1/orders/{id}
 *   DELETE /jsci-prm/v1/orders/{id}          (soft delete)
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Class REST_Orders_Controller
 */
class REST_Orders_Controller {

    protected string $namespace = REST_Router::NAMESPACE;
    protected string $rest_base = 'orders';

    public function register_routes(): void {

        register_rest_route( $this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_items' ],
                'permission_callback' => [ $this, 'get_items_permissions_check' ],
                'args'                => $this->collection_params(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_item' ],
                'permission_callback' => [ $this, 'create_item_permissions_check' ],
                'args'                => $this->item_schema(),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_item' ],
                'permission_callback' => [ $this, 'get_items_permissions_check' ],
            ],
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [ $this, 'update_item' ],
                'permission_callback' => [ $this, 'update_item_permissions_check' ],
                'args'                => $this->item_schema(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_item' ],
                'permission_callback' => [ $this, 'delete_item_permissions_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/status', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'update_status' ],
                'permission_callback' => [ $this, 'change_status_permissions_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/complete', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'complete_order' ],
                'permission_callback' => [ $this, 'change_status_permissions_check' ],
            ],
        ] );
    }

    // ── Permission callbacks ──────────────────────────────────────────────────

    public function get_items_permissions_check( WP_REST_Request $request ): bool|WP_Error {
        return Roles::current_user_can( 'jsci_view_dashboard' )
            ?: new WP_Error( 'jsci_forbidden', __( 'Insufficient permissions.', 'jsci-prm' ), [ 'status' => 403 ] );
    }

    public function create_item_permissions_check( WP_REST_Request $request ): bool|WP_Error {
        return Access_Manager::user_can_manage_order_action( get_current_user_id(), 'create' )
            ?: new WP_Error( 'jsci_forbidden', __( 'Insufficient permissions.', 'jsci-prm' ), [ 'status' => 403 ] );
    }

    public function update_item_permissions_check( WP_REST_Request $request ): bool|WP_Error {
        return Access_Manager::user_can_manage_order_action( get_current_user_id(), 'edit' )
            ?: new WP_Error( 'jsci_forbidden', __( 'Insufficient permissions.', 'jsci-prm' ), [ 'status' => 403 ] );
    }

    public function delete_item_permissions_check( WP_REST_Request $request ): bool|WP_Error {
        return Access_Manager::user_can_manage_order_action( get_current_user_id(), 'void' )
            ?: new WP_Error( 'jsci_forbidden', __( 'Insufficient permissions.', 'jsci-prm' ), [ 'status' => 403 ] );
    }

    public function change_status_permissions_check( WP_REST_Request $request ): bool|WP_Error {
        return Access_Manager::user_can_manage_order_action( get_current_user_id(), 'change_status' )
            ?: new WP_Error( 'jsci_forbidden', __( 'Insufficient permissions.', 'jsci-prm' ), [ 'status' => 403 ] );
    }

    // ── Endpoint handlers ─────────────────────────────────────────────────────

    public function get_items( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $orders_table = Database::orders();
        $sizes_table  = Database::order_sizes();

        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $offset   = ( $page - 1 ) * $per_page;
        $status   = sanitize_key( $request->get_param( 'status' ) ?: '' );
        $search   = sanitize_text_field( $request->get_param( 'search' ) ?: '' );

        $show_voided = 'voided' === $status;
        $where  = $show_voided ? "WHERE o.deleted_at IS NOT NULL" : "WHERE o.deleted_at IS NULL";
        $params = [];

        if ( $status && ! $show_voided ) {
            $where   .= " AND o.status = %s";
            $params[] = $status;
        }

        if ( $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where   .= " AND (o.buyer_name LIKE %s OR o.order_number LIKE %s OR o.reference_number LIKE %s)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$orders_table}` o {$where}",
            ...$params
        ) );

        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT o.* FROM `{$orders_table}` o {$where} ORDER BY o.created_at DESC LIMIT %d OFFSET %d",
            ...$params
        ) );

        // Attach sizes for each order.
        $order_ids = wp_list_pluck( $orders, 'id' );
        $sizes_map = [];

        if ( $order_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sizes = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM `{$sizes_table}` WHERE order_id IN ({$placeholders}) ORDER BY sort_order ASC",
                ...$order_ids
            ) );
            foreach ( $sizes as $s ) {
                $sizes_map[ $s->order_id ][] = $s;
            }
        }

        $data = array_map( function ( $order ) use ( $sizes_map ) {

            global $wpdb;

            $order->sizes = $sizes_map[ $order->id ] ?? [];

            $order->department_deadlines = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT d.*, dept.name AS department_name
                     FROM `" . Database::order_department_deadlines() . "` d
                     LEFT JOIN `" . Database::departments() . "` dept
                       ON dept.id = d.department_id
                     WHERE d.order_id = %d
                     ORDER BY dept.workflow_order ASC",
                    $order->id
                )
            );
            $order->is_voided = ! empty( $order->deleted_at );

            $frontend_url = $this->get_frontend_order_entry_url();
            $can_edit_order = Access_Manager::user_can_manage_order_action( get_current_user_id(), 'edit' );
            $can_change_order_status = Access_Manager::user_can_manage_order_action( get_current_user_id(), 'change_status' );
            $can_void_order = Access_Manager::user_can_manage_order_action( get_current_user_id(), 'void' );
            $order->actions = [];

            if ( $can_edit_order ) {
                $order->actions['edit'] = [
                    'label' => __( 'Edit Order', 'jsci-prm' ),
                    'method' => 'PUT',
                    'endpoint' => rest_url(
                        REST_Router::NAMESPACE . '/orders/' . $order->id
                    ),
                ];
            }

            if ( $can_void_order ) {
                $order->actions['void'] = [
                    'label' => __( 'Void', 'jsci-prm' ),
                    'method' => 'DELETE',
                    'endpoint' => rest_url(
                        REST_Router::NAMESPACE . '/orders/' . $order->id
                    ),
                ];
            }

            if ( $can_change_order_status && ! $order->is_voided && $frontend_url ) {
                $order->actions[ 'completed' === $order->status ? 'review' : 'status' ] = [
                    'action' => 'completed' === $order->status ? 'review' : 'status',
                    'label' => 'completed' === $order->status
                        ? __( 'Review', 'jsci-prm' )
                        : __( 'Change Status', 'jsci-prm' ),
                    'method'  => 'GET',
                    'url'     => $frontend_url,
                ];
            } elseif ( $order->is_voided && isset( $order->actions['edit'] ) ) {
                $order->actions['edit']['label'] = __( 'View Voided Order', 'jsci-prm' );
                unset( $order->actions['void'] );
            }

            if ( 'completed' === $order->status ) {
                unset( $order->actions['edit'] );
            }

            return $order;

        }, $orders );

        $response = rest_ensure_response( $data );
        $response->header( 'X-WP-Total',      $total );
        $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

        return $response;
    }

    public function get_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id    = (int) $request->get_param( 'id' );
        $allow_deleted = rest_sanitize_boolean( $request->get_param( 'include_deleted' ) ?: false );
        $order = $wpdb->get_row( $wpdb->prepare(
            $allow_deleted
                ? "SELECT * FROM `" . Database::orders() . "` WHERE id = %d"
                : "SELECT * FROM `" . Database::orders() . "` WHERE id = %d AND deleted_at IS NULL",
            $id
        ) );

        if ( ! $order ) {
            return new WP_Error( 'jsci_not_found', __( 'Order not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        $order->sizes = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `" . Database::order_sizes() . "` WHERE order_id = %d ORDER BY sort_order ASC",
            $id
        ) );
        $order->department_deadlines = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.*, dept.name AS department_name
                 FROM `" . Database::order_department_deadlines() . "` d
                 LEFT JOIN `" . Database::departments() . "` dept
                   ON dept.id = d.department_id
                 WHERE d.order_id = %d
                 ORDER BY dept.workflow_order ASC",
                $id
            )
        );
        $order->completion = $this->get_order_completion( $id );
        $order->shipment_summary = $this->get_order_shipment_summary( $id, $order->sizes );
        $order->server_time = current_time( 'mysql' );
        $order->is_voided = ! empty( $order->deleted_at );
        $can_edit_order = Access_Manager::user_can_manage_order_action( get_current_user_id(), 'edit' );
        $can_change_order_status = Access_Manager::user_can_manage_order_action( get_current_user_id(), 'change_status' );
        $can_void_order = Access_Manager::user_can_manage_order_action( get_current_user_id(), 'void' );
        $order->actions = [];

        if ( $can_edit_order ) {
            $order->actions['edit'] = [
                'label' => __( 'Edit Order', 'jsci-prm' ),
                'method' => 'PUT',
                'endpoint' => rest_url(
                    REST_Router::NAMESPACE . '/orders/' . $order->id
                ),
            ];
        }

        if ( $can_void_order ) {
            $order->actions['void'] = [
                'label' => __( 'Void', 'jsci-prm' ),
                'method' => 'DELETE',
                'endpoint' => rest_url(
                    REST_Router::NAMESPACE . '/orders/' . $order->id
                ),
            ];
        }

        $frontend_url = $this->get_frontend_order_entry_url();
        if ( $can_change_order_status && ! $order->is_voided && $frontend_url ) {
            $order->actions[ 'completed' === $order->status ? 'review' : 'status' ] = [
                'action' => 'completed' === $order->status ? 'review' : 'status',
                'label' => 'completed' === $order->status
                    ? __( 'Review', 'jsci-prm' )
                    : __( 'Change Status', 'jsci-prm' ),
                'method'  => 'GET',
                'url'     => $frontend_url,
            ];
        } elseif ( $order->is_voided && isset( $order->actions['edit'] ) ) {
            unset( $order->actions['void'] );
            $order->actions['edit']['label'] = __( 'View Voided Order', 'jsci-prm' );
        }

        if ( 'completed' === $order->status ) {
            unset( $order->actions['edit'] );
        }

        return rest_ensure_response( $order );
    }

    public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $data = $this->prepare_item_for_database( $request );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $wpdb->insert( Database::orders(), $data['order'], $data['order_format'] );
        $order_id = (int) $wpdb->insert_id;

        if ( ! $order_id ) {
            return new WP_Error( 'jsci_db_error', __( 'Could not create order.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        // Insert sizes.
        foreach ( ( $request->get_param( 'sizes' ) ?: [] ) as $idx => $size ) {
            $wpdb->insert( Database::order_sizes(), [
                'order_id'     => $order_id,
                'size_label'   => sanitize_text_field( $size['size_label'] ),
                'required_qty' => (int) $size['required_qty'],
                'sort_order'   => $idx,
            ], [ '%d', '%s', '%d', '%d' ] );
        }

        // Insert department deadlines.
        foreach ( ( $request->get_param( 'department_deadlines' ) ?: [] ) as $row ) {

            $wpdb->insert(
                Database::order_department_deadlines(),
                [
                    'order_id'      => $order_id,
                    'department_id' => (int) $row['department_id'],
                    'deadline_date' => sanitize_text_field( $row['deadline_date'] ?? '' ) ?: null,
                    'extra_pct'     => ! empty( $row['add_extra'] ) ? (float) ( $row['extra_pct'] ?? 0 ) : 0,
                ],
                [ '%d', '%d', '%s', '%f' ]
            );
        }

        Logger::log( Logger::ACTION_CREATE, Logger::TYPE_ORDER, $order_id, null, $data['order'] );

        $request->set_url_params( [ 'id' => $order_id ] );

        return rest_ensure_response( $this->get_item( $request ) );
    }

    public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::orders() . "` WHERE id = %d AND deleted_at IS NULL", $id
        ) );

        if ( ! $existing ) {
            return new WP_Error( 'jsci_not_found', __( 'Order not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        if ( 'completed' === $existing->status ) {
            return new WP_Error(
                'jsci_invalid_status',
                __( 'Completed orders cannot be edited.', 'jsci-prm' ),
                [ 'status' => 409 ]
            );
        }

        $requested_status = sanitize_key( $request->get_param( 'status' ) ?: '' );

        if (
            $requested_status
            && $requested_status !== $existing->status
            && ! Access_Manager::user_can_manage_order_action( get_current_user_id(), 'change_status' )
        ) {
            return new WP_Error(
                'jsci_forbidden',
                __( 'Insufficient permissions to change order status.', 'jsci-prm' ),
                [ 'status' => 403 ]
            );
        }

        $data = $this->prepare_item_for_database( $request, $existing->status );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $data['order']['updated_by'] = get_current_user_id();
        $wpdb->update( Database::orders(), $data['order'], [ 'id' => $id ], $data['order_format'], [ '%d' ] );

        // Replace sizes.
        $wpdb->delete(
            Database::order_sizes(),
            [ 'order_id' => $id ],
            [ '%d' ]
        );

        foreach ( ( $request->get_param( 'sizes' ) ?: [] ) as $idx => $size ) {

            $wpdb->insert(
                Database::order_sizes(),
                [
                    'order_id'     => $id,
                    'size_label'   => sanitize_text_field( $size['size_label'] ),
                    'required_qty' => (int) $size['required_qty'],
                    'sort_order'   => $idx,
                ],
                [ '%d', '%s', '%d', '%d' ]
            );
        }

        // Replace department deadlines.
        $wpdb->delete(
            Database::order_department_deadlines(),
            [ 'order_id' => $id ],
            [ '%d' ]
        );

        foreach ( ( $request->get_param( 'department_deadlines' ) ?: [] ) as $row ) {

            $wpdb->insert(
                Database::order_department_deadlines(),
                [
                    'order_id'      => $id,
                    'department_id' => (int) $row['department_id'],
                    'deadline_date' => sanitize_text_field( $row['deadline_date'] ?? '' ) ?: null,
                    'extra_pct'     => ! empty( $row['add_extra'] ) ? (float) ( $row['extra_pct'] ?? 0 ) : 0,
                ],
                [ '%d', '%d', '%s', '%f' ]
            );
        }

        Logger::log( Logger::ACTION_UPDATE, Logger::TYPE_ORDER, $id, $existing, $data['order'] );

        return $this->get_item( $request );
    }

    public function delete_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $password = (string) ( $request->get_param( 'password' ) ?: '' );

        if ( '' === $password ) {
            return new WP_Error(
                'jsci_missing_password',
                __( 'Please re-enter your password to void this order.', 'jsci-prm' ),
                [ 'status' => 422 ]
            );
        }

        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
            return new WP_Error(
                'jsci_invalid_password',
                __( 'The password you entered is incorrect.', 'jsci-prm' ),
                [ 'status' => 403 ]
            );
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::orders() . "` WHERE id = %d AND deleted_at IS NULL", $id
        ) );

        if ( ! $existing ) {
            return new WP_Error( 'jsci_not_found', __( 'Order not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        $wpdb->update(
            Database::orders(),
            [ 'deleted_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ],
            [ '%s' ], [ '%d' ]
        );

        Logger::log( Logger::ACTION_DELETE, Logger::TYPE_ORDER, $id, $existing, null );

        return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
    }

    private function get_frontend_order_entry_url(): string {
        $templates = [ 'order entry.php', 'Production Entry.php' ];

        foreach ( $templates as $template ) {
            $pages = get_pages( [
                'number'      => 1,
                'post_status' => 'publish',
                'meta_key'    => '_wp_page_template',
                'meta_value'  => $template,
            ] );

            if ( ! empty( $pages[0]->ID ) ) {
                $url = get_permalink( (int) $pages[0]->ID );
                if ( $url ) {
                    return $url;
                }
            }
        }

        return '';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function prepare_item_for_database( WP_REST_Request $request, ?string $existing_status = null ): array|WP_Error {
        $order_number = sanitize_text_field( $request->get_param( 'order_number' ) );
        if ( ! $order_number ) {
            return new WP_Error( 'jsci_missing_field', __( 'order_number is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $requested_status = sanitize_key( $request->get_param( 'status' ) ?: '' );
        $allowed_statuses  = [ 'active', 'cancelled', 'on_hold' ];
        $status            = $existing_status ?: 'active';

        if ( $requested_status ) {
            if ( 'completed' === $existing_status && 'completed' !== $requested_status ) {
                return new WP_Error(
                    'jsci_invalid_status',
                    __( 'Completed orders cannot be changed from the edit form.', 'jsci-prm' ),
                    [ 'status' => 409 ]
                );
            }

            if ( 'completed' === $requested_status ) {
                if ( 'completed' !== $existing_status ) {
                    return new WP_Error(
                        'jsci_invalid_status',
                        __( 'Use the completion dialog to mark an order completed.', 'jsci-prm' ),
                        [ 'status' => 422 ]
                    );
                }

                $status = 'completed';
            } elseif ( in_array( $requested_status, $allowed_statuses, true ) ) {
                $status = $requested_status;
            } elseif ( null === $existing_status ) {
                $status = 'active';
            }
        }

        return [
            'order' => [
                'order_number'        => $order_number,
                'reference_number'    => sanitize_text_field( $request->get_param( 'reference_number' ) ?: '' ),
                'buyer_name'          => sanitize_text_field( $request->get_param( 'buyer_name' )       ?: '' ),
                'required_yarn_qty'   => (float) ( $request->get_param( 'required_yarn_qty' )   ?: 0 ),
                'required_fabric_qty' => (float) ( $request->get_param( 'required_fabric_qty' ) ?: 0 ),
                'cutting_extra_pct'   => (float) ( $request->get_param( 'cutting_extra_pct' )   ?: 0 ),
                'shipment_deadline'   => sanitize_text_field( $request->get_param( 'shipment_deadline' ) ?: '' ) ?: null,
                'notes'               => sanitize_textarea_field( $request->get_param( 'notes' ) ?: '' ),
                'status'              => $status,
                'created_by'          => get_current_user_id(),
                'updated_by'          => get_current_user_id(),
            ],
            'order_format' => [ '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%d', '%d' ],
        ];
    }

    public function update_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id     = (int) $request->get_param( 'id' );
        $status = sanitize_key( $request->get_param( 'status' ) ?: '' );
        $note   = sanitize_textarea_field( $request->get_param( 'note' ) ?: '' );

        if ( ! in_array( $status, [ 'active', 'cancelled', 'on_hold' ], true ) ) {
            return new WP_Error(
                'jsci_invalid_status',
                __( 'Only active, cancelled, and on hold can be selected from the status dropdown.', 'jsci-prm' ),
                [ 'status' => 422 ]
            );
        }

        if ( in_array( $status, [ 'cancelled', 'on_hold' ], true ) && ! trim( $note ) ) {
            return new WP_Error(
                'jsci_missing_field',
                __( 'A note is required when setting an order to cancelled or on hold.', 'jsci-prm' ),
                [ 'status' => 422 ]
            );
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::orders() . "` WHERE id = %d AND deleted_at IS NULL",
            $id
        ) );

        if ( ! $existing ) {
            return new WP_Error( 'jsci_not_found', __( 'Order not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        if ( 'completed' === $existing->status ) {
            return new WP_Error(
                'jsci_invalid_status',
                __( 'Completed orders cannot be moved back with the status dropdown.', 'jsci-prm' ),
                [ 'status' => 409 ]
            );
        }

        $updated = $wpdb->update(
            Database::orders(),
            [
                'status'     => $status,
                'updated_by' => get_current_user_id(),
            ],
            [ 'id' => $id ],
            [ '%s', '%d' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return new WP_Error( 'jsci_db_error', __( 'Could not update order status.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        $wpdb->insert(
            Database::order_status_history(),
            [
                'order_id'    => $id,
                'status'      => $status,
                'note'        => $note ?: null,
                'changed_by'  => get_current_user_id(),
                'changed_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s' ]
        );

        Logger::log(
            Logger::ACTION_UPDATE,
            Logger::TYPE_ORDER,
            $id,
            [ 'status' => $existing->status ],
            [ 'status' => $status, 'note' => $note ]
        );

        $request->set_url_params( [ 'id' => $id ] );

        return $this->get_item( $request );
    }

    public function complete_order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id            = (int) $request->get_param( 'id' );
        $quantity_mode = sanitize_key( $request->get_param( 'quantity_mode' ) ?: '' );
        $detail_mode   = sanitize_key( $request->get_param( 'detail_mode' ) ?: 'summary' );
        $shipped_date  = sanitize_text_field( $request->get_param( 'shipped_date' ) ?: '' );
        $note          = sanitize_textarea_field( $request->get_param( 'note' ) ?: '' );

        if ( 'auto' !== $quantity_mode ) {
            return new WP_Error(
                'jsci_missing_field',
                __( 'Only Auto Detect completion is available right now.', 'jsci-prm' ),
                [ 'status' => 422 ]
            );
        }

        if ( ! in_array( $detail_mode, [ 'summary', 'sizes' ], true ) ) {
            return new WP_Error(
                'jsci_invalid_field',
                __( 'Please choose a valid completion view.', 'jsci-prm' ),
                [ 'status' => 422 ]
            );
        }

        if ( ! $shipped_date ) {
            return new WP_Error(
                'jsci_missing_field',
                __( 'Order shipped date is required.', 'jsci-prm' ),
                [ 'status' => 422 ]
            );
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $shipped_date ) ) {
            return new WP_Error(
                'jsci_invalid_date',
                __( 'Please enter a valid shipped date.', 'jsci-prm' ),
                [ 'status' => 422 ]
            );
        }

        if ( ! trim( $note ) ) {
            return new WP_Error(
                'jsci_missing_field',
                __( 'A note is required to complete this order.', 'jsci-prm' ),
                [ 'status' => 422 ]
            );
        }

        $order = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::orders() . "` WHERE id = %d AND deleted_at IS NULL",
            $id
        ) );

        if ( ! $order ) {
            return new WP_Error( 'jsci_not_found', __( 'Order not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        if ( 'completed' === $order->status ) {
            return new WP_Error(
                'jsci_invalid_status',
                __( 'This order is already completed.', 'jsci-prm' ),
                [ 'status' => 409 ]
            );
        }

        $sizes = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `" . Database::order_sizes() . "` WHERE order_id = %d ORDER BY sort_order ASC",
            $id
        ) );

        if ( empty( $sizes ) ) {
            return new WP_Error(
                'jsci_missing_field',
                __( 'At least one size row is required to complete an order.', 'jsci-prm' ),
                [ 'status' => 422 ]
            );
        }

        $items           = [];
        $required_total   = 0;
        $actual_total     = 0;

        foreach ( $sizes as $size ) {
            $required_total += max( 0, (int) $size->required_qty );
        }

        if ( 'auto' === $quantity_mode ) {
            $detail_mode = 'sizes';
            $shipment_summary = $this->get_order_shipment_summary( $id, $sizes );

            foreach ( $shipment_summary['items'] as $item ) {
                $actual_total += (int) $item['actual_qty'];
                $items[] = [
                    'order_size_id' => (int) $item['order_size_id'],
                    'size_label'    => sanitize_text_field( $item['size_label'] ),
                    'required_qty'  => (int) $item['required_qty'],
                    'actual_qty'    => (int) $item['actual_qty'],
                    'qty_diff'      => (int) $item['qty_diff'],
                ];
            }
        } elseif ( 'full' === $quantity_mode ) {
            $actual_total = $required_total;
        } elseif ( 'summary' === $detail_mode ) {
            $summary_actual = $request->get_param( 'summary_actual_qty' );
            $summary_diff   = $request->get_param( 'summary_diff_qty' );
            $has_actual     = null !== $summary_actual && '' !== $summary_actual;
            $has_diff       = null !== $summary_diff && '' !== $summary_diff;

            if ( ! $has_actual && ! $has_diff ) {
                return new WP_Error(
                    'jsci_missing_field',
                    __( 'Please enter the total quantity for completion.', 'jsci-prm' ),
                    [ 'status' => 422 ]
                );
            }

            if ( $has_actual ) {
                $actual_total = (int) $summary_actual;

                if ( $actual_total < 0 ) {
                    return new WP_Error(
                        'jsci_invalid_field',
                        __( 'Quantities cannot be negative.', 'jsci-prm' ),
                        [ 'status' => 422 ]
                    );
                }
            } else {
                $summary_diff = (int) $summary_diff;

                if ( $summary_diff < 0 ) {
                    return new WP_Error(
                        'jsci_invalid_field',
                        __( 'Quantities cannot be negative.', 'jsci-prm' ),
                        [ 'status' => 422 ]
                    );
                }

                $actual_total = 'short' === $quantity_mode
                    ? max( 0, $required_total - $summary_diff )
                    : $required_total + $summary_diff;
            }

            if ( 'short' === $quantity_mode && $actual_total > $required_total ) {
                return new WP_Error(
                    'jsci_invalid_field',
                    __( 'Short quantity cannot be greater than the required quantity.', 'jsci-prm' ),
                    [ 'status' => 422 ]
                );
            }

            if ( 'over' === $quantity_mode && $actual_total < $required_total ) {
                return new WP_Error(
                    'jsci_invalid_field',
                    __( 'Over quantity must be equal to or greater than the required quantity.', 'jsci-prm' ),
                    [ 'status' => 422 ]
                );
            }
        } else {
            $items_payload = $request->get_param( 'items' ) ?: [];
            $items_map     = [];

            foreach ( $items_payload as $item ) {
                if ( empty( $item['order_size_id'] ) ) {
                    continue;
                }
                $items_map[ (int) $item['order_size_id'] ] = $item;
            }

            foreach ( $sizes as $size ) {
                $required_qty = max( 0, (int) $size->required_qty );
                $actual_qty   = $required_qty;

                if ( in_array( $quantity_mode, [ 'short', 'over' ], true ) ) {
                    if ( empty( $items_map[ (int) $size->id ] ) ) {
                        return new WP_Error(
                            'jsci_missing_field',
                            __( 'Please enter a quantity for every size.', 'jsci-prm' ),
                            [ 'status' => 422 ]
                        );
                    }

                    $raw_actual = $items_map[ (int) $size->id ]['actual_qty'] ?? null;
                    if ( null === $raw_actual || '' === $raw_actual ) {
                        return new WP_Error(
                            'jsci_missing_field',
                            __( 'Please enter a quantity for every size.', 'jsci-prm' ),
                            [ 'status' => 422 ]
                        );
                    }

                    $actual_qty = (int) $raw_actual;

                    if ( $actual_qty < 0 ) {
                        return new WP_Error(
                            'jsci_invalid_field',
                            __( 'Quantities cannot be negative.', 'jsci-prm' ),
                            [ 'status' => 422 ]
                        );
                    }

                    if ( 'short' === $quantity_mode && $actual_qty > $required_qty ) {
                        return new WP_Error(
                            'jsci_invalid_field',
                            __( 'Short quantity cannot be greater than the required quantity.', 'jsci-prm' ),
                            [ 'status' => 422 ]
                        );
                    }

                    if ( 'over' === $quantity_mode && $actual_qty < $required_qty ) {
                        return new WP_Error(
                            'jsci_invalid_field',
                            __( 'Over quantity must be equal to or greater than the required quantity.', 'jsci-prm' ),
                            [ 'status' => 422 ]
                        );
                    }
                }

                $items[] = [
                    'order_size_id' => (int) $size->id,
                    'size_label'    => sanitize_text_field( $size->size_label ),
                    'required_qty'  => $required_qty,
                    'actual_qty'    => $actual_qty,
                    'qty_diff'      => $actual_qty - $required_qty,
                ];

                $actual_total += $actual_qty;
            }
        }

        $qty_diff_total = $actual_total - $required_total;

        $completion_table = Database::order_completions();
        $items_table      = Database::order_completion_items();
        $history_table     = Database::order_status_history();
        $now              = current_time( 'mysql' );
        $current_user     = get_current_user_id();

        $wpdb->query( 'START TRANSACTION' );

        $completion_inserted = $wpdb->insert(
            $completion_table,
            [
                'order_id'           => $id,
                'quantity_mode'      => $quantity_mode,
                'detail_mode'        => $detail_mode,
                'shipped_date'       => $shipped_date,
                'completion_note'    => $note,
                'required_qty_total' => $required_total,
                'actual_qty_total'   => $actual_total,
                'qty_diff_total'     => $qty_diff_total,
                'completed_by'       => $current_user,
                'completed_at'       => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' ]
        );

        if ( false === $completion_inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'jsci_db_error', __( 'Could not save order completion.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        $completion_id = (int) $wpdb->insert_id;

        foreach ( $items as $item ) {
            $ok = $wpdb->insert(
                $items_table,
                [
                    'completion_id' => $completion_id,
                    'order_size_id' => $item['order_size_id'],
                    'size_label'    => $item['size_label'],
                    'required_qty'  => $item['required_qty'],
                    'actual_qty'    => $item['actual_qty'],
                    'qty_diff'      => $item['qty_diff'],
                ],
                [ '%d', '%d', '%s', '%d', '%d', '%d' ]
            );

            if ( false === $ok ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'jsci_db_error', __( 'Could not save order completion items.', 'jsci-prm' ), [ 'status' => 500 ] );
            }
        }

        $updated = $wpdb->update(
            Database::orders(),
            [
                'status'     => 'completed',
                'updated_by' => $current_user,
            ],
            [ 'id' => $id ],
            [ '%s', '%d' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'jsci_db_error', __( 'Could not update order status.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        $history_ok = $wpdb->insert(
            $history_table,
            [
                'order_id'    => $id,
                'status'      => 'completed',
                'note'        => $note,
                'changed_by'  => $current_user,
                'changed_at'  => $now,
            ],
            [ '%d', '%s', '%s', '%d', '%s' ]
        );

        if ( false === $history_ok ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'jsci_db_error', __( 'Could not record order history.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        $wpdb->query( 'COMMIT' );

        Logger::log(
            Logger::ACTION_UPDATE,
            Logger::TYPE_ORDER,
            $id,
            [ 'status' => $order->status ],
            [
                'status'         => 'completed',
                'quantity_mode'  => $quantity_mode,
                'detail_mode'    => $detail_mode,
                'shipped_date'   => $shipped_date,
                'completed_at'   => $now,
                'total_actual'   => $actual_total,
                'total_diff'     => $qty_diff_total,
            ]
        );

        $request->set_url_params( [ 'id' => $id ] );

        return $this->get_item( $request );
    }

    private function get_order_completion( int $order_id ): ?object {
        global $wpdb;

        $completion = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::order_completions() . "` WHERE order_id = %d",
            $order_id
        ) );

        if ( ! $completion ) {
            return null;
        }

        $completion->items = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                i.*,
                (i.actual_qty - i.required_qty) AS qty_diff
             FROM `" . Database::order_completion_items() . "` i
             WHERE i.completion_id = %d
             ORDER BY i.id ASC",
            $completion->id
        ) );

        if ( empty( $completion->required_qty_total ) && ! empty( $completion->items ) ) {
            $completion->required_qty_total = array_sum( array_map( static fn( $item ) => (int) $item->required_qty, $completion->items ) );
        }

        if ( empty( $completion->actual_qty_total ) && ! empty( $completion->items ) ) {
            $completion->actual_qty_total = array_sum( array_map( static fn( $item ) => (int) $item->actual_qty, $completion->items ) );
        }

        if ( ! isset( $completion->qty_diff_total ) || '' === $completion->qty_diff_total ) {
            $completion->qty_diff_total = (int) $completion->actual_qty_total - (int) $completion->required_qty_total;
        }

        return $completion;
    }

    private function get_order_shipment_summary( int $order_id, array $sizes ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                ti.order_size_id,
                ti.size_label,
                SUM(ti.quantity) AS shipped_qty
             FROM `" . Database::transactions() . "` tx
             INNER JOIN `" . Database::transaction_items() . "` ti
                ON ti.transaction_id = tx.id
             WHERE tx.order_id = %d
               AND tx.tx_type = %s
               AND tx.send_outside_purpose = %s
               AND tx.status IN ('pending','confirmed','locked')
               AND tx.deleted_at IS NULL
             GROUP BY ti.order_size_id, ti.size_label",
            $order_id,
            'send_outside_factory',
            'shipment'
        ) );

        $shipped_by_size = [];
        $shipped_by_label = [];
        foreach ( $rows as $row ) {
            $qty = (int) round( (float) $row->shipped_qty );
            $order_size_id = (int) $row->order_size_id;
            $size_label = sanitize_text_field( $row->size_label ?? '' );

            if ( $order_size_id > 0 ) {
                $shipped_by_size[ $order_size_id ] = ( $shipped_by_size[ $order_size_id ] ?? 0 ) + $qty;
            }

            if ( '' !== $size_label ) {
                $label_key = strtolower( trim( $size_label ) );
                $shipped_by_label[ $label_key ] = ( $shipped_by_label[ $label_key ] ?? 0 ) + $qty;
            }
        }

        $items          = [];
        $required_total = 0;
        $actual_total   = 0;

        foreach ( $sizes as $size ) {
            $required_qty = max( 0, (int) $size->required_qty );
            $label_key    = strtolower( trim( (string) $size->size_label ) );
            $actual_qty   = $shipped_by_size[ (int) $size->id ] ?? ( $shipped_by_label[ $label_key ] ?? 0 );

            $required_total += $required_qty;
            $actual_total   += $actual_qty;

            $items[] = [
                'order_size_id' => (int) $size->id,
                'size_label'    => sanitize_text_field( $size->size_label ),
                'required_qty'  => $required_qty,
                'actual_qty'    => $actual_qty,
                'qty_diff'      => $actual_qty - $required_qty,
            ];
        }

        return [
            'required_qty_total' => $required_total,
            'actual_qty_total'   => $actual_total,
            'qty_diff_total'     => $actual_total - $required_total,
            'items'              => $items,
        ];
    }

    private function collection_params(): array {
        return [
            'page'     => [ 'type' => 'integer', 'default' => 1,    'minimum' => 1    ],
            'per_page' => [ 'type' => 'integer', 'default' => 20,   'minimum' => 1, 'maximum' => 100 ],
            'status'   => [ 'type' => 'string',  'default' => '',   'enum' => [ '', 'active', 'completed', 'cancelled', 'on_hold', 'voided' ] ],
            'search'   => [ 'type' => 'string',  'default' => '',   'sanitize_callback' => 'sanitize_text_field' ],
        ];
    }

    private function item_schema(): array {
        return [
            'order_number'        => [ 'type' => 'string', 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
            'reference_number'    => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'buyer_name'          => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
            'required_yarn_qty'   => [ 'type' => 'number', 'required' => false  ],
            'required_fabric_qty' => [ 'type' => 'number', 'required' => false  ],
            'cutting_extra_pct'   => [ 'type' => 'number', 'required' => false  ],
            'shipment_deadline'   => [ 'type' => 'string', 'required' => false, 'format' => 'date' ],
            'notes'               => [ 'type' => 'string', 'required' => false  ],
            'status'              => [ 'type' => 'string', 'required' => false  ],
            'sizes'               => [ 'type' => 'array',  'required' => false  ],
            'department_deadlines' => [ 'type' => 'array', 'required' => false ],
        ];
    }
}
