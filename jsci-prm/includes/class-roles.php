<?php
/**
 * Custom capabilities for JSCI PRM.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

/**
 * Class Roles
 *
 * Keeps PRM capability definitions in one place.
 */
final class Roles {

    private const ROLE_EMPLOYEE = 'jsci_employee';
    private const ROLE_ADMIN = 'jsci_admin';
    private const ROLE_SUPER_ADMIN = 'jsci_super_admin';

    /**
     * Flat list of PRM capability keys.
     */
    private const CAPS = [
        'jsci_view_dashboard',
        'jsci_create_transaction',
        'jsci_confirm_transaction',
        'jsci_edit_transaction',
        'jsci_void_transaction',
        'jsci_unlock_transaction',
        'jsci_view_reports',
        'jsci_export_reports',
        'jsci_manage_orders',
        'jsci_manage_users',
        'jsci_manage_departments',
        'jsci_view_audit_log',
        'jsci_manage_settings',
    ];

    /**
     * Capabilities needed by access-managed frontend entry work.
     */
    private const APP_CAPS = [
        'jsci_view_dashboard',
        'jsci_create_transaction',
        'jsci_confirm_transaction',
    ];

    /**
     * WordPress roles allowed to use the PRM app.
     */
    private const APP_ROLES = [
        self::ROLE_EMPLOYEE,
        self::ROLE_ADMIN,
        self::ROLE_SUPER_ADMIN,
    ];

    /**
     * Old role slugs from the removed detailed role system.
     */
    private const LEGACY_ROLES = [
        'jsci_operator',
        'jsci_supervisor',
        'jsci_manager',
    ];

    /**
     * Built-in WordPress roles not used by this app.
     */
    private const DISABLED_DEFAULT_ROLES = [
        'subscriber',
        'contributor',
        'author',
        'editor',
    ];

    /**
     * Called on plugins_loaded - keeps JSCI roles and capabilities in sync.
     */
    public static function init(): void {
        self::add_roles();

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::all_caps() as $cap ) {
                if ( ! $admin->has_cap( $cap ) ) {
                    $admin->add_cap( $cap, true );
                }
            }
        }

        add_action( 'admin_init', [ self::class, 'redirect_non_admin_roles_from_wp_admin' ], 1 );
        add_action( 'admin_menu', [ self::class, 'limit_super_admin_menu' ], 999 );
        add_action( 'admin_bar_menu', [ self::class, 'add_admin_bar_menu' ], 80 );
        add_action( 'admin_init', [ self::class, 'collapse_jsci_admin_menu' ], 20 );
        add_action( 'admin_head', [ self::class, 'hide_jsci_admin_toolbar' ] );
        add_action( 'wp_before_admin_bar_render', [ self::class, 'limit_super_admin_bar' ], 999 );
        add_filter( 'admin_body_class', [ self::class, 'add_jsci_admin_body_classes' ] );
        add_filter( 'show_admin_bar', [ self::class, 'should_show_admin_bar' ] );
    }

    /**
     * Creates the three broad JSCI roles used as app membership.
     */
    public static function add_roles(): void {
        $roles = [
            self::ROLE_EMPLOYEE => [
                'label' => __( 'JSCI Employee', 'jsci-prm' ),
                'caps'  => self::APP_CAPS,
            ],
            self::ROLE_ADMIN => [
                'label' => __( 'JSCI Admin', 'jsci-prm' ),
                'caps'  => self::all_caps(),
            ],
            self::ROLE_SUPER_ADMIN => [
                'label' => __( 'JSCI Super Admin', 'jsci-prm' ),
                'caps'  => self::all_caps(),
            ],
        ];

        foreach ( $roles as $role_key => $definition ) {
            $role = get_role( $role_key );

            if ( ! $role ) {
                add_role( $role_key, $definition['label'], [ 'read' => true ] );
                $role = get_role( $role_key );
            }

            if ( ! $role ) {
                continue;
            }

            $role->add_cap( 'read', true );

            foreach ( self::all_caps() as $cap ) {
                if ( in_array( $cap, $definition['caps'], true ) ) {
                    $role->add_cap( $cap, true );
                } else {
                    $role->remove_cap( $cap );
                }
            }
        }

        self::migrate_legacy_roles();
        self::disable_default_roles();

        foreach ( self::LEGACY_ROLES as $legacy_role ) {
            remove_role( $legacy_role );
        }
    }

    /**
     * Removes PRM roles and capabilities during full uninstall.
     */
    public static function remove_roles(): void {
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::all_caps() as $cap ) {
                $admin->remove_cap( $cap );
            }
        }

        foreach ( self::APP_ROLES as $role ) {
            remove_role( $role );
        }

        foreach ( self::LEGACY_ROLES as $role ) {
            remove_role( $role );
        }

        foreach ( self::DISABLED_DEFAULT_ROLES as $role ) {
            remove_role( $role );
        }
    }

    /**
     * Check whether the current (or given) user has a specific PRM capability.
     */
    public static function current_user_can( string $cap, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return user_can( $user_id, $cap );
    }

    /**
     * Returns whether a user belongs to WordPress Administrator or a JSCI app role.
     */
    public static function user_can_use_app( int $user_id = 0 ): bool {
        $user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        if ( self::user_is_inactive( (int) $user->ID ) ) {
            return false;
        }

        if ( in_array( 'administrator', (array) $user->roles, true ) ) {
            return true;
        }

        return (bool) array_intersect( self::APP_ROLES, (array) $user->roles );
    }

    /**
     * Returns whether the current user can access PRM wp-admin pages.
     */
    public static function current_user_can_view_plugin_admin(): bool {
        $user = wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        if ( self::user_is_inactive( (int) $user->ID ) ) {
            return false;
        }

        $roles = (array) $user->roles;

        return in_array( 'administrator', $roles, true )
            || in_array( self::ROLE_ADMIN, $roles, true )
            || in_array( self::ROLE_SUPER_ADMIN, $roles, true );
    }

    /**
     * Returns whether the current user can manage user access rules.
     */
    public static function current_user_can_manage_access_management(): bool {
        $user = wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        if ( self::user_is_inactive( (int) $user->ID ) ) {
            return false;
        }

        $roles = (array) $user->roles;

        return in_array( 'administrator', $roles, true )
            || in_array( self::ROLE_SUPER_ADMIN, $roles, true );
    }

    /**
     * Returns whether the current user is the JSCI Super Admin role.
     */
    public static function current_user_is_jsci_super_admin(): bool {
        $user = wp_get_current_user();

        return $user && $user->exists() && in_array( self::ROLE_SUPER_ADMIN, (array) $user->roles, true );
    }

    /**
     * Returns whether the current user is the JSCI Admin role without full Administrator privileges.
     */
    public static function current_user_is_jsci_admin_only(): bool {
        $user = wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        $roles = (array) $user->roles;

        return in_array( self::ROLE_ADMIN, $roles, true )
            && ! in_array( 'administrator', $roles, true );
    }

    /**
     * Returns whether the current user is a normal WordPress Administrator.
     */
    public static function current_user_is_wp_administrator(): bool {
        $user = wp_get_current_user();

        return $user && $user->exists() && in_array( 'administrator', (array) $user->roles, true );
    }

    /**
     * Returns whether a user has been deactivated from the PRM hub.
     */
    public static function user_is_inactive( int $user_id ): bool {
        return (bool) get_user_meta( $user_id, 'jsci_prm_user_inactive', true );
    }

    /**
     * Keep JSCI Employee out of wp-admin and send JSCI Admin/Super Admin to PRM.
     */
    public static function redirect_non_admin_roles_from_wp_admin(): void {
        if ( wp_doing_ajax() || ! is_admin() ) {
            return;
        }

        global $pagenow;

        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return;
        }

        $roles = (array) $user->roles;

        if (
            ( in_array( self::ROLE_ADMIN, $roles, true ) || in_array( self::ROLE_SUPER_ADMIN, $roles, true ) )
            && 'index.php' === $pagenow
        ) {
            wp_safe_redirect( admin_url( 'admin.php?page=jsci-prm' ) );
            exit;
        }

        if (
            in_array( 'administrator', $roles, true )
            || in_array( self::ROLE_ADMIN, $roles, true )
            || in_array( self::ROLE_SUPER_ADMIN, $roles, true )
            || ! in_array( self::ROLE_EMPLOYEE, $roles, true )
        ) {
            return;
        }

        wp_safe_redirect( home_url( '/' ) );
        exit;
    }

    /**
     * JSCI Admin/Super Admin may enter wp-admin, but only the JSCI PRM menu remains.
     */
    public static function limit_super_admin_menu(): void {
        if ( ! self::current_user_can_view_plugin_admin() || self::current_user_is_wp_administrator() ) {
            return;
        }

        global $menu;

        foreach ( (array) $menu as $item ) {
            $slug = $item[2] ?? '';

            if ( 'jsci-prm' !== $slug ) {
                remove_menu_page( $slug );
            }
        }
    }

    /**
     * Add JSCI PRM shortcuts to the admin bar.
     */
    public static function add_admin_bar_menu( \WP_Admin_Bar $admin_bar ): void {
        if ( ! self::current_user_is_wp_administrator() ) {
            return;
        }

        $admin_url = admin_url( 'admin.php?page=jsci-prm' );
        $orders_url = self::get_frontend_page_url_by_template( 'order entry.php' );
        $production_url = self::get_frontend_page_url_by_template( 'Production Entry.php' );
        $primary_url = self::current_user_can_view_plugin_admin()
            ? $admin_url
            : ( $production_url ?: ( $orders_url ?: home_url( '/' ) ) );

        $admin_bar->add_node( [
            'id'    => 'jsci-prm',
            'title' => __( 'JSCI PRM', 'jsci-prm' ),
            'href'  => $primary_url,
            'meta'  => [
                'class' => 'jsci-prm-admin-bar',
            ],
        ] );

        $admin_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        if ( is_admin() && 0 === strpos( $admin_page, 'jsci-prm' ) ) {
            $admin_bar->add_node( [
                'id'    => 'jsci-prm-main-site',
                'title' => __( 'Main Site', 'jsci-prm' ),
                'href'  => home_url( '/' ),
                'meta'  => [
                    'class' => 'jsci-prm-main-site-admin-bar',
                ],
            ] );
        }
    }

    /**
     * Hide non-PRM admin-bar links for restricted JSCI roles.
     */
    public static function limit_super_admin_bar(): void {
        if ( ! self::current_user_has_jsci_role_without_administrator() ) {
            return;
        }

        global $wp_admin_bar;

        foreach ( [ 'wp-logo', 'site-name', 'comments', 'new-content', 'updates', 'customize', 'search' ] as $node ) {
            $wp_admin_bar->remove_node( $node );
        }
    }

    /**
     * Keep the wp-admin left menu collapsed for JSCI Admin on every visit.
     */
    public static function collapse_jsci_admin_menu(): void {
        if ( ! self::current_user_is_jsci_admin_only() ) {
            return;
        }

        set_user_setting( 'mfold', 'f' );
    }

    /**
     * Add WordPress' folded class when JSCI Admin is inside wp-admin.
     */
    public static function add_jsci_admin_body_classes( string $classes ): string {
        if ( ! self::current_user_is_jsci_admin_only() ) {
            return $classes;
        }

        if ( false === strpos( " $classes ", ' folded ' ) ) {
            $classes .= ' folded';
        }

        return $classes;
    }

    /**
     * Hide the wp-admin toolbar for JSCI Admin users.
     */
    public static function hide_jsci_admin_toolbar(): void {
        if ( ! self::current_user_is_jsci_admin_only() ) {
            return;
        }

        echo '<style id="jsci-prm-hide-jsci-admin-toolbar">html.wp-toolbar{padding-top:0!important;}#wpadminbar{display:none!important;}</style>';
    }

    /**
     * Hide the admin bar from everyone except WordPress Administrators.
     */
    public static function should_show_admin_bar( bool $show ): bool {
        $user = wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            return $show;
        }

        return in_array( 'administrator', (array) $user->roles, true );
    }

    /**
     * Returns whether the user has a JSCI role but is not a full WP administrator.
     */
    private static function current_user_has_jsci_role_without_administrator(): bool {
        $user = wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        $roles = (array) $user->roles;

        return ! in_array( 'administrator', $roles, true )
            && (bool) array_intersect( self::APP_ROLES, $roles );
    }

    /**
     * Find the first published page that uses a given page template.
     */
    private static function get_frontend_page_url_by_template( string $template ): string {
        $pages = get_pages( [
            'number'      => 1,
            'post_status' => 'publish',
            'meta_key'    => '_wp_page_template',
            'meta_value'  => $template,
        ] );

        if ( empty( $pages[0]->ID ) ) {
            return '';
        }

        $url = get_permalink( (int) $pages[0]->ID );

        return $url ? $url : '';
    }

    /**
     * Move users from removed detailed roles into the broad Employee role.
     */
    private static function migrate_legacy_roles(): void {
        $users = get_users( [
            'role__in' => self::LEGACY_ROLES,
            'fields'   => [ 'ID' ],
        ] );

        foreach ( $users as $user_data ) {
            $user = new \WP_User( (int) $user_data->ID );
            $user->add_role( self::ROLE_EMPLOYEE );

            foreach ( self::LEGACY_ROLES as $legacy_role ) {
                $user->remove_role( $legacy_role );
            }
        }
    }

    /**
     * Remove unused built-in content roles so only Administrator and JSCI roles remain.
     */
    private static function disable_default_roles(): void {
        $default_role = get_option( 'default_role' );

        if ( in_array( $default_role, self::DISABLED_DEFAULT_ROLES, true ) ) {
            update_option( 'default_role', self::ROLE_EMPLOYEE );
        }

        foreach ( self::DISABLED_DEFAULT_ROLES as $role ) {
            remove_role( $role );
        }
    }

    /**
     * Returns a flat list of all custom capability keys.
     *
     * @return string[]
     */
    public static function all_caps(): array {
        return self::CAPS;
    }

    /**
     * Returns custom capability labels for access management screens.
     *
     * @return array<string,string>
     */
    public static function cap_labels(): array {
        return [
            'jsci_view_dashboard'      => __( 'View dashboard', 'jsci-prm' ),
            'jsci_create_transaction'  => __( 'Create transactions', 'jsci-prm' ),
            'jsci_confirm_transaction' => __( 'Confirm transactions', 'jsci-prm' ),
            'jsci_edit_transaction'    => __( 'Edit transactions', 'jsci-prm' ),
            'jsci_void_transaction'    => __( 'Void transactions', 'jsci-prm' ),
            'jsci_unlock_transaction'  => __( 'Unlock transactions', 'jsci-prm' ),
            'jsci_view_reports'        => __( 'View reports', 'jsci-prm' ),
            'jsci_export_reports'      => __( 'Export reports', 'jsci-prm' ),
            'jsci_manage_orders'       => __( 'Manage orders', 'jsci-prm' ),
            'jsci_manage_users'        => __( 'Manage users', 'jsci-prm' ),
            'jsci_manage_departments'  => __( 'Manage departments', 'jsci-prm' ),
            'jsci_view_audit_log'      => __( 'View audit log', 'jsci-prm' ),
            'jsci_manage_settings'     => __( 'Manage settings', 'jsci-prm' ),
        ];
    }
}
