<?php
/**
 * Installer class
 * 
 * @package Membership_Discount_Budget
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles installation and updates
 */
class MDB_Installer {
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'membership_discount_budget';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            membership_id bigint(20) NOT NULL,
            total_budget decimal(10,2) NOT NULL DEFAULT 0.00,
            used_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            remaining_budget decimal(10,2) NOT NULL DEFAULT 0.00,
            month int(2) NOT NULL,
            year int(4) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY membership_id (membership_id),
            KEY month_year (month, year),
            KEY user_month_year (user_id, month, year)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Store table version
        update_option('mdb_db_version', MDB_VERSION);
    }
    
    /**
     * Update plugin if needed
     */
    public function update_if_needed() {
        $current_version = get_option('mdb_version', '0.0.0');
        
        // If the versions match, no need to update
        if (version_compare($current_version, MDB_VERSION, '==')) {
            return;
        }
        
        // Run update routines based on version
        if (version_compare($current_version, '1.0.0', '<')) {
            $this->update_to_1_0_0();
        }
        
        if (version_compare($current_version, '1.1.0', '<')) {
            $this->update_to_1_1_0();
        }
        
        // Update version number
        update_option('mdb_version', MDB_VERSION);
    }
    
    /**
     * Update to version 1.0.0
     */
    private function update_to_1_0_0() {
        // Initial version, no updates needed
    }
    
    /**
     * Update to version 1.1.0
     */
    private function update_to_1_1_0() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'membership_discount_budget';
        
        // Check if additional indexes need to be added
        $results = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name IN ('user_id', 'membership_id', 'month_year')");
        
        if (empty($results)) {
            // Add the additional indexes for better performance
            $wpdb->query("ALTER TABLE $table_name ADD INDEX user_id (user_id), ADD INDEX membership_id (membership_id), ADD INDEX month_year (month, year)");
        }
    }
}
