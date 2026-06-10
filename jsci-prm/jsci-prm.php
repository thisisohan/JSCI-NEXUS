<?php
/**
 * Plugin Name:       JSCI PRM – Production Report Management
 * Plugin URI:        https://snivertech.com/jsci-prm
 * Description:       Garments production tracking: orders, size-wise movements, department workflow, transactions, KPI and shipment readiness.
 * Version:           1.1.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Snivertech / Sohan
 * Author URI:        https://snivertech.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jsci-prm
 * Domain Path:       /languages
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

// ── Constants ────────────────────────────────────────────────────────────────

define( 'JSCI_PRM_VERSION',     '1.1.4' );
define( 'JSCI_PRM_DB_VERSION',  '1.11.0' );
define( 'JSCI_PRM_FILE',        __FILE__ );
define( 'JSCI_PRM_DIR',         plugin_dir_path( __FILE__ ) );
define( 'JSCI_PRM_URL',         plugin_dir_url( __FILE__ ) );
define( 'JSCI_PRM_BASENAME',    plugin_basename( __FILE__ ) );

// ── Autoloader ───────────────────────────────────────────────────────────────

spl_autoload_register( function ( string $class ): void {
    $prefix = 'Snivertech\\Sohan\\JSCIPRM\\';
    $base   = JSCI_PRM_DIR . 'includes/';

    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    $file     = $base . 'class-' . strtolower( str_replace( '\\', '-', $relative ) ) . '.php';
    $file     = str_replace( '_', '-', $file );

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Load the plugin core explicitly so activation and hooks do not depend on
// autoload timing on case-sensitive hosts.
require_once JSCI_PRM_DIR . 'includes/class-database.php';
require_once JSCI_PRM_DIR . 'includes/class-logger.php';
require_once JSCI_PRM_DIR . 'includes/class-roles.php';
require_once JSCI_PRM_DIR . 'includes/class-access-manager.php';
require_once JSCI_PRM_DIR . 'includes/class-sequence.php';
require_once JSCI_PRM_DIR . 'includes/class-rest-router.php';
require_once JSCI_PRM_DIR . 'includes/class-admin-menu.php';
require_once JSCI_PRM_DIR . 'includes/class-assets.php';
require_once JSCI_PRM_DIR . 'includes/class-installer.php';
require_once JSCI_PRM_DIR . 'includes/class-rest-orders-controller.php';
require_once JSCI_PRM_DIR . 'includes/class-rest-transactions-controller.php';
require_once JSCI_PRM_DIR . 'includes/class-rest-departments-controller.php';
require_once JSCI_PRM_DIR . 'includes/class-rest-reports-controller.php';
require_once JSCI_PRM_DIR . 'includes/class-rest-access-management-controller.php';
require_once JSCI_PRM_DIR . 'includes/class-rest-messages-controller.php';
require_once JSCI_PRM_DIR . 'includes/class-rest-external-organizations-controller.php';
require_once JSCI_PRM_DIR . 'includes/class-plugin.php';

// ── Bootstrap ────────────────────────────────────────────────────────────────

/**
 * Returns the single Plugin instance.
 */
function jsci_prm(): Plugin {
    return Plugin::instance();
}

// Kick off after all plugins have loaded so hooks are available.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\jsci_prm' );

// ── Activation / Deactivation / Uninstall hooks ──────────────────────────────

register_activation_hook(   __FILE__, [ Installer::class, 'activate'   ] );
register_deactivation_hook( __FILE__, [ Installer::class, 'deactivate' ] );
register_uninstall_hook(    __FILE__, [ Installer::class, 'uninstall'  ] );
