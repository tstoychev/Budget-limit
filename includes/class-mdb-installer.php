<?php
/**
 * Installer Class
 *
 * Handles plugin installation and updates
 *
 * @package Membership Discount Budget
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MDB_Installer Class
 */
class MDB_Installer {

    /**
     * DB Table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'membership_discount_budget';
        
        // Add upgrade routine if needed
        add_action( 'plugins_loaded', array( $this, 'check_update' ), 30 );
    }

    /**
     * Install the plugin
     */
    public function install() {
        // Create the database table
        $this->create_tables();
        
        // Add default options
        $this->add_default_options();
        
        // Set the installed version
        update_option( 'mdb_version', MDB_VERSION );
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
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
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Add default options
     */
    private function add_default_options() {
        // Add default settings
        $default_settings = array(
            'monthly_budget' => 300.00,
            'discount_percentage' => 20,
            'eligible_membership_plans' => array(),
            'debug_mode' => 'no',
            'display_budget_on_account' => 'yes',
            'display_budget_on_cart' => 'yes',
            'low_budget_threshold_percentage' => 10,
        );
        
        update_option( 'mdb_settings', $default_settings );
    }

    /**
     * Check for updates
     */
    public function check_update() {
        $installed_version = get_option( 'mdb_version' );
        
        // If installed version is different from current version, run updates
        if ( $installed_version != MDB_VERSION ) {
            $this->update( $installed_version );
        }
    }

    /**
     * Update the plugin
     *
     * @param string $installed_version The currently installed version
     */
    private function update( $installed_version ) {
        // Run different updates based on version
        if ( version_compare( $installed_version, '1.0.0', '<' ) ) {
            // Perform update for versions less than 1.0.0
            $this->create_tables();
        }
        
        // Always update the version number
        update_option( 'mdb_version', MDB_VERSION );
    }

    /**
     * Uninstall the plugin
     */
    public static function uninstall() {
        // If uninstall not called from WordPress, exit
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            exit;
        }
        
        global $wpdb;
        
        // Delete the database table
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}membership_discount_budget" );
        
        // Delete options
        delete_option( 'mdb_settings' );
        delete_option( 'mdb_version' );
        
        // Clear any cached data that has been removed
        wp_cache_flush();
    }
}

// Register uninstall hook
register_uninstall_hook( MDB_PLUGIN_BASENAME, array( 'MDB_Installer', 'uninstall' ) );
