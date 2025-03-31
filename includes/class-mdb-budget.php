<?php
/**
 * Budget handler class
 * 
 * @package Membership_Discount_Budget
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles budget operations
 */
class MDB_Budget {
    
    /**
     * Cache group
     *
     * @var string
     */
    private $cache_group = 'mdb_budget';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize budget when membership becomes active
        add_action('wc_memberships_user_membership_status_changed', array($this, 'handle_membership_status_change'), 10, 3);
        
        // Track discounts after order is completed or processed
        add_action('woocommerce_order_status_completed', array($this, 'track_discount_usage'));
        add_action('woocommerce_order_status_processing', array($this, 'track_discount_usage'));
        
        // Monthly reset
        add_action('mdb_monthly_reset', array($this, 'reset_active_memberships_budget'));
        
        // Budget validation
        add_action('woocommerce_before_cart', array($this, 'display_budget_warning_on_cart'));
        add_action('woocommerce_before_checkout_form', array($this, 'display_budget_warning_on_cart')); // Add to checkout too
        add_action('woocommerce_before_checkout_process', array($this, 'check_discount_budget_before_checkout'));
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_add_to_cart'), 10, 3);
        
        // Filter price with lower priority to prevent recursion issues
        add_filter('woocommerce_product_get_price', array($this, 'adjust_price_based_on_budget'), 999, 2);
        add_action('woocommerce_before_calculate_totals', array($this, 'adjust_cart_prices'), 20);
    }

    /**
     * Adjust product price based on available budget
     *
     * @param float $price Product price
     * @param object $product WC_Product object
     * @return float Modified price
     */
    public function adjust_price_based_on_budget($price, $product) {
        // Prevent infinite loops and recursion
        static $is_adjusting_price = false;
        if ($is_adjusting_price) {
            return $price;
        }
        
        $is_adjusting_price = true;
        
        // Only apply for logged-in users
        if (!is_user_logged_in()) {
            $is_adjusting_price = false;
            return $price;
        }
        
        $user_id = get_current_user_id();
        $budget_data = $this->get_user_current_budget($user_id);
        
        // If no budget or budget exhausted, return regular price
        if (!$budget_data || $budget_data->remaining_budget <= 0) {
            $regular_price = $product->get_regular_price();
            $is_adjusting_price = false;
            return $regular_price;
        }
        
        $is_adjusting_price = false;
        return $price;
    }
    
    /**
     * Adjust cart prices based on available budget
     *
     * @param WC_Cart $cart Cart object
     */
    public function adjust_cart_prices($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        if (!is_user_logged_in() || empty($cart->get_cart())) {
            return;
        }
        
        $user_id = get_current_user_id();
        $budget_data = $this->get_user_current_budget($user_id);
        
        // If no budget or budget exhausted, set all prices to regular
        if (!$budget_data || $budget_data->remaining_budget <= 0) {
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                $regular_price = $product->get_regular_price();
                if ($regular_price) {
                    $product->set_price($regular_price);
                }
            }
        }
    }

    /**
     * Display budget warning on cart page
     */
    public function display_budget_warning_on_cart() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $memberships = wc_memberships_get_user_memberships(array(
            'user_id' => $user_id,
            'status' => 'active'
        ));
        
        if (empty($memberships)) {
            return;
        }
        
        // Check if user's membership plan is allowed
        $allowed_plans = get_option('mdb_allowed_plans', array());
        $user_has_allowed_plan = false;
        $membership_plans = array();
        
        foreach ($memberships as $membership) {
            if (empty($allowed_plans) || in_array($membership->get_plan_id(), (array) $allowed_plans)) {
                $user_has_allowed_plan = true;
                $membership_plans[] = $membership->get_plan_id();
            }
        }
        
        if (!$user_has_allowed_plan) {
            return;
        }
        
        // Get current budget data
        $budget_data = $this->get_user_current_budget($user_id);
        
        if (!$budget_data) {
            // Try to initialize budget if it doesn't exist
            foreach ($memberships as $membership) {
                $this->initialize_user_budget($membership);
            }
            
            // Check again after initialization
            $budget_data = $this->get_user_current_budget($user_id);
            
            if (!$budget_data) {
                return;
            }
        }
        
        // Calculate potential discount in current cart
        $cart_discount = $this->calculate_potential_cart_discount();
        
        // Check if discount exceeds remaining budget
        if ($cart_discount > $budget_data->remaining_budget) {
            wc_add_notice(
                sprintf(
                    __('Budget Warning: Your order contains %s in membership discounts, but you only have %s remaining in your discount budget. Some items may be charged at full price.', 'membership-discount-budget'),
                    wc_price($cart_discount),
                    wc_price($budget_data->remaining_budget)
                ),
                'notice'
            );
        }
    }
    
    /**
     * Calculate potential cart discount with budget tracking
     */
    public function calculate_potential_cart_discount() {
        if (!is_user_logged_in() || !WC()->cart || WC()->cart->is_empty()) {
            return 0;
        }
        
        $user_id = get_current_user_id();
        $memberships = wc_memberships_get_user_memberships(array(
            'user_id' => $user_id,
            'status' => 'active'
        ));
        
        if (empty($memberships)) {
            return 0;
        }
        
        // Get current budget data
        $budget_data = $this->get_user_current_budget($user_id);
        
        if (!$budget_data || $budget_data->remaining_budget <= 0) {
            return 0;
        }
        
        // Check if user's membership plan is allowed
        $allowed_plans = get_option('mdb_allowed_plans', array());
        $user_has_allowed_plan = false;
        $membership_plans = array();
        
        foreach ($memberships as $membership) {
            if (empty($allowed_plans) || in_array($membership->get_plan_id(), (array) $allowed_plans)) {
                $user_has_allowed_plan = true;
                $membership_plans[] = $membership->get_plan_id();
            }
        }
        
        if (!$user_has_allowed_plan) {
            return 0;
        }
        
        $discount_amount = 0;
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            
            if (!$product) {
                continue;
            }
            
            // Get regular price
            $regular_price = floatval($product->get_regular_price());
            
            // Skip if no regular price is set
            if (empty($regular_price) || $regular_price <= 0) {
                continue;
            }
            
            // Get membership discount for this product
            $discount_percentage = $this->get_product_membership_discount($product->get_id(), $membership_plans);
            
            if ($discount_percentage > 0) {
                // Calculate the discount amount based on the regular price and percentage
                $item_discount = $regular_price * ($discount_percentage / 100) * $cart_item['quantity'];
                
                $discount_amount += $item_discount;
            }
        }
        
        // Limit discount to remaining budget
        $discount_amount = min($discount_amount, $budget_data->remaining_budget);
        
        return $discount_amount;
    }
    
    /**
     * Check discount budget before checkout
     */
    public function check_discount_budget_before_checkout() {
        if (!is_user_logged_in() || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $memberships = wc_memberships_get_user_memberships(array(
            'user_id' => $user_id,
            'status' => 'active'
        ));
        
        if (empty($memberships)) {
            return;
        }
        
        // Get current budget data
        $budget_data = $this->get_user_current_budget($user_id);
        
        if (!$budget_data || $budget_data->remaining_budget <= 0) {
            // Add a notice that no discounts are available
            wc_add_notice(
                __('Your monthly discount budget has been exhausted. All products will be charged at full price.', 'membership-discount-budget'),
                'notice'
            );
            return;
        }
        
        // Check if user's membership plan is allowed
        $allowed_plans = get_option('mdb_allowed_plans', array());
        $user_has_allowed_plan = false;
        
        foreach ($memberships as $membership) {
            if (empty($allowed_plans) || in_array($membership->get_plan_id(), (array) $allowed_plans)) {
                $user_has_allowed_plan = true;
                break;
            }
        }
        
        if (!$user_has_allowed_plan) {
            return;
        }
        
        // Calculate potential discount in current cart
        $cart_discount = $this->calculate_potential_cart_discount();
        
        // Add a notice about the available budget
        wc_add_notice(
            sprintf(
                __('Available Discount Budget: %s of your %s monthly budget remains.', 'membership-discount-budget'),
                wc_price($budget_data->remaining_budget),
                wc_price($budget_data->total_budget)
            ),
            'notice'
        );
    }
    
    /**
     * Validate adding items to cart
     */
    public function validate_add_to_cart($passed, $product_id, $quantity) {
        if (!is_user_logged_in() || !$passed) {
            return $passed;
        }
        
        $user_id = get_current_user_id();
        $memberships = wc_memberships_get_user_memberships(array(
            'user_id' => $user_id,
            'status' => 'active'
        ));
        
        if (empty($memberships)) {
            return $passed;
        }
        
        // Get current budget data
        $budget_data = $this->get_user_current_budget($user_id);
        
        if (!$budget_data || $budget_data->remaining_budget <= 0) {
            // No budget available, show a warning
            wc_add_notice(
                __('Your monthly discount budget has been exhausted. Products will be charged at full price.', 'membership-discount-budget'),
                'notice'
            );
            return $passed;
        }
        
        // Check if user's membership plan is allowed
        $allowed_plans = get_option('mdb_allowed_plans', array());
        $user_has_allowed_plan = false;
        $membership_plans = array();
        
        foreach ($memberships as $membership) {
            if (empty($allowed_plans) || in_array($membership->get_plan_id(), (array) $allowed_plans)) {
                $user_has_allowed_plan = true;
                $membership_plans[] = $membership->get_plan_id();
            }
        }
        
        if (!$user_has_allowed_plan) {
            return $passed;
        }
        
        // Get product
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return $passed;
        }
        
        // Get regular price
        $regular_price = floatval($product->get_regular_price());
        
        // Skip if no regular price is set
        if (empty($regular_price) || $regular_price <= 0) {
            return $passed;
        }
        
        // Get membership discount for this product
        $discount_percentage = $this->get_product_membership_discount($product_id, $membership_plans);
        
        if ($discount_percentage <= 0) {
            return $passed;
        }
        
        // Calculate new item discount
        $new_item_discount = $regular_price * ($discount_percentage / 100) * $quantity;
        
        // Calculate current cart discount
        $current_cart_discount = $this->calculate_potential_cart_discount();
        
        // Check if adding this item would exceed budget
        $total_discount = $current_cart_discount + $new_item_discount;
        
        if ($total_discount > $budget_data->remaining_budget) {
            wc_add_notice(
                sprintf(
                    __('Budget Notice: Adding this product would exceed your remaining discount budget of %s. The product will be added at full price.', 'membership-discount-budget'),
                    wc_price($budget_data->remaining_budget)
                ),
                'notice'
            );
        }
        
        return $passed;
    }

    /**
     * Handle membership status change
     *
     * @param WC_Memberships_User_Membership $user_membership The user membership
     * @param string $old_status Old status
     * @param string $new_status New status
     */
    public function handle_membership_status_change($user_membership, $old_status, $new_status) {
        // Only initialize budget when membership becomes active
        if ($new_status === 'active') {
            $this->initialize_user_budget($user_membership);
        }
    }
    
    /**
     * Initialize user budget
     *
     * @param WC_Memberships_User_Membership $user_membership The user membership
     * @return bool|int Result of the initialization
     */
    public function initialize_user_budget($user_membership) {
        // Check if this membership plan should have a budget
        $allowed_plans = get_option('mdb_allowed_plans', array());
        
        if (!empty($allowed_plans) && !in_array($user_membership->get_plan_id(), (array) $allowed_plans)) {
            return false;
        }
        
        global $wpdb;
        
        $user_id = $user_membership->get_user_id();
        $membership_id = $user_membership->get_id();
        
        // Get monthly budget amount from settings
        $monthly_budget = get_option('mdb_monthly_budget', 200.00);
        
        // Allow other plugins to filter the budget amount
        $monthly_budget = apply_filters('mdb_monthly_budget_amount', $monthly_budget, $user_id, $membership_id);
        
        $month = date('n');
        $year = date('Y');
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        // Check if the user already has a budget for this month
        $existing_budget = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND month = %d AND year = %d",
            $user_id, $month, $year
        ));
        
        if ($existing_budget) {
            // Budget already exists for this month, no need to initialize
            return false;
        }
        
        // Handle carryover if enabled
        $carryover_amount = 0;
        
        if (get_option('mdb_carryover_budget', false)) {
            // Get previous month's budget
            $prev_month = $month == 1 ? 12 : $month - 1;
            $prev_year = $month == 1 ? $year - 1 : $year;
            
            $prev_budget = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE user_id = %d AND month = %d AND year = %d",
                $user_id, $prev_month, $prev_year
            ));
            
            if ($prev_budget) {
                $carryover_amount = $prev_budget->remaining_budget;
            }
        }
        
        // Allow plugins to modify carryover amount
        $carryover_amount = apply_filters('mdb_carryover_amount', $carryover_amount, $user_id, $membership_id);
        
        // Initialize budget for current month
        $total_budget = $monthly_budget + $carryover_amount;
        
        // Action before budget creation
        do_action('mdb_before_budget_creation', $user_id, $membership_id, $total_budget);
        
        $result = $wpdb->insert($table, [
            'user_id' => $user_id,
            'membership_id' => $membership_id,
            'total_budget' => $total_budget,
            'used_amount' => 0.00,
            'remaining_budget' => $total_budget,
            'month' => $month,
            'year' => $year
        ], ['%d', '%d', '%f', '%f', '%f', '%d', '%d']);
        
        // Clear cached budget data
        $this->clear_user_budget_cache($user_id);
        
        // Action after budget creation
        do_action('mdb_after_budget_creation', $user_id, $membership_id, $total_budget, $wpdb->insert_id);
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Reset budget for all active memberships (monthly cron)*/
    public function reset_active_memberships_budget() {
        global $wpdb;
        
        // Get monthly budget amount from settings
        $monthly_budget = get_option('mdb_monthly_budget', 200.00);
        
        // Get all active memberships
        $memberships = wc_memberships_get_user_memberships([
            'status' => 'active',
        ]);
        
        $month = date('n');
        $year = date('Y');
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        // Store reset info for logging
        $reset_count = 0;
        $create_count = 0;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($memberships as $membership) {
                $user_id = $membership->get_user_id();
                $membership_id = $membership->get_id();
                $plan_id = $membership->get_plan_id();
                
                // Check if this plan should have a budget
                $allowed_plans = get_option('mdb_allowed_plans', array());
                if (!empty($allowed_plans) && !in_array($plan_id, (array) $allowed_plans)) {
                    continue;
                }
                
                // Allow plugins to filter the budget amount per user
                $user_monthly_budget = apply_filters('mdb_monthly_budget_amount', $monthly_budget, $user_id, $membership_id);
                
                // Handle carryover if enabled
                $carryover_amount = 0;
                
                if (get_option('mdb_carryover_budget', false)) {
                    // Get previous month's budget
                    $prev_month = $month == 1 ? 12 : $month - 1;
                    $prev_year = $month == 1 ? $year - 1 : $year;
                    
                    $prev_budget = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $table WHERE user_id = %d AND month = %d AND year = %d",
                        $user_id, $prev_month, $prev_year
                    ));
                    
                    if ($prev_budget) {
                        $carryover_amount = $prev_budget->remaining_budget;
                    }
                }
                
                // Allow plugins to modify carryover amount
                $carryover_amount = apply_filters('mdb_carryover_amount', $carryover_amount, $user_id, $membership_id);
                
                // Calculate total budget
                $total_budget = $user_monthly_budget + $carryover_amount;
                
                // Check if budget already exists for this month
                $existing_budget = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table WHERE user_id = %d AND month = %d AND year = %d",
                    $user_id, $month, $year
                ));
                
                // Action before budget reset/creation
                do_action('mdb_before_budget_reset', $user_id, $membership_id, $total_budget, $existing_budget);
                
                if ($existing_budget) {
                    // Update existing budget
                    $wpdb->update(
                        $table,
                        [
                            'total_budget' => $total_budget,
                            'used_amount' => 0.00,
                            'remaining_budget' => $total_budget,
                        ],
                        ['id' => $existing_budget->id],
                        ['%f', '%f', '%f'],
                        ['%d']
                    );
                    $reset_count++;
                } else {
                    // Create new budget entry
                    $wpdb->insert($table, [
                        'user_id' => $user_id,
                        'membership_id' => $membership_id,
                        'total_budget' => $total_budget,
                        'used_amount' => 0.00,
                        'remaining_budget' => $total_budget,
                        'month' => $month,
                        'year' => $year
                    ], ['%d', '%d', '%f', '%f', '%f', '%d', '%d']);
                    $create_count++;
                }
                
                // Clear cached budget data
                $this->clear_user_budget_cache($user_id);
                
                // Action after budget reset/creation
                do_action('mdb_after_budget_reset', $user_id, $membership_id, $total_budget);
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            error_log('MDB ERROR: Failed to reset budgets: ' . $e->getMessage());
        }
    }
    
    /**
     * Track discount usage when order is completed
     *
     * @param int $order_id Order ID
     */
    public function track_discount_usage($order_id) {
        error_log('MDB DEBUG: Processing order #' . $order_id . ' for budget tracking');
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            error_log('MDB DEBUG: Order #' . $order_id . ' not found');
            return;
        }
        
        $user_id = $order->get_user_id();
        
        if (!$user_id) {
            error_log('MDB DEBUG: Order #' . $order_id . ' has no user ID');
            return;
        }
        
        $memberships = wc_memberships_get_user_memberships(array(
            'user_id' => $user_id,
            'status' => 'active'
        ));
        
        if (empty($memberships)) {
            error_log('MDB DEBUG: User ID ' . $user_id . ' has no active memberships');
            return;
        }
        
        // Check if user's membership plan is allowed
        $allowed_plans = get_option('mdb_allowed_plans', array());
        $user_has_allowed_plan = false;
        $membership_plans = array();
        
        foreach ($memberships as $membership) {
            $plan_id = $membership->get_plan_id();
            if (empty($allowed_plans) || in_array($plan_id, (array) $allowed_plans)) {
                $user_has_allowed_plan = true;
                $membership_plans[] = $plan_id;
                error_log('MDB DEBUG: User ID ' . $user_id . ' has allowed plan: ' . $plan_id);
            }
        }
        
        if (!$user_has_allowed_plan) {
            error_log('MDB DEBUG: User ID ' . $user_id . ' has no allowed membership plans');
            return;
        }
        
        // Get current budget data
        $budget_data = $this->get_user_current_budget($user_id);
        
        if (!$budget_data) {
            error_log('MDB DEBUG: User ID ' . $user_id . ' has no budget data, trying to initialize');
            // Try to initialize budget for the first membership
            $this->initialize_user_budget($memberships[0]);
            $budget_data = $this->get_user_current_budget($user_id);
            
            if (!$budget_data) {
                error_log('MDB DEBUG: Failed to initialize budget for User ID ' . $user_id);
                return;
            }
        }
        
        // Calculate order discount
        $order_discount = $this->calculate_order_discount($order, $membership_plans);
        error_log('MDB DEBUG: Order #' . $order_id . ' calculated discount: ' . $order_discount);
        
        // Limit the discount to remaining budget
        $actual_discount = min($order_discount, $budget_data->remaining_budget);
        error_log('MDB DEBUG: Order #' . $order_id . ' actual discount (limited by budget): ' . $actual_discount);
        
        // Skip if no discount to apply
        if ($actual_discount <= 0) {
            error_log('MDB DEBUG: Order #' . $order_id . ' has no discount to apply');
            return;
        }
        
        // Action before updating budget usage
        do_action('mdb_before_discount_usage_update', $user_id, $order_id, $actual_discount, $budget_data);
        
        // Update budget usage
        global $wpdb;
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        $new_used_amount = min($budget_data->total_budget, $budget_data->used_amount + $actual_discount);
        $new_remaining_budget = max(0, $budget_data->total_budget - $new_used_amount);
        
        $result = $wpdb->update(
            $table,
            [
                'used_amount' => $new_used_amount,
                'remaining_budget' => $new_remaining_budget
            ],
            ['id' => $budget_data->id],
            ['%f', '%f'],
            ['%d']
        );
        
        if ($result) {
            error_log('MDB DEBUG: Successfully updated budget for User ID ' . $user_id . ', new used: ' . $new_used_amount . ', new remaining: ' . $new_remaining_budget);
        } else {
            error_log('MDB DEBUG: Failed to update budget for User ID ' . $user_id);
        }
        
        // Clear cached budget data
        $this->clear_user_budget_cache($user_id);
        
        // Add order meta to track budget usage
        $order->update_meta_data('_membership_discount_budget_used', $actual_discount);
        $order->save();
        
        error_log('MDB DEBUG: Added meta to Order #' . $order_id . ' with discount amount: ' . $actual_discount);
        
        // Action after updating budget usage
        do_action('mdb_after_discount_usage_update', $user_id, $order_id, $actual_discount, $new_remaining_budget);
    }
    
    /**
     * Calculate order discount amount
     *
     * @param WC_Order $order The order object
     * @param array $membership_plans Array of membership plan IDs
     * @return float The total discount amount
     */
    public function calculate_order_discount($order, $membership_plans) {
        error_log('MDB DEBUG: Calculating order discount for Order #' . $order->get_id());
        
        $order_discount = 0;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product = wc_get_product($product_id);
            
            if (!$product) {
                continue;
            }
            
            // Get regular price
            $regular_price = floatval($product->get_regular_price());
            $sale_price = floatval($product->get_sale_price());
            $actual_price = floatval($item->get_subtotal() / $item->get_quantity());
            
            error_log('MDB DEBUG: Item: ' . $product->get_name() . ', Regular: ' . $regular_price . ', Sale: ' . $sale_price . ', Actual: ' . $actual_price);
            
            // Skip if no regular price is set
            if (empty($regular_price) || $regular_price <= 0) {
                continue;
            }
            
            // Get membership discount for this product
            $discount_percentage = $this->get_product_membership_discount($product_id, $membership_plans);
            error_log('MDB DEBUG: Product ID ' . $product_id . ' discount percentage: ' . $discount_percentage);
            
            if ($discount_percentage > 0) {
                // Calculate the discount amount
                $item_discount = $regular_price * ($discount_percentage / 100) * $item->get_quantity();
                
                // Allow plugins to modify the discount amount
                $item_discount = apply_filters('mdb_order_item_discount', $item_discount, $product_id, $item, $discount_percentage);
                
                error_log('MDB DEBUG: Item discount: ' . $item_discount);
                $order_discount += $item_discount;
            }
        }
        
        // Allow plugins to modify the total order discount
        $final_discount = apply_filters('mdb_order_total_discount', $order_discount, $order, $membership_plans);
        error_log('MDB DEBUG: Total order discount: ' . $final_discount);
        
        return $final_discount;
    }
    
    /**
    * Get the membership discount percentage for a specific product
     * 
     * @param int $product_id The product ID
     * @param array $membership_plans Array of membership plan IDs
     * @return float The discount percentage (0-100)
     */
    public function get_product_membership_discount($product_id, $membership_plans) {
        // Debug output
        error_log("MDB DEBUG: Checking discount for product ID $product_id with plans: " . print_r($membership_plans, true));
        
        // Cache key
        $cache_key = 'product_discount_' . $product_id . '_' . md5(serialize($membership_plans));
        $discount_percentage = wp_cache_get($cache_key, $this->cache_group);
        
        if (false !== $discount_percentage) {
            return $discount_percentage;
        }
        
        // Default discount percentage
        $discount_percentage = 0;
        
        // Bail if no membership plans
        if (empty($membership_plans)) {
            error_log("MDB DEBUG: No membership plans provided");
            return $discount_percentage;
        }
        
        // Check if WC Memberships has the required function
        if (!function_exists('wc_memberships_get_product_purchasing_discount_rules')) {
            error_log("MDB DEBUG: Function wc_memberships_get_product_purchasing_discount_rules not found");
            return $discount_percentage;
        }
        
        // Get all discount rules that apply to this product
        $product_discount_rules = wc_memberships_get_product_purchasing_discount_rules($product_id);
        
        if (empty($product_discount_rules)) {
            error_log("MDB DEBUG: No discount rules found for product ID $product_id");
            wp_cache_set($cache_key, $discount_percentage, $this->cache_group, 3600);
            return $discount_percentage;
        }
        
        error_log("MDB DEBUG: Found " . count($product_discount_rules) . " discount rules for product ID $product_id");
        
        // Find the highest discount percentage that applies to the user's membership plans
        foreach ($product_discount_rules as $rule) {
            $rule_plan_id = $rule->get_membership_plan_id();
            error_log("MDB DEBUG: Rule plan ID: $rule_plan_id, discount: " . $rule->get_discount_amount());
            
            if (in_array($rule_plan_id, $membership_plans)) {
                $rule_discount = $rule->get_discount_amount();
                
                // Always use the highest discount percentage
                if ($rule_discount > $discount_percentage) {
                    $discount_percentage = $rule_discount;
                    error_log("MDB DEBUG: Using discount: $discount_percentage%");
                }
            }
        }
        
        // Allow plugins to modify the discount percentage
        $discount_percentage = apply_filters('mdb_product_discount_percentage', $discount_percentage, $product_id, $membership_plans);
        
        // Cache the result
        wp_cache_set($cache_key, $discount_percentage, $this->cache_group, 3600);
        
        return $discount_percentage;
    }
    
    /**
     * Get user's current budget
     *
     * @param int $user_id User ID
     * @return object|bool Budget data or false if not found
     */
    public function get_user_current_budget($user_id) {
        // Try to get from cache first
        $cache_key = 'user_budget_' . $user_id . '_' . date('n') . '_' . date('Y');
        $budget_data = wp_cache_get($cache_key, $this->cache_group);
        
        if (false !== $budget_data) {
            return $budget_data;
        }
        
        global $wpdb;
        
        $month = date('n');
        $year = date('Y');
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        $budget_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND month = %d AND year = %d",
            $user_id, $month, $year
        ));
        
        if ($budget_data) {
            wp_cache_set($cache_key, $budget_data, $this->cache_group, 3600);
        }
        
        return $budget_data;
    }
    
    /**
     * Update user budget
     *
     * @param int $budget_id Budget ID
     * @param float $new_budget New budget amount
     * @return bool Update success
     */
    public function update_user_budget($budget_id, $new_budget) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        $budget = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $budget_id
        ));
        
        if (!$budget) {
            return false;
        }
        
        // Action before budget update
        do_action('mdb_before_budget_update', $budget->user_id, $budget->remaining_budget, $new_budget, $budget_id);
        
        // Update used amount based on the new remaining budget
        $used_amount = $budget->total_budget - $new_budget;
        
        $result = $wpdb->update(
            $table,
            [
                'used_amount' => $used_amount,
                'remaining_budget' => $new_budget
            ],
            ['id' => $budget_id],
            ['%f', '%f'],
            ['%d']
        );
        
        if ($result) {
            // Clear cache
            $this->clear_user_budget_cache($budget->user_id);
            
            // Action after budget update
            do_action('mdb_after_budget_update', $budget->user_id, $new_budget, $budget_id);
        }
        
        return $result;
    }
    
    /**
     * Reset user budget to default
     *
     * @param int $budget_id Budget ID
     * @return bool Reset success
     */
    public function reset_user_budget($budget_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        $budget = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $budget_id
        ));
        
        if (!$budget) {
            return false;
        }
        
        // Action before budget reset
        do_action('mdb_before_budget_reset_admin', $budget->user_id, $budget_id);
        
        // Get monthly budget amount
        $monthly_budget = get_option('mdb_monthly_budget', 200.00);
        
        // Allow plugins to filter the budget amount
        $monthly_budget = apply_filters('mdb_monthly_budget_amount', $monthly_budget, $budget->user_id, $budget->membership_id);
        
        $result = $wpdb->update(
            $table,
            [
                'total_budget' => $monthly_budget,
                'used_amount' => 0.00,
                'remaining_budget' => $monthly_budget
            ],
            ['id' => $budget_id],
            ['%f', '%f', '%f'],
            ['%d']
        );
        
        if ($result) {
            // Clear cache
            $this->clear_user_budget_cache($budget->user_id);
            
            // Action after budget reset
            do_action('mdb_after_budget_reset_admin', $budget->user_id, $monthly_budget, $budget_id);
        }
        
        return $result;
    }
    
    /**
     * Clear user budget cache
     *
     * @param int $user_id User ID
     */
    private function clear_user_budget_cache($user_id) {
        $cache_key = 'user_budget_' . $user_id . '_' . date('n') . '_' . date('Y');
        wp_cache_delete($cache_key, $this->cache_group);
    }
}
