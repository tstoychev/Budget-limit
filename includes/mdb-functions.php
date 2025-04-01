<?php
/**
 * Helper Functions
 *
 * @package Membership Discount Budget
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get plugin settings
 *
 * @param string $key Optional. Setting key to retrieve.
 * @return mixed
 */
function mdb_get_settings( $key = '' ) {
    $settings = get_option( 'mdb_settings', array() );
    
    if ( ! empty( $key ) ) {
        return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
    }
    
    return $settings;
}

/**
 * Check if user has active membership
 *
 * @param int $user_id User ID
 * @return bool
 */
function mdb_user_has_active_membership( $user_id ) {
    // Check if WC Memberships is active
    if ( ! function_exists( 'wc_memberships_get_user_memberships' ) ) {
        return false;
    }
    
    // Get user memberships
    $memberships = wc_memberships_get_user_memberships( $user_id );
    
    // Check if there are any active memberships
    foreach ( $memberships as $membership ) {
        if ( $membership->has_status( 'active' ) ) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get active membership plans that are eligible for discount budget
 *
 * @return array
 */
function mdb_get_eligible_membership_plans() {
    $eligible_plans = mdb_get_settings( 'eligible_membership_plans' );
    
    if ( empty( $eligible_plans ) ) {
        // If no plans are specified, include all plans
        $plans = wc_memberships_get_membership_plans();
        $eligible_plans = wp_list_pluck( $plans, 'id' );
    }
    
    return $eligible_plans;
}

/**
 * Check if user has eligible membership for discount budget
 *
 * @param int $user_id User ID
 * @return bool|object False if no eligible membership, otherwise returns the membership object
 */
function mdb_user_has_eligible_membership( $user_id ) {
    // Check if WC Memberships is active
    if ( ! function_exists( 'wc_memberships_get_user_memberships' ) ) {
        return false;
    }
    
    // Get eligible membership plans
    $eligible_plans = mdb_get_eligible_membership_plans();
    
    // Get user memberships
    $memberships = wc_memberships_get_user_memberships( $user_id );
    
    // Check if there are any active eligible memberships
    foreach ( $memberships as $membership ) {
        if ( $membership->has_status( 'active' ) && in_array( $membership->get_plan_id(), $eligible_plans ) ) {
            return $membership;
        }
    }
    
    return false;
}

/**
 * Get user's current discount budget
 *
 * @param int $user_id User ID
 * @return object|bool Budget object or false if no budget found
 */
function mdb_get_user_current_budget( $user_id ) {
    global $wpdb;
    
    $current_month = date( 'n' );
    $current_year = date( 'Y' );
    
    // Get current budget
    $budget = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}membership_discount_budget 
        WHERE user_id = %d AND month = %d AND year = %d 
        ORDER BY id DESC LIMIT 1",
        $user_id, $current_month, $current_year
    ) );
    
    return $budget;
}

/**
 * Get or create user's current budget
 *
 * @param int $user_id User ID
 * @return object|bool Budget object or false if no eligible membership
 */
function mdb_get_or_create_user_budget( $user_id ) {
    // Check if user has current budget
    $budget = mdb_get_user_current_budget( $user_id );
    
    if ( $budget ) {
        return $budget;
    }
    
    // No budget exists, check if user has eligible membership
    $membership = mdb_user_has_eligible_membership( $user_id );
    
    if ( ! $membership ) {
        return false;
    }
    
    // Create new budget
    $budget_obj = new MDB_Budget();
    $new_budget = $budget_obj->create_user_budget( $user_id, $membership->get_id() );
    
    return $new_budget;
}

/**
 * Calculate discount amount for an order
 *
 * @param WC_Order $order Order object
 * @return float Discount amount
 */
function mdb_calculate_order_discount_amount( $order ) {
    $discount_amount = 0;
    $discount_percentage = mdb_get_settings( 'discount_percentage' );
    
    // Calculate discount from each line item
    foreach ( $order->get_items() as $item ) {
        $line_subtotal = $item->get_subtotal();
        $item_discount = ( $line_subtotal * $discount_percentage ) / 100;
        $discount_amount += $item_discount;
    }
    
    return round( $discount_amount, 2 );
}

/**
 * Format price for display
 *
 * @param float $price Price to format
 * @return string Formatted price
 */
function mdb_format_price( $price ) {
    return wc_price( $price );
}

/**
 * Check if budget is low
 *
 * @param object $budget Budget object
 * @return bool
 */
function mdb_is_budget_low( $budget ) {
    $threshold_percentage = mdb_get_settings( 'low_budget_threshold_percentage' );
    
    if ( ! $threshold_percentage ) {
        $threshold_percentage = 10;
    }
    
    // Calculate threshold amount
    $threshold_amount = ( $budget->total_budget * $threshold_percentage ) / 100;
    
    // Check if remaining budget is less than threshold
    return ( $budget->remaining_budget <= $threshold_amount );
}

/**
 * Get next subscription payment date
 *
 * @param int $user_id User ID
 * @return string|bool Next payment date or false if no subscription
 */
function mdb_get_next_subscription_payment_date( $user_id ) {
    // Check if WC Subscriptions is active
    if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
        return false;
    }
    
    // Get user's active subscriptions
    $subscriptions = wcs_get_users_subscriptions( $user_id );
    
    foreach ( $subscriptions as $subscription ) {
        if ( $subscription->has_status( 'active' ) ) {
            $next_payment = $subscription->get_date( 'next_payment' );
            
            if ( ! empty( $next_payment ) ) {
                return $next_payment;
            }
        }
    }
    
    return false;
}

/**
 * Get membership discount percentage
 *
 * @return float Discount percentage
 */
function mdb_get_discount_percentage() {
    return (float) mdb_get_settings( 'discount_percentage' );
}

/**
 * Get total monthly budget amount
 *
 * @return float Budget amount
 */
function mdb_get_monthly_budget_amount() {
    return (float) mdb_get_settings( 'monthly_budget' );
}

/**
 * Check if a product is eligible for discount
 *
 * @param int $product_id Product ID
 * @return bool
 */
function mdb_is_product_eligible_for_discount( $product_id ) {
    // By default, all products are eligible
    // You can add custom logic here to exclude certain products
    
    $is_eligible = true;
    
    // Allow filtering product eligibility
    return apply_filters( 'mdb_product_eligible_for_discount', $is_eligible, $product_id );
}

/**
 * Log debug messages
 *
 * @param string $message Message to log
 */
function mdb_log( $message ) {
    if ( 'yes' === mdb_get_settings( 'debug_mode' ) ) {
        if ( is_array( $message ) || is_object( $message ) ) {
            $message = print_r( $message, true );
        }
        
        error_log( 'MDB: ' . $message );
    }
}
