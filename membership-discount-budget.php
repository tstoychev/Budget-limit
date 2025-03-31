<?php
/**
 * Plugin Name: Membership Discount Budget
 * Description: Manages a monthly discount budget for WooCommerce Membership users.
 * Version: 1.1.0
 * Author: 1 Click Studio Ltd
 * Text Domain: membership-discount-budget
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Requires WooCommerce: 3.0.0
 * License: GPL v2 or later
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Plugin Class
 *
 * @since 1.0.0
 */
final class Membership_Discount_Budget {
    
    /**
     * The single instance of the class.
     *
     * @var Membership_Discount_Budget
     */
    protected static $_instance = null;
    
    /**
     * Plugin version.
     *
     * @var string
     */
    public $version = '1.1.0';
    
    /**
     * Admin handler instance
     *
     * @var MDB_Admin
     */
    public $admin = null;
    
    /**
     * Frontend handler instance
     *
     * @var MDB_Frontend
     */
    public $frontend = null;
    
    /**
     * Budget handler instance
     *
     * @var MDB_Budget
     */
    public $budget = null;
    
    /**
     * Main instance
     *
     * @return Membership_Discount_Budget
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Constructor.
     */
    public function __construct() {
        $this->define_constants();
        $this->check_requirements();
        $this->init_hooks();
        $this->includes();
        $this->init_components();
        
        // Allow other plugins to extend this one
        do_action('mdb_initialized');
    }
    
    /**
     * Define constants
     */
    private function define_constants() {
        $this->define('MDB_PLUGIN_FILE', __FILE__);
        $this->define('MDB_PLUGIN_BASENAME', plugin_basename(__FILE__));
        $this->define('MDB_VERSION', $this->version);
        $this->define('MDB_PLUGIN_URL', plugin_dir_url(__FILE__));
        $this->define('MDB_PLUGIN_PATH', plugin_dir_path(__FILE__));
        $this->define('MDB_LANGUAGE_PATH', trailingslashit(dirname(plugin_basename(__FILE__))) . 'languages/');
    }
    
    /**
     * Define constant if not already set.
     *
     * @param string $name  Constant name.
     * @param mixed  $value Constant value.
     */
    private function define($name, $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
    
    /**
     * Check plugin requirements
     */
    private function check_requirements() {
        // Check if WooCommerce is active
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . esc_html__('Membership Discount Budget requires WooCommerce to be active.', 'membership-discount-budget') . '</p></div>';
            });
            return;
        }
        
        // Check if WooCommerce Memberships is active
        if (!in_array('woocommerce-memberships/woocommerce-memberships.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . esc_html__('Membership Discount Budget requires WooCommerce Memberships to be active.', 'membership-discount-budget') . '</p></div>';
            });
            return;
        }
    }
    
    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once MDB_PLUGIN_PATH . 'includes/class-mdb-installer.php';
        require_once MDB_PLUGIN_PATH . 'includes/class-mdb-budget.php';
        require_once MDB_PLUGIN_PATH . 'includes/class-mdb-admin.php';
        require_once MDB_PLUGIN_PATH . 'includes/class-mdb-frontend.php';
        require_once MDB_PLUGIN_PATH . 'includes/class-mdb-api.php';
        
        // Functions
        require_once MDB_PLUGIN_PATH . 'includes/mdb-functions.php';
    }
    
    /**
     * Initialize component objects
     */
    private function init_components() {
        $this->admin = new MDB_Admin();
        $this->frontend = new MDB_Frontend();
        $this->budget = new MDB_Budget();
        
        // Initialize API if needed
        if (apply_filters('mdb_enable_api', false)) {
            new MDB_API();
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Plugin activation/deactivation
        register_activation_hook(MDB_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(MDB_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Localization
        add_action('init', array($this, 'load_textdomain'));
        
        // Register cron event if not scheduled
        add_action('init', array($this, 'setup_cron'));
        
        // Initialize admin assets
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        
        // Initialize frontend assets
        add_action('wp_enqueue_scripts', array($this, 'frontend_assets'));
    }
    
    /**
     * Plugin activation handler
     */
    public function activate() {
        // Create database tables
        $installer = new MDB_Installer();
        $installer->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Schedule cron job
        if (!wp_next_scheduled('mdb_monthly_reset')) {
            // Schedule for first day of each month at midnight
            wp_schedule_event(strtotime('first day of next month midnight'), 'monthly', 'mdb_monthly_reset');
        }
        
        // Log activation
        error_log('Membership Discount Budget plugin activated - v' . $this->version);
        
        // Trigger action for other plugins
        do_action('mdb_activated');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation handler
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('mdb_monthly_reset');
        
        // Log deactivation
        error_log('Membership Discount Budget plugin deactivated - v' . $this->version);
        
        // Trigger action for other plugins
        do_action('mdb_deactivated');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Set default options
     */
    private function set_default_options() {
        // Default monthly budget
        if (false === get_option('mdb_monthly_budget')) {
            update_option('mdb_monthly_budget', 200.00);
        }
        
        // Default carryover setting
        if (false === get_option('mdb_carryover_budget')) {
            update_option('mdb_carryover_budget', false);
        }
        
        // Default allowed plans
        if (false === get_option('mdb_allowed_plans')) {
            update_option('mdb_allowed_plans', array());
        }
        
        // Set version
        update_option('mdb_version', $this->version);
    }
    
    /**
     * Setup cron job
     */
    public function setup_cron() {
        if (!wp_next_scheduled('mdb_monthly_reset')) {
            // Schedule for first day of each month at midnight
            wp_schedule_event(strtotime('first day of next month midnight'), 'monthly', 'mdb_monthly_reset');
        }
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('membership-discount-budget', false, MDB_LANGUAGE_PATH);
    }
    
    /**
     * Admin assets
     */
    public function admin_assets($hook) {
        // Only load on plugin pages
        if (strpos($hook, 'membership-budget') === false) {
            return;
        }
        
        // Admin CSS
        wp_enqueue_style(
            'mdb-admin-styles',
            MDB_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $this->version
        );
        
        // Admin JS
        wp_enqueue_script(
            'mdb-admin-scripts',
            MDB_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $this->version,
            true
        );
        
        // Localize script data
        wp_localize_script('mdb-admin-scripts', 'mdbAdminData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mdb-admin-nonce'),
            'strings' => array(
                'resetConfirm' => __('Are you sure you want to reset this budget to the monthly amount?', 'membership-discount-budget'),
                'error' => __('An error occurred. Please try again.', 'membership-discount-budget'),
                'success' => __('Operation completed successfully.', 'membership-discount-budget')
            )
        ));
    }
    
    /**
     * Frontend assets
     */
    public function frontend_assets() {
        // Only load on relevant pages
        if (!is_account_page() && !is_cart() && !is_checkout()) {
            return;
        }
        
        // Frontend CSS
        wp_enqueue_style(
            'mdb-frontend-styles',
            MDB_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            $this->version
        );
        
        // Frontend JS
        wp_enqueue_script(
            'mdb-frontend-scripts',
            MDB_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            $this->version,
            true
        );
        
        // Localize script data
        wp_localize_script('mdb-frontend-scripts', 'mdbFrontendData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mdb-frontend-nonce')
        ));
    }
}

/**
 * Returns the main instance of the plugin
 * 
 * @return Membership_Discount_Budget
 */
function MDB() {
    return Membership_Discount_Budget::instance();
}

// Initialize the plugin
MDB();
