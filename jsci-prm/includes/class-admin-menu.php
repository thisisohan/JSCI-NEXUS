<?php
/**
 * WordPress admin menu for JSCI PRM.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Menu
 */
final class Admin_Menu {

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menus' ] );
    }

    public static function register_menus(): void {

        add_menu_page(
            __( 'JSCI PRM', 'jsci-prm' ),
            __( 'JSCI PRM', 'jsci-prm' ),
            'jsci_view_dashboard',
            'jsci-prm',
            [ self::class, 'render_dashboard' ],
            'dashicons-clipboard',
            30
        );

        add_submenu_page(
            'jsci-prm',
            __( 'Dashboard', 'jsci-prm' ),
            __( 'Dashboard', 'jsci-prm' ),
            'jsci_view_dashboard',
            'jsci-prm',
            [ self::class, 'render_dashboard' ]
        );

        add_submenu_page(
            'jsci-prm',
            __( 'Orders', 'jsci-prm' ),
            __( 'Orders', 'jsci-prm' ),
            'jsci_manage_orders',
            'jsci-prm-orders',
            [ self::class, 'render_orders' ]
        );

        add_submenu_page(
            'jsci-prm',
            __( 'Transactions', 'jsci-prm' ),
            __( 'Transactions', 'jsci-prm' ),
            'jsci_view_dashboard',
            'jsci-prm-transactions',
            [ self::class, 'render_transactions' ]
        );

        if ( Roles::current_user_can_manage_access_management() ) {
            add_submenu_page(
                'jsci-prm',
                __( 'Departments', 'jsci-prm' ),
                __( 'Departments', 'jsci-prm' ),
                'jsci_manage_users',
                'jsci-prm-departments',
                [ self::class, 'render_departments' ]
            );

            add_submenu_page(
                'jsci-prm',
                __( 'External organizations', 'jsci-prm' ),
                __( 'External organizations', 'jsci-prm' ),
                'jsci_manage_users',
                'jsci-prm-external-organizations',
                [ self::class, 'render_external_organizations' ]
            );
        }

        add_submenu_page(
            'jsci-prm',
            __( 'Reports', 'jsci-prm' ),
            __( 'Reports', 'jsci-prm' ),
            'jsci_view_reports',
            'jsci-prm-reports',
            [ self::class, 'render_reports' ]
        );

        if ( Roles::current_user_can_manage_access_management() ) {
            add_submenu_page(
                'jsci-prm',
                __( 'Access Management', 'jsci-prm' ),
                __( 'Access Management', 'jsci-prm' ),
                'jsci_manage_users',
                'jsci-prm-access-management',
                [ self::class, 'render_access_management' ]
            );
        }

        add_submenu_page(
            'jsci-prm',
            __( 'Audit Log', 'jsci-prm' ),
            __( 'Audit Log', 'jsci-prm' ),
            'jsci_view_audit_log',
            'jsci-prm-audit',
            [ self::class, 'render_audit' ]
        );

        if ( Roles::current_user_is_wp_administrator() ) {
            add_submenu_page(
                'jsci-prm',
                __( 'Settings', 'jsci-prm' ),
                __( 'Settings', 'jsci-prm' ),
                'jsci_manage_settings',
                'jsci-prm-settings',
                [ self::class, 'render_settings' ]
            );
        }
    }

    // ── Page renderers ────────────────────────────────────────────────────────
    // Each renders a minimal shell; the real UI is driven by the React/JS app.

    public static function render_dashboard(): void    { self::render_app_shell( 'dashboard' );    }
    public static function render_orders(): void       { self::render_app_shell( 'orders' );       }
    public static function render_transactions(): void { self::render_app_shell( 'transactions' ); }
    public static function render_departments(): void {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            wp_die(
                esc_html__( 'You do not have permission to access Departments.', 'jsci-prm' ),
                esc_html__( 'Access denied', 'jsci-prm' ),
                [ 'response' => 403 ]
            );
        }

        self::render_app_shell( 'departments' );
    }
    public static function render_reports(): void      { self::render_app_shell( 'reports' );      }
    public static function render_external_organizations(): void {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            wp_die(
                esc_html__( 'You do not have permission to access External organizations.', 'jsci-prm' ),
                esc_html__( 'Access denied', 'jsci-prm' ),
                [ 'response' => 403 ]
            );
        }

        self::render_app_shell( 'external-organizations' );
    }
    public static function render_access_management(): void {
        if ( ! Roles::current_user_can_manage_access_management() ) {
            wp_die(
                esc_html__( 'You do not have permission to access Access Management.', 'jsci-prm' ),
                esc_html__( 'Access denied', 'jsci-prm' ),
                [ 'response' => 403 ]
            );
        }

        self::render_app_shell( 'access-management' );
    }
    public static function render_audit(): void        { self::render_app_shell( 'audit' );        }
    public static function render_settings(): void {
        if ( ! Roles::current_user_is_wp_administrator() ) {
            wp_die(
                esc_html__( 'You do not have permission to access Settings.', 'jsci-prm' ),
                esc_html__( 'Access denied', 'jsci-prm' ),
                [ 'response' => 403 ]
            );
        }

        self::render_app_shell( 'settings' );
    }

    private static function render_app_shell( string $page ): void {
        echo '<div id="jsci-prm-app" data-page="' . esc_attr( $page ) . '"></div>';
    }
}
