<?php
/**
 * Helper functions for the plugin.
 *
 * @package Membership_Discount_Budget
 */

defined('ABSPATH') || exit;

/**
 * Get the current user's budget for this month.
 *
 * @param int $user_id Optional. User ID to check. Defaults to current user.
 * @return object|null Budget object or null if not found.
 */
function mdb_get_current_budget($user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    if (!$user_id) {
        return null;
    }

    // Try to get from cache first
    $cache_key = 'mdb_budget_' . $user_id . '_' . current_time('n') . '_' . current_time('Y');
    $budget = wp_cache_get($cache_key);
    
    // If not in cache, get from database
    if (false === $budget) {
        $budget = mdb_get_user_budget($user_id, current_time('n'), current_time('Y'));
        
        // Cache for 5 minutes
        if ($budget) {
            wp_cache_set($cache_key, $budget, '', 300);
        }
    }
    
    return $budget;
}

/**
 * Clear budget cache for a specific user, month, and year.
 *
 * @param int $user_id User ID.
 * @param int $month Month number (1-12).
 * @param int $year Year.
 */
function mdb_clear_budget_cache($user_id, $month, $year) {
    $cache_key = 'mdb_budget_' . $user_id . '_' . $month . '_' . $year;
    wp_cache_delete($cache_key);
}

/**
 * Get a user's budget for a specific month and year.
 *
 * @param int $user_id User ID.
 * @param int $month Month number (1-12).
 * @param int $year Year.
 * @return object|null Budget object or null if not found.
 */
function mdb_get_user_budget($user_id, $month, $year) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'membership_discount_budget';
    
    $budget = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE user_id = %d AND month = %d AND year = %d",
        $user_id, $month, $year
    ));
    
    return $budget;
}

/**
 * Create or update a user's budget.
 *
 * @param array $data Budget data.
 * @return int|false The number of rows updated, or false on error.
 */
/**
 * Create or update a user's budget.
 *
 * @param array $data Budget data.
 * @return int|false The number of rows updated, or false on error.
 */
function mdb_update_budget($data) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'membership_discount_budget';
    
    $defaults = array(
        'user_id'           => 0,
        'membership_id'     => 0,
        'total_budget'      => get_option('mdb_monthly_budget', 300),
        'used_amount'       => 0,
        'remaining_budget'  => get_option('mdb_monthly_budget', 300),
        'month'             => current_time('n'),
        'year'              => current_time('Y'),
        'created_at'        => current_time('mysql'),
        'updated_at'        => current_time('mysql'),
    );
    
    $data = wp_parse_args($data, $defaults);
    
    // Check if record exists
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE user_id = %d AND month = %d AND year = %d",
        $data['user_id'], $data['month'], $data['year']
    ));
    
    if ($exists) {
        // Update
        $data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->update(
            $table_name,
            $data,
            array(
                'id' => $exists,
            ),
            array(
                '%d', // user_id
                '%d', // membership_id
                '%f', // total_budget
                '%f', // used_amount
                '%f', // remaining_budget
                '%d', // month
                '%d', // year
                '%s', // created_at
                '%s', // updated_at
            ),
            array('%d')
        );
    } else {
        // Insert
        $result = $wpdb->insert(
            $table_name,
            $data,
            array(
                '%d', // user_id
                '%d', // membership_id
                '%f', // total_budget
                '%f', // used_amount
                '%f', // remaining_budget
                '%d', // month
                '%d', // year
                '%s', // created_at
                '%s', // updated_at
            )
        );
    }
    
    // Clear cache after update
    mdb_clear_budget_cache($data['user_id'], $data['month'], $data['year']);
    
    return $result;
}

/**
 * Calculate the discount amount for a product.
 *
 * @param float $price Product price.
 * @return float The discount amount.
 */
function mdb_calculate_discount_amount($price) {
    $discount_percentage = get_option('mdb_discount_percentage', 20);
    $discount_amount = ($price * $discount_percentage) / 100;
    return round($discount_amount, 2);
}

/**
 * Check if user has an active membership.
 *
 * @param int $user_id User ID.
 * @return bool True if user has active membership, false otherwise.
 */
function mdb_user_has_membership($user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    if (!$user_id || !function_exists('wc_memberships_is_user_active_member')) {
        return false;
    }

    // Get all active membership plans
    $membership_plans = wc_memberships_get_membership_plans();
    
    // Check if user is an active member of any plan
    foreach ($membership_plans as $plan) {
        if (wc_memberships_is_user_active_member($user_id, $plan->get_id())) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get user's membership ID.
 *
 * @param int $user_id User ID.
 * @return int|false Membership ID or false if not found.
 */
function mdb_get_user_membership_id($user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    if (!$user_id || !function_exists('wc_memberships_get_user_memberships')) {
        return false;
    }

    $memberships = wc_memberships_get_user_memberships($user_id, array('status' => 'active'));
    
    if (!empty($memberships)) {
        $membership = reset($memberships);
        return $membership->get_id();
    }
    
    return false;
}

/**
 * Get next subscription payment date for a user.
 *
 * @param int $user_id User ID.
 * @return string|false Next payment date or false if not found.
 */
function mdb_get_next_payment_date($user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    if (!$user_id || !class_exists('WC_Subscriptions')) {
        return false;
    }

    $subscriptions = wcs_get_users_subscriptions($user_id);
    $next_payment = false;
    
    foreach ($subscriptions as $subscription) {
        if ($subscription->get_status() === 'active') {
            $next_payment = $subscription->get_date('next_payment');
            break;
        }
    }
    
    return $next_payment;
}
/**
 * Get orders with budget usage for a user.
 *
 * @param int $user_id User ID.
 * @return array Orders.
 */
function mdb_get_user_orders_with_budget($user_id) {
    // HPOS compatible way to get orders
    $orders = wc_get_orders(array(
        'customer' => $user_id,
        'limit' => -1,
        'meta_key' => '_mdb_discount_used',
        'meta_compare' => 'EXISTS',
    ));
    
    return $orders;
}
/**
 * Add to mdb-functions.php
 */
function mdb_debug_log($message) {
    if (get_option('mdb_debug_mode', false)) {
        error_log('MDB DEBUG: ' . $message);
    }
}
