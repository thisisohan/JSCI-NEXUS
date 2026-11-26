<?php
/**
 * Theme Nexus by Snivertech bootstrap.
 */

defined( 'ABSPATH' ) || exit;

const THEME_NEXUS_BY_SNIVERTECH_VERSION = '2.2.58';

add_action( 'after_setup_theme', 'theme_nexus_by_snivertech_setup' );
function theme_nexus_by_snivertech_setup(): void {
    add_theme_support( 'title-tag' );
    add_theme_support( 'custom-logo', [
        'height'      => 218,
        'width'       => 500,
        'flex-height' => true,
        'flex-width'  => true,
    ] );
    register_nav_menus( [
        'primary' => __( 'Primary App Menu', 'theme-nexus-by-snivertech' ),
    ] );
}

// add_action( 'wp_default_scripts', 'theme_nexus_by_snivertech_font_awesome_frontend_dependency_fix', 0 );
// function theme_nexus_by_snivertech_font_awesome_frontend_dependency_fix( WP_Scripts $scripts ): void {
//     if ( isset( $scripts->registered['font-awesome-official-admin'] ) ) {
//         return;
//     }

//     $scripts->add( 'font-awesome-official-admin', false, [], null );
// }

add_action( 'wp_enqueue_scripts', 'theme_nexus_by_snivertech_assets', 80 );
function theme_nexus_by_snivertech_assets(): void {
    wp_enqueue_style(
        'theme-nexus-by-snivertech',
        get_stylesheet_uri(),
        [],
        theme_nexus_by_snivertech_asset_version( 'style.css' )
    );

    wp_enqueue_style(
        'theme-nexus-by-snivertech-mobile',
        get_theme_file_uri( 'css_for_mobile_only.css' ),
        [ 'theme-nexus-by-snivertech' ],
        theme_nexus_by_snivertech_asset_version( 'css_for_mobile_only.css' ),
        '(max-width: 880px)'
    );

    wp_enqueue_script(
        'theme-nexus-by-snivertech',
        get_theme_file_uri( 'theme.js' ),
        [],
        theme_nexus_by_snivertech_asset_version( 'theme.js' ),
        true
    );

    wp_add_inline_style( 'theme-nexus-by-snivertech', theme_nexus_by_snivertech_custom_css() );
}

add_action( 'init', 'theme_nexus_by_snivertech_handle_logout' );
function theme_nexus_by_snivertech_handle_logout(): void {
    if ( empty( $_GET['app_nexus_by_sohan_logout'] ) ) {
        return;
    }

    if ( is_user_logged_in() ) {
        wp_logout();
    }

    wp_safe_redirect( home_url( '/' ) );
    exit;
}


function theme_nexus_by_snivertech_mobile_styles_footer(): void {
    if ( wp_is_mobile() ) {
        printf(
            '<link rel="stylesheet" id="theme-nexus-by-snivertech-mobile-footer-css" href="%s" type="text/css" media="all" />' . "\n",
            esc_url( add_query_arg(
                'ver',
                theme_nexus_by_snivertech_asset_version( 'css_for_mobile_only.css' ),
                get_theme_file_uri( 'css_for_mobile_only.css' )
            ) )
        );
    }
}
add_action( 'wp_footer', 'theme_nexus_by_snivertech_mobile_styles_footer', 1 );



function theme_nexus_by_snivertech_asset_version( string $relative_path ): string {
    $path = get_theme_file_path( ltrim( $relative_path, '/\\' ) );

    return file_exists( $path ) ? (string) filemtime( $path ) : THEME_NEXUS_BY_SNIVERTECH_VERSION;
}

function theme_nexus_by_snivertech_default_template_settings(): array {
    return [
        'menu_text_color'             => '#111827',
        'menu_hover_color'            => '#0f766e',
        'menu_active_underline_color' => '#01c4c6',
        'header_background_color'     => '#ffffff',
        'submenu_background_color'    => '#ffffff',
        'submenu_border_color'        => '#000000',
        'mobile_menu_background_color' => '#0f172a',
        'mobile_menu_font_color'       => '#ffffff',
        'mobile_menu_icon_color'       => '#ffffff',
        'body_background_color'       => '#f1f5f9',
        'main_content_background'     => '#ffffff',
        'page_head_start_color'       => '#0f766e',
        'page_head_middle_color'      => '#01c4c6',
        'page_head_end_color'         => '#38bdf8',
        'page_head_text_color'        => '#ffffff',
        'page_head_icon_background'   => '#ffffff',
        'page_head_icon_color'        => '#0f766e',
        'footer_background_color'     => '#ffffff',
        'footer_text_color'           => '#64748b',
        'footer_left_text'            => __( 'Production management system', 'theme-nexus-by-snivertech' ),
        'footer_right_text'           => sprintf(
            /* translators: %s: Current year. */
            __( '(c) %s Snivertech. All rights reserved.', 'theme-nexus-by-snivertech' ),
            (string) gmdate( 'Y' )
        ),
    ];
}

function theme_nexus_by_snivertech_template_setting( string $key ): string {
    $defaults = theme_nexus_by_snivertech_default_template_settings();
    $default  = $defaults[ $key ] ?? '';
    $value    = get_theme_mod( 'theme_nexus_' . $key, $default );

    return sanitize_hex_color( $value ) ?: $default;
}

function theme_nexus_by_snivertech_template_text_setting( string $key ): string {
    $defaults = theme_nexus_by_snivertech_default_template_settings();
    $default  = $defaults[ $key ] ?? '';
    $value    = get_theme_mod( 'theme_nexus_' . $key, $default );
    $value    = sanitize_textarea_field( (string) $value );

    return '' !== $value ? $value : $default;
}

function theme_nexus_by_snivertech_custom_css(): string {
    $settings = [
        '--theme-nexus-menu-text'             => theme_nexus_by_snivertech_template_setting( 'menu_text_color' ),
        '--theme-nexus-menu-hover'            => theme_nexus_by_snivertech_template_setting( 'menu_hover_color' ),
        '--theme-nexus-menu-active'           => theme_nexus_by_snivertech_template_setting( 'menu_active_underline_color' ),
        '--theme-nexus-header-bg'             => theme_nexus_by_snivertech_template_setting( 'header_background_color' ),
        '--theme-nexus-submenu-bg'            => theme_nexus_by_snivertech_template_setting( 'submenu_background_color' ),
        '--theme-nexus-submenu-border'        => theme_nexus_by_snivertech_template_setting( 'submenu_border_color' ),
        '--theme-nexus-mobile-menu-bg'        => theme_nexus_by_snivertech_template_setting( 'mobile_menu_background_color' ),
        '--theme-nexus-mobile-menu-text'      => theme_nexus_by_snivertech_template_setting( 'mobile_menu_font_color' ),
        '--theme-nexus-mobile-menu-icon'      => theme_nexus_by_snivertech_template_setting( 'mobile_menu_icon_color' ),
        '--theme-nexus-body-bg'               => theme_nexus_by_snivertech_template_setting( 'body_background_color' ),
        '--theme-nexus-main-content-bg'       => theme_nexus_by_snivertech_template_setting( 'main_content_background' ),
        '--theme-nexus-page-head-start'       => theme_nexus_by_snivertech_template_setting( 'page_head_start_color' ),
        '--theme-nexus-page-head-middle'      => theme_nexus_by_snivertech_template_setting( 'page_head_middle_color' ),
        '--theme-nexus-page-head-end'         => theme_nexus_by_snivertech_template_setting( 'page_head_end_color' ),
        '--theme-nexus-page-head-text'        => theme_nexus_by_snivertech_template_setting( 'page_head_text_color' ),
        '--theme-nexus-page-head-icon-bg'     => theme_nexus_by_snivertech_template_setting( 'page_head_icon_background' ),
        '--theme-nexus-page-head-icon-color'  => theme_nexus_by_snivertech_template_setting( 'page_head_icon_color' ),
        '--theme-nexus-footer-bg'             => theme_nexus_by_snivertech_template_setting( 'footer_background_color' ),
        '--theme-nexus-footer-text'           => theme_nexus_by_snivertech_template_setting( 'footer_text_color' ),
    ];

    $css = ':root{';
    foreach ( $settings as $property => $value ) {
        $css .= $property . ':' . $value . ';';
    }
    $css .= '}';

    return $css;
}

add_action( 'customize_register', 'theme_nexus_by_snivertech_customize_register' );
function theme_nexus_by_snivertech_customize_register( WP_Customize_Manager $wp_customize ): void {
    $wp_customize->add_section( 'theme_nexus_template_settings', [
        'title'       => __( 'App Template Settings', 'theme-nexus-by-snivertech' ),
        'description' => __( 'Change the visual style used by the app theme templates.', 'theme-nexus-by-snivertech' ),
        'priority'    => 35,
    ] );

    if ( $wp_customize->get_setting( 'custom_logo' ) ) {
        $wp_customize->add_control( new WP_Customize_Media_Control(
            $wp_customize,
            'theme_nexus_site_logo',
            [
                'label'       => __( 'Site logo', 'theme-nexus-by-snivertech' ),
                'description' => __( 'Uses the WordPress custom logo setting.', 'theme-nexus-by-snivertech' ),
                'section'     => 'theme_nexus_template_settings',
                'settings'    => 'custom_logo',
                'mime_type'   => 'image',
            ]
        ) );
    }

    $wp_customize->add_setting( 'theme_nexus_footer_logo_id', [
        'default'           => (int) get_theme_mod( 'custom_logo', 0 ),
        'sanitize_callback' => 'absint',
        'transport'         => 'refresh',
    ] );

    $wp_customize->add_control( new WP_Customize_Media_Control(
        $wp_customize,
        'theme_nexus_footer_logo_id',
        [
            'label'       => __( 'Footer logo', 'theme-nexus-by-snivertech' ),
            'description' => __( 'Choose a separate footer logo, or remove it to hide the footer logo.', 'theme-nexus-by-snivertech' ),
            'section'     => 'theme_nexus_template_settings',
            'mime_type'   => 'image',
        ]
    ) );

    $color_controls = [
        'menu_text_color'             => __( 'Menu text color', 'theme-nexus-by-snivertech' ),
        'menu_hover_color'            => __( 'Menu hover/current color', 'theme-nexus-by-snivertech' ),
        'menu_active_underline_color' => __( 'Menu active underline color', 'theme-nexus-by-snivertech' ),
        'header_background_color'     => __( 'Header background color', 'theme-nexus-by-snivertech' ),
        'submenu_background_color'    => __( 'Dropdown menu background color', 'theme-nexus-by-snivertech' ),
        'submenu_border_color'        => __( 'Dropdown top border color', 'theme-nexus-by-snivertech' ),
        'mobile_menu_background_color' => __( 'Mobile menu background color', 'theme-nexus-by-snivertech' ),
        'mobile_menu_font_color'       => __( 'Mobile menu font color', 'theme-nexus-by-snivertech' ),
        'mobile_menu_icon_color'       => __( 'Mobile menu icon color', 'theme-nexus-by-snivertech' ),
        'body_background_color'       => __( 'Page background color', 'theme-nexus-by-snivertech' ),
        'main_content_background'     => __( 'Main content area background color', 'theme-nexus-by-snivertech' ),
        'page_head_start_color'       => __( 'Subheader gradient start color', 'theme-nexus-by-snivertech' ),
        'page_head_middle_color'      => __( 'Subheader gradient middle color', 'theme-nexus-by-snivertech' ),
        'page_head_end_color'         => __( 'Subheader gradient end color', 'theme-nexus-by-snivertech' ),
        'page_head_text_color'        => __( 'Subheader text color', 'theme-nexus-by-snivertech' ),
        'page_head_icon_background'   => __( 'Subheader icon background color', 'theme-nexus-by-snivertech' ),
        'page_head_icon_color'        => __( 'Subheader icon color', 'theme-nexus-by-snivertech' ),
        'footer_background_color'     => __( 'Footer background color', 'theme-nexus-by-snivertech' ),
        'footer_text_color'           => __( 'Footer text color', 'theme-nexus-by-snivertech' ),
    ];

    $defaults = theme_nexus_by_snivertech_default_template_settings();

    foreach ( $color_controls as $key => $label ) {
        $setting_id = 'theme_nexus_' . $key;

        $wp_customize->add_setting( $setting_id, [
            'default'           => $defaults[ $key ],
            'sanitize_callback' => 'sanitize_hex_color',
            'transport'         => 'refresh',
        ] );

        $wp_customize->add_control( new WP_Customize_Color_Control(
            $wp_customize,
            $setting_id,
            [
                'label'   => $label,
                'section' => 'theme_nexus_template_settings',
            ]
        ) );
    }

    $text_controls = [
        'footer_left_text'  => __( 'Footer left text', 'theme-nexus-by-snivertech' ),
        'footer_right_text' => __( 'Footer right text', 'theme-nexus-by-snivertech' ),
    ];

    foreach ( $text_controls as $key => $label ) {
        $setting_id = 'theme_nexus_' . $key;

        $wp_customize->add_setting( $setting_id, [
            'default'           => $defaults[ $key ],
            'sanitize_callback' => 'sanitize_textarea_field',
            'transport'         => 'refresh',
        ] );

        $wp_customize->add_control( $setting_id, [
            'label'       => $label,
            'section'     => 'theme_nexus_template_settings',
            'type'        => 'textarea',
            'input_attrs' => [
                'rows' => 2,
            ],
        ] );
    }
}

add_action( 'admin_menu', 'theme_nexus_by_snivertech_admin_menu' );
function theme_nexus_by_snivertech_admin_menu(): void {
    add_theme_page(
        __( 'App Template Settings', 'theme-nexus-by-snivertech' ),
        __( 'App Template Settings', 'theme-nexus-by-snivertech' ),
        'edit_theme_options',
        'theme-nexus-template-settings',
        'theme_nexus_by_snivertech_render_settings_page'
    );
}

function theme_nexus_by_snivertech_render_settings_page(): void {
    if ( ! current_user_can( 'edit_theme_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to change these settings.', 'theme-nexus-by-snivertech' ) );
    }

    $customizer_url = add_query_arg(
        [
            'autofocus[section]' => 'theme_nexus_template_settings',
            'return'             => admin_url( 'themes.php?page=theme-nexus-template-settings' ),
        ],
        admin_url( 'customize.php' )
    );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'App Template Settings', 'theme-nexus-by-snivertech' ); ?></h1>
        <p><?php esc_html_e( 'Change the logo, menu colors, page background, main content background, subheader colors, footer colors, and footer text for the app templates.', 'theme-nexus-by-snivertech' ); ?></p>
        <p>
            <a class="button button-primary" href="<?php echo esc_url( $customizer_url ); ?>">
                <?php esc_html_e( 'Open Template Customizer', 'theme-nexus-by-snivertech' ); ?>
            </a>
        </p>
    </div>
    <?php
}

function theme_nexus_by_snivertech_get_page_url_by_template( string $template ): string {
    $pages = get_pages( [
        'number'     => 1,
        'meta_key'   => '_wp_page_template',
        'meta_value' => $template,
    ] );

    if ( ! empty( $pages[0] ) ) {
        return get_permalink( $pages[0]->ID );
    }

    return home_url( '/' );
}

function theme_nexus_by_snivertech_get_frontend_reports(): array {
    $registry_class = '\Snivertech\Sohan\App\Report_Registry';
    $access_class   = '\Snivertech\Sohan\App\Access_Manager';

    if ( ! class_exists( $registry_class ) || ! class_exists( $access_class ) || ! is_user_logged_in() ) {
        return [];
    }

    return $access_class::get_accessible_reports_for_user( get_current_user_id() );
}

function theme_nexus_by_snivertech_get_frontend_report( string $slug ): ?array {
    $registry_class = '\Snivertech\Sohan\App\Report_Registry';

    if ( ! class_exists( $registry_class ) ) {
        return null;
    }

    return $registry_class::get( sanitize_key( $slug ) );
}

function theme_nexus_by_snivertech_user_can_view_frontend_report( string $slug ): bool {
    $access_class = '\Snivertech\Sohan\App\Access_Manager';

    if ( ! class_exists( $access_class ) || ! is_user_logged_in() ) {
        return false;
    }

    return $access_class::user_can_access_report( get_current_user_id(), sanitize_key( $slug ) );
}

function theme_nexus_by_snivertech_user_can_use_app(): bool {
    $roles_class = '\Snivertech\Sohan\App\Roles';

    return is_user_logged_in()
        && class_exists( $roles_class )
        && $roles_class::user_can_use_app();
}

function theme_nexus_by_snivertech_current_user_has_role( string $role ): bool {
    if ( ! is_user_logged_in() ) {
        return false;
    }

    $user = wp_get_current_user();

    return in_array( $role, (array) $user->roles, true );
}

function theme_nexus_by_snivertech_logo_url(): string {
    $custom_logo_id = get_theme_mod( 'custom_logo' );

    if ( $custom_logo_id ) {
        $src = wp_get_attachment_image_url( (int) $custom_logo_id, 'full' );
        if ( $src ) {
            return $src;
        }
    }

    return content_url( 'uploads/2026/05/APP-Nexus-Logo-02.png' );
}

function theme_nexus_by_snivertech_footer_logo_url(): string {
    $footer_logo_id = get_theme_mod( 'theme_nexus_footer_logo_id', null );

    if ( null === $footer_logo_id || false === $footer_logo_id ) {
        $footer_logo_id = get_theme_mod( 'custom_logo' );
    }

    if ( $footer_logo_id ) {
        $src = wp_get_attachment_image_url( (int) $footer_logo_id, 'full' );
        if ( $src ) {
            return $src;
        }
    }

    return '';
}

function theme_nexus_by_snivertech_logout_url(): string {
    return wp_logout_url( home_url( '/' ) );
}

add_filter( 'wp_nav_menu_objects', 'theme_nexus_by_snivertech_fix_logout_menu_items' );
function theme_nexus_by_snivertech_fix_logout_menu_items( array $items ): array {
    foreach ( $items as $item ) {
        if ( theme_nexus_by_snivertech_is_logout_menu_item( $item ) ) {
            $item->url     = theme_nexus_by_snivertech_logout_url();
            $item->classes = array_unique( array_merge( (array) $item->classes, [ 'theme-nexus-logout-menu-item' ] ) );
        }
    }

    return $items;
}

add_filter( 'nav_menu_link_attributes', 'theme_nexus_by_snivertech_logout_menu_link_attributes', 10, 4 );
function theme_nexus_by_snivertech_logout_menu_link_attributes( array $atts, WP_Post $menu_item, stdClass $args, int $depth ): array {
    if ( theme_nexus_by_snivertech_is_logout_menu_item( $menu_item ) ) {
        $atts['data-theme-nexus-logout'] = 'true';
    }

    return $atts;
}

add_action( 'rest_api_init', 'theme_nexus_by_snivertech_register_order_yarn_requirements_routes' );
function theme_nexus_by_snivertech_register_order_yarn_requirements_routes(): void {
    register_rest_route( 'app-nexus-by_sohan/v1', '/order-yarn-types', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'theme_nexus_by_snivertech_get_order_yarn_types',
        'permission_callback' => 'theme_nexus_by_snivertech_can_view_order_yarn_requirements',
    ] );

    register_rest_route( 'app-nexus-by_sohan/v1', '/order-yarn-requirements/(?P<order_id>\d+)', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'theme_nexus_by_snivertech_get_order_yarn_requirements',
            'permission_callback' => 'theme_nexus_by_snivertech_can_view_order_yarn_requirements',
            'args'                => [
                'order_id' => [
                    'sanitize_callback' => 'absint',
                ],
            ],
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'theme_nexus_by_snivertech_save_order_yarn_requirements',
            'permission_callback' => 'theme_nexus_by_snivertech_can_edit_order_yarn_requirements',
            'args'                => [
                'order_id' => [
                    'sanitize_callback' => 'absint',
                ],
            ],
        ],
    ] );
}

function theme_nexus_by_snivertech_order_yarn_requirements_option_key( int $order_id ): string {
    return 'theme_nexus_order_yarn_requirements_' . max( 0, $order_id );
}

function theme_nexus_by_snivertech_can_view_order_yarn_requirements(): bool {
    $access_class = '\Snivertech\Sohan\App\Access_Manager';

    return is_user_logged_in()
        && class_exists( $access_class )
        && (
            $access_class::user_can_manage_order_action( get_current_user_id(), 'view_order' )
            || $access_class::user_can_manage_order_action( get_current_user_id(), 'view' )
        );
}

function theme_nexus_by_snivertech_can_edit_order_yarn_requirements(): bool {
    $access_class = '\Snivertech\Sohan\App\Access_Manager';

    return is_user_logged_in()
        && class_exists( $access_class )
        && (
            $access_class::user_can_manage_order_action( get_current_user_id(), 'create' )
            || $access_class::user_can_manage_order_action( get_current_user_id(), 'edit' )
        );
}

function theme_nexus_by_snivertech_get_yarn_type_names(): array {
    global $wpdb;

    $database_class = '\Snivertech\Sohan\App\Database';

    if ( ! class_exists( $database_class ) ) {
        return [];
    }

    $table = $database_class::yarn_types();
    $rows  = $wpdb->get_results( "SELECT id, name FROM `{$table}`" );
    $names = [];

    foreach ( $rows ?: [] as $row ) {
        $names[ (int) $row->id ] = sanitize_text_field( (string) $row->name );
    }

    return $names;
}

function theme_nexus_by_snivertech_get_order_yarn_types(): WP_REST_Response {
    global $wpdb;

    $database_class = '\Snivertech\Sohan\App\Database';

    if ( ! class_exists( $database_class ) ) {
        return rest_ensure_response( [] );
    }

    $table = $database_class::yarn_types();
    $rows  = $wpdb->get_results(
        "SELECT id, name, is_active, is_voided FROM `{$table}` ORDER BY name ASC"
    );

    return rest_ensure_response( array_map( static fn( $row ) => [
        'id'        => (int) $row->id,
        'name'      => sanitize_text_field( (string) $row->name ),
        'is_active' => (int) ( $row->is_active ?? 1 ),
        'is_voided' => (int) ( $row->is_voided ?? 0 ),
    ], $rows ?: [] ) );
}

function theme_nexus_by_snivertech_normalize_order_yarn_requirements( array $items ): array {
    $yarn_type_names = theme_nexus_by_snivertech_get_yarn_type_names();
    $normalized      = [];

    foreach ( $items as $item ) {
        $yarn_type_id = absint( $item['yarn_type_id'] ?? 0 );
        $required_qty = (float) ( $item['required_qty'] ?? 0 );

        if ( $yarn_type_id <= 0 || $required_qty <= 0 ) {
            continue;
        }

        if ( ! isset( $normalized[ $yarn_type_id ] ) ) {
            $normalized[ $yarn_type_id ] = [
                'yarn_type_id' => $yarn_type_id,
                'yarn_name'    => $yarn_type_names[ $yarn_type_id ] ?? sanitize_text_field( (string) ( $item['yarn_name'] ?? 'Yarn' ) ),
                'required_qty' => 0.0,
            ];
        }

        $normalized[ $yarn_type_id ]['required_qty'] += $required_qty;
    }

    return array_values( $normalized );
}

function theme_nexus_by_snivertech_get_order_yarn_requirements( WP_REST_Request $request ): WP_REST_Response {
    $order_id = absint( $request['order_id'] ?? 0 );
    $items    = get_option( theme_nexus_by_snivertech_order_yarn_requirements_option_key( $order_id ), [] );

    return rest_ensure_response( [
        'order_id' => $order_id,
        'items'    => theme_nexus_by_snivertech_normalize_order_yarn_requirements( is_array( $items ) ? $items : [] ),
    ] );
}

function theme_nexus_by_snivertech_save_order_yarn_requirements( WP_REST_Request $request ): WP_REST_Response {
    $order_id = absint( $request['order_id'] ?? 0 );
    $params   = $request->get_json_params();
    $items    = is_array( $params['items'] ?? null ) ? $params['items'] : [];
    $items    = theme_nexus_by_snivertech_normalize_order_yarn_requirements( $items );

    update_option( theme_nexus_by_snivertech_order_yarn_requirements_option_key( $order_id ), $items, false );

    return rest_ensure_response( [
        'order_id'           => $order_id,
        'items'              => $items,
        'required_yarn_qty'  => array_reduce( $items, static fn( $sum, $item ) => $sum + (float) ( $item['required_qty'] ?? 0 ), 0.0 ),
    ] );
}

function theme_nexus_by_snivertech_is_logout_menu_item( $item ): bool {
    $title = strtolower( trim( wp_strip_all_tags( html_entity_decode( (string) ( $item->title ?? '' ), ENT_QUOTES, get_bloginfo( 'charset' ) ) ) ) );
    $url   = (string) ( $item->url ?? '' );

    return 'logout' === $title
        || false !== stripos( $url, 'app_nexus_by_sohan_logout' )
        || false !== stripos( $url, 'action=logout' );
}

function theme_nexus_by_snivertech_app_menu_items(): array {
    return [
        [
            'label' => __( 'Dashboard', 'theme-nexus-by-snivertech' ),
            'url'   => home_url( '/' ),
        ],
        [
            'label' => __( 'Orders', 'theme-nexus-by-snivertech' ),
            'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'order entry.php' ),
        ],
        [
            'label'    => __( 'Report Entry', 'theme-nexus-by-snivertech' ),
            'url'      => '#',
            'children' => [
                [
                    'label' => __( 'Production Report Entry', 'theme-nexus-by-snivertech' ),
                    'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Production Entry.php' ),
                ],
                [
                    'label' => __( 'Technical Report Entry', 'theme-nexus-by-snivertech' ),
                    'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Technical report Entry.php' ),
                ],
                [
                    'label' => __( 'Accessories stock entry', 'theme-nexus-by-snivertech' ),
                    'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Accessories Stock Entry.php' ),
                ],
                [
                    'label' => __( 'Yarn stock entry', 'theme-nexus-by-snivertech' ),
                    'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Yarn Stock Entry.php' ),
                ],
            ],
        ],
        [
            'label' => __( 'Transactions', 'theme-nexus-by-snivertech' ),
            'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Transactions.php' ),
        ],
        [
            'label' => __( 'Notice', 'theme-nexus-by-snivertech' ),
            'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Message Page.php' ),
        ],
        [
            'label' => __( 'Logout', 'theme-nexus-by-snivertech' ),
            'url'   => theme_nexus_by_snivertech_logout_url(),
        ],
    ];
}

function theme_nexus_by_snivertech_logout_menu_items(): array {
    return [
        [
            'label' => __( 'Logout', 'theme-nexus-by-snivertech' ),
            'url'   => theme_nexus_by_snivertech_logout_url(),
        ],
    ];
}

function theme_nexus_by_snivertech_app_employee_menu_items(): array {
    return [
        [
            'label'    => __( 'Report Entry', 'theme-nexus-by-snivertech' ),
            'url'      => '#',
            'children' => [
                [
                    'label' => __( 'Production Report Entry', 'theme-nexus-by-snivertech' ),
                    'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Production Entry.php' ),
                ],
                [
                    'label' => __( 'Technical Report Entry', 'theme-nexus-by-snivertech' ),
                    'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Technical report Entry.php' ),
                ],
                [
                    'label' => __( 'Accessories stock entry', 'theme-nexus-by-snivertech' ),
                    'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Accessories Stock Entry.php' ),
                ],
                [
                    'label' => __( 'Yarn stock entry', 'theme-nexus-by-snivertech' ),
                    'url'   => theme_nexus_by_snivertech_get_page_url_by_template( 'Yarn Stock Entry.php' ),
                ],
            ],
        ],
        [
            'label' => __( 'Logout', 'theme-nexus-by-snivertech' ),
            'url'   => theme_nexus_by_snivertech_logout_url(),
        ],
    ];
}

function theme_nexus_by_snivertech_is_current_url( string $url ): bool {
    if ( '#' === $url ) {
        return false;
    }

    $current_path = trailingslashit( wp_parse_url( home_url( add_query_arg( [] ) ), PHP_URL_PATH ) ?: '/' );
    $target_path  = trailingslashit( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );

    return $current_path === $target_path;
}

function theme_nexus_by_snivertech_render_menu_items( array $items ): void {
    foreach ( $items as $item ) {
        $children = $item['children'] ?? [];
        $current  = theme_nexus_by_snivertech_is_current_url( (string) $item['url'] );
        foreach ( $children as $child ) {
            $current = $current || theme_nexus_by_snivertech_is_current_url( (string) $child['url'] );
        }
        $is_logout_item = 'Logout' === (string) $item['label']
            || false !== stripos( (string) $item['url'], 'action=logout' )
            || false !== stripos( (string) $item['url'], 'app_nexus_by_sohan_logout' );
        ?>
        <li class="theme-nexus-menu__item<?php echo $children ? ' theme-nexus-menu__item--has-children' : ''; ?><?php echo $current ? ' current-menu-item' : ''; ?>">
            <a href="<?php echo esc_url( (string) $item['url'] ); ?>"<?php echo $children ? ' aria-haspopup="true" aria-expanded="false"' : ''; ?><?php echo $is_logout_item ? ' data-theme-nexus-logout="true"' : ''; ?>>
                <?php echo esc_html( (string) $item['label'] ); ?>
            </a>
            <?php if ( $children ) : ?>
                <button class="theme-nexus-submenu-toggle" type="button" aria-label="<?php echo esc_attr( sprintf( __( 'Toggle %s menu', 'theme-nexus-by-snivertech' ), (string) $item['label'] ) ); ?>" aria-expanded="false"></button>
                <ul class="theme-nexus-submenu">
                    <?php foreach ( $children as $child ) : ?>
                        <li class="<?php echo theme_nexus_by_snivertech_is_current_url( (string) $child['url'] ) ? 'current-menu-item' : ''; ?>">
                            <a href="<?php echo esc_url( (string) $child['url'] ); ?>"><?php echo esc_html( (string) $child['label'] ); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
        <?php
    }
}

/**
 * Compatibility wrappers for migrated templates that were assigned before this
 * standalone theme existed.
 */
function app_nexus_get_page_url_by_template( $template ) {
    return theme_nexus_by_snivertech_get_page_url_by_template( (string) $template );
}

function app_nexus_get_frontend_reports() {
    return theme_nexus_by_snivertech_get_frontend_reports();
}

function app_nexus_get_frontend_report( $slug ) {
    return theme_nexus_by_snivertech_get_frontend_report( (string) $slug );
}

function app_nexus_user_can_view_frontend_report( $slug ) {
    return theme_nexus_by_snivertech_user_can_view_frontend_report( (string) $slug );
}

function app_nexus_by_sohan_get_page_url_by_template( $template ) {
    return app_nexus_get_page_url_by_template( $template );
}

function app_nexus_by_sohan_get_frontend_reports() {
    return app_nexus_get_frontend_reports();
}

function app_nexus_by_sohan_get_frontend_report( $slug ) {
    return app_nexus_get_frontend_report( $slug );
}

function app_nexus_by_sohan_user_can_view_frontend_report( $slug ) {
    return app_nexus_user_can_view_frontend_report( $slug );
}
