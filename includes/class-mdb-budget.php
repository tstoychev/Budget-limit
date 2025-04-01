<?php
/**
 * Budget Class
 *
 * Handles core budget functionality
 *
 * @package Membership Discount Budget
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MDB_Budget Class
 */
class MDB_Budget {

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
        
        // Register hooks
        $this->register_hooks();
    }

    /**
     * Register hooks
     */
    private function register_hooks() {
        // Memberships hooks
        add_action( 'wc_memberships_user_membership_status_changed', array( $this, 'handle_membership_status_change' ), 10, 3 );
        
        // Subscription hooks
        add_action( 'woocommerce_subscription_payment_complete', array( $this, 'handle_subscription_payment' ) );
        
        // Order hooks
        add_action( 'woocommerce_checkout_create_order', array( $this, 'add_budget_data_to_order' ), 10, 2 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'update_budget_on_order_complete' ) );
        
        // Product pricing
        add_filter( 'woocommerce_product_get_price', array( $this, 'maybe_modify_product_price' ), 99, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( $this, 'maybe_modify_product_price' ), 99, 2 );
        
        // AJAX handlers
        add_action( 'wp_ajax_mdb_get_user_budget', array( $this, 'ajax_get_user_budget' ) );
    }
    
    /**
     * Create a new budget for a user
     *
     * @param int $user_id User ID
     * @param int $membership_id Membership ID
     * @return object|bool New budget object or false on failure
     */
    public function create_user_budget( $user_id, $membership_id ) {
        global $wpdb;
        
        $current_month = date( 'n' );
        $current_year = date( 'Y' );
        $now = current_time( 'mysql' );
        $budget_amount = mdb_get_monthly_budget_amount();
        
        // Insert the new budget record
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'user_id' => $user_id,
                'membership_id' => $membership_id,
                'total_budget' => $budget_amount,
                'used_amount' => 0,
                'remaining_budget' => $budget_amount,
                'month' => $current_month,
                'year' => $current_year,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array( '%d', '%d', '%f', '%f', '%f', '%d', '%d', '%s', '%s' )
        );
        
        if ( ! $result ) {
            return false;
        }
        
        // Get the newly created budget
        $budget = $this->get_budget( $wpdb->insert_id );
        
        // Log budget creation
        mdb_log( sprintf( 'Created new budget for user %d with membership %d: %s', 
            $user_id, $membership_id, print_r( $budget, true ) ) );
        
        return $budget;
    }
    
    /**
     * Get a specific budget record
     *
     * @param int $budget_id Budget ID
     * @return object|bool Budget object or false if not found
     */
    public function get_budget( $budget_id ) {
        global $wpdb;
        
        $budget = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $budget_id
        ) );
        
        return $budget;
    }
    
    /**
     * Update a budget record
     *
     * @param int $budget_id Budget ID
     * @param array $data Data to update
     * @return bool Success or failure
     */
    public function update_budget( $budget_id, $data ) {
        global $wpdb;
        
        // Add updated timestamp
        $data['updated_at'] = current_time( 'mysql' );
        
        // Update the budget record
        $result = $wpdb->update(
            $this->table_name,
            $data,
            array( 'id' => $budget_id ),
            array( '%f', '%f', '%f', '%s' ),
            array( '%d' )
        );
        
        // Log budget update
        mdb_log( sprintf( 'Updated budget %d with data: %s', 
            $budget_id, print_r( $data, true ) ) );
        
        return ( false !== $result );
    }
    
    /**
     * Update budget usage
     *
     * @param int $budget_id Budget ID
     * @param float $amount Amount to add to used amount
     * @return bool Success or failure
     */
    public function update_budget_usage( $budget_id, $amount ) {
        global $wpdb;
        
        // Get the current budget
        $budget = $this->get_budget( $budget_id );
        
        if ( ! $budget ) {
            return false;
        }
        
        // Calculate new amounts
        $used_amount = $budget->used_amount + $amount;
        $remaining_budget = $budget->total_budget - $used_amount;
        
        // If remaining would be negative, cap at zero
        if ( $remaining_budget < 0 ) {
            $remaining_budget = 0;
        }
        
        // Update the budget
        return $this->update_budget( $budget_id, array(
            'used_amount' => $used_amount,
            'remaining_budget' => $remaining_budget,
        ) );
    }
    
    /**
     * Reset user budget
     *
     * @param int $user_id User ID
     * @param int $membership_id Membership ID
     * @return object|bool New budget object or false on failure
     */
    public function reset_user_budget( $user_id, $membership_id ) {
        global $wpdb;
        
        // Get current budget
        $current_budget = mdb_get_user_current_budget( $user_id );
        
        // If there's an existing budget, archive it by setting remaining to 0
        if ( $current_budget ) {
            $this->update_budget( $current_budget->id, array(
                'remaining_budget' => 0,
                'used_amount' => $current_budget->total_budget,
            ) );
        }
        
        // Create a new budget
        return $this->create_user_budget( $user_id, $membership_id );
    }
    
    /**
     * Handle membership status change
     *
     * @param \WC_Memberships_User_Membership $user_membership User membership object
     * @param string $old_status Old status
     * @param string $new_status New status
     */
    public function handle_membership_status_change( $user_membership, $old_status, $new_status ) {
        $user_id = $user_membership->get_user_id();
        $membership_id = $user_membership->get_id();
        
        // If membership becomes active, check/create budget
        if ( 'active' === $new_status ) {
            $eligible_plans = mdb_get_eligible_membership_plans();
            
            // Check if this membership plan is eligible
            if ( in_array( $user_membership->get_plan_id(), $eligible_plans ) ) {
                // Get current budget or create new one
                $budget = mdb_get_user_current_budget( $user_id );
                
                if ( ! $budget ) {
                    $this->create_user_budget( $user_id, $membership_id );
                }
            }
        }
        
        // If membership becomes inactive, update budget
        if ( 'active' === $old_status && 'active' !== $new_status ) {
            $budget = mdb_get_user_current_budget( $user_id );
            
            if ( $budget && $budget->membership_id == $membership_id ) {
                // Set remaining budget to 0
                $this->update_budget( $budget->id, array(
                    'remaining_budget' => 0,
                ) );
            }
        }
    }
    
    /**
     * Handle subscription payment
     *
     * @param \WC_Subscription $subscription Subscription object
     */
    public function handle_subscription_payment( $subscription ) {
        $user_id = $subscription->get_user_id();
        
        // Check if user has eligible membership
        $membership = mdb_user_has_eligible_membership( $user_id );
        
        if ( $membership ) {
            // Reset budget on subscription payment
            $this->reset_user_budget( $user_id, $membership->get_id() );
            
            // Log the budget reset
            mdb_log( sprintf( 'Reset budget for user %d due to subscription payment', $user_id ) );
        }
    }
    
    /**
     * Add budget data to order meta
     *
     * @param \WC_Order $order Order object
     * @param array $data Order data
     */
    public function add_budget_data_to_order( $order, $data ) {
        $user_id = $order->get_user_id();
        
        // Only process for logged-in users with memberships
        if ( ! $user_id || ! mdb_user_has_eligible_membership( $user_id ) ) {
            return;
        }
        
        // Get user's current budget
        $budget = mdb_get_user_current_budget( $user_id );
        
        if ( ! $budget ) {
            return;
        }
        
        // Calculate discount amount for the order
        $discount_amount = mdb_calculate_order_discount_amount( $order );
        
        // Store budget info in order meta
        $order->update_meta_data( '_mdb_budget_id', $budget->id );
        $order->update_meta_data( '_mdb_discount_amount', $discount_amount );
        $order->update_meta_data( '_mdb_remaining_budget_before', $budget->remaining_budget );
        
        // Calculate remaining budget after this order
        $remaining_after = $budget->remaining_budget - $discount_amount;
        if ( $remaining_after < 0 ) {
            $remaining_after = 0;
        }
        
        $order->update_meta_data( '_mdb_remaining_budget_after', $remaining_after );
    }
    
    /**
     * Update budget when order is completed
     *
     * @param int $order_id Order ID
     */
    public function update_budget_on_order_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            return;
        }
        
        // Check if this order has budget data
        $budget_id = $order->get_meta( '_mdb_budget_id' );
        $discount_amount = $order->get_meta( '_mdb_discount_amount' );
        
        if ( ! $budget_id || ! $discount_amount ) {
            return;
        }
        
        // Update the budget usage
        $this->update_budget_usage( $budget_id, $discount_amount );
        
        // Log the budget update
        mdb_log( sprintf( 'Updated budget %d after order completion %d with discount amount %f', 
            $budget_id, $order_id, $discount_amount ) );
    }
    
    /**
     * Modify product price based on available budget
     *
     * @param float $price Product price
     * @param \WC_Product $product Product object
     * @return float Modified price
     */
    public function maybe_modify_product_price( $price, $product ) {
        // Only apply for logged in users
        if ( ! is_user_logged_in() ) {
            return $price;
        }
        
        $user_id = get_current_user_id();
        
        // Check if user has eligible membership
        if ( ! mdb_user_has_eligible_membership( $user_id ) ) {
            return $price;
        }
        
        // Check if product is eligible for discount
        if ( ! mdb_is_product_eligible_for_discount( $product->get_id() ) ) {
            return $price;
        }
        
        // Get user's current budget
        $budget = mdb_get_user_current_budget( $user_id );
        
        if ( ! $budget || $budget->remaining_budget <= 0 ) {
            return $price;
        }
        
        // Calculate discount amount
        $discount_percentage = mdb_get_discount_percentage();
        $discount_amount = ( $price * $discount_percentage ) / 100;
        
        // Check if discount exceeds remaining budget
        if ( $discount_amount > $budget->remaining_budget ) {
            // Calculate what percentage we can apply with remaining budget
            $possible_percentage = ( $budget->remaining_budget / $price ) * 100;
            
            // Apply partial discount if possible
            if ( $possible_percentage > 0 ) {
                $discount_amount = ( $price * $possible_percentage ) / 100;
            } else {
                // No discount can be applied
                return $price;
            }
        }
        
        // Apply discount to price
        $discounted_price = $price - $discount_amount;
        
        return $discounted_price;
    }
    
    /**
     * AJAX handler for getting user budget
     */
    public function ajax_get_user_budget() {
        // Check nonce
        if ( ! check_ajax_referer( 'mdb-frontend-nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed', 'membership-discount-budget' ) ) );
        }
        
        // Check if user is logged in
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in to view budget information', 'membership-discount-budget' ) ) );
        }
        
        $user_id = get_current_user_id();
        
        // Get budget
        $budget = mdb_get_user_current_budget( $user_id );
        
        if ( ! $budget ) {
            wp_send_json_error( array( 'message' => __( 'No active budget found', 'membership-discount-budget' ) ) );
        }
        
        // Get subscription info
        $next_payment_date = mdb_get_next_subscription_payment_date( $user_id );
        
        // Prepare response
        $response = array(
            'budget' => $budget,
            'formatted' => array(
                'total_budget' => mdb_format_price( $budget->total_budget ),
                'used_amount' => mdb_format_price( $budget->used_amount ),
                'remaining_budget' => mdb_format_price( $budget->remaining_budget ),
            ),
            'percentage_used' => ( $budget->used_amount / $budget->total_budget ) * 100,
            'percentage_remaining' => ( $budget->remaining_budget / $budget->total_budget ) * 100,
            'is_low' => mdb_is_budget_low( $budget ),
            'next_payment_date' => $next_payment_date ? date_i18n( get_option( 'date_format' ), strtotime( $next_payment_date ) ) : false,
        );
        
        wp_send_json_success( $response );
    }
}
