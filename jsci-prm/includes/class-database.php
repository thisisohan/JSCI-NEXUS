<?php
/**
 * Database table management.
 *
 * @package Snivertech\Sohan\JSCIPRM
 */

namespace Snivertech\Sohan\JSCIPRM;

defined( 'ABSPATH' ) || exit;

/**
 * Class Database
 */
final class Database {

    // ── Public API ────────────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        foreach ( self::schema( $charset ) as $sql ) {
            dbDelta( $sql );
        }

        self::ensure_schema_updates();

        update_option( 'jsci_prm_db_version', JSCI_PRM_DB_VERSION );
    }

    public static function maybe_upgrade(): void {
        $stored = get_option( 'jsci_prm_db_version', '0.0.0' );

        if ( version_compare( $stored, JSCI_PRM_DB_VERSION, '<' ) ) {
            self::install();
        }
    }

    /**
     * Applies non-destructive schema fixes on normal runtime loads.
     */
    public static function ensure_runtime_schema(): void {
        self::ensure_schema_updates();
    }

    public static function drop_tables(): void {
        global $wpdb;

        $tables = [
            'jsci_logs',
            'jsci_Messages',
            'jsci_transaction_items',
            'jsci_transactions',
            'jsci_order_completion_items',
            'jsci_order_completions',
            'jsci_order_status_history',
            'jsci_order_department_deadlines',
            'jsci_department_lines',
            'jsci_users_permissions',
            'jsci_departments',
            'jsci_order_sizes',
            'jsci_orders',
            'jsci_sequence_counters',
        ];

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore
        }
    }

    // ── Table helpers ─────────────────────────────────────────────────────────

    public static function orders(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_orders';
    }

    public static function order_sizes(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_order_sizes';
    }

    public static function order_department_deadlines(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_order_department_deadlines';
    }

    public static function order_completions(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_order_completions';
    }

    public static function order_completion_items(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_order_completion_items';
    }

    public static function order_status_history(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_order_status_history';
    }

    public static function departments(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_departments';
    }

    public static function department_lines(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_department_lines';
    }

    public static function transactions(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_transactions';
    }

    public static function transaction_items(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_transaction_items';
    }

    public static function users_permissions(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_users_permissions';
    }

    public static function logs(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_logs';
    }

    public static function Messages(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_Messages';
    }

    public static function sequence_counters(): string {
        global $wpdb;
        return $wpdb->prefix . 'jsci_sequence_counters';
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    private static function schema( string $charset ): array {

        global $wpdb;

        $p = $wpdb->prefix;

        return [

            // ── Orders ────────────────────────────────────────────────────────
            "CREATE TABLE `{$p}jsci_orders` (
  `id`                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number`        VARCHAR(100)        NOT NULL DEFAULT '',
  `reference_number`    VARCHAR(100)        NOT NULL DEFAULT '',
  `buyer_name`          VARCHAR(255)        NOT NULL DEFAULT '',
  `required_yarn_qty`   DECIMAL(12,3)       NOT NULL DEFAULT 0.000,
  `required_fabric_qty` DECIMAL(12,3)       NOT NULL DEFAULT 0.000,
  `cutting_extra_pct`   DECIMAL(5,2)        NOT NULL DEFAULT 0.00,
  `shipment_deadline`   DATE                         DEFAULT NULL,
  `notes`               TEXT                         DEFAULT NULL,
  `status`              ENUM('active','completed','cancelled','on_hold') NOT NULL DEFAULT 'active',
  `created_by`          BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `updated_by`          BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `deleted_at`          DATETIME                     DEFAULT NULL,
  `created_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_number` (`order_number`),
  KEY `idx_buyer` (`buyer_name`(50)),
  KEY `idx_status` (`status`),
  KEY `idx_deadline` (`shipment_deadline`),
  KEY `idx_deleted_at` (`deleted_at`)
) $charset",

            // ── Order Sizes ───────────────────────────────────────────────────
            "CREATE TABLE `{$p}jsci_order_completions` (
  `id`               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`         BIGINT(20) UNSIGNED NOT NULL,
  `quantity_mode`    ENUM('full','short','over','auto') NOT NULL DEFAULT 'full',
  `detail_mode`      ENUM('summary','sizes') NOT NULL DEFAULT 'summary',
  `shipped_date`     DATE DEFAULT NULL,
  `completion_note`  TEXT DEFAULT NULL,
  `required_qty_total` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `actual_qty_total`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `qty_diff_total`    INT(11) NOT NULL DEFAULT 0,
  `completed_by`     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `completed_at`     DATETIME NOT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_completion_order` (`order_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_mode` (`quantity_mode`),
  KEY `idx_detail_mode` (`detail_mode`)
) $charset",

            "CREATE TABLE `{$p}jsci_order_completion_items` (
  `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `completion_id`   BIGINT(20) UNSIGNED NOT NULL,
  `order_size_id`   BIGINT(20) UNSIGNED NOT NULL,
  `size_label`      VARCHAR(30) NOT NULL DEFAULT '',
  `required_qty`    INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `actual_qty`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `qty_diff`        INT(11) NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_completion_id` (`completion_id`),
  KEY `idx_order_size_id` (`order_size_id`)
) $charset",

            "CREATE TABLE `{$p}jsci_order_status_history` (
  `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`        BIGINT(20) UNSIGNED NOT NULL,
  `status`          ENUM('active','completed','cancelled','on_hold') NOT NULL,
  `note`            TEXT DEFAULT NULL,
  `changed_by`      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `changed_at`      DATETIME NOT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`)
) $charset",

            "CREATE TABLE `{$p}jsci_order_sizes` (
  `id`           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     BIGINT(20) UNSIGNED NOT NULL,
  `size_label`   VARCHAR(30)         NOT NULL DEFAULT '',
  `required_qty` INT(10) UNSIGNED    NOT NULL DEFAULT 0,
  `sort_order`   SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
  `created_at`   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_size` (`order_id`, `size_label`),
  KEY `idx_order_id` (`order_id`)
) $charset",

            // ── Order Department Deadlines ───────────────────────────────────
            "CREATE TABLE `{$p}jsci_order_department_deadlines` (
  `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`        BIGINT(20) UNSIGNED NOT NULL,
  `department_id`   BIGINT(20) UNSIGNED NOT NULL,
  `deadline_date`   DATE DEFAULT NULL,
  `extra_pct`       DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_department` (`order_id`, `department_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_department_id` (`department_id`)
) $charset",

            // ── Departments ───────────────────────────────────────────────────
            "CREATE TABLE `{$p}jsci_departments` (
  `id`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`           VARCHAR(60)         NOT NULL DEFAULT '',
  `name`           VARCHAR(150)        NOT NULL DEFAULT '',
  `tx_prefix`      VARCHAR(10)         NOT NULL DEFAULT '',
  `workflow_order` SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)          NOT NULL DEFAULT 1,
  `allow_manual_entry` TINYINT(1)      NOT NULL DEFAULT 1,
  `allow_production_entry` TINYINT(1)  NOT NULL DEFAULT 1,
  `allow_fabric_to_piece_conversion_entry` TINYINT(1) NOT NULL DEFAULT 0,
  `auto_produce_on_receive` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_reject_entry` TINYINT(1)      NOT NULL DEFAULT 1,
  `reject_stages` LONGTEXT DEFAULT NULL,
  `allow_send_entry` TINYINT(1)        NOT NULL DEFAULT 1,
  `allow_send_outside_factory_entry` TINYINT(1) NOT NULL DEFAULT 1,
  `send_outside_purposes` LONGTEXT DEFAULT NULL,
  `allow_manual_entry_kg` TINYINT(1)   NOT NULL DEFAULT 0,
  `allow_production_entry_kg` TINYINT(1)  NOT NULL DEFAULT 0,
  `auto_produce_on_receive_kg` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_reject_entry_kg` TINYINT(1)      NOT NULL DEFAULT 0,
  `reject_stages_kg` LONGTEXT DEFAULT NULL,
  `allow_send_entry_kg` TINYINT(1)        NOT NULL DEFAULT 0,
  `allow_send_outside_factory_entry_kg` TINYINT(1) NOT NULL DEFAULT 0,
  `send_outside_purposes_kg` LONGTEXT DEFAULT NULL,
  `view_required_qty` TINYINT(1)      NOT NULL DEFAULT 1,
  `compatibility_transaction_types` LONGTEXT DEFAULT NULL,
  `send_to_department_ids` LONGTEXT DEFAULT NULL,
  `send_to_department_ids_kg` LONGTEXT DEFAULT NULL,
  `created_at`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  UNIQUE KEY `uq_tx_prefix` (`tx_prefix`)
) $charset",

            // ── Transactions ──────────────────────────────────────────────────
            "CREATE TABLE `{$p}jsci_transactions` (
  `id`                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tx_number`         VARCHAR(40)         NOT NULL DEFAULT '',
  `order_id`          BIGINT(20) UNSIGNED NOT NULL,
  `from_dept_id`      BIGINT(20) UNSIGNED DEFAULT NULL,
  `to_dept_id`        BIGINT(20) UNSIGNED DEFAULT NULL,
  `tx_type`           ENUM('receive','manual_receive','produce','send','send_outside_factory','reject','return') NOT NULL,
  `entry_mode`        ENUM('size','kg','cutting') NOT NULL DEFAULT 'size',
  `fabric_used_kg`    DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `line_number`       VARCHAR(30) DEFAULT NULL,
  `reject_stage`      ENUM('before_production','after_production') DEFAULT NULL,
  `send_outside_purpose` VARCHAR(30) DEFAULT NULL,
  `external_organization_name` VARCHAR(190) DEFAULT NULL,
  `status`            ENUM('pending','confirmed','locked','voided') NOT NULL DEFAULT 'pending',
  `notes`             TEXT DEFAULT NULL,
  `created_by`        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `confirmed_by`      BIGINT(20) UNSIGNED DEFAULT NULL,
  `confirmed_at`      DATETIME DEFAULT NULL,
  `locked_at`         DATETIME DEFAULT NULL,
  `deleted_at`        DATETIME DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tx_number` (`tx_number`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_from_dept` (`from_dept_id`),
  KEY `idx_to_dept` (`to_dept_id`),
  KEY `idx_tx_type` (`tx_type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_deleted_at` (`deleted_at`)
) $charset",

            // ── Transaction Items ─────────────────────────────────────────────
            "CREATE TABLE `{$p}jsci_transaction_items` (
  `id`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` BIGINT(20) UNSIGNED NOT NULL,
  `order_size_id`  BIGINT(20) UNSIGNED NOT NULL,
  `size_label`     VARCHAR(30) NOT NULL DEFAULT '',
  `quantity`       DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_order_size_id` (`order_size_id`)
) $charset",

            // ── User Permissions ──────────────────────────────────────────────
            "CREATE TABLE `{$p}jsci_users_permissions` (
  `id`           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT(20) UNSIGNED NOT NULL,
  `dept_id`      BIGINT(20) UNSIGNED DEFAULT NULL,
  `role`         ENUM('operator','supervisor','manager','admin','super_admin') NOT NULL DEFAULT 'operator',
  `dept_access`  TINYINT(1) NOT NULL DEFAULT 0,
  `line_access`  VARCHAR(255) DEFAULT NULL,
  `entry_types`  LONGTEXT DEFAULT NULL,
  `history_access` TINYINT(1) NOT NULL DEFAULT 0,
  `department_history_access` TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit`     TINYINT(1) NOT NULL DEFAULT 0,
  `can_report`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_by`   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_dept` (`user_id`, `dept_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_dept_id` (`dept_id`),
  KEY `idx_role` (`role`)
) $charset",

            // ── Audit Logs ────────────────────────────────────────────────────
            "CREATE TABLE `{$p}jsci_logs` (
  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `action`      VARCHAR(60) NOT NULL DEFAULT '',
  `object_type` VARCHAR(60) NOT NULL DEFAULT '',
  `object_id`   BIGINT(20) UNSIGNED DEFAULT NULL,
  `old_value`   LONGTEXT DEFAULT NULL,
  `new_value`   LONGTEXT DEFAULT NULL,
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_object` (`object_type`, `object_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) $charset",

            "CREATE TABLE `{$p}jsci_Messages` (
  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `user_name`   VARCHAR(190) NOT NULL DEFAULT '',
  `slot`        ENUM('primary','secondary') NOT NULL DEFAULT 'primary',
  `message`     TEXT NOT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `expires_at`  DATETIME DEFAULT NULL,
  `deleted_at`  DATETIME DEFAULT NULL,
  `created_by`  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `updated_by`  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_slot` (`slot`),
  KEY `idx_active_expiry` (`is_active`, `expires_at`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_created_at` (`created_at`)
) $charset",

            // ── Sequence Counters ─────────────────────────────────────────────
            "CREATE TABLE `{$p}jsci_sequence_counters` (
  `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `prefix`     VARCHAR(30) NOT NULL DEFAULT '',
  `date_key`   CHAR(8) NOT NULL DEFAULT '',
  `last_seq`   INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prefix_date` (`prefix`, `date_key`)
) $charset",

        ];
    }

    private static function ensure_schema_updates(): void {
        global $wpdb;

        $deadline_table = self::order_department_deadlines();

        $extra_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$deadline_table}` LIKE %s",
            'extra_pct'
        ) );

        if ( ! $extra_column ) {
            $wpdb->query( "ALTER TABLE `{$deadline_table}` ADD `extra_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `deadline_date`" );
        }

        $wpdb->query( "ALTER TABLE `{$deadline_table}` MODIFY `deadline_date` DATE DEFAULT NULL" );

        $completion_table = self::order_completions();
        $quantity_mode_column = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$completion_table}` LIKE %s",
            'quantity_mode'
        ) );

        if ( $quantity_mode_column && false === strpos( (string) $quantity_mode_column->Type, 'auto' ) ) {
            $wpdb->query( "ALTER TABLE `{$completion_table}` MODIFY `quantity_mode` ENUM('full','short','over','auto') NOT NULL DEFAULT 'full'" );
        }

        $lines_table = self::department_lines();
        $lines_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lines_table ) );

        if ( ! $lines_exists ) {
            $wpdb->query( "CREATE TABLE `{$lines_table}` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT(20) UNSIGNED NOT NULL,
  `line_number` VARCHAR(30) NOT NULL DEFAULT '',
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_line` (`department_id`, `line_number`),
  KEY `idx_department_id` (`department_id`),
  KEY `idx_sort_order` (`sort_order`)
) " . $wpdb->get_charset_collate() );
        }

        $transactions_table = self::transactions();
        $reject_stage_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$transactions_table}` LIKE %s",
            'reject_stage'
        ) );

        if ( ! $reject_stage_column ) {
            $wpdb->query( "ALTER TABLE `{$transactions_table}` ADD `reject_stage` ENUM('before_production','after_production') DEFAULT NULL AFTER `line_number`" );
        }

        $tx_type_column = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$transactions_table}` LIKE %s",
            'tx_type'
        ) );

        if ( $tx_type_column && false === strpos( (string) $tx_type_column->Type, 'send_outside_factory' ) ) {
            $wpdb->query( "ALTER TABLE `{$transactions_table}` MODIFY `tx_type` ENUM('receive','manual_receive','produce','send','send_outside_factory','reject','return') NOT NULL" );
        }

        $entry_mode_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$transactions_table}` LIKE %s",
            'entry_mode'
        ) );

        if ( ! $entry_mode_column ) {
            $wpdb->query( "ALTER TABLE `{$transactions_table}` ADD `entry_mode` ENUM('size','kg','cutting') NOT NULL DEFAULT 'size' AFTER `tx_type`" );
        } else {
            $entry_mode_definition = $wpdb->get_row( $wpdb->prepare(
                "SHOW COLUMNS FROM `{$transactions_table}` LIKE %s",
                'entry_mode'
            ) );

            if ( $entry_mode_definition && false === strpos( (string) $entry_mode_definition->Type, 'cutting' ) ) {
                $wpdb->query( "ALTER TABLE `{$transactions_table}` MODIFY `entry_mode` ENUM('size','kg','cutting') NOT NULL DEFAULT 'size'" );
            }
        }

        $fabric_used_kg_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$transactions_table}` LIKE %s",
            'fabric_used_kg'
        ) );

        if ( ! $fabric_used_kg_column ) {
            $wpdb->query( "ALTER TABLE `{$transactions_table}` ADD `fabric_used_kg` DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER `entry_mode`" );
        }

        $send_outside_purpose_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$transactions_table}` LIKE %s",
            'send_outside_purpose'
        ) );

        if ( ! $send_outside_purpose_column ) {
            $wpdb->query( "ALTER TABLE `{$transactions_table}` ADD `send_outside_purpose` VARCHAR(30) DEFAULT NULL AFTER `reject_stage`" );
        }

        $external_organization_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$transactions_table}` LIKE %s",
            'external_organization_name'
        ) );

        if ( ! $external_organization_column ) {
            $wpdb->query( "ALTER TABLE `{$transactions_table}` ADD `external_organization_name` VARCHAR(190) DEFAULT NULL AFTER `send_outside_purpose`" );
        }

        $transaction_items_table = self::transaction_items();
        $quantity_column = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$transaction_items_table}` LIKE %s",
            'quantity'
        ) );

        if ( $quantity_column && false === strpos( (string) $quantity_column->Type, 'decimal' ) ) {
            $wpdb->query( "ALTER TABLE `{$transaction_items_table}` MODIFY `quantity` DECIMAL(12,3) NOT NULL DEFAULT 0.000" );
        }

        $departments_table = self::departments();
        $department_behavior_columns = [
            'allow_manual_entry'      => "ALTER TABLE `{$departments_table}` ADD `allow_manual_entry` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_active`",
            'allow_production_entry'  => "ALTER TABLE `{$departments_table}` ADD `allow_production_entry` TINYINT(1) NOT NULL DEFAULT 1 AFTER `allow_manual_entry`",
            'allow_fabric_to_piece_conversion_entry' => "ALTER TABLE `{$departments_table}` ADD `allow_fabric_to_piece_conversion_entry` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_production_entry`",
            'auto_produce_on_receive' => "ALTER TABLE `{$departments_table}` ADD `auto_produce_on_receive` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_fabric_to_piece_conversion_entry`",
            'allow_reject_entry'      => "ALTER TABLE `{$departments_table}` ADD `allow_reject_entry` TINYINT(1) NOT NULL DEFAULT 1 AFTER `auto_produce_on_receive`",
            'reject_stages'           => "ALTER TABLE `{$departments_table}` ADD `reject_stages` LONGTEXT DEFAULT NULL AFTER `allow_reject_entry`",
            'allow_send_entry'        => "ALTER TABLE `{$departments_table}` ADD `allow_send_entry` TINYINT(1) NOT NULL DEFAULT 1 AFTER `reject_stages`",
            'allow_send_outside_factory_entry' => "ALTER TABLE `{$departments_table}` ADD `allow_send_outside_factory_entry` TINYINT(1) NOT NULL DEFAULT 1 AFTER `allow_send_entry`",
            'send_outside_purposes' => "ALTER TABLE `{$departments_table}` ADD `send_outside_purposes` LONGTEXT DEFAULT NULL AFTER `allow_send_outside_factory_entry`",
            'allow_manual_entry_kg'      => "ALTER TABLE `{$departments_table}` ADD `allow_manual_entry_kg` TINYINT(1) NOT NULL DEFAULT 0 AFTER `send_outside_purposes`",
            'allow_production_entry_kg'  => "ALTER TABLE `{$departments_table}` ADD `allow_production_entry_kg` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_manual_entry_kg`",
            'auto_produce_on_receive_kg' => "ALTER TABLE `{$departments_table}` ADD `auto_produce_on_receive_kg` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_production_entry_kg`",
            'allow_reject_entry_kg'      => "ALTER TABLE `{$departments_table}` ADD `allow_reject_entry_kg` TINYINT(1) NOT NULL DEFAULT 0 AFTER `auto_produce_on_receive_kg`",
            'reject_stages_kg'           => "ALTER TABLE `{$departments_table}` ADD `reject_stages_kg` LONGTEXT DEFAULT NULL AFTER `allow_reject_entry_kg`",
            'allow_send_entry_kg'        => "ALTER TABLE `{$departments_table}` ADD `allow_send_entry_kg` TINYINT(1) NOT NULL DEFAULT 0 AFTER `reject_stages_kg`",
            'allow_send_outside_factory_entry_kg' => "ALTER TABLE `{$departments_table}` ADD `allow_send_outside_factory_entry_kg` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_send_entry_kg`",
            'send_outside_purposes_kg' => "ALTER TABLE `{$departments_table}` ADD `send_outside_purposes_kg` LONGTEXT DEFAULT NULL AFTER `allow_send_outside_factory_entry_kg`",
            'view_required_qty'      => "ALTER TABLE `{$departments_table}` ADD `view_required_qty` TINYINT(1) NOT NULL DEFAULT 1 AFTER `send_outside_purposes_kg`",
            'compatibility_transaction_types' => "ALTER TABLE `{$departments_table}` ADD `compatibility_transaction_types` LONGTEXT DEFAULT NULL AFTER `view_required_qty`",
            'send_to_department_ids'  => "ALTER TABLE `{$departments_table}` ADD `send_to_department_ids` LONGTEXT DEFAULT NULL AFTER `compatibility_transaction_types`",
            'send_to_department_ids_kg'  => "ALTER TABLE `{$departments_table}` ADD `send_to_department_ids_kg` LONGTEXT DEFAULT NULL AFTER `send_to_department_ids`",
        ];

        foreach ( $department_behavior_columns as $column => $alter_sql ) {
            $existing_column = $wpdb->get_var( $wpdb->prepare(
                "SHOW COLUMNS FROM `{$departments_table}` LIKE %s",
                $column
            ) );

            if ( ! $existing_column ) {
                $wpdb->query( $alter_sql );
            }
        }

        $permissions_table = self::users_permissions();

        $dept_access_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$permissions_table}` LIKE %s",
            'dept_access'
        ) );

        if ( ! $dept_access_column ) {
            $wpdb->query( "ALTER TABLE `{$permissions_table}` ADD `dept_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`" );
        }

        $entry_types_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$permissions_table}` LIKE %s",
            'entry_types'
        ) );

        if ( ! $entry_types_column ) {
            $wpdb->query( "ALTER TABLE `{$permissions_table}` ADD `entry_types` LONGTEXT DEFAULT NULL AFTER `line_access`" );
        }

        $history_access_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$permissions_table}` LIKE %s",
            'history_access'
        ) );

        if ( ! $history_access_column ) {
            $wpdb->query( "ALTER TABLE `{$permissions_table}` ADD `history_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `entry_types`" );
        }

        $department_history_access_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$permissions_table}` LIKE %s",
            'department_history_access'
        ) );

        if ( ! $department_history_access_column ) {
            $wpdb->query( "ALTER TABLE `{$permissions_table}` ADD `department_history_access` TINYINT(1) NOT NULL DEFAULT 0 AFTER `history_access`" );
        }

        $Messages_table = self::Messages();
        $Messages_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $Messages_table ) );

        if ( ! $Messages_exists ) {
            $wpdb->query( "CREATE TABLE `{$Messages_table}` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `user_name` VARCHAR(190) NOT NULL DEFAULT '',
  `slot` ENUM('primary','secondary') NOT NULL DEFAULT 'primary',
  `message` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `expires_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  `created_by` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `updated_by` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_slot` (`slot`),
  KEY `idx_active_expiry` (`is_active`, `expires_at`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_created_at` (`created_at`)
) " . $wpdb->get_charset_collate() );
        }

        $Message_slot_column = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$Messages_table}` LIKE %s",
            'slot'
        ) );

        if ( ! $Message_slot_column ) {
            $wpdb->query( "ALTER TABLE `{$Messages_table}` ADD `slot` ENUM('primary','secondary') NOT NULL DEFAULT 'primary' AFTER `user_name`" );
            $wpdb->query( "ALTER TABLE `{$Messages_table}` ADD KEY `idx_slot` (`slot`)" );
        }
    }
}
