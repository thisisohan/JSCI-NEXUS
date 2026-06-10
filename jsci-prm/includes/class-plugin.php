<?php
/**
 * Core plugin bootstrap.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 *
 * Singleton that wires up every sub-system on the correct hook.
 */
final class Plugin {

    /** @var Plugin|null */
    private static ?Plugin $instance = null;

    private function __construct() {
        $this->load_textdomain();
        $this->init_components();
    }

    /**
     * Return (and lazily create) the singleton.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function load_textdomain(): void {
        load_plugin_textdomain(
            'jsci-prm',
            false,
            dirname( JSCI_PRM_BASENAME ) . '/languages'
        );
    }

    private function init_components(): void {
        // Database upgrade check on every load (no-op when version matches).
        Database::maybe_upgrade();
        Database::ensure_runtime_schema();

        // Register custom capabilities and flush rewrite rules if needed.
        Roles::init();

        // REST API routes.
        add_action( 'rest_api_init', [ REST_Router::class, 'register_routes' ] );

        // Admin menus (only in wp-admin).
        if ( is_admin() ) {
            Admin_Menu::init();
        }

        // Enqueue front-end assets for PRM pages.
        add_action( 'wp_enqueue_scripts',    [ Assets::class, 'enqueue_frontend' ] );
        add_action( 'wp_head',               [ Assets::class, 'print_frontend_Message_styles' ] );
        add_action( 'wp_footer',             [ Assets::class, 'print_frontend_Message_script' ], 20 );
        add_action( 'admin_enqueue_scripts', [ Assets::class, 'enqueue_admin'    ] );
    }
}
