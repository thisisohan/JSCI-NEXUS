<?php
/**
 * REST controller – Departments.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class REST_Departments_Controller {

    protected string $namespace = REST_Router::NAMESPACE;
    protected string $rest_base = 'departments';

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
                'permission_callback' => fn() => Roles::current_user_can_manage_access_management(),
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [ $this, 'update_item' ],
                'permission_callback' => fn() => Roles::current_user_can_manage_access_management(),
            ],
        ] );
    }

    public function get_items( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM `" . Database::departments() . "` ORDER BY workflow_order ASC"
        );

        $department_ids = array_map( static fn( $row ) => (int) $row->id, $rows ?: [] );
        $lines_by_department = $this->get_lines_map( $department_ids );

        $rows = array_map( function ( $row ) use ( $lines_by_department ) {
            $row->lines = $lines_by_department[ (int) $row->id ] ?? [];
            $raw_send_to_ids = $row->send_to_department_ids ?? null;
            $row->send_to_all_departments = null === $raw_send_to_ids || '' === $raw_send_to_ids || 'null' === $raw_send_to_ids;
            $row->send_to_department_ids = $this->sanitize_department_ids( $raw_send_to_ids );
            $raw_send_to_ids_kg = $row->send_to_department_ids_kg ?? null;
            $row->send_to_all_departments_kg = null === $raw_send_to_ids_kg || '' === $raw_send_to_ids_kg || 'null' === $raw_send_to_ids_kg;
            $row->send_to_department_ids_kg = $this->sanitize_department_ids( $raw_send_to_ids_kg );
            $row->send_outside_purposes = $this->sanitize_send_outside_purposes_value(
                $row->send_outside_purposes ?? null
            );
            $row->send_outside_purposes_kg = $this->sanitize_send_outside_purposes_value(
                $row->send_outside_purposes_kg ?? null
            );
            $row->reject_stages = $this->sanitize_reject_stages_value(
                $row->reject_stages ?? null
            );
            $row->reject_stages_kg = $this->sanitize_reject_stages_value(
                $row->reject_stages_kg ?? null
            );
            $row->compatibility_transaction_types = $this->sanitize_compatibility_transaction_types_value(
                $row->compatibility_transaction_types ?? null
            );
            $row->view_required_qty = isset( $row->view_required_qty ) ? (int) $row->view_required_qty : 1;
            $row->actions = [];

            if ( Roles::current_user_can_manage_access_management() ) {
                $row->actions['edit'] = [
                    'label'    => __( 'Edit Department', 'jsci-prm' ),
                    'method'   => 'PUT',
                    'endpoint' => rest_url(
                        REST_Router::NAMESPACE . '/departments/' . $row->id
                    ),
                ];
            }

            return $row;
        }, $rows ?: [] );

        return rest_ensure_response( $rows );
    }

    public function create_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $name      = sanitize_text_field( $request->get_param( 'name' ) );
        $prefix    = strtoupper( sanitize_key( $request->get_param( 'tx_prefix' ) ) );
        $order     = (int) $request->get_param( 'workflow_order' );
        $is_active = isset( $request['is_active'] ) ? (int) (bool) $request->get_param( 'is_active' ) : 1;
        $lines     = $this->sanitize_lines( $request->get_param( 'lines' ) ?: [] );
        $behavior  = $this->sanitize_behavior( $request );
        $compatibility = $this->sanitize_compatibility_transaction_types( $request );
        $view_required_qty = $this->sanitize_view_required_qty( $request );
        $slug      = $this->generate_unique_department_slug( $name );

        if ( ! $name || ! $prefix ) {
            return new WP_Error( 'jsci_missing_field', __( 'name and tx_prefix are required.', 'jsci-prm' ), [ 'status' => 422 ] );
        }

        $wpdb->insert( Database::departments(), [
            'slug'           => $slug,
            'name'           => $name,
            'tx_prefix'      => $prefix,
            'workflow_order' => $order,
            'is_active'      => $is_active,
            'allow_manual_entry'     => $behavior['allow_manual_entry'],
            'allow_production_entry' => $behavior['allow_production_entry'],
            'allow_fabric_to_piece_conversion_entry' => $behavior['allow_fabric_to_piece_conversion_entry'],
            'auto_produce_on_receive' => $behavior['auto_produce_on_receive'],
            'allow_reject_entry'     => $behavior['allow_reject_entry'],
            'reject_stages' => wp_json_encode( $behavior['reject_stages'] ),
            'allow_send_entry'       => $behavior['allow_send_entry'],
            'allow_send_outside_factory_entry' => $behavior['allow_send_outside_factory_entry'],
            'send_outside_purposes' => wp_json_encode( $behavior['send_outside_purposes'] ),
            'allow_manual_entry_kg'     => $behavior['allow_manual_entry_kg'],
            'allow_production_entry_kg' => $behavior['allow_production_entry_kg'],
            'auto_produce_on_receive_kg' => $behavior['auto_produce_on_receive_kg'],
            'allow_reject_entry_kg'     => $behavior['allow_reject_entry_kg'],
            'reject_stages_kg' => wp_json_encode( $behavior['reject_stages_kg'] ),
            'allow_send_entry_kg'       => $behavior['allow_send_entry_kg'],
            'allow_send_outside_factory_entry_kg' => $behavior['allow_send_outside_factory_entry_kg'],
            'send_outside_purposes_kg' => wp_json_encode( $behavior['send_outside_purposes_kg'] ),
            'view_required_qty' => $view_required_qty,
            'compatibility_transaction_types' => wp_json_encode( $compatibility ),
            'send_to_department_ids' => is_array( $behavior['send_to_department_ids'] )
                ? wp_json_encode( $behavior['send_to_department_ids'] )
                : null,
            'send_to_department_ids_kg' => is_array( $behavior['send_to_department_ids_kg'] )
                ? wp_json_encode( $behavior['send_to_department_ids_kg'] )
                : null,
        ], [ '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' ] );

        $id = (int) $wpdb->insert_id;
        $this->replace_department_lines( $id, $lines );
        Logger::log( Logger::ACTION_CREATE, Logger::TYPE_DEPARTMENT, $id, null, [
            'slug'      => $slug,
            'name'      => $name,
            'tx_prefix' => $prefix,
            'is_active' => $is_active,
            'behavior'  => $behavior,
            'view_required_qty' => $view_required_qty,
            'compatibility_transaction_types' => $compatibility,
            'lines'     => $lines,
        ] );

        return rest_ensure_response( [
            'id'        => $id,
            'slug'      => $slug,
            'name'      => $name,
            'tx_prefix' => $prefix,
            'is_active' => $is_active,
            'behavior'  => $behavior,
            'view_required_qty' => $view_required_qty,
            'compatibility_transaction_types' => $compatibility,
            'lines'     => $lines,
        ] );
    }

    public function update_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        global $wpdb;

        $id   = (int) $request->get_param( 'id' );
        $data = [];
        $name = $request->get_param( 'name' );
        $prefix = $request->get_param( 'tx_prefix' );
        $workflow_order = $request->get_param( 'workflow_order' );
        $lines = $request->get_param( 'lines' );
        $behavior = $this->sanitize_behavior( $request, false );
        $compatibility = $this->sanitize_compatibility_transaction_types( $request, false );
        $view_required_qty = $this->sanitize_view_required_qty( $request, false );

        if ( null !== $name ) {
            $sanitized_name = sanitize_text_field( $name );
            $data['name'] = $sanitized_name;
            $data['slug'] = $this->generate_unique_department_slug( $sanitized_name, $id );
        }

        if ( null !== $prefix ) {
            $data['tx_prefix'] = strtoupper( sanitize_key( $prefix ) );
        }

        if ( null !== $workflow_order ) {
            $data['workflow_order'] = (int) $workflow_order;
        }

        if ( isset( $request['is_active'] ) ) {
            $data['is_active'] = (int) (bool) $request['is_active'];
        }

        if ( is_array( $lines ) ) {
            $data['lines'] = $this->sanitize_lines( $lines );
        }

        foreach ( $behavior as $key => $value ) {
            $data[ $key ] = in_array( $key, [ 'send_to_department_ids', 'send_to_department_ids_kg' ], true )
                ? ( is_array( $value ) ? wp_json_encode( $value ) : null )
                : ( in_array( $key, [ 'send_outside_purposes', 'send_outside_purposes_kg', 'reject_stages', 'reject_stages_kg' ], true )
                    ? wp_json_encode( $value )
                    : $value );
        }

        if ( null !== $compatibility ) {
            $data['compatibility_transaction_types'] = wp_json_encode( $compatibility );
        }

        if ( null !== $view_required_qty ) {
            $data['view_required_qty'] = $view_required_qty;
        }

        if ( $data ) {
            $lines_to_save = $data['lines'] ?? null;
            unset( $data['lines'] );

            if ( $data ) {
                $wpdb->update( Database::departments(), $data, [ 'id' => $id ] );
            }

            if ( is_array( $lines_to_save ) ) {
                $this->replace_department_lines( $id, $lines_to_save );
                $data['lines'] = $lines_to_save;
            }

            Logger::log( Logger::ACTION_UPDATE, Logger::TYPE_DEPARTMENT, $id, null, $data );
        }

        return rest_ensure_response( [ 'updated' => true, 'id' => $id ] );
    }

    private function sanitize_lines( array $lines ): array {
        $sanitized = [];

        foreach ( $lines as $index => $line ) {
            if ( is_array( $line ) ) {
                $line_number = sanitize_text_field( $line['line_number'] ?? '' );
                $sort_order = isset( $line['sort_order'] ) ? (int) $line['sort_order'] : (int) $index;
                $is_active = array_key_exists( 'is_active', $line ) ? (int) (bool) $line['is_active'] : 1;
            } else {
                $line_number = sanitize_text_field( $line );
                $sort_order = (int) $index;
                $is_active = 1;
            }

            if ( '' === $line_number ) {
                continue;
            }

            $sanitized[] = [
                'line_number' => $line_number,
                'sort_order'  => $sort_order,
                'is_active'   => $is_active,
            ];
        }

        return $sanitized;
    }

    private function generate_unique_department_slug( string $name, int $exclude_id = 0 ): string {
        global $wpdb;

        $base = sanitize_title( $name );

        if ( '' === $base ) {
            $base = 'department';
        }

        $slug = $base;
        $suffix = 2;

        while ( true ) {
            if ( $exclude_id ) {
                $query = $wpdb->prepare(
                    "SELECT id FROM `" . Database::departments() . "` WHERE slug = %s AND id <> %d LIMIT 1",
                    $slug,
                    $exclude_id
                );
            } else {
                $query = $wpdb->prepare(
                    "SELECT id FROM `" . Database::departments() . "` WHERE slug = %s LIMIT 1",
                    $slug
                );
            }
            $existing_id = (int) $wpdb->get_var( $query );

            if ( ! $existing_id ) {
                return $slug;
            }

            $slug = $base . '-' . $suffix++;
        }
    }

    private function sanitize_behavior( WP_REST_Request $request, bool $with_defaults = true ): array {
        $fields = [
            'allow_manual_entry'     => 1,
            'allow_production_entry' => 1,
            'allow_fabric_to_piece_conversion_entry' => 0,
            'auto_produce_on_receive' => 0,
            'allow_reject_entry'     => 1,
            'allow_send_entry'       => 1,
            'allow_send_outside_factory_entry' => 1,
            'allow_manual_entry_kg'     => 0,
            'allow_production_entry_kg' => 0,
            'auto_produce_on_receive_kg' => 0,
            'allow_reject_entry_kg'     => 0,
            'allow_send_entry_kg'       => 0,
            'allow_send_outside_factory_entry_kg' => 0,
        ];
        $behavior = [];

        foreach ( $fields as $field => $default ) {
            if ( $request->has_param( $field ) ) {
                $behavior[ $field ] = (int) (bool) $request->get_param( $field );
            } elseif ( $with_defaults ) {
                $behavior[ $field ] = $default;
            }
        }

        if ( $request->has_param( 'send_to_department_ids' ) ) {
            $send_to_department_ids = $request->get_param( 'send_to_department_ids' );
            $behavior['send_to_department_ids'] = null === $send_to_department_ids
                ? null
                : $this->sanitize_department_ids( $send_to_department_ids );
        } elseif ( $with_defaults ) {
            $behavior['send_to_department_ids'] = null;
        }

        if ( $request->has_param( 'send_to_department_ids_kg' ) ) {
            $send_to_department_ids_kg = $request->get_param( 'send_to_department_ids_kg' );
            $behavior['send_to_department_ids_kg'] = null === $send_to_department_ids_kg
                ? null
                : $this->sanitize_department_ids( $send_to_department_ids_kg );
        } elseif ( $with_defaults ) {
            $behavior['send_to_department_ids_kg'] = null;
        }

        if ( $request->has_param( 'send_outside_purposes' ) ) {
            $behavior['send_outside_purposes'] = $this->sanitize_send_outside_purposes_value(
                $request->get_param( 'send_outside_purposes' )
            );
        } elseif ( $with_defaults ) {
            $behavior['send_outside_purposes'] = $this->send_outside_purpose_keys();
        }

        if ( $request->has_param( 'send_outside_purposes_kg' ) ) {
            $behavior['send_outside_purposes_kg'] = $this->sanitize_send_outside_purposes_value(
                $request->get_param( 'send_outside_purposes_kg' )
            );
        } elseif ( $with_defaults ) {
            $behavior['send_outside_purposes_kg'] = $this->send_outside_purpose_keys();
        }

        if ( $request->has_param( 'reject_stages' ) ) {
            $behavior['reject_stages'] = $this->sanitize_reject_stages_value(
                $request->get_param( 'reject_stages' )
            );
        } elseif ( $with_defaults ) {
            $behavior['reject_stages'] = $this->reject_stage_keys();
        }

        if ( $request->has_param( 'reject_stages_kg' ) ) {
            $behavior['reject_stages_kg'] = $this->sanitize_reject_stages_value(
                $request->get_param( 'reject_stages_kg' )
            );
        } elseif ( $with_defaults ) {
            $behavior['reject_stages_kg'] = $this->reject_stage_keys();
        }

        return $behavior;
    }

    private function send_outside_purpose_keys(): array {
        return [ 'embroidery', 'dyeing', 'shipment' ];
    }

    private function reject_stage_keys(): array {
        return [ 'before_production', 'after_production' ];
    }

    private function sanitize_send_outside_purposes_value( mixed $value ): array {
        $purposes = $this->send_outside_purpose_keys();

        if ( null === $value || '' === $value || 'null' === $value ) {
            return $purposes;
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $value = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $value ) ) {
            return [];
        }

        $value = array_map( 'sanitize_key', $value );

        return array_values( array_intersect( $purposes, $value ) );
    }

    private function sanitize_reject_stages_value( mixed $value ): array {
        $stages = $this->reject_stage_keys();

        if ( null === $value || '' === $value || 'null' === $value ) {
            return $stages;
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $value = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $value ) ) {
            return [];
        }

        $value = array_map( 'sanitize_key', $value );

        return array_values( array_intersect( $stages, $value ) );
    }

    private function sanitize_compatibility_transaction_types( WP_REST_Request $request, bool $with_defaults = true ): ?array {
        $types = [ 'receive', 'produce', 'send', 'send_outside_factory', 'reject' ];

        if ( ! $request->has_param( 'compatibility_transaction_types' ) ) {
            return $with_defaults ? $types : null;
        }

        $requested = $request->get_param( 'compatibility_transaction_types' );

        if ( ! is_array( $requested ) ) {
            return [];
        }

        $requested = array_map( 'sanitize_key', $requested );

        return array_values( array_intersect( $types, $requested ) );
    }

    private function sanitize_compatibility_transaction_types_value( mixed $value ): array {
        $types = [ 'receive', 'produce', 'send', 'send_outside_factory', 'reject' ];

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $value = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $value ) ) {
            return $types;
        }

        $value = array_map( 'sanitize_key', $value );

        return array_values( array_intersect( $types, $value ) );
    }

    private function sanitize_view_required_qty( WP_REST_Request $request, bool $with_defaults = true ): ?int {
        if ( ! $request->has_param( 'view_required_qty' ) ) {
            return $with_defaults ? 1 : null;
        }

        return (int) (bool) $request->get_param( 'view_required_qty' );
    }

    private function sanitize_department_ids( mixed $value ): array {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            $value = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $value ) ) {
            return [];
        }

        $ids = array_map( 'absint', $value );
        $ids = array_filter( $ids );

        return array_values( array_unique( $ids ) );
    }

    private function get_lines_map( array $department_ids ): array {
        global $wpdb;

        if ( [] === $department_ids ) {
            return [];
        }

        $placeholders = implode( ', ', array_fill( 0, count( $department_ids ), '%d' ) );
        $query = $wpdb->prepare(
            "SELECT * FROM `" . Database::department_lines() . "` WHERE department_id IN ($placeholders) ORDER BY sort_order ASC, id ASC",
            ...$department_ids
        );
        $rows = $wpdb->get_results( $query ) ?: [];
        $mapped = [];

        foreach ( $rows as $row ) {
            $mapped[ (int) $row->department_id ][] = $row;
        }

        return $mapped;
    }

    private function replace_department_lines( int $department_id, array $lines ): void {
        global $wpdb;

        $wpdb->delete( Database::department_lines(), [ 'department_id' => $department_id ], [ '%d' ] );

        foreach ( $lines as $line ) {
            $wpdb->insert(
                Database::department_lines(),
                [
                    'department_id' => $department_id,
                    'line_number'   => $line['line_number'],
                    'sort_order'    => (int) $line['sort_order'],
                    'is_active'     => (int) $line['is_active'],
                ],
                [ '%d', '%s', '%d', '%d' ]
            );
        }
    }
}
