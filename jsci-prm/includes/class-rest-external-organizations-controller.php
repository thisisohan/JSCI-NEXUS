<?php
/**
 * REST controller - External organizations.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class REST_External_Organizations_Controller {

    protected string $namespace = REST_Router::NAMESPACE;
    protected string $rest_base = 'external-organizations';

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_items' ],
                'permission_callback' => [ $this, 'manage_permissions_check' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_item' ],
                'permission_callback' => [ $this, 'manage_permissions_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/void', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'void_item' ],
            'permission_callback' => [ $this, 'manage_permissions_check' ],
        ] );
    }

    public function manage_permissions_check(): bool|WP_Error {
        return Roles::current_user_can_manage_access_management()
            ?: new WP_Error( 'jsci_forbidden', __( 'You do not have permission to manage External organizations.', 'jsci-prm' ), [ 'status' => 403 ] );
    }

    public function get_items(): WP_REST_Response {
        return rest_ensure_response( [
            'active' => $this->active_items(),
            'voided' => $this->voided_items(),
        ] );
    }

    public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $name = sanitize_text_field( (string) $request->get_param( 'name' ) );

        if ( '' === $name ) {
            return new WP_Error( 'jsci_missing_field', __( 'External organization name is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $active = $this->active_items();
        $voided = $this->voided_items();

        if ( ! in_array( $name, $active, true ) ) {
            $active[] = $name;
        }

        $voided = array_values( array_filter( $voided, static fn( string $item ): bool => $item !== $name ) );
        $this->save_items( $active, $voided );

        return $this->get_items();
    }

    public function void_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $name = sanitize_text_field( (string) $request->get_param( 'name' ) );

        if ( '' === $name ) {
            return new WP_Error( 'jsci_missing_field', __( 'External organization name is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $active = array_values( array_filter(
            $this->active_items(),
            static fn( string $item ): bool => $item !== $name
        ) );
        $voided = $this->voided_items();

        if ( ! in_array( $name, $voided, true ) ) {
            $voided[] = $name;
        }

        $this->save_items( $active, $voided );

        return $this->get_items();
    }

    private function active_items(): array {
        return $this->sanitize_items( get_option( 'jsci_prm_external_organizations', [] ) );
    }

    private function voided_items(): array {
        return $this->sanitize_items( get_option( 'jsci_prm_voided_external_organizations', [] ) );
    }

    private function save_items( array $active, array $voided ): void {
        update_option( 'jsci_prm_external_organizations', $this->sanitize_items( $active ), false );
        update_option( 'jsci_prm_voided_external_organizations', $this->sanitize_items( $voided ), false );
    }

    private function sanitize_items( mixed $items ): array {
        $items = is_array( $items ) ? $items : [];
        $items = array_map( 'sanitize_text_field', $items );
        $items = array_values( array_unique( array_filter( $items, static fn( string $item ): bool => '' !== $item ) ) );
        natcasesort( $items );

        return array_values( $items );
    }
}
