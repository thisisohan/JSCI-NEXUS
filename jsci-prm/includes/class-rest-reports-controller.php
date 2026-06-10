<?php
/**
 * REST controller – Reports & KPI.
 *
 * Endpoints:
 *   GET /jsci-prm/v1/reports/order-summary/{order_id}
 *   GET /jsci-prm/v1/reports/kpi/{order_id}
 *   GET /jsci-prm/v1/reports/department-balance
 *   GET /jsci-prm/v1/reports/audit-log
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class REST_Reports_Controller {

    protected string $namespace = REST_Router::NAMESPACE;
    protected string $rest_base = 'reports';

    public function register_routes(): void {

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/order-summary/(?P<order_id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'order_summary' ],
                'permission_callback' => fn() => Roles::current_user_can( 'jsci_view_reports' ),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/kpi/(?P<order_id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'kpi_report' ],
                'permission_callback' => fn() => Roles::current_user_can( 'jsci_view_reports' ),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/department-balance', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'department_balance' ],
                'permission_callback' => fn() => Roles::current_user_can( 'jsci_view_reports' ),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/audit-log', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'audit_log' ],
                'permission_callback' => fn() => Roles::current_user_can( 'jsci_view_audit_log' ),
            ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    /**
     * Size-wise production totals across all confirmed transactions for an order.
     */
    public function order_summary( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $order_id = (int) $request->get_param( 'order_id' );
        $tx_table  = Database::transactions();
        $item_table = Database::transaction_items();
        $size_table = Database::order_sizes();

        // Planned quantities.
        $planned = $wpdb->get_results( $wpdb->prepare(
            "SELECT size_label, required_qty FROM `{$size_table}` WHERE order_id = %d ORDER BY sort_order ASC",
            $order_id
        ) );

        // Produced quantities (produce transactions, confirmed/locked).
        $produced = $wpdb->get_results( $wpdb->prepare(
            "SELECT ti.size_label, SUM(ti.quantity) AS total_qty
               FROM `{$item_table}` ti
               JOIN `{$tx_table}` tx ON tx.id = ti.transaction_id
              WHERE tx.order_id = %d
                AND tx.tx_type  = 'produce'
                AND tx.status   IN ('confirmed','locked')
                AND tx.deleted_at IS NULL
              GROUP BY ti.size_label",
            $order_id
        ) );

        $produced_map = array_column( $produced, 'total_qty', 'size_label' );

        $summary = array_map( function ( $p ) use ( $produced_map ) {
            $done = (int) ( $produced_map[ $p->size_label ] ?? 0 );
            return [
                'size_label'   => $p->size_label,
                'required_qty' => (int) $p->required_qty,
                'produced_qty' => $done,
                'remaining'    => max( 0, (int) $p->required_qty - $done ),
                'pct_complete' => $p->required_qty > 0
                    ? round( $done / $p->required_qty * 100, 1 )
                    : 0,
            ];
        }, $planned );

        return rest_ensure_response( [
            'order_id' => $order_id,
            'sizes'    => $summary,
        ] );
    }

    /**
     * KPI evaluation: PASS / LATE PASS / FAIL per order.
     */
    public function kpi_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $order_id = (int) $request->get_param( 'order_id' );
        $order    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . Database::orders() . "` WHERE id = %d AND deleted_at IS NULL",
            $order_id
        ) );

        if ( ! $order ) {
            return new WP_Error( 'jsci_not_found', __( 'Order not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        $summary_response = $this->order_summary( $request );
        $summary          = rest_ensure_response( $summary_response )->get_data();

        $deadline    = $order->shipment_deadline ? new \DateTime( $order->shipment_deadline ) : null;
        $now         = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

        $results = [];
        foreach ( $summary['sizes'] as $size ) {
            $complete  = $size['produced_qty'] >= $size['required_qty'];

            if ( $complete && $deadline ) {
                $kpi = $now <= $deadline ? 'PASS' : 'LATE_PASS';
            } elseif ( $complete ) {
                $kpi = 'PASS';
            } else {
                $kpi = 'FAIL';
            }

            $results[] = array_merge( $size, [
                'kpi'             => $kpi,
                'deadline'        => $order->shipment_deadline,
                'days_remaining'  => $deadline ? (int) $now->diff( $deadline )->days * ( $now <= $deadline ? 1 : -1 ) : null,
            ] );
        }

        return rest_ensure_response( [
            'order_id' => $order_id,
            'buyer'    => $order->buyer_name,
            'deadline' => $order->shipment_deadline,
            'kpi'      => $results,
        ] );
    }

    /**
     * Running balance per department (sent - received) for an optional order.
     */
    public function department_balance( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $order_id  = (int) ( $request->get_param( 'order_id' ) ?: 0 );
        $tx_table  = Database::transactions();
        $item_table = Database::transaction_items();
        $dept_table = Database::departments();

        $where  = "tx.status IN ('confirmed','locked') AND tx.deleted_at IS NULL";
        $params = [];
        if ( $order_id ) {
            $where   .= " AND tx.order_id = %d";
            $params[] = $order_id;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $params
                ? $wpdb->prepare(
                    "SELECT d.name AS dept_name, tx.tx_type, ti.size_label, SUM(ti.quantity) AS qty
                       FROM `{$item_table}` ti
                       JOIN `{$tx_table}` tx ON tx.id = ti.transaction_id
                       JOIN `{$dept_table}` d ON d.id = tx.from_dept_id
                      WHERE {$where}
                      GROUP BY d.name, tx.tx_type, ti.size_label",
                    ...$params
                )
                : "SELECT d.name AS dept_name, tx.tx_type, ti.size_label, SUM(ti.quantity) AS qty
                     FROM `{$item_table}` ti
                     JOIN `{$tx_table}` tx ON tx.id = ti.transaction_id
                     JOIN `{$dept_table}` d ON d.id = tx.from_dept_id
                    WHERE {$where}
                    GROUP BY d.name, tx.tx_type, ti.size_label"
        );

        return rest_ensure_response( $rows ?: [] );
    }

    /**
     * Audit log with filtering.
     */
    public function audit_log( WP_REST_Request $request ): WP_REST_Response {
        $args = [
            'user_id'     => (int) ( $request->get_param( 'user_id' )     ?: 0 ) ?: null,
            'action'      => sanitize_key( $request->get_param( 'action' ) ?: '' ) ?: null,
            'object_type' => sanitize_key( $request->get_param( 'object_type' ) ?: '' ) ?: null,
            'object_id'   => (int) ( $request->get_param( 'object_id' )   ?: 0 ) ?: null,
            'date_from'   => sanitize_text_field( $request->get_param( 'date_from' ) ?: '' ) ?: null,
            'date_to'     => sanitize_text_field( $request->get_param( 'date_to' )   ?: '' ) ?: null,
            'limit'       => min( 200, max( 1, (int) ( $request->get_param( 'limit' )  ?: 50 ) ) ),
            'offset'      => max( 0, (int) ( $request->get_param( 'offset' ) ?: 0 ) ),
        ];

        return rest_ensure_response( Logger::query( array_filter( $args ) ) );
    }
}
