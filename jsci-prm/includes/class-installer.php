<?php
/**
 * Handles plugin activation, deactivation, and uninstall.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

/**
 * Class Installer
 */
final class Installer {

    /**
     * Run on plugin activation.
     * Creates / upgrades DB tables and prepares capability access.
     */
    public static function activate(): void {
        Database::install();
        Roles::add_roles();
        flush_rewrite_rules();
    }

    /**
     * Run on plugin deactivation.
     * Capability assignments are intentionally kept so access stays intact.
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Run on uninstall (called statically by WordPress).
     * Drops all tables and cleans up options only when the user opts in.
     */
    public static function uninstall(): void {
        $remove = get_option( 'jsci_prm_remove_data_on_uninstall', false );

        if ( $remove ) {
            Database::drop_tables();
            Roles::remove_roles();
            self::delete_options();
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function delete_options(): void {
        $options = [
            'jsci_prm_db_version',
            'jsci_prm_remove_data_on_uninstall',
            'jsci_prm_sequence_counters',
        ];
        foreach ( $options as $option ) {
            delete_option( $option );
        }
    }
}
