<?php
/**
 * Installer class for setting up the plugin.
 *
 * @package Membership_Discount_Budget
 */

defined('ABSPATH') || exit;

/**
 * MDB_Installer Class.
 */
class MDB_Installer {
    /**
     * Install the plugin.
     */
    public static function install() {
        self::create_tables();
        self::create_options();
    }

    /**
     * Create plugin database tables.
     */
    private static function create_tables() {
        global $wpdb;

        $wpdb->hide_errors();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_name = $wpdb->prefix . 'membership_discount_budget';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            membership_id bigint(20) NOT NULL,
            total_budget decimal(10,2) NOT NULL DEFAULT 0,
            used_amount decimal(10,2) NOT NULL DEFAULT 0,
            remaining_budget decimal(10,2) NOT NULL DEFAULT 0,
            month int(2) NOT NULL,
            year int(4) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY membership_id (membership_id),
            KEY month_year (month, year),
            KEY user_month_year (user_id, month, year)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Create plugin options with default values.
     */
    private static function create_options() {
        // Add default options
        add_option('mdb_monthly_budget', 300); // Default budget amount of 300 BGN
        add_option('mdb_discount_percentage', 20); // Default discount of 20%
    }
}
