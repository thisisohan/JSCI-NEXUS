<?php
/**
 * REST API router.
 *
 * Namespace: jsci-prm/v1
 *
 * Route registration is delegated to individual controller classes so each
 * module is self-contained. Controllers are loaded here rather than via the
 * autoloader so we can keep them in a sub-folder without renaming.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

/**
 * Class REST_Router
 */
final class REST_Router {

    public const NAMESPACE = 'jsci-prm/v1';

    /**
     * Called on rest_api_init.
     * Instantiate every controller and call its register_routes() method.
     */
    public static function register_routes(): void {
        $controllers = [
            REST_Orders_Controller::class,
            REST_Transactions_Controller::class,
            REST_Departments_Controller::class,
            REST_Reports_Controller::class,
            REST_Access_Management_Controller::class,
            REST_Messages_Controller::class,
            REST_External_Organizations_Controller::class,
        ];

        foreach ( $controllers as $class ) {
            if ( class_exists( $class ) ) {
                ( new $class() )->register_routes();
            }
        }
    }
}
