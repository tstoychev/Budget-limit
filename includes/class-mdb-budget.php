<?php
/**
 * Core budget functionality.
 *
 * @package Membership_Discount_Budget
 */

defined('ABSPATH') || exit;

/**
 * MDB_Budget Class.
 */
class MDB_Budget {
    /**
     * Single instance of the class.
     *
     * @var MDB_Budget
     */
    protected static $_instance = null;

    /**
     * Main class instance.
     *
     * @return MDB_Budget
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
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Apply discount for members
        add_filter('woocommerce_product_get_price', array($this, 'apply_member_discount'), 999, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'apply_member_discount'), 999, 2);

        // Save discount information to order
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_discount_data_to_order_item'), 10, 4);
        
        // Process order completion
        add_action('woocommerce_order_status_completed', array($this, 'process_completed_order'));
        
        // Clear cache for HPOS compatibility
        add_action('woocommerce_after_order_object_save', array($this, 'clear_cache_after_order_save'));
        
        // Membership and Subscription hooks
        add_action('wc_memberships_user_membership_status_changed', array($this, 'membership_status_changed'), 10, 3);
        add_action('woocommerce_subscription_status_updated', array($this, 'subscription_status_updated'), 10, 3);
        
        // Reset budget on subscription payment
        add_action('woocommerce_subscription_payment_complete', array($this, 'reset_budget_on_payment'));

        //Add this to the init_hooks method in class-mdb-budget.php:
         add_filter('woocommerce_product_get_price', array($this, 'enforce_regular_price_for_exhausted_budget'), 1000, 2);
         add_filter('woocommerce_product_variation_get_price', array($this, 'enforce_regular_price_for_exhausted_budget'), 1000, 2);

        //Add this to the init_hooks method in class-mdb-budget.php:
         add_action('wp', array($this, 'check_budget_status_for_session'), 10);
 
        // Add this line at the end of the init_hooks method
        add_action('woocommerce_before_calculate_totals', array($this, 'refresh_prices_after_budget_update'));
    }

   /**
 * Apply discount to product price for members.
 *
 * @param float $price Product price.
 * @param WC_Product $product Product object.
 * @return float Modified price.
 */
public function apply_member_discount($price, $product) {
    // Skip if not on frontend or price is empty
    if (is_admin() || '' === $price) {
        return $price;
    }

    // Skip if cart is being calculated or on checkout
    if (defined('WOOCOMMERCE_CHECKOUT') || WC()->session->get('mdb_checking_budget')) {
        return $price;
    }

    // Get current user ID
    $user_id = get_current_user_id();
    if (!$user_id) {
        return $price;
    }

    // Debug logging
    mdb_debug_log("Product price hook start for Product ID " . $product->get_id() . " (" . $product->get_name() . "), Regular price: " . wc_price($price) . ", User ID: " . $user_id);

    // Check if user has active membership
    if (!mdb_user_has_membership($user_id)) {
        mdb_debug_log("User ID " . $user_id . " has no active membership");
        return $price;
    }

    // Get current budget directly from database to avoid caching issues
    $budget = mdb_get_user_budget($user_id, current_time('n'), current_time('Y'));
    
    // If no budget exists, create one
    if (!$budget) {
        $membership_id = mdb_get_user_membership_id($user_id);
        
        if ($membership_id) {
            mdb_update_budget(array(
                'user_id' => $user_id,
                'membership_id' => $membership_id,
            ));
            
            $budget = mdb_get_user_budget($user_id, current_time('n'), current_time('Y'));
        }
    }

    // Debug log the budget status
    if ($budget) {
        mdb_debug_log("Budget check for User ID " . $user_id . ": Total: " . wc_price($budget->total_budget) . ", Used: " . wc_price($budget->used_amount) . ", Remaining: " . wc_price($budget->remaining_budget));
    } else {
        mdb_debug_log("No budget found for User ID " . $user_id);
    }

    // Strict budget check: Only apply discount if budget exists AND remaining budget is > 0
    if ($budget && $budget->remaining_budget > 0) {
        $discount_amount = mdb_calculate_discount_amount($price);
        
        // Check if we have enough budget for the full discount
        if ($discount_amount <= $budget->remaining_budget) {
            // We have enough budget, apply full discount
            $discounted_price = $price - $discount_amount;
            
            // Store discount data temporarily for later use
            if (WC()->session) {
                WC()->session->set('product_' . $product->get_id() . '_discount', array(
                    'original_price' => $price,
                    'discount_amount' => $discount_amount,
                    'discounted_price' => $discounted_price,
                ));
            }
            
            mdb_debug_log("Discount applied for Product ID " . $product->get_id() . ": Regular price: " . wc_price($price) . ", Discounted price: " . wc_price($discounted_price) . ", Discount: " . wc_price($discount_amount));
            
            return $discounted_price;
        } else {
            // Not enough remaining budget for full discount
            mdb_debug_log("Not enough budget for Product ID " . $product->get_id() . " - Required: " . wc_price($discount_amount) . ", Available: " . wc_price($budget->remaining_budget));
            
            // Clear any previously stored discount data for this product
            if (WC()->session) {
                WC()->session->__unset('product_' . $product->get_id() . '_discount');
            }
        }
    } else {
        // Budget is exhausted or non-existent
        mdb_debug_log("Budget is exhausted or non-existent for User ID " . $user_id);
        
        // Clear any previously stored discount data for this product
        if (WC()->session) {
            WC()->session->__unset('product_' . $product->get_id() . '_discount');
        }
    }
    
    // No discount applied
    mdb_debug_log("No discount applied for Product ID " . $product->get_id() . " - Returning regular price: " . wc_price($price));
    return $price;
}

    /**
     * Add discount data to order line item.
     *
     * @param WC_Order_Item_Product $item Order item.
     * @param string $cart_item_key Cart item key.
     * @param array $values Cart item values.
     * @param WC_Order $order Order.
     */
    public function add_discount_data_to_order_item($item, $cart_item_key, $values, $order) {
    $product_id = $item->get_product_id();
    $discount_data = WC()->session->get('product_' . $product_id . '_discount');
    
    if ($discount_data) {
        // HPOS compatible way to add meta data
        $item->update_meta_data('_mdb_original_price', $discount_data['original_price']);
        $item->update_meta_data('_mdb_discount_amount', $discount_data['discount_amount']);
        $item->update_meta_data('_mdb_discounted_price', $discount_data['discounted_price']);
        $item->save_meta_data(); // Save meta data
        
        // Clear session data
        WC()->session->__unset('product_' . $product_id . '_discount');
    }
}

/**
 * Process completed order to update budget usage.
 *
 * @param int $order_id Order ID.
 */
public function process_completed_order($order_id) {
    // Get order - HPOS compatible way
    $order = wc_get_order($order_id);
    
    if (!$order) {
        return;
    }
    
    // Get customer ID - HPOS compatible
    $user_id = $order->get_customer_id();
    
    if (!$user_id || !mdb_user_has_membership($user_id)) {
        return;
    }
    
    // Get current budget
    $budget = mdb_get_user_budget($user_id, current_time('n'), current_time('Y'));
    
    if (!$budget) {
        return;
    }
    
    // Calculate total discount used in this order
    $total_discount = 0;
    
    foreach ($order->get_items() as $item) {
        $discount_amount = $item->get_meta('_mdb_discount_amount');
        
        if ($discount_amount) {
            $quantity = $item->get_quantity();
            $total_discount += ($discount_amount * $quantity);
        }
    }
    
    if ($total_discount > 0) {
        // Update budget usage
        $new_used_amount = $budget->used_amount + $total_discount;
        $new_remaining_budget = max(0, $budget->total_budget - $new_used_amount);
        
        // Log budget update for debugging
        if (get_option('mdb_debug_mode', false)) {
            error_log(sprintf(
                'Budget update for user #%d - Order #%d - Previous: %f - Used: %f - New Total: %f - Remaining: %f',
                $user_id,
                $order_id,
                $budget->used_amount,
                $total_discount,
                $new_used_amount,
                $new_remaining_budget
            ));
        }
        
        mdb_update_budget(array(
            'id' => $budget->id,
            'user_id' => $user_id,
            'membership_id' => $budget->membership_id,
            'total_budget' => $budget->total_budget,
            'used_amount' => $new_used_amount,
            'remaining_budget' => $new_remaining_budget,
            'month' => $budget->month,
            'year' => $budget->year,
        ));
        
        // Add budget usage to order meta - HPOS compatible
        $order->update_meta_data('_mdb_discount_used', $total_discount);
        $order->update_meta_data('_mdb_remaining_budget', $new_remaining_budget);
        $order->save();
        
        // Clear any cached product prices for this user
        WC()->session->set('mdb_budget_updated', true);
    }
}

    /**
     * Clear cache after order save for HPOS compatibility.
     *
     * @param WC_Order $order Order object.
     */
    public function clear_cache_after_order_save($order) {
        if (method_exists($order, 'get_id')) {
            wp_cache_delete('order-items-' . $order->get_id(), 'orders');
        }
    }

    /**
     * Handle membership status changes.
     *
     * @param WC_Memberships_User_Membership $membership The membership.
     * @param string $old_status Previous status.
     * @param string $new_status New status.
     */
    public function membership_status_changed($membership, $old_status, $new_status) {
        $user_id = $membership->get_user_id();
        
        if ('active' === $new_status) {
            // Membership activated, create or update budget
            $budget = mdb_get_current_budget($user_id);
            
            if (!$budget) {
                mdb_update_budget(array(
                    'user_id' => $user_id,
                    'membership_id' => $membership->get_id(),
                ));
            }
        }
    }

    /**
     * Handle subscription status changes.
     *
     * @param WC_Subscription $subscription The subscription.
     * @param string $old_status Previous status.
     * @param string $new_status New status.
     */
    public function subscription_status_updated($subscription, $old_status, $new_status) {
        if ('active' === $new_status) {
            $user_id = $subscription->get_user_id();
            
            if ($user_id && mdb_user_has_membership($user_id)) {
                // Subscription activated, reset budget
                $this->reset_user_budget($user_id);
            }
        }
    }

    /**
     * Reset budget when subscription payment is completed.
     *
     * @param WC_Subscription $subscription The subscription.
     */
    public function reset_budget_on_payment($subscription) {
        $user_id = $subscription->get_user_id();
        
        if ($user_id && mdb_user_has_membership($user_id)) {
            $this->reset_user_budget($user_id);
        }
    }

    /**
     * Reset a user's budget.
     *
     * @param int $user_id User ID.
     */
    private function reset_user_budget($user_id) {
        $membership_id = mdb_get_user_membership_id($user_id);
        
        if ($membership_id) {
            $monthly_budget = get_option('mdb_monthly_budget', 300);
            
            mdb_update_budget(array(
                'user_id' => $user_id,
                'membership_id' => $membership_id,
                'total_budget' => $monthly_budget,
                'used_amount' => 0,
                'remaining_budget' => $monthly_budget,
                'month' => current_time('n'),
                'year' => current_time('Y'),
            ));
        }
    }
/**
 * Ensure that regular prices are used when budget is exhausted.
 * This is a safety check that runs after all other price filters.
 * 
 * @param float $price Product price.
 * @param WC_Product $product Product object.
 * @return float Modified price.
 */
public function enforce_regular_price_for_exhausted_budget($price, $product) {
    // Skip if not on frontend
    if (is_admin()) {
        return $price;
    }
    
    // Get current user ID
    $user_id = get_current_user_id();
    if (!$user_id) {
        return $price;
    }
    
    // Check if user has active membership
    if (!mdb_user_has_membership($user_id)) {
        return $price;
    }
    
    // Get current budget directly from database to avoid caching issues
    $budget = mdb_get_user_budget($user_id, current_time('n'), current_time('Y'));
    
    // If budget is exhausted, ensure the regular price is used
    if (!$budget || $budget->remaining_budget <= 0) {
        // Get the regular price directly
        $regular_price = $product->get_regular_price();
        
        // If the current price is less than the regular price, it's discounted
        if ($price < $regular_price) {
            mdb_debug_log("Enforcing regular price for Product ID " . $product->get_id() . " due to exhausted budget - Current price: " . wc_price($price) . ", Regular price: " . wc_price($regular_price));
            return $regular_price;
        }
    }
    
    return $price;
}

/**
 * Check budget status and clear session if budget is exhausted.
 */
public function check_budget_status_for_session() {
    // Skip if not on frontend or user not logged in
    if (is_admin() || !is_user_logged_in()) {
        return;
    }
    
    // Get current user ID
    $user_id = get_current_user_id();
    
    // Check if user has active membership
    if (!mdb_user_has_membership($user_id)) {
        return;
    }
    
    // Get current budget directly from database to avoid caching issues
    $budget = mdb_get_user_budget($user_id, current_time('n'), current_time('Y'));
    
    // If budget is exhausted, clear all discount sessions
    if (!$budget || $budget->remaining_budget <= 0) {
        mdb_debug_log("Budget exhausted for User ID " . $user_id . " - Clearing all discount sessions");
        
        if (WC()->session) {
            // Get all session data
            $session_data = WC()->session->get_session_data();
            
            // Find and clear all discount-related session data
            foreach ($session_data as $key => $value) {
                if (strpos($key, '_discount') !== false) {
                    WC()->session->__unset($key);
                }
            }
            
            // Set a flag to indicate budget is exhausted
            WC()->session->set('mdb_budget_exhausted', true);
        }
    } else {
        // Budget is not exhausted, clear the exhausted flag if it exists
        if (WC()->session && WC()->session->get('mdb_budget_exhausted')) {
            WC()->session->__unset('mdb_budget_exhausted');
        }
    }
}
    /**
 * Refreshes prices after budget is updated
 */
public function refresh_prices_after_budget_update($cart) {
    if (WC()->session && WC()->session->get('mdb_budget_updated')) {
        // Clear the flag
        WC()->session->set('mdb_budget_updated', false);
        
        // Loop through cart items and remove any cached discount data
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            WC()->session->__unset('product_' . $product_id . '_discount');
        }
        
        // Force WooCommerce to recalculate prices
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $cart_item['data']->set_price($cart_item['data']->get_regular_price());
        }
    }
}
}
