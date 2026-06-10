<?php
/**
 * REST controller - Access management.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class REST_Access_Management_Controller
 */
class REST_Access_Management_Controller {

    protected string $namespace = REST_Router::NAMESPACE;
    protected string $rest_base = 'access-management';

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/users', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_user' ],
                'permission_callback' => fn() => Roles::current_user_can_manage_access_management(),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/users/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_user_access' ],
                'permission_callback' => fn() => Roles::current_user_can_manage_access_management(),
            ],
            [
                'methods'             => 'PUT,PATCH,POST',
                'callback'            => [ $this, 'save_user_access' ],
                'permission_callback' => fn() => Roles::current_user_can_manage_access_management(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'deactivate_user' ],
                'permission_callback' => fn() => Roles::current_user_can_manage_access_management(),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/users/(?P<id>\d+)/profile', [
            [
                'methods'             => 'PUT,PATCH,POST',
                'callback'            => [ $this, 'update_user_profile' ],
                'permission_callback' => fn() => Roles::current_user_can_manage_access_management(),
            ],
        ] );
    }

    public function create_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $username = sanitize_user( (string) $request->get_param( 'username' ), true );
        $email = sanitize_email( (string) $request->get_param( 'email' ) );
        $password = (string) $request->get_param( 'password' );

        if ( '' === $username ) {
            return new WP_Error( 'jsci_missing_username', __( 'Username is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( ! is_email( $email ) ) {
            return new WP_Error( 'jsci_invalid_email', __( 'A valid email is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( '' === $password ) {
            return new WP_Error( 'jsci_missing_password', __( 'Password is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( username_exists( $username ) ) {
            return new WP_Error( 'jsci_username_exists', __( 'Username already exists.', 'jsci-prm' ), [ 'status' => 409 ] );
        }

        if ( email_exists( $email ) ) {
            return new WP_Error( 'jsci_email_exists', __( 'Email already exists.', 'jsci-prm' ), [ 'status' => 409 ] );
        }

        $role = $this->sanitize_user_role( (string) $request->get_param( 'role' ) );
        $first_name = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
        $last_name = sanitize_text_field( (string) $request->get_param( 'last_name' ) );
        $designation = sanitize_text_field( (string) $request->get_param( 'designation' ) );

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $email,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'nickname'     => $first_name ?: $username,
            'display_name' => trim( $first_name . ' ' . $last_name ) ?: $username,
            'role'         => $role,
        ] );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        update_user_meta( (int) $user_id, 'jsci_prm_designation', $designation );
        delete_user_meta( (int) $user_id, 'jsci_prm_user_inactive' );

        return rest_ensure_response( [
            'saved' => true,
            'user'  => $this->format_user( new \WP_User( (int) $user_id ) ),
        ] );
    }

    public function update_user_profile( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = (int) $request->get_param( 'id' );
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return new WP_Error( 'jsci_user_not_found', __( 'User not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        if ( in_array( 'administrator', (array) $user->roles, true ) ) {
            return new WP_Error( 'jsci_protected_user', __( 'Administrator users cannot be edited from Access Management.', 'jsci-prm' ), [ 'status' => 403 ] );
        }

        $email = sanitize_email( (string) $request->get_param( 'email' ) );

        if ( ! is_email( $email ) ) {
            return new WP_Error( 'jsci_invalid_email', __( 'A valid email is required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $existing_email_user = email_exists( $email );

        if ( $existing_email_user && (int) $existing_email_user !== $user_id ) {
            return new WP_Error( 'jsci_email_exists', __( 'Email already exists.', 'jsci-prm' ), [ 'status' => 409 ] );
        }

        $first_name = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
        $last_name = sanitize_text_field( (string) $request->get_param( 'last_name' ) );
        $password = (string) $request->get_param( 'password' );
        $designation = sanitize_text_field( (string) $request->get_param( 'designation' ) );
        $role = $this->sanitize_user_role( (string) $request->get_param( 'role' ) );

        $payload = [
            'ID'           => $user_id,
            'user_email'   => $email,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'nickname'     => $first_name ?: $user->user_login,
            'display_name' => trim( $first_name . ' ' . $last_name ) ?: $user->user_login,
            'role'         => $role,
        ];

        if ( '' !== $password ) {
            $payload['user_pass'] = $password;
        }

        $updated = wp_update_user( $payload );

        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        update_user_meta( $user_id, 'jsci_prm_designation', $designation );
        delete_user_meta( $user_id, 'jsci_prm_user_inactive' );

        return rest_ensure_response( [
            'saved' => true,
            'user'  => $this->format_user( new \WP_User( $user_id ) ),
        ] );
    }

    public function deactivate_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = (int) $request->get_param( 'id' );
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return new WP_Error( 'jsci_user_not_found', __( 'User not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        if ( get_current_user_id() === $user_id ) {
            return new WP_Error( 'jsci_cannot_deactivate_self', __( 'You cannot deactivate your own user account.', 'jsci-prm' ), [ 'status' => 403 ] );
        }

        if ( in_array( 'administrator', (array) $user->roles, true ) ) {
            return new WP_Error( 'jsci_protected_user', __( 'Administrator users cannot be deactivated from Access Management.', 'jsci-prm' ), [ 'status' => 403 ] );
        }

        $wp_user = new \WP_User( $user_id );

        foreach ( (array) $wp_user->roles as $role ) {
            $wp_user->remove_role( $role );
        }

        Access_Manager::clear_user_access( $user_id );
        update_user_meta( $user_id, 'jsci_prm_user_inactive', '1' );

        return rest_ensure_response( [
            'deleted' => true,
            'user'    => $this->format_user( new \WP_User( $user_id ) ),
        ] );
    }

    public function get_user_access( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = (int) $request->get_param( 'id' );
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return new WP_Error( 'jsci_user_not_found', __( 'User not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        return rest_ensure_response( [
            'user_id'          => $user_id,
            'production_entry' => array_values( Access_Manager::get_user_production_entry_access( $user_id ) ),
            'order_management' => Access_Manager::get_user_order_management_access( $user_id ),
            'transaction_management' => Access_Manager::get_user_transaction_management_access( $user_id ),
            'Message_management' => [
                'access' => Access_Manager::user_can_manage_Messages( $user_id ),
            ],
        ] );
    }

    public function save_user_access( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $user_id = (int) $request->get_param( 'id' );
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return new WP_Error( 'jsci_user_not_found', __( 'User not found.', 'jsci-prm' ), [ 'status' => 404 ] );
        }

        $permissions = $request->get_param( 'production_entry' );
        $order_management = $request->get_param( 'order_management' );
        $transaction_management = $request->get_param( 'transaction_management' );
        $Message_management = $request->get_param( 'Message_management' );

        if ( ! is_array( $permissions ) ) {
            return new WP_Error( 'jsci_invalid_permissions', __( 'Production entry permissions are required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( null !== $order_management && ! is_array( $order_management ) ) {
            return new WP_Error( 'jsci_invalid_order_permissions', __( 'Order management permissions must be an object.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( null !== $transaction_management && ! is_array( $transaction_management ) ) {
            return new WP_Error( 'jsci_invalid_transaction_permissions', __( 'Transaction management permissions must be an object.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        if ( null !== $Message_management && ! is_array( $Message_management ) ) {
            return new WP_Error( 'jsci_invalid_Message_permissions', __( 'Message permissions must be an object.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $departments = $this->department_line_map();
        $normalized = [];

        foreach ( $permissions as $permission ) {
            $dept_id = (int) ( $permission['dept_id'] ?? 0 );

            if ( ! $dept_id || ! isset( $departments[ $dept_id ] ) ) {
                continue;
            }

            $dept_access = ! empty( $permission['dept_access'] );
            $line_access = array_values( array_unique( array_map( 'sanitize_text_field', is_array( $permission['line_access'] ?? null ) ? $permission['line_access'] : [] ) ) );
            $entry_types = array_values( array_unique( array_intersect(
                Access_Manager::production_entry_types(),
                array_map( 'sanitize_key', is_array( $permission['entry_types'] ?? null ) ? $permission['entry_types'] : [] )
            ) ) );
            $entry_form_types = array_values( array_filter(
                $entry_types,
                static fn( string $type ): bool => 'accept_transfers' !== $type
            ) );
            $history_access = ! empty( $permission['history_access'] );
            $department_history_access = ! empty( $permission['department_history_access'] );

            if ( ! $dept_access ) {
                continue;
            }

            $needs_line_access = ! empty( $departments[ $dept_id ] ) && in_array( 'produce', $entry_form_types, true );

            if ( $needs_line_access && [] === $line_access ) {
                return new WP_Error(
                    'jsci_missing_line_access',
                    sprintf(
                        /* translators: %d: department id */
                        __( 'At least one line or Collective option must be selected for department %d.', 'jsci-prm' ),
                        $dept_id
                    ),
                    [ 'status' => 422 ]
                );
            }

            if ( [] === $entry_types && ! $history_access && ! $department_history_access ) {
                return new WP_Error(
                    'jsci_missing_entry_type_access',
                    sprintf(
                        /* translators: %d: department id */
                        __( 'At least one entry type, History, or Department History must be selected for department %d.', 'jsci-prm' ),
                        $dept_id
                    ),
                    [ 'status' => 422 ]
                );
            }

            $allowed_lines = $departments[ $dept_id ];
            $line_access = array_values( array_filter(
                $line_access,
                static fn( string $line ): bool => 'Collective' === $line || in_array( $line, $allowed_lines, true )
            ) );

            if ( $needs_line_access && [] === $line_access ) {
                return new WP_Error(
                    'jsci_invalid_line_access',
                    sprintf(
                        __( 'Please select valid lines for department %d.', 'jsci-prm' ),
                        $dept_id
                    ),
                    [ 'status' => 422 ]
                );
            }

            $normalized[] = [
                'dept_id'                    => $dept_id,
                'dept_access'                => true,
                'line_access'                => $line_access,
                'entry_types'                => $entry_types,
                'history_access'             => $history_access,
                'department_history_access'  => $department_history_access,
            ];
        }

        $saved = Access_Manager::save_user_production_entry_access( $user_id, $normalized );

        if ( is_wp_error( $saved ) ) {
            return $saved;
        }

        Access_Manager::save_user_order_management_access(
            $user_id,
            is_array( $order_management ) ? $order_management : []
        );

        Access_Manager::save_user_transaction_management_access(
            $user_id,
            is_array( $transaction_management ) ? $transaction_management : []
        );

        Access_Manager::save_user_Message_access(
            $user_id,
            is_array( $Message_management ) && ! empty( $Message_management['access'] )
        );

        return rest_ensure_response( [
            'saved'            => true,
            'user_id'          => $user_id,
            'production_entry' => array_values( Access_Manager::get_user_production_entry_access( $user_id ) ),
            'order_management' => Access_Manager::get_user_order_management_access( $user_id ),
            'transaction_management' => Access_Manager::get_user_transaction_management_access( $user_id ),
            'Message_management' => [
                'access' => Access_Manager::user_can_manage_Messages( $user_id ),
            ],
        ] );
    }

    /**
     * Returns active lines keyed by department id.
     *
     * @return array<int,string[]>
     */
    private function department_line_map(): array {
        global $wpdb;

        $departments = $wpdb->get_results(
            "SELECT id FROM `" . Database::departments() . "` WHERE is_active = 1 ORDER BY workflow_order ASC, id ASC"
        ) ?: [];

        $rows = $wpdb->get_results(
            "SELECT department_id, line_number FROM `" . Database::department_lines() . "` WHERE is_active = 1 ORDER BY department_id ASC, sort_order ASC, id ASC"
        ) ?: [];

        $map = [];

        foreach ( $departments as $department ) {
            $map[ (int) $department->id ] = [];
        }

        foreach ( $rows as $row ) {
            $map[ (int) $row->department_id ][] = $row->line_number;
        }

        return $map;
    }

    /**
     * Sanitizes a role from the Access Management role dropdown.
     */
    private function sanitize_user_role( string $role ): string {
        $role = sanitize_key( $role );
        $allowed = array_keys( $this->available_user_roles() );

        return in_array( $role, $allowed, true ) ? $role : 'jsci_employee';
    }

    /**
     * Roles available to Access Management user forms.
     *
     * @return array<string,string>
     */
    private function available_user_roles(): array {
        return [
            'jsci_employee'    => __( 'JSCI Employee', 'jsci-prm' ),
            'jsci_admin'       => __( 'JSCI Admin', 'jsci-prm' ),
            'jsci_super_admin' => __( 'JSCI Super Admin', 'jsci-prm' ),
        ];
    }

    /**
     * Format a WP user for the Access Management UI.
     */
    private function format_user( \WP_User $user ): array {
        $caps = [];

        foreach ( Roles::all_caps() as $cap ) {
            $caps[ $cap ] = user_can( $user, $cap );
        }

        $wp_roles = array_values( (array) $user->roles );
        $user_type = empty( $wp_roles )
            ? __( 'No role assigned', 'jsci-prm' )
            : implode( ', ', $wp_roles );

        return [
            'id'          => (int) $user->ID,
            'username'    => $user->user_login,
            'firstName'   => get_user_meta( (int) $user->ID, 'first_name', true ),
            'lastName'    => get_user_meta( (int) $user->ID, 'last_name', true ),
            'designation' => get_user_meta( (int) $user->ID, 'jsci_prm_designation', true ),
            'name'        => $user->display_name,
            'email'       => $user->user_email,
            'userType'    => $user_type,
            'wpRoles'     => $wp_roles,
            'caps'        => $caps,
            'inactive'    => Roles::user_is_inactive( (int) $user->ID ),
        ];
    }
}
