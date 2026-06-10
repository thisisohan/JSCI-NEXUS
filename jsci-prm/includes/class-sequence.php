<?php
/**
 * Atomic transaction number generator.
 *
 * Format: {DEPT}-{TYPE}-{YYYYMMDD}-{SEQ6}
 * e.g.   CUT-SEND-20260513-000125
 *        SEW-PROD-LINE3-20260513-000551
 *
 * Uses a dedicated sequence table + SELECT … FOR UPDATE to guarantee
 * uniqueness under concurrent requests.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sequence
 */
final class Sequence {

    /**
     * Generate the next transaction number for a given prefix.
     *
     * @param string      $dept_prefix  Department prefix, e.g. 'CUT', 'SEW'.
     * @param string      $tx_type      Transaction type abbrev, e.g. 'SEND', 'REC', 'PROD'.
     * @param string|null $line         Optional line identifier appended before date, e.g. 'LINE3'.
     * @return string     Transaction number string.
     */
    public static function next( string $dept_prefix, string $tx_type, ?string $line = null ): string {
        global $wpdb;

        $table    = Database::sequence_counters();
        $date_key = gmdate( 'Ymd' );

        // Build the prefix key used to namespace the counter.
        $parts = array_filter( [
            strtoupper( $dept_prefix ),
            strtoupper( $tx_type ),
            $line ? strtoupper( $line ) : null,
        ] );
        $prefix_key = implode( '-', $parts );

        $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore

        // Lock the row for this prefix+date.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, last_seq FROM `{$table}` WHERE prefix = %s AND date_key = %s FOR UPDATE",
            $prefix_key,
            $date_key
        ) );

        if ( $row ) {
            $next_seq = (int) $row->last_seq + 1;
            $wpdb->update(
                $table,
                [ 'last_seq' => $next_seq ],
                [ 'id'       => $row->id ],
                [ '%d' ],
                [ '%d' ]
            );
        } else {
            $next_seq = 1;
            $wpdb->insert(
                $table,
                [
                    'prefix'   => $prefix_key,
                    'date_key' => $date_key,
                    'last_seq' => $next_seq,
                ],
                [ '%s', '%s', '%d' ]
            );
        }

        $wpdb->query( 'COMMIT' ); // phpcs:ignore

        // Format: CUT-SEND-20260513-000001
        return sprintf( '%s-%s-%06d', $prefix_key, $date_key, $next_seq );
    }
}
