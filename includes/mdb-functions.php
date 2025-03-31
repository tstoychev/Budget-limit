<?php
/**
 * Helper functions
 * 
 * @package Membership_Discount_Budget
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if debug logging is enabled
 *
 * @return bool
 */
function mdb_is_debug_enabled() {
    return get_option('mdb_enable_debug_logging', false);
}

/**
 * Log a message to the error log
 *
 * @param mixed $message Message to log
 * @param string $level Log level (debug, info, warning, error)
 */
function mdb_log($message, $level = 'debug') {
    if (!mdb_is_debug_enabled() && $level === 'debug') {
        return;
    }
    
    // Format message as string
    if (is_array($message) || is_object($message)) {
        $message = print_r($message, true);
    }
    
    // Add level prefix
    $message = strtoupper($level) . ': ' . $message;
    
    // Log to error log
    error_log('Membership Discount Budget: ' . $message);
}

/**
 * Get user's current budget
 *
 * @param int $user_id User ID
 * @return object|false Budget data or false if not found
 */
function mdb_get_user_budget($user_id) {
    return MDB()->budget->get_user_current_budget($user_id);
}

/**
 * Get budget usage for a user
 *
 * @param int $user_id User ID
 * @return float Budget usage percentage (0-100)
 */
function mdb_get_budget_usage($user_id) {
    $budget = mdb_get_user_budget($user_id);
    
    if (!$budget || $budget->total_budget <= 0) {
        return 0;
    }
    
    return ($budget->used_amount / $budget->total_budget) * 100;
}

/**
 * Check if user has enough budget for a specific amount
 *
 * @param int $user_id User ID
 * @param float $amount Amount to check
 * @return bool True if user has enough budget
 */
function mdb_has_enough_budget($user_id, $amount) {
    $budget = mdb_get_user_budget($user_id);
    
    if (!$budget) {
        return false;
    }
    
    return $budget->remaining_budget >= $amount;
}

/**
 * Format budget amount
 *
 * @param float $amount Amount to format
 * @return string Formatted amount
 */
function mdb_format_budget($amount) {
    return wc_price($amount);
}

/**
 * Get dashboard URL for budget
 *
 * @return string URL to budget dashboard
 */
function mdb_get_dashboard_url() {
    return wc_get_account_endpoint_url('dashboard') . '#discount-budget';
}

/**
 * Get admin budget page URL
 *
 * @return string URL to admin budget page
 */
function mdb_get_admin_url() {
    return admin_url('admin.php?page=membership-budget');
}

/**
 * Check if user has an active membership with budget
 *
 * @param int $user_id User ID
 * @return bool True if user has an active membership with budget
 */
function mdb_user_has_budget_membership($user_id) {
    $memberships = wc_memberships_get_user_active_memberships($user_id);
    
    if (empty($memberships)) {
        return false;
    }
    
    $allowed_plans = get_option('mdb_allowed_plans', array());
    
    foreach ($memberships as $membership) {
        if (empty($allowed_plans) || in_array($membership->get_plan_id(), $allowed_plans)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Calculate potential discount for a product
 *
 * @param int $product_id Product ID
 * @param int $user_id User ID
 * @param int $quantity Product quantity
 * @return float Potential discount amount
 */
function mdb_calculate_product_discount($product_id, $user_id, $quantity = 1) {
    $memberships = wc_memberships_get_user_active_memberships($user_id);
    
    if (empty($memberships)) {
        return 0;
    }
    
    $allowed_plans = get_option('mdb_allowed_plans', array());
    $membership_plans = array();
    
    foreach ($memberships as $membership) {
        if (empty($allowed_plans) || in_array($membership->get_plan_id(), $allowed_plans)) {
            $membership_plans[] = $membership->get_plan_id();
        }
    }
    
    if (empty($membership_plans)) {
        return 0;
    }
    
    $product = wc_get_product($product_id);
    
    if (!$product) {
        return 0;
    }
    
    $regular_price = floatval($product->get_regular_price());
    
    if (empty($regular_price) || $regular_price <= 0) {
        return 0;
    }
    
    $discount_percentage = MDB()->budget->get_product_membership_discount($product_id, $membership_plans);
    
    if ($discount_percentage <= 0) {
        return 0;
    }
    
    $discount_amount = $regular_price * ($discount_percentage / 100) * $quantity;
    
    return $discount_amount;
}

/**
 * Get budget reset date
 *
 * @return string Formatted reset date
 */
function mdb_get_reset_date() {
    $next_month = strtotime('first day of next month midnight');
    return date_i18n(get_option('date_format'), $next_month);
}

/**
 * Check if a product has a membership discount
 *
 * @param int $product_id Product ID
 * @return bool True if product has a membership discount
 */
function mdb_product_has_discount($product_id) {
    if (!function_exists('wc_memberships_get_product_purchasing_discount_rules')) {
        return false;
    }
    
    $product_discount_rules = wc_memberships_get_product_purchasing_discount_rules($product_id);
    
    return !empty($product_discount_rules);
}

/**
 * Get membership plans with budgets
 *
 * @return array Array of plan IDs with names
 */
function mdb_get_budget_plans() {
    $allowed_plans = get_option('mdb_allowed_plans', array());
    $plans = array();
    
    if (empty($allowed_plans)) {
        return array();
    }
    
    foreach ($allowed_plans as $plan_id) {
        $plans[$plan_id] = get_the_title($plan_id);
    }
    
    return $plans;
}

/**
 * Clear all budget caches
 */
function mdb_clear_budget_caches() {
    global $wpdb;
    
    $table = $wpdb->prefix . 'membership_discount_budget';
    
    // Get all users with budgets
    $user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM $table");
    
    if (empty($user_ids)) {
        return;
    }
    
    // Clear each user's cache
    foreach ($user_ids as $user_id) {
        $cache_key = 'user_budget_' . $user_id . '_' . date('n') . '_' . date('Y');
        wp_cache_delete($cache_key, 'mdb_budget');
    }
}