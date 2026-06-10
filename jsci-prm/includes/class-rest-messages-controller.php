<?php
/**
 * REST controller - frontend Messages.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class REST_Messages_Controller
 */
class REST_Messages_Controller {

    protected string $namespace = REST_Router::NAMESPACE;
    protected string $rest_base = 'Messages';

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/active', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'active' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'items' ],
                'permission_callback' => [ $this, 'manage_permissions_check' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create' ],
                'permission_callback' => [ $this, 'manage_permissions_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [ $this, 'update' ],
                'permission_callback' => [ $this, 'manage_permissions_check' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete' ],
                'permission_callback' => [ $this, 'manage_permissions_check' ],
            ],
        ] );
    }

    public function manage_permissions_check(): bool|WP_Error {
        return Access_Manager::user_can_manage_Messages( get_current_user_id() )
            ?: new WP_Error( 'jsci_forbidden', __( 'You do not have access to the Message page.', 'jsci-prm' ), [ 'status' => 403 ] );
    }

    public function active(): WP_REST_Response {
        global $wpdb;

        $now = current_time( 'mysql' );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `" . Database::Messages() . "`
                WHERE is_active = 1
                AND deleted_at IS NULL
                AND (expires_at IS NULL OR expires_at > %s)
                AND id IN (
                    SELECT MAX(slot_item.id)
                    FROM `" . Database::Messages() . "` slot_item
                    WHERE slot_item.is_active = 1
                    AND slot_item.deleted_at IS NULL
                    AND (slot_item.expires_at IS NULL OR slot_item.expires_at > %s)
                    GROUP BY slot_item.slot
                )
                ORDER BY FIELD(slot, 'primary', 'secondary'), created_at DESC, id DESC",
                $now,
                $now
            )
        ) ?: [];

        $items = [
            'primary'   => null,
            'secondary' => null,
        ];

        foreach ( $rows as $row ) {
            $slot = $this->sanitize_slot( $row->slot ?? 'primary' );
            $items[ $slot ] = $this->format_item( $row );
        }

        return rest_ensure_response( [
            'primary'   => $items['primary'],
            'secondary' => $items['secondary'],
            'items'     => array_values( array_filter( $items ) ),
        ] );
    }

    public function items(): WP_REST_Response {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `" . Database::Messages() . "`
                WHERE user_id = %d
                AND deleted_at IS NULL
                ORDER BY created_at DESC, id DESC",
                get_current_user_id()
            )
        ) ?: [];

        return rest_ensure_response( array_map( [ $this, 'format_item' ], $rows ) );
    }

    public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $data = $this->sanitize_payload( $request );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $user = wp_get_current_user();
        $inserted = $wpdb->insert(
            Database::Messages(),
            [
                'user_id'      => get_current_user_id(),
                'user_name'    => $user->display_name,
                'slot'         => $data['slot'],
                'message'      => $data['message'],
                'expires_at'   => $data['expires_at'],
                'is_active'    => 1,
                'created_by'   => get_current_user_id(),
                'updated_by'   => get_current_user_id(),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d' ]
        );

        if ( false === $inserted ) {
            return new WP_Error( 'jsci_Message_create_failed', __( 'Could not create Message.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        $item = $this->get_item( (int) $wpdb->insert_id );
        Logger::log( Logger::ACTION_CREATE, Logger::TYPE_Message, (int) $wpdb->insert_id, null, $this->audit_snapshot( $item ) );

        return rest_ensure_response( $this->format_item( $item ) );
    }

    public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $existing = $this->get_item( $id );
        if ( ! $existing || (int) $existing->user_id !== get_current_user_id() ) {
            return new WP_Error( 'jsci_Message_not_found', __( 'Message not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        $data = $this->sanitize_payload( $request, true );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        $updated = $wpdb->update(
            Database::Messages(),
            [
                'message'    => $data['message'],
                'slot'       => $data['slot'],
                'expires_at' => $data['expires_at'],
                'is_active'  => ! empty( $data['is_active'] ) ? 1 : 0,
                'updated_by' => get_current_user_id(),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%d', '%d' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return new WP_Error( 'jsci_Message_update_failed', __( 'Could not update Message.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        $item = $this->get_item( $id );
        Logger::log( Logger::ACTION_UPDATE, Logger::TYPE_Message, $id, $this->audit_snapshot( $existing ), $this->audit_snapshot( $item ) );

        return rest_ensure_response( $this->format_item( $item ) );
    }

    public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $existing = $this->get_item( $id );
        if ( ! $existing || (int) $existing->user_id !== get_current_user_id() ) {
            return new WP_Error( 'jsci_Message_not_found', __( 'Message not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        $deleted_at = current_time( 'mysql' );
        $deleted = $wpdb->update(
            Database::Messages(),
            [
                'is_active'  => 0,
                'deleted_at' => $deleted_at,
                'updated_by' => get_current_user_id(),
            ],
            [ 'id' => $id ],
            [ '%d', '%s', '%d' ],
            [ '%d' ]
        );

        if ( false === $deleted ) {
            return new WP_Error( 'jsci_Message_delete_failed', __( 'Could not delete Message.', 'jsci-prm' ), [ 'status' => 500 ] );
        }

        Logger::log( Logger::ACTION_DELETE, Logger::TYPE_Message, $id, $this->audit_snapshot( $existing ), [ 'deleted_at' => $deleted_at ] );

        return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
    }

    private function get_item( int $id ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `" . Database::Messages() . "` WHERE id = %d", $id )
        ) ?: null;
    }

    private function sanitize_payload( WP_REST_Request $request, bool $allow_status = false ): array|WP_Error {
        $message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
        $expires = sanitize_text_field( (string) $request->get_param( 'expires_at' ) );
        $slot = $this->sanitize_slot( $request->get_param( 'slot' ) );

        if ( '' === trim( $message ) ) {
            return new WP_Error( 'jsci_Message_required', __( 'Message is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( mb_strlen( $message ) > 3000 ) {
            return new WP_Error( 'jsci_Message_too_long', __( 'Message cannot be longer than 3000 characters.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( '' === $expires ) {
            return new WP_Error( 'jsci_Message_expiry_required', __( 'Please choose when this Message will deactivate.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        try {
            $date = new \DateTimeImmutable( $expires, wp_timezone() );
        } catch ( \Exception $exception ) {
            return new WP_Error( 'jsci_Message_expiry_invalid', __( 'Please choose a valid deactivate time.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $expires_at = $date->format( 'Y-m-d H:i:s' );
        $is_active = $allow_status ? ! empty( $request->get_param( 'is_active' ) ) : true;

        return [
            'message'    => $message,
            'slot'       => $slot,
            'expires_at' => $expires_at,
            'is_active'  => $is_active,
        ];
    }

    private function sanitize_slot( mixed $slot ): string {
        $slot = sanitize_key( (string) $slot );

        return in_array( $slot, [ 'primary', 'secondary' ], true ) ? $slot : 'primary';
    }

    private function format_item( object $item ): array {
        $expires_at = $item->expires_at ?: null;
        $now = current_time( 'mysql' );
        $designation = sanitize_text_field( (string) get_user_meta( (int) $item->user_id, 'jsci_prm_designation', true ) );
        $first_name = sanitize_text_field( (string) get_user_meta( (int) $item->user_id, 'first_name', true ) );
        $last_name = sanitize_text_field( (string) get_user_meta( (int) $item->user_id, 'last_name', true ) );
        $full_name = trim( $first_name . ' ' . $last_name );
        $full_name = '' !== $full_name ? $full_name : $item->user_name;

        return [
            'id'         => (int) $item->id,
            'user_id'    => (int) $item->user_id,
            'user_name'  => $item->user_name,
            'user_full_name' => $full_name,
            'user_designation' => $designation,
            'slot'       => $this->sanitize_slot( $item->slot ?? 'primary' ),
            'message'    => $item->message,
            'is_active'  => (bool) $item->is_active && ( ! $expires_at || $expires_at > $now ),
            'expires_at' => $expires_at,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }

    private function audit_snapshot( ?object $item ): ?array {
        if ( ! $item ) {
            return null;
        }

        return [
            'Message_id'  => (int) $item->id,
            'user_id'     => (int) $item->user_id,
            'user_name'   => $item->user_name,
            'user_full_name' => trim(
                sanitize_text_field( (string) get_user_meta( (int) $item->user_id, 'first_name', true ) )
                . ' '
                . sanitize_text_field( (string) get_user_meta( (int) $item->user_id, 'last_name', true ) )
            ) ?: $item->user_name,
            'user_designation' => sanitize_text_field( (string) get_user_meta( (int) $item->user_id, 'jsci_prm_designation', true ) ),
            'slot'        => $this->sanitize_slot( $item->slot ?? 'primary' ),
            'Message'     => $item->message,
            'is_active'   => (bool) $item->is_active,
            'expires_at'  => $item->expires_at,
            'created_at'  => $item->created_at,
            'updated_at'  => $item->updated_at,
        ];
    }
}
