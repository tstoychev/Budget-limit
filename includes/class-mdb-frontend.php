<?php
/**
 * Frontend functionality.
 *
 * @package Membership_Discount_Budget
 */

defined('ABSPATH') || exit;

/**
 * MDB_Frontend Class.
 */
class MDB_Frontend {
    /**
     * Single instance of the class.
     *
     * @var MDB_Frontend
     */
    protected static $_instance = null;

    /**
     * Main class instance.
     *
     * @return MDB_Frontend
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
        // Display budget information on My Account page
        add_action('woocommerce_account_dashboard', array($this, 'display_budget_info'));
        
        // Add budget tab to My Account
        add_filter('woocommerce_account_menu_items', array($this, 'add_budget_account_menu_item'));
        add_action('woocommerce_account_discount-budget_endpoint', array($this, 'budget_account_content'));
        add_action('init', array($this, 'add_budget_endpoint'));
        
        // Add budget information to cart page
        add_action('woocommerce_before_cart', array($this, 'display_cart_budget_info'));
        
        // Add budget information to order details
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_order_budget_info'));
        
        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }

    /**
     * Add budget endpoint for My Account page.
     */
    public function add_budget_endpoint() {
        add_rewrite_endpoint('discount-budget', EP_ROOT | EP_PAGES);}

    /**
     * Add budget menu item to My Account menu.
     *
     * @param array $menu_items Menu items.
     * @return array Modified menu items.
     */
    public function add_budget_account_menu_item($menu_items) {
        // Add the budget item after the dashboard
        $new_menu_items = array();
        
        foreach ($menu_items as $key => $value) {
            $new_menu_items[$key] = $value;
            
            if ('dashboard' === $key) {
                $new_menu_items['discount-budget'] = __('Discount Budget', 'membership-discount-budget');
            }
        }
        
        return $new_menu_items;
    }

    /**
     * Display budget information on the My Account dashboard.
     */
    public function display_budget_info() {
        if (!mdb_user_has_membership()) {
            return;
        }
        
        $budget = mdb_get_current_budget();
        
        if (!$budget) {
            return;
        }
        
        $next_payment = mdb_get_next_payment_date();
        $percentage = ($budget->remaining_budget / $budget->total_budget) * 100;
        
        ?>
        <div class="mdb-budget-summary">
            <h3><?php _e('Your Discount Budget', 'membership-discount-budget'); ?></h3>
            
            <div class="mdb-budget-info">
                <div class="mdb-budget-details">
                    <div class="mdb-budget-item">
                        <span class="mdb-label"><?php _e('Total Monthly Budget:', 'membership-discount-budget'); ?></span>
                        <span class="mdb-value"><?php echo wc_price($budget->total_budget); ?></span>
                    </div>
                    
                    <div class="mdb-budget-item">
                        <span class="mdb-label"><?php _e('Used Amount:', 'membership-discount-budget'); ?></span>
                        <span class="mdb-value"><?php echo wc_price($budget->used_amount); ?></span>
                    </div>
                    
                    <div class="mdb-budget-item">
                        <span class="mdb-label"><?php _e('Remaining Budget:', 'membership-discount-budget'); ?></span>
                        <span class="mdb-value"><?php echo wc_price($budget->remaining_budget); ?></span>
                    </div>
                    
                    <?php if ($next_payment) : ?>
                        <div class="mdb-budget-item">
                            <span class="mdb-label"><?php _e('Next Reset Date:', 'membership-discount-budget'); ?></span>
                            <span class="mdb-value"><?php echo date_i18n(get_option('date_format'), strtotime($next_payment)); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="mdb-budget-progress-container">
                    <div class="mdb-budget-progress">
                        <div class="mdb-budget-progress-bar" style="width: <?php echo esc_attr($percentage); ?>%"></div>
                    </div>
                    <div class="mdb-budget-percentage"><?php echo sprintf(__('%s remaining', 'membership-discount-budget'), round($percentage) . '%'); ?></div>
                </div>
            </div>
            
            <div class="mdb-budget-info-text">
                <p><?php _e('As a member, you get a 20% discount on all products up to your monthly budget limit. Once your budget is used up, products will be available at regular prices.', 'membership-discount-budget'); ?></p>
                <p><?php _e('Your budget will be reset automatically on your next subscription payment date.', 'membership-discount-budget'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Display budget account content.
     */
    public function budget_account_content() {
        if (!mdb_user_has_membership()) {
            echo '<p>' . __('You do not have an active membership with a discount budget.', 'membership-discount-budget') . '</p>';
            return;
        }
        
        $budget = mdb_get_current_budget();
        
        if (!$budget) {
            echo '<p>' . __('No budget information available.', 'membership-discount-budget') . '</p>';
            return;
        }
        
        $next_payment = mdb_get_next_payment_date();
        $percentage = ($budget->remaining_budget / $budget->total_budget) * 100;
        $discount_percentage = get_option('mdb_discount_percentage', 20);
        
        // Get recent orders with budget usage
        $orders = wc_get_orders(array(
            'customer' => get_current_user_id(),
            'limit' => 5,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_mdb_discount_used',
        ));
        
        ?>
        <h2><?php _e('Your Discount Budget', 'membership-discount-budget'); ?></h2>
        
        <div class="mdb-budget-dashboard">
            <div class="mdb-budget-summary-card">
                <h3><?php _e('Budget Overview', 'membership-discount-budget'); ?></h3>
                
                <div class="mdb-budget-details">
                    <div class="mdb-budget-item">
                        <span class="mdb-label"><?php _e('Total Monthly Budget:', 'membership-discount-budget'); ?></span>
                        <span class="mdb-value"><?php echo wc_price($budget->total_budget); ?></span>
                    </div>
                    
                    <div class="mdb-budget-item">
                        <span class="mdb-label"><?php _e('Used Amount:', 'membership-discount-budget'); ?></span>
                        <span class="mdb-value"><?php echo wc_price($budget->used_amount); ?></span>
                    </div>
                    
                    <div class="mdb-budget-item">
                        <span class="mdb-label"><?php _e('Remaining Budget:', 'membership-discount-budget'); ?></span>
                        <span class="mdb-value"><?php echo wc_price($budget->remaining_budget); ?></span>
                    </div>
                    
                    <div class="mdb-budget-item">
                        <span class="mdb-label"><?php _e('Discount Percentage:', 'membership-discount-budget'); ?></span>
                        <span class="mdb-value"><?php echo esc_html($discount_percentage) . '%'; ?></span>
                    </div>
                    
                    <?php if ($next_payment) : ?>
                        <div class="mdb-budget-item">
                            <span class="mdb-label"><?php _e('Next Reset Date:', 'membership-discount-budget'); ?></span>
                            <span class="mdb-value"><?php echo date_i18n(get_option('date_format'), strtotime($next_payment)); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="mdb-budget-progress-container">
                    <div class="mdb-budget-progress">
                        <div class="mdb-budget-progress-bar" style="width: <?php echo esc_attr($percentage); ?>%"></div>
                    </div>
                    <div class="mdb-budget-percentage"><?php echo sprintf(__('%s remaining', 'membership-discount-budget'), round($percentage) . '%'); ?></div>
                </div>
            </div>
            
            <?php if (!empty($orders)) : ?>
                <div class="mdb-budget-history-card">
                    <h3><?php _e('Recent Orders with Discount', 'membership-discount-budget'); ?></h3>
                    
                    <table class="mdb-budget-history-table">
                        <thead>
                            <tr>
                                <th><?php _e('Order', 'membership-discount-budget'); ?></th>
                                <th><?php _e('Date', 'membership-discount-budget'); ?></th>
                                <th><?php _e('Discount Used', 'membership-discount-budget'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order) : ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                                            <?php echo sprintf(_x('#%s', 'hash before order number', 'membership-discount-budget'), $order->get_order_number()); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></td>
                                    <td><?php echo wc_price($order->get_meta('_mdb_discount_used')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="mdb-budget-explanation">
            <h3><?php _e('How Your Discount Budget Works', 'membership-discount-budget'); ?></h3>
            
            <p><?php echo sprintf(__('As a member, you receive a %1$s discount on all products up to your monthly budget limit of %2$s. For example, if a product costs 20 BGN, you will pay 18 BGN (saving 2 BGN). The 2 BGN discount will be deducted from your monthly budget.', 'membership-discount-budget'), $discount_percentage . '%', wc_price($budget->total_budget)); ?></p>
            
            <p><?php _e('Once your budget is fully used, products will be available at regular prices until your budget resets on your next subscription payment date.', 'membership-discount-budget'); ?></p>
            
            <p><?php _e('Your budget resets every month when your membership subscription is renewed.', 'membership-discount-budget'); ?></p>
        </div>
        <?php
    }

    /**
     * Display budget information on the cart page.
     */
    public function display_cart_budget_info() {
        if (!mdb_user_has_membership() || is_admin()) {
            return;
        }
        
        $budget = mdb_get_current_budget();
        
        if (!$budget) {
            return;
        }
        
        // Calculate potential discount for the cart
        $cart = WC()->cart;
        $cart_discount = 0;
        $remaining_after_purchase = $budget->remaining_budget;
        $discount_percentage = get_option('mdb_discount_percentage', 20);
        
        // Start tracking budget usage through cart items
        WC()->session->set('mdb_checking_budget', true);
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_price = $product->get_price();
            $quantity = $cart_item['quantity'];
            
            $item_discount = mdb_calculate_discount_amount($product_price) * $quantity;
            
            // Check if we have enough budget for this item
            if ($item_discount <= $remaining_after_purchase) {
                $cart_discount += $item_discount;
                $remaining_after_purchase -= $item_discount;
            } else {
                // We don't have enough budget for the full discount on this item
                $affordable_quantity = floor($remaining_after_purchase / mdb_calculate_discount_amount($product_price));
                $cart_discount += $affordable_quantity > 0 ? mdb_calculate_discount_amount($product_price) * $affordable_quantity : 0;
                $remaining_after_purchase = 0;
            }
        }
        
        WC()->session->set('mdb_checking_budget', false);
        
        ?>
        <div class="mdb-cart-budget-info">
            <h3><?php _e('Your Discount Budget', 'membership-discount-budget'); ?></h3>
            
            <div class="mdb-budget-status">
                <div class="mdb-budget-item">
                    <span class="mdb-label"><?php _e('Current Budget:', 'membership-discount-budget'); ?></span>
                    <span class="mdb-value"><?php echo wc_price($budget->remaining_budget); ?></span>
                </div>
                
                <div class="mdb-budget-item">
                    <span class="mdb-label"><?php _e('Discount Applied to Cart:', 'membership-discount-budget'); ?></span>
                    <span class="mdb-value"><?php echo wc_price($cart_discount); ?></span>
                </div>
                
                <div class="mdb-budget-item">
                    <span class="mdb-label"><?php _e('Remaining After Purchase:', 'membership-discount-budget'); ?></span>
                    <span class="mdb-value"><?php echo wc_price($remaining_after_purchase); ?></span>
                </div>
            </div>
            
            <?php if ($remaining_after_purchase <= 0) : ?>
                <div class="mdb-budget-warning">
                    <p><?php _e('Your discount budget will be fully used with this purchase. Some items may not receive the full discount.', 'membership-discount-budget'); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="mdb-budget-explanation">
                <p><?php echo sprintf(__('As a member, you receive a %s discount on all products up to your monthly budget limit.', 'membership-discount-budget'), $discount_percentage . '%'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Display budget information on the order details page.
     *
     * @param WC_Order $order Order object.
     */
    public function display_order_budget_info($order) {
    // HPOS compatible way to get meta data
    $discount_used = $order->get_meta('_mdb_discount_used');
    $remaining_budget = $order->get_meta('_mdb_remaining_budget');
    
    if (!$discount_used) {
        return;
    }
    
    ?>
    <h2><?php _e('Discount Budget Information', 'membership-discount-budget'); ?></h2>
    
    <table class="woocommerce-table mdb-order-budget-table">
        <tbody>
            <tr>
                <th><?php _e('Discount Used:', 'membership-discount-budget'); ?></th>
                <td><?php echo wc_price($discount_used); ?></td>
            </tr>
            <tr>
                <th><?php _e('Remaining Budget After Order:', 'membership-discount-budget'); ?></th>
                <td><?php echo wc_price($remaining_budget); ?></td>
            </tr>
        </tbody>
    </table>
    <?php
}

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style(
            'mdb-frontend-styles',
            MDB_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            MDB_VERSION
        );
        
        wp_enqueue_script(
            'mdb-frontend-scripts',
            MDB_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            MDB_VERSION,
            true
        );
    }
}
