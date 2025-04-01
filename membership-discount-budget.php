<?php
/**
 * Plugin Name: Membership Discount Budget
 * Plugin URI: 
 * Description: Integrates WooCommerce Memberships with WooCommerce Subscriptions to provide a monthly discount budget system for members.
 * Version: 1.0.0
 * Author: 1 Click Studio
 * Author URI: 1clickstudio.bg
 * Text Domain: membership-discount-budget
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.0
 * WC requires at least: 6.5
 * WC tested up to: 8.3
 *
 * @package Membership_Discount_Budget
 */

defined('ABSPATH') || exit;

/**
 * Main plugin class
 */
final class Membership_Discount_Budget {
    /**
     * Plugin version.
     *
     * @var string
     */
    public $version = '1.0.0';

    /**
     * Single instance of the class.
     *
     * @var Membership_Discount_Budget
     */
    protected static $_instance = null;

    /**
     * Main plugin instance.
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
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Define plugin constants.
     */
    private function define_constants() {
        $this->define('MDB_VERSION', $this->version);
        $this->define('MDB_PLUGIN_DIR', plugin_dir_path(__FILE__));
        $this->define('MDB_PLUGIN_URL', plugin_dir_url(__FILE__));
        $this->define('MDB_PLUGIN_BASENAME', plugin_basename(__FILE__));
    }

    /**
     * Define constant if not already defined.
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
     * Include required files.
     */
    private function includes() {
        // Core classes
        include_once MDB_PLUGIN_DIR . 'includes/class-mdb-installer.php';
        include_once MDB_PLUGIN_DIR . 'includes/mdb-functions.php';
        include_once MDB_PLUGIN_DIR . 'includes/class-mdb-budget.php';
        
        // Only admin area
        if (is_admin()) {
            include_once MDB_PLUGIN_DIR . 'includes/class-mdb-admin.php';
        }
        
        // Frontend classes
        include_once MDB_PLUGIN_DIR . 'includes/class-mdb-frontend.php';
        
        // API classes
        include_once MDB_PLUGIN_DIR . 'includes/class-mdb-api.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Check if WooCommerce, WooCommerce Memberships, and WooCommerce Subscriptions are active
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        
        // Initialize plugin after WooCommerce is loaded
        add_action('woocommerce_loaded', array($this, 'init'));
        
        // Activation hook
        register_activation_hook(__FILE__, array('MDB_Installer', 'install'));
        
        // Load text domain
        add_action('init', array($this, 'load_plugin_textdomain'));
    }

    /**
     * Load plugin text domain.
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain('membership-discount-budget', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Check plugin dependencies.
     */
    public function check_dependencies() {
        // Check for WooCommerce
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Check for WooCommerce Memberships
        if (!class_exists('WC_Memberships')) {
            add_action('admin_notices', array($this, 'memberships_missing_notice'));
            return;
        }

        // Check for WooCommerce Subscriptions
        if (!class_exists('WC_Subscriptions')) {
            add_action('admin_notices', array($this, 'subscriptions_missing_notice'));
            return;
        }
    }

    /**
     * Initialize the plugin.
     */
    public function init() {
        // Initialize plugin components
        MDB_Budget::instance();
        
        if (is_admin()) {
            MDB_Admin::instance();
        }
        
        MDB_Frontend::instance();
        MDB_API::instance();
    }

    /**
     * WooCommerce missing notice.
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p>';
        echo sprintf(
            /* translators: %s: WooCommerce URL */
            __('Membership Discount Budget requires WooCommerce to be installed and active. You can download %s here.', 'membership-discount-budget'),
            '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
        );
        echo '</p></div>';
    }

    /**
     * WooCommerce Memberships missing notice.
     */
    public function memberships_missing_notice() {
        echo '<div class="error"><p>';
        echo sprintf(
            /* translators: %s: WooCommerce Memberships URL */
            __('Membership Discount Budget requires WooCommerce Memberships to be installed and active. You can purchase %s here.', 'membership-discount-budget'),
            '<a href="https://woocommerce.com/products/woocommerce-memberships/" target="_blank">WooCommerce Memberships</a>'
        );
        echo '</p></div>';
    }

    /**
     * WooCommerce Subscriptions missing notice.
     */
    public function subscriptions_missing_notice() {
        echo '<div class="error"><p>';
        echo sprintf(
            /* translators: %s: WooCommerce Subscriptions URL */
            __('Membership Discount Budget requires WooCommerce Subscriptions to be installed and active. You can purchase %s here.', 'membership-discount-budget'),
            '<a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank">WooCommerce Subscriptions</a>'
        );
        echo '</p></div>';
    }
}

/**
 * Returns the main instance of the plugin.
 *
 * @return Membership_Discount_Budget
 */
function MDB() {
    return Membership_Discount_Budget::instance();
}

// Initialize the plugin
MDB();
