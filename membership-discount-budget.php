<?php
/**
 * Plugin Name: Membership Discount Budget
 * Description: Integrates WooCommerce Memberships with WooCommerce Subscriptions to provide a monthly discount budget system for members.
 * Version: 1.0.0
 * Author: 1 Click Studio Ltd
 * Text Domain: membership-discount-budget
 * Domain Path: /languages
 * WC requires at least: 4.0.0
 * WC tested up to: 8.0.0
 * Requires PHP: 7.4
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Define plugin constants
 */
define( 'MDB_VERSION', '1.0.0' );
define( 'MDB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MDB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active
 */
function mdb_check_woocommerce_active() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'mdb_woocommerce_missing_notice' );
        return false;
    }
    return true;
}

/**
 * Check if WooCommerce Memberships is active
 */
function mdb_check_wc_memberships_active() {
    if ( ! class_exists( 'WC_Memberships' ) ) {
        add_action( 'admin_notices', 'mdb_wc_memberships_missing_notice' );
        return false;
    }
    return true;
}

/**
 * Check if WooCommerce Subscriptions is active
 */
function mdb_check_wc_subscriptions_active() {
    if ( ! class_exists( 'WC_Subscriptions' ) ) {
        add_action( 'admin_notices', 'mdb_wc_subscriptions_missing_notice' );
        return false;
    }
    return true;
}

/**
 * WooCommerce missing notice
 */
function mdb_woocommerce_missing_notice() {
    echo '<div class="error"><p>' . sprintf(
        __( 'Membership Discount Budget requires WooCommerce to be installed and active. You can download %s here.', 'membership-discount-budget' ),
        '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
    ) . '</p></div>';
}

/**
 * WooCommerce Memberships missing notice
 */
function mdb_wc_memberships_missing_notice() {
    echo '<div class="error"><p>' . sprintf(
        __( 'Membership Discount Budget requires WooCommerce Memberships to be installed and active. You can download %s here.', 'membership-discount-budget' ),
        '<a href="https://woocommerce.com/products/woocommerce-memberships/" target="_blank">WooCommerce Memberships</a>'
    ) . '</p></div>';
}

/**
 * WooCommerce Subscriptions missing notice
 */
function mdb_wc_subscriptions_missing_notice() {
    echo '<div class="error"><p>' . sprintf(
        __( 'Membership Discount Budget requires WooCommerce Subscriptions to be installed and active. You can download %s here.', 'membership-discount-budget' ),
        '<a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank">WooCommerce Subscriptions</a>'
    ) . '</p></div>';
}

/**
 * Initialize the plugin
 */
function mdb_init() {
    // Check if required plugins are active
    if ( ! mdb_check_woocommerce_active() || ! mdb_check_wc_memberships_active() || ! mdb_check_wc_subscriptions_active() ) {
        return;
    }

    // Load plugin textdomain
    load_plugin_textdomain( 'membership-discount-budget', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // Include required files
    require_once MDB_PLUGIN_DIR . 'includes/mdb-functions.php';
    require_once MDB_PLUGIN_DIR . 'includes/class-mdb-installer.php';
    require_once MDB_PLUGIN_DIR . 'includes/class-mdb-budget.php';
    require_once MDB_PLUGIN_DIR . 'includes/class-mdb-frontend.php';
    require_once MDB_PLUGIN_DIR . 'includes/class-mdb-admin.php';
    require_once MDB_PLUGIN_DIR . 'includes/class-mdb-api.php';

    // Initialize installer
    $installer = new MDB_Installer();
    register_activation_hook( __FILE__, array( $installer, 'install' ) );

    // Initialize core classes
    $budget = new MDB_Budget();
    $frontend = new MDB_Frontend();
    $admin = new MDB_Admin();
    $api = new MDB_API();

    // Register actions and filters
    add_action( 'plugins_loaded', 'mdb_compatibility_check' );
    add_action( 'admin_enqueue_scripts', 'mdb_admin_scripts' );
    add_action( 'wp_enqueue_scripts', 'mdb_frontend_scripts' );
}
add_action( 'plugins_loaded', 'mdb_init', 20 );

/**
 * Check plugin compatibility with WordPress and PHP versions
 */
function mdb_compatibility_check() {
    if ( ! version_compare( PHP_VERSION, '7.4', '>=' ) ) {
        add_action( 'admin_notices', 'mdb_php_version_notice' );
        return;
    }

    if ( ! version_compare( get_bloginfo( 'version' ), '5.0', '>=' ) ) {
        add_action( 'admin_notices', 'mdb_wp_version_notice' );
        return;
    }
}

/**
 * PHP version compatibility notice
 */
function mdb_php_version_notice() {
    echo '<div class="error"><p>' . sprintf(
        __( 'Membership Discount Budget requires PHP 7.4 or higher. You are running PHP %s. Please upgrade your PHP version.', 'membership-discount-budget' ),
        PHP_VERSION
    ) . '</p></div>';
}

/**
 * WordPress version compatibility notice
 */
function mdb_wp_version_notice() {
    echo '<div class="error"><p>' . sprintf(
        __( 'Membership Discount Budget requires WordPress 5.0 or higher. You are running WordPress %s. Please upgrade your WordPress version.', 'membership-discount-budget' ),
        get_bloginfo( 'version' )
    ) . '</p></div>';
}

/**
 * Enqueue admin scripts and styles
 */
function mdb_admin_scripts( $hook ) {
    // Only load on plugin pages
    if ( strpos( $hook, 'membership-discount-budget' ) === false ) {
        return;
    }

    wp_enqueue_style( 'mdb-admin-css', MDB_PLUGIN_URL . 'assets/css/admin.css', array(), MDB_VERSION );
    wp_enqueue_script( 'mdb-admin-js', MDB_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), MDB_VERSION, true );

    // Add localization
    wp_localize_script( 'mdb-admin-js', 'mdb_params', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'mdb-admin-nonce' ),
        'currency_symbol' => get_woocommerce_currency_symbol(),
    ) );
}

/**
 * Enqueue frontend scripts and styles
 */
function mdb_frontend_scripts() {
    // Only load for logged in users with an active membership
    if ( ! is_user_logged_in() || ! mdb_user_has_active_membership( get_current_user_id() ) ) {
        return;
    }

    wp_enqueue_style( 'mdb-frontend-css', MDB_PLUGIN_URL . 'assets/css/frontend.css', array(), MDB_VERSION );
    wp_enqueue_script( 'mdb-frontend-js', MDB_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), MDB_VERSION, true );

    // Add localization
    wp_localize_script( 'mdb-frontend-js', 'mdb_params', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'mdb-frontend-nonce' ),
        'currency_symbol' => get_woocommerce_currency_symbol(),
        'i18n' => array(
            'budget_exceeded' => __( 'Your discount budget would be exceeded with this purchase.', 'membership-discount-budget' ),
            'budget_low' => __( 'Your discount budget is running low.', 'membership-discount-budget' ),
        )
    ) );
}
