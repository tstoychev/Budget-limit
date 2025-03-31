<?php
/**
 * Frontend handler class
 * 
 * @package Membership_Discount_Budget
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles frontend functionality
 */
class MDB_Frontend {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add budget info on My Account page
        add_action('woocommerce_before_my_account', array($this, 'display_discount_budget_status'));
        
        // Add dashboard widget to My Account
        add_action('woocommerce_account_dashboard', array($this, 'add_account_dashboard_widget'));
        
        // Display budget info on cart page
        $cart_position = get_option('mdb_cart_notice_position', 'before_cart');
        add_action('woocommerce_' . $cart_position, array($this, 'display_discount_budget_status_cart'));
        
        // Add budget usage to order details page
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_budget_usage_in_order'));
    }
    
    /**
     * Display discount budget status on My Account page
     */
    public function display_discount_budget_status() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $memberships = wc_memberships_get_user_active_memberships($user_id);
        
        if (empty($memberships)) {
            return;
        }
        
        // Check if user's membership plan is allowed
        $allowed_plans = get_option('mdb_allowed_plans', array());
        $user_has_allowed_plan = false;
        
        foreach ($memberships as $membership) {
            if (empty($allowed_plans) || in_array($membership->get_plan_id(), $allowed_plans)) {
                $user_has_allowed_plan = true;
                break;
            }
        }
        
        if (!$user_has_allowed_plan) {
            return;
        }
        
        // Get current budget data
        $budget_data = MDB()->budget->get_user_current_budget($user_id);
        
        if (!$budget_data) {
            return;
        }
        
        echo '<div class="mdb-budget-info">';
        echo '<h2>' . esc_html__('Membership Discount Budget', 'membership-discount-budget') . '</h2>';
        echo '<p>';
        echo sprintf(
            __('Your monthly discount budget: %s used of %s total. Remaining budget: %s', 'membership-discount-budget'),
            '<strong>' . wc_price($budget_data->used_amount) . '</strong>',
            '<strong>' . wc_price($budget_data->total_budget) . '</strong>',
            '<strong>' . wc_price($budget_data->remaining_budget) . '</strong>'
        );
        echo '</p>';
        
        // Add progress bar
        $percentage = $budget_data->total_budget > 0 ? ($budget_data->used_amount / $budget_data->total_budget) * 100 : 0;
        echo '<div class="mdb-progress-container">';
        echo '<div class="mdb-progress-bar" style="width: ' . esc_attr(min(100, $percentage)) . '%"></div>';
        echo '</div>';
        
        // Add reset date info
        echo '<p class="mdb-reset-info">';
        echo esc_html__('Your budget will reset on the first day of next month.', 'membership-discount-budget');
        echo '</p>';
        
        echo '</div>';
    }
    
    /**
     * Add dashboard widget to My Account
     */
    public function add_account_dashboard_widget() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $memberships = wc_memberships_get_user_active_memberships($user_id);
        
        if (empty($memberships)) {
            return;
        }
        
        // Check if user's membership plan is allowed
        $allowed_plans = get_option('mdb_allowed_plans', array());
        $user_has_allowed_plan = false;
        
        foreach ($memberships as $membership) {
            if (empty($allowed_plans) || in_array($membership->get_plan_id(), $allowed_plans)) {
                $user_has_allowed_plan = true;
                break;
            }
        }
        
        if (!$user_has_allowed_plan) {
            return;
        }
        
        // Get current budget data
        $budget_data = MDB()->budget->get_user_current_budget($user_id);
        
        if (!$budget_data) {
            return;
        }
        
        // Get recent orders where budget was used
        $recent_orders = $this->get_recent_budget_orders($user_id);
        
        ?>
        <div class="mdb-dashboard-widget">
            <h3><?php esc_html_e('Discount Budget Summary', 'membership-discount-budget'); ?></h3>
            
            <div class="mdb-dashboard-stats">
                <div class="mdb-stat">
                    <span class="mdb-stat-label"><?php esc_html_e('Total Budget', 'membership-discount-budget'); ?></span>
                    <span class="mdb-stat-value"><?php echo wc_price($budget_data->total_budget); ?></span>
                </div>
                
                <div class="mdb-stat">
                    <span class="mdb-stat-label"><?php esc_html_e('Used', 'membership-discount-budget'); ?></span>
                    <span class="mdb-stat-value"><?php echo wc_price($budget_data->used_amount); ?></span>
                </div>
                
                <div class="mdb-stat">
                    <span class="mdb-stat-label"><?php esc_html_e('Remaining', 'membership-discount-budget'); ?></span>
                    <span class="mdb-stat-value"><?php echo wc_price($budget_data->remaining_budget); ?></span>
                </div>
            </div>
            
            <?php if (!empty($recent_orders)) : ?>
                <h4><?php esc_html_e('Recent Discount Usage', 'membership-discount-budget'); ?></h4>
                <ul class="mdb-recent-orders">
                    <?php foreach ($recent_orders as $order) : ?>
                        <li>
                            <?php 
                            echo sprintf(
                                /* translators: 1: order number 2: order date 3: order status 4: discount amount */
                                __('Order #%1$s (%2$s): %3$s', 'membership-discount-budget'),
                                '<a href="' . esc_url($order->get_view_order_url()) . '">' . $order->get_order_number() . '</a>',
                                date_i18n(get_option('date_format'), $order->get_date_created()->getTimestamp()),
                                wc_price($order->get_meta('_membership_discount_budget_used'))
                            ); 
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <div class="mdb-dashboard-footer">
                <p class="mdb-reset-info">
                    <?php esc_html_e('Your budget will reset on the first day of next month.', 'membership-discount-budget'); ?>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get recent orders where budget was used
     *
     * @param int $user_id User ID
     * @return array Array of WC_Order objects
     */
    private function get_recent_budget_orders($user_id) {
        $orders = wc_get_orders(array(
            'limit' => 5,
            'customer_id' => $user_id,
            'status' => array('wc-completed'),
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        
        $budget_orders = array();
        
        foreach ($orders as $order) {
            $discount_used = $order->get_meta('_membership_discount_budget_used');
            
            if (!empty($discount_used) && $discount_used > 0) {
                $budget_orders[] = $order;
            }
            
            if (count($budget_orders) >= 3) {
                break;
            }
        }
        
        return $budget_orders;
    }
    
    /**
     * Display discount budget status on cart page
     */
    public function display_discount_budget_status_cart() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        $memberships = wc_memberships_get_user_active_memberships($user_id);
        
        if (empty($memberships)) {
            return;
        }
        
        // Check if user's membership plan is allowed
        $allowed_plans = get_option('mdb_allowed_plans', array());
        $user_has_allowed_plan = false;
        
        foreach ($memberships as $membership) {
            if (empty($allowed_plans) || in_array($membership->get_plan_id(), $allowed_plans)) {
                $user_has_allowed_plan = true;
                break;
            }
        }
        
        if (!$user_has_allowed_plan) {
            return;
        }
        
        // Get current budget data
        $budget_data = MDB()->budget->get_user_current_budget($user_id);
        
        if (!$budget_data) {
            return;
        }
        
        // Calculate potential discount in current cart
        $cart_discount = MDB()->budget->calculate_potential_cart_discount();
        $remaining_after_purchase = max(0, $budget_data->remaining_budget - $cart_discount);
        
        echo '<div class="woocommerce-info mdb-cart-notice">';
        echo sprintf(
            __('Your membership discount budget: %s remaining. This order will use %s of your budget, leaving %s.', 'membership-discount-budget'),
            '<strong>' . wc_price($budget_data->remaining_budget) . '</strong>',
            '<strong>' . wc_price($cart_discount) . '</strong>',
            '<strong>' . wc_price($remaining_after_purchase) . '</strong>'
        );
        
        // Add progress bar
        $percentage = $budget_data->total_budget > 0 ? (($budget_data->used_amount + $cart_discount) / $budget_data->total_budget) * 100 : 0;
        echo '<div class="mdb-progress-container">';
        echo '<div class="mdb-progress-bar" style="width: ' . esc_attr(min(100, $percentage)) . '%"></div>';
        echo '</div>';
        
        if ($cart_discount > $budget_data->remaining_budget) {
            echo '<p class="woocommerce-error mdb-budget-error">';
            echo esc_html__('Warning: The discount in your cart exceeds your remaining budget. Please remove some items or contact an administrator.', 'membership-discount-budget');
            echo '</p>';
        }
        
        echo '</div>';
    }
    
    /**
     * Display budget usage in order details
     *
     * @param WC_Order $order Order object
     */
    public function display_budget_usage_in_order($order) {
        if (!is_user_logged_in()) {
            return;
        }
        
        $user_id = get_current_user_id();
        
        // Only show for the order owner
        if ($order->get_user_id() !== $user_id) {
            return;
        }
        
        $discount_used = $order->get_meta('_membership_discount_budget_used');
        
        if (empty($discount_used) || $discount_used <= 0) {
            return;
        }
        
        // Get current budget data
        $budget_data = MDB()->budget->get_user_current_budget($user_id);
        
        ?>
        <section class="woocommerce-order-budget-details">
            <h2><?php esc_html_e('Membership Discount Budget', 'membership-discount-budget'); ?></h2>
            
            <table class="woocommerce-table woocommerce-table--budget-details shop_table budget_details">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Discount Used:', 'membership-discount-budget'); ?></th>
                        <td><?php echo wc_price($discount_used); ?></td>
                    </tr>
                    
                    <?php if ($budget_data) : ?>
                    <tr>
                        <th><?php esc_html_e('Current Remaining Budget:', 'membership-discount-budget'); ?></th>
                        <td><?php echo wc_price($budget_data->remaining_budget); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <?php
    }
}