<?php
/**
 * Plugin Name:       App Nexus
 * Plugin URI:        https://snivertech.com/app-nexus
 * Description:       JS Cotton International production tracking software: orders, size-wise movements, department workflow, transactions, KPI and shipment readiness.
 * Version:           2.2.58
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Snivertech / Sohan
 * Author URI:        https://snivertech.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       app-nexus-by_sohan
 * Domain Path:       /languages
 *
 * @package Snivertech\Sohan\App
 */

namespace Snivertech\Sohan\App;

defined( 'ABSPATH' ) || exit;

// ── Constants ────────────────────────────────────────────────────────────────

define( 'APP_NEXUS_VERSION',     '2.2.58' );
define( 'APP_NEXUS_DB_VERSION',  '1.24.0' );
define( 'APP_NEXUS_FILE',        __FILE__ );
define( 'APP_NEXUS_DIR',         plugin_dir_path( __FILE__ ) );
define( 'APP_NEXUS_URL',         plugin_dir_url( __FILE__ ) );
define( 'APP_NEXUS_BASENAME',    plugin_basename( __FILE__ ) );

defined( 'app_nexus_by_sohan_VERSION' ) || define( 'app_nexus_by_sohan_VERSION', APP_NEXUS_VERSION );
defined( 'app_nexus_by_sohan_DB_VERSION' ) || define( 'app_nexus_by_sohan_DB_VERSION', APP_NEXUS_DB_VERSION );
defined( 'app_nexus_by_sohan_FILE' ) || define( 'app_nexus_by_sohan_FILE', APP_NEXUS_FILE );
defined( 'app_nexus_by_sohan_DIR' ) || define( 'app_nexus_by_sohan_DIR', APP_NEXUS_DIR );
defined( 'app_nexus_by_sohan_URL' ) || define( 'app_nexus_by_sohan_URL', APP_NEXUS_URL );
defined( 'app_nexus_by_sohan_BASENAME' ) || define( 'app_nexus_by_sohan_BASENAME', APP_NEXUS_BASENAME );

// ── Autoloader ───────────────────────────────────────────────────────────────

spl_autoload_register( function ( string $class ): void {
    $prefix = 'Snivertech\\Sohan\\App\\';
    $base   = APP_NEXUS_DIR . 'includes/';

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
require_once APP_NEXUS_DIR . 'includes/class-date-time.php';
require_once APP_NEXUS_DIR . 'includes/class-database.php';
require_once APP_NEXUS_DIR . 'includes/class-logger.php';
require_once APP_NEXUS_DIR . 'includes/class-roles.php';
require_once APP_NEXUS_DIR . 'includes/class-access-manager.php';
require_once APP_NEXUS_DIR . 'includes/class-production-profile.php';
require_once APP_NEXUS_DIR . 'includes/class-production-relay.php';
require_once APP_NEXUS_DIR . 'includes/class-update-manager.php';
require_once APP_NEXUS_DIR . 'includes/class-settings.php';
require_once APP_NEXUS_DIR . 'includes/class-email-notifications.php';
require_once APP_NEXUS_DIR . 'includes/class-accessory-negative-stock-email-notifications.php';
require_once APP_NEXUS_DIR . 'includes/class-audit-email-notifications.php';
require_once APP_NEXUS_DIR . 'includes/class-department-deadline-email-notifications.php';
require_once APP_NEXUS_DIR . 'includes/class-department-target-status.php';
require_once APP_NEXUS_DIR . 'includes/class-report-registry.php';
require_once APP_NEXUS_DIR . 'includes/class-report-page.php';
require_once APP_NEXUS_DIR . 'includes/class-sequence.php';
require_once APP_NEXUS_DIR . 'includes/class-stock-ledger.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-router.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-runtime-profile-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-settings-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-admin-menu.php';
require_once APP_NEXUS_DIR . 'includes/class-assets.php';
require_once APP_NEXUS_DIR . 'includes/class-login-gate.php';
require_once APP_NEXUS_DIR . 'includes/class-installer.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-orders-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-transactions-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-departments-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-audit-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-frontend-reports-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-access-management-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-messages-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-external-organizations-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-outside-purposes-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-buyers-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-email-recipients-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-rest-yarn-controller.php';
require_once APP_NEXUS_DIR . 'includes/class-plugin.php';

function app_register_legacy_namespace_aliases(): void {
    $classes = [
        'Access_Manager',
        'Accessory_Negative_Stock_Email_Notifications',
        'Admin_Menu',
        'Assets',
        'Audit_Email_Notifications',
        'Production_Profile',
        'Production_Relay',
        'Update_Manager',
        'Database',
        'Date_Time',
        'Department_Deadline_Email_Notifications',
        'Department_Target_Status',
        'Email_Notifications',
        'Installer',
        'Logger',
        'Login_Gate',
        'Plugin',
        'Report_Page',
        'Report_Registry',
        'REST_Access_Management_Controller',
        'REST_Audit_Controller',
        'REST_Runtime_Profile_Controller',
        'REST_Buyers_Controller',
        'REST_Cache_Controller',
        'REST_Departments_Controller',
        'REST_Email_Recipients_Controller',
        'REST_External_Organizations_Controller',
        'REST_Frontend_Reports_Controller',
        'REST_Messages_Controller',
        'REST_Orders_Controller',
        'REST_Outside_Purposes_Controller',
        'REST_Router',
        'REST_Settings_Controller',
        'REST_Transactions_Controller',
        'REST_Yarn_Controller',
        'Roles',
        'Sequence',
        'Settings',
        'Stock_Ledger',
    ];

    foreach ( $classes as $class ) {
        $current = __NAMESPACE__ . '\\' . $class;
        $legacy  = 'Snivertech\\Sohan\\APPNEXUS\\' . $class;

        if ( class_exists( $current, false ) && ! class_exists( $legacy, false ) ) {
            class_alias( $current, $legacy );
        }
    }
}

app_register_legacy_namespace_aliases();

// ── Bootstrap ────────────────────────────────────────────────────────────────

/**
 * Returns the single Plugin instance.
 */
function app_nexus(): Plugin {
    return Plugin::instance();
}

/**
 * Sends an HTML notification email to every active App Nexus receiver.
 *
 * @param string $subject Email subject.
 * @param string $html_message HTML email body.
 * @param array  $args Optional wp_mail overrides: headers, attachments, recipients.
 */
function app_nexus_send_email_notification( string $subject, string $html_message, array $args = [] ): array {
    return Email_Notifications::send( $subject, $html_message, $args );
}

function app_nexus_by_sohan(): Plugin {
    return app_nexus();
}

function app_nexus_by_sohan_send_email_notification( string $subject, string $html_message, array $args = [] ): array {
    return app_nexus_send_email_notification( $subject, $html_message, $args );
}

function app_previous_prefix(): string {
    return chr( 106 ) . chr( 115 ) . chr( 99 ) . chr( 105 );
}

function app_previous_key( string $key ): string {
    if ( 0 === strpos( $key, 'app' ) ) {
        return app_previous_prefix() . substr( $key, 3 );
    }

    return $key;
}

function app_migrate_previous_storage(): void {
    global $wpdb;

    if ( ! $wpdb instanceof \wpdb ) {
        return;
    }

    $previous = app_previous_prefix();

    app_migrate_previous_tables( $previous );
    app_migrate_previous_option_keys( $previous );
    app_migrate_previous_user_meta_keys( $previous );
    app_migrate_previous_roles( $previous );
}

function app_migrate_previous_tables( string $previous ): void {
    global $wpdb;

    $suffixes = [
        'buyers',
        'nexus_by_sohan_email_recipients',
        'orders',
        'order_completions',
        'order_completion_items',
        'order_status_history',
        'order_sizes',
        'order_accessories',
        'order_department_deadlines',
        'order_outside_purpose_deadlines',
        'outside_purposes',
        'departments',
        'department_lines',
        'transactions',
        'transaction_items',
        'users_permissions',
        'logs',
        'Messages',
        'sequence_counters',
        'yarn_types',
        'yarn_stock',
        'yarn_stock_movements',
        'yarn_outside_jobs',
        'yarn_outside_job_items',
        'yarn_outside_receipts',
        'yarn_outside_losses',
    ];

    foreach ( $suffixes as $suffix ) {
        $old_table = $wpdb->prefix . $previous . '_' . $suffix;
        $new_table = $wpdb->prefix . 'app_' . $suffix;

        $old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );
        $new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) );

        if ( $old_exists && ! $new_exists ) {
            $wpdb->query( 'RENAME TABLE `' . esc_sql( $old_table ) . '` TO `' . esc_sql( $new_table ) . '`' );
        } elseif ( $old_exists && $new_exists ) {
            $old_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $old_table ) . '`' );
            $new_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $new_table ) . '`' );

            if ( $old_count > 0 && 0 === $new_count ) {
                $wpdb->query( 'DROP TABLE `' . esc_sql( $new_table ) . '`' );
                $wpdb->query( 'RENAME TABLE `' . esc_sql( $old_table ) . '` TO `' . esc_sql( $new_table ) . '`' );
            }
        }
    }
}

function app_migrate_previous_option_keys( string $previous ): void {
    global $wpdb;

    $old_like = $wpdb->esc_like( $previous . '_' ) . '%';
    $keys = $wpdb->get_col( $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $old_like
    ) );

    foreach ( $keys as $old_key ) {
        $new_key = 'app' . substr( (string) $old_key, strlen( $previous ) );
        $new_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $new_key
        ) );

        if ( ! $new_exists ) {
            $wpdb->update( $wpdb->options, [ 'option_name' => $new_key ], [ 'option_name' => $old_key ], [ '%s' ], [ '%s' ] );
        }
    }
}

function app_migrate_previous_user_meta_keys( string $previous ): void {
    global $wpdb;

    $old_like = $wpdb->esc_like( $previous . '_' ) . '%';
    $keys = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $old_like
    ) );

    foreach ( $keys as $old_key ) {
        $new_key = 'app' . substr( (string) $old_key, strlen( $previous ) );
        $wpdb->update( $wpdb->usermeta, [ 'meta_key' => $new_key ], [ 'meta_key' => $old_key ], [ '%s' ], [ '%s' ] );
    }
}

function app_migrate_previous_roles( string $previous ): void {
    $map = [
        $previous . '_employee' => 'app_employee',
        $previous . '_admin' => 'app_admin',
        $previous . '_super_admin' => 'app_super_admin',
    ];

    foreach ( $map as $old_role => $new_role ) {
        $users = get_users( [
            'role'   => $old_role,
            'fields' => [ 'ID' ],
        ] );

        foreach ( $users as $user_data ) {
            $user = new \WP_User( (int) $user_data->ID );
            $user->add_role( $new_role );
            $user->remove_role( $old_role );
        }
    }
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\app_migrate_previous_storage', 0 );

// Kick off after all plugins have loaded so hooks are available.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\app_nexus' );

// ── Activation / Deactivation / Uninstall hooks ──────────────────────────────

register_activation_hook(   __FILE__, [ Installer::class, 'activate'   ] );
register_deactivation_hook( __FILE__, [ Installer::class, 'deactivate' ] );
register_uninstall_hook(    __FILE__, [ Installer::class, 'uninstall'  ] );
