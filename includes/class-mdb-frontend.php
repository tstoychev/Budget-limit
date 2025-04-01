<?php
/**
 * Frontend Class
 *
 * Handles front-end display
 *
 * @package Membership Discount Budget
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MDB_Frontend Class
 */
class MDB_Frontend {

    /**
     * Constructor
     */
    public function __construct() {
        // Register hooks
        $this->register_hooks();
    }

    /**
     * Register hooks
     */
    private function register_hooks() {
        // My Account page
        add_action( 'woocommerce_account_dashboard', array( $this, 'show_budget_info_on_account' ) );
        
        // Cart page
        add_action( 'woocommerce_before_cart_table', array( $this, 'show_budget_info_on_cart' ) );
        add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'show_discount_budget_in_cart_totals' ) );
        
        // Checkout page
        add_action( 'woocommerce_before_checkout_form', array( $this, 'show_budget_info_on_checkout' ), 10 );
        
        // Order pages
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'show_budget_info_on_order' ), 10, 1 );
        
        // Add notices
        add_action( 'template_redirect', array( $this, 'add_budget_notices' ) );
    }
    
    /**
     * Display budget information on My Account page
     */
    public function show_budget_info_on_account() {
        // Check if display is enabled
        if ( 'yes' !== mdb_get_settings( 'display_budget_on_account' ) ) {
            return;
        }
        
        // Only show for logged in users with eligible membership
        if ( ! is_user_logged_in() || ! mdb_user_has_eligible_membership( get_current_user_id() ) ) {
            return;
        }
        
        // Get user's current budget
        $budget = mdb_get_user_current_budget( get_current_user_id() );
        
        if ( ! $budget ) {
            return;
        }
        
        // Get subscription info
        $next_payment_date = mdb_get_next_subscription_payment_date( get_current_user_id() );
        
        // Calculate percentages
        $percentage_used = ( $budget->used_amount / $budget->total_budget ) * 100;
        $percentage_remaining = ( $budget->remaining_budget / $budget->total_budget ) * 100;
        $is_low = mdb_is_budget_low( $budget );
        
        // Display budget information
        wc_get_template(
            'membership-budget-account.php',
            array(
                'budget' => $budget,
                'formatted_total' => mdb_format_price( $budget->total_budget ),
                'formatted_used' => mdb_format_price( $budget->used_amount ),
                'formatted_remaining' => mdb_format_price( $budget->remaining_budget ),
                'percentage_used' => $percentage_used,
                'percentage_remaining' => $percentage_remaining,
                'is_low' => $is_low,
                'next_payment_date' => $next_payment_date ? date_i18n( get_option( 'date_format' ), strtotime( $next_payment_date ) ) : false,
            ),
            '',
            MDB_PLUGIN_DIR . 'templates/'
        );
    }
    
    /**
     * Display budget information on cart page
     */
    public function show_budget_info_on_cart() {
        // Check if display is enabled
        if ( 'yes' !== mdb_get_settings( 'display_budget_on_cart' ) ) {
            return;
        }
        
        // Only show for logged in users with eligible membership
        if ( ! is_user_logged_in() || ! mdb_user_has_eligible_membership( get_current_user_id() ) ) {
            return;
        }
        
        // Get user's current budget
        $budget = mdb_get_user_current_budget( get_current_user_id() );
        
        if ( ! $budget ) {
            return;
        }
        
        // Get cart discount amount
        $cart_total = WC()->cart->get_subtotal();
        $discount_percentage = mdb_get_discount_percentage();
        $potential_discount = ( $cart_total * $discount_percentage ) / 100;
        
        // Check if discount would exceed remaining budget
        $would_exceed = ( $potential_discount > $budget->remaining_budget );
        $available_discount = $would_exceed ? $budget->remaining_budget : $potential_discount;
        
        // Display budget information
        wc_get_template(
            'membership-budget-cart.php',
            array(
                'budget' => $budget,
                'formatted_total' => mdb_format_price( $budget->total_budget ),
                'formatted_used' => mdb_format_price( $budget->used_amount ),
                'formatted_remaining' => mdb_format_price( $budget->remaining_budget ),
                'potential_discount' => $potential_discount,
                'formatted_potential_discount' => mdb_format_price( $potential_discount ),
                'would_exceed' => $would_exceed,
                'available_discount' => $available_discount,
                'formatted_available_discount' => mdb_format_price( $available_discount ),
            ),
            '',
            MDB_PLUGIN_DIR . 'templates/'
        );
    }
    
    /**
     * Display discount budget information in cart totals
     */
    public function show_discount_budget_in_cart_totals() {
        // Only show for logged in users with eligible membership
        if ( ! is_user_logged_in() || ! mdb_user_has_eligible_membership( get_current_user_id() ) ) {
            return;
        }
        
        // Get user's current budget
        $budget = mdb_get_user_current_budget( get_current_user_id() );
        
        if ( ! $budget ) {
            return;
        }
        
        // Get cart discount amount
        $cart_total = WC()->cart->get_subtotal();
        $discount_percentage = mdb_get_discount_percentage();
        $potential_discount = ( $cart_total * $discount_percentage ) / 100;
        
        // Check if discount would exceed remaining budget
        $would_exceed = ( $potential_discount > $budget->remaining_budget );
        $available_discount = $would_exceed ? $budget->remaining_budget : $potential_discount;
        
        // Display in cart totals
        ?>
        <tr class="mdb-discount-budget">
            <th><?php _e( 'Membership Discount', 'membership-discount-budget' ); ?></th>
            <td>-<?php echo mdb_format_price( $available_discount ); ?></td>
        </tr>
        <?php
    }
    
    /**
     * Display budget information on checkout page
     */
    public function show_budget_info_on_checkout() {
        // Only show for logged in users with eligible membership
        if ( ! is_user_logged_in() || ! mdb_user_has_eligible_membership( get_current_user_id() ) ) {
            return;
        }
        
        // Get user's current budget
        $budget = mdb_get_user_current_budget( get_current_user_id() );
        
        if ( ! $budget ) {
            return;
        }
        
        // Get cart discount amount
        $cart_total = WC()->cart->get_subtotal();
        $discount_percentage = mdb_get_discount_percentage();
        $potential_discount = ( $cart_total * $discount_percentage ) / 100;
        
        // Check if discount would exceed remaining budget
        $would_exceed = ( $potential_discount > $budget->remaining_budget );
        $available_discount = $would_exceed ? $budget->remaining_budget : $potential_discount;
        
        // Display budget information
        wc_get_template(
            'membership-budget-checkout.php',
            array(
                'budget' => $budget,
                'formatted_remaining' => mdb_format_price( $budget->remaining_budget ),
                'potential_discount' => $potential_discount,
                'formatted_potential_discount' => mdb_format_price( $potential_discount ),
                'would_exceed' => $would_exceed,
                'available_discount' => $available_discount,
                'formatted_available_discount' => mdb_format_price( $available_discount ),
            ),
            '',
            MDB_PLUGIN_DIR . 'templates/'
        );
    }
    
    /**
     * Display budget information on order page
     *
     * @param \WC_Order $order Order object
     */
    public function show_budget_info_on_order( $order ) {
        // Only show for the user who owns the order
        if ( ! is_user_logged_in() || $order->get_user_id() !== get_current_user_id() ) {
            return;
        }
        
        // Check if order has budget data
        $budget_id = $order->get_meta( '_mdb_budget_id' );
        $discount_amount = $order->get_meta( '_mdb_discount_amount' );
        $remaining_before = $order->get_meta( '_mdb_remaining_budget_before' );
        $remaining_after = $order->get_meta( '_mdb_remaining_budget_after' );
        
        if ( ! $budget_id || ! $discount_amount ) {
            return;
        }
        
        // Display budget information
        wc_get_template(
            'membership-budget-order.php',
            array(
                'order' => $order,
                'discount_amount' => $discount_amount,
                'formatted_discount' => mdb_format_price( $discount_amount ),
                'remaining_before' => $remaining_before,
                'formatted_before' => mdb_format_price( $remaining_before ),
                'remaining_after' => $remaining_after,
                'formatted_after' => mdb_format_price( $remaining_after ),
            ),
            '',
            MDB_PLUGIN_DIR . 'templates/'
        );
    }
    
    /**
     * Add notices about budget status
     */
    public function add_budget_notices() {
        // Only for logged in users
        if ( ! is_user_logged_in() ) {
            return;
        }
        
        // Only on cart, checkout, and my account pages
        if ( ! is_cart() && ! is_checkout() && ! is_account_page() ) {
            return;
        }
        
        // Check if user has eligible membership
        if ( ! mdb_user_has_eligible_membership( get_current_user_id() ) ) {
            return;
        }
        
        // Get user's current budget
        $budget = mdb_get_user_current_budget( get_current_user_id() );
        
        if ( ! $budget ) {
            return;
        }
        
        // Check if budget is low or depleted
        if ( $budget->remaining_budget <= 0 ) {
            wc_add_notice( __( 'Your membership discount budget is depleted. You will be charged regular prices until your budget resets.', 'membership-discount-budget' ), 'notice' );
        } elseif ( mdb_is_budget_low( $budget ) ) {
            wc_add_notice( sprintf( 
                __( 'Your membership discount budget is running low. You have %s remaining.', 'membership-discount-budget' ),
                mdb_format_price( $budget->remaining_budget )
            ), 'notice' );
        }
        
        // On cart and checkout, check if current order would exceed budget
        if ( ( is_cart() || is_checkout() ) && WC()->cart ) {
            $cart_total = WC()->cart->get_subtotal();
            $discount_percentage = mdb_get_discount_percentage();
            $potential_discount = ( $cart_total * $discount_percentage ) / 100;
            
            // Check if discount would exceed remaining budget
            if ( $potential_discount > $budget->remaining_budget && $budget->remaining_budget > 0 ) {
                wc_add_notice( sprintf( 
                    __( 'This purchase would exceed your remaining discount budget of %s. Partial discount will be applied, and the rest will be charged at regular price.', 'membership-discount-budget' ),
                    mdb_format_price( $budget->remaining_budget )
                ), 'notice' );
            }
        }
    }
}
