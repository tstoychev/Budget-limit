<?php
/**
 * Admin interface for the plugin.
 *
 * @package Membership_Discount_Budget
 */

defined('ABSPATH') || exit;

/**
 * MDB_Admin Class.
 */
class MDB_Admin {
    /**
     * Single instance of the class.
     *
     * @var MDB_Admin
     */
    protected static $_instance = null;

    /**
     * Main class instance.
     *
     * @return MDB_Admin
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
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add meta box to orders
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
        
        // Add column to users list
        add_filter('manage_users_columns', array($this, 'add_user_budget_column'));
        add_filter('manage_users_custom_column', array($this, 'user_budget_column_content'), 10, 3);
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Add admin menu items.
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Membership Discount Budget', 'membership-discount-budget'),
            __('Discount Budget', 'membership-discount-budget'),
            'manage_woocommerce',
            'membership-discount-budget',
            array($this, 'settings_page'),
            'dashicons-money-alt',
            56
        );
        
        add_submenu_page(
            'membership-discount-budget',
            __('Settings', 'membership-discount-budget'),
            __('Settings', 'membership-discount-budget'),
            'manage_woocommerce',
            'membership-discount-budget',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'membership-discount-budget',
            __('User Budgets', 'membership-discount-budget'),
            __('User Budgets', 'membership-discount-budget'),
            'manage_woocommerce',
            'mdb-user-budgets',
            array($this, 'user_budgets_page')
        );
        
        add_submenu_page(
            'membership-discount-budget',
            __('Reports', 'membership-discount-budget'),
            __('Reports', 'membership-discount-budget'),
            'manage_woocommerce',
            'mdb-reports',
            array($this, 'reports_page')
        );
    }

    /**
     * Register plugin settings.
     */
    /**
 * Register plugin settings.
 */
public function register_settings() {
    register_setting('mdb_settings', 'mdb_monthly_budget', array(
        'type' => 'number',
        'sanitize_callback' => 'floatval',
        'default' => 300
    ));
    
    register_setting('mdb_settings', 'mdb_discount_percentage', array(
        'type' => 'number',
        'sanitize_callback' => 'intval',
        'default' => 20
    ));
    
    register_setting('mdb_settings', 'mdb_debug_mode', array(
        'type' => 'boolean',
        'sanitize_callback' => function($value) { return (bool) $value; },
        'default' => false
    ));
    
    add_settings_section(
        'mdb_general_settings',
        __('General Settings', 'membership-discount-budget'),
        array($this, 'general_settings_section_callback'),
        'mdb_settings'
    );
    
    add_settings_field(
        'mdb_monthly_budget',
        __('Monthly Budget Amount (BGN)', 'membership-discount-budget'),
        array($this, 'monthly_budget_callback'),
        'mdb_settings',
        'mdb_general_settings'
    );
    
    add_settings_field(
        'mdb_discount_percentage',
        __('Discount Percentage (%)', 'membership-discount-budget'),
        array($this, 'discount_percentage_callback'),
        'mdb_settings',
        'mdb_general_settings'
    );
    
    add_settings_field(
        'mdb_debug_mode',
        __('Debug Mode', 'membership-discount-budget'),
        array($this, 'debug_mode_callback'),
        'mdb_settings',
        'mdb_general_settings'
    );
}
    /**
     * General settings section callback.
     */
    public function general_settings_section_callback() {
        echo '<p>' . __('Configure the general settings for the membership discount budget.', 'membership-discount-budget') . '</p>';
    }

    /**
     * Monthly budget field callback.
     */
    public function monthly_budget_callback() {
        $value = get_option('mdb_monthly_budget', 300);
        echo '<input type="number" step="0.01" min="0" name="mdb_monthly_budget" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('The monthly discount budget amount for each member in BGN.', 'membership-discount-budget') . '</p>';
    }

    /**
     * Discount percentage field callback.
     */
    public function discount_percentage_callback() {
        $value = get_option('mdb_discount_percentage', 20);
        echo '<input type="number" step="1" min="0" max="100" name="mdb_discount_percentage" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('The discount percentage to apply for members.', 'membership-discount-budget') . '</p>';
    }

    /**
     * Debug mode field callback.
     */
    public function debug_mode_callback() {
        $value = get_option('mdb_debug_mode', false);
        echo '<input type="checkbox" name="mdb_debug_mode" value="1" ' . checked(1, $value, false) . ' />';
        echo '<p class="description">' . __('Enable debug logging for troubleshooting.', 'membership-discount-budget') . '</p>';
    }

    /**
     * Settings page callback.
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('mdb_settings');
                do_settings_sections('mdb_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * User budgets page callback.
     */
    public function user_budgets_page() {
        // Create custom list table if it doesn't exist yet
        if (!class_exists('MDB_User_Budgets_List_Table')) {
            require_once MDB_PLUGIN_DIR . 'includes/class-mdb-user-budgets-list-table.php';
        }

        $list_table = new MDB_User_Budgets_List_Table();
        $list_table->prepare_items();

        // Process bulk actions
        if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'bulk-' . $list_table->_args['plural'])) {
            if (isset($_POST['bulk-reset']) && !empty($_POST['budget'])) {
                $user_ids = array_map('intval', $_POST['budget']);
                foreach ($user_ids as $user_id) {
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
                
                // Add admin notice
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>' . __('Budgets have been reset successfully.', 'membership-discount-budget') . '</p></div>';
                });
            }
        }

        // Handle individual budget edit
        if (isset($_POST['mdb_edit_budget']) && isset($_POST['mdb_user_id']) && isset($_POST['mdb_budget_amount'])) {
            if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'mdb_edit_budget_' . $_POST['mdb_user_id'])) {
                $user_id = intval($_POST['mdb_user_id']);
                $budget_amount = floatval($_POST['mdb_budget_amount']);
                
                $budget = mdb_get_current_budget($user_id);
                if ($budget) {
                    $remaining = max(0, $budget_amount - $budget->used_amount);
                    mdb_update_budget(array(
                        'id' => $budget->id,
                        'user_id' => $user_id,
                        'membership_id' => $budget->membership_id,
                        'total_budget' => $budget_amount,
                        'used_amount' => $budget->used_amount,
                        'remaining_budget' => $remaining,
                        'month' => $budget->month,
                        'year' => $budget->year,
                    ));
                    
                    // Add admin notice
                    add_action('admin_notices', function() {
                        echo '<div class="notice notice-success is-dismissible"><p>' . __('Budget has been updated successfully.', 'membership-discount-budget') . '</p></div>';
                    });
                }
            }
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form id="user-budgets-filter" method="post">
                <?php
                $list_table->display();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Reports page callback.
     */
    public function reports_page() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'membership_discount_budget';
        $current_month = current_time('n');
        $current_year = current_time('Y');
        
        // Get total budget usage
        $total_usage = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(used_amount) FROM {$table_name} WHERE month = %d AND year = %d",
            $current_month, $current_year
        ));
        
        // Get user count with > 50% usage
        $high_usage_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE month = %d AND year = %d AND (used_amount / total_budget) > 0.5",
            $current_month, $current_year
        ));
        
        // Get usage by membership plan
        $membership_usage = $wpdb->get_results($wpdb->prepare(
            "SELECT membership_id, SUM(used_amount) as total_used, COUNT(user_id) as user_count 
            FROM {$table_name} 
            WHERE month = %d AND year = %d 
            GROUP BY membership_id",
            $current_month, $current_year
        ));
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="mdb-reports-container">
                <div class="mdb-report-card">
                    <h2><?php _e('Total Budget Usage This Month', 'membership-discount-budget'); ?></h2>
                    <div class="mdb-stat"><?php echo wc_price($total_usage ?: 0); ?></div>
                </div>
                
                <div class="mdb-report-card">
                    <h2><?php _e('Users with >50% Budget Used', 'membership-discount-budget'); ?></h2>
                    <div class="mdb-stat"><?php echo esc_html($high_usage_count ?: 0); ?></div>
                </div>
                
                <div class="mdb-report-card full-width">
                    <h2><?php _e('Usage by Membership Plan', 'membership-discount-budget'); ?></h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Membership Plan', 'membership-discount-budget'); ?></th>
                                <th><?php _e('Member Count', 'membership-discount-budget'); ?></th>
                                <th><?php _e('Total Budget Used', 'membership-discount-budget'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($membership_usage)) : ?>
                                <tr>
                                    <td colspan="3"><?php _e('No data available.', 'membership-discount-budget'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($membership_usage as $usage) : ?>
                                    <tr>
                                        <td>
                                            <?php 
                                            $plan_name = __('Unknown Plan', 'membership-discount-budget');
                                            if (function_exists('wc_memberships_get_membership_plan')) {
                                                $plan = wc_memberships_get_membership_plan($usage->membership_id);
                                                if ($plan) {
                                                    $plan_name = $plan->get_name();
                                                }
                                            }
                                            echo esc_html($plan_name);
                                            ?>
                                        </td>
                                        <td><?php echo esc_html($usage->user_count); ?></td>
                                        <td><?php echo wc_price($usage->total_used); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mdb-report-actions">
                    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=mdb_export_csv')); ?>" class="button button-primary">
                        <?php _e('Export to CSV', 'membership-discount-budget'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Add budget meta box to order screen.
     */
    public function add_order_meta_box() {
        add_meta_box(
            'mdb_order_budget_info',
            __('Discount Budget Information', 'membership-discount-budget'),
            array($this, 'order_budget_meta_box_callback'),
            wc_get_order_types('order-meta-boxes'),
            'side',
            'default'
        );
    }

    /**
     * Order budget meta box callback.
     *
     * @param WP_Post $post Post object.
     */
    public function order_budget_meta_box_callback($post) {
        $order = wc_get_order($post->ID);
        
        if (!$order) {
            return;
        }
        
        $discount_used = $order->get_meta('_mdb_discount_used');
        $remaining_budget = $order->get_meta('_mdb_remaining_budget');
        
        if (!$discount_used) {
            echo '<p>' . __('No discount budget was used for this order.', 'membership-discount-budget') . '</p>';
            return;
        }
        
        ?>
        <p>
            <strong><?php _e('Discount Used:', 'membership-discount-budget'); ?></strong>
            <?php echo wc_price($discount_used); ?>
        </p>
        <p>
            <strong><?php _e('Remaining Budget After Order:', 'membership-discount-budget'); ?></strong>
            <?php echo wc_price($remaining_budget); ?>
        </p>
        <?php
    }

    /**
     * Add budget column to users list.
     *
     * @param array $columns User list columns.
     * @return array Modified columns.
     */
    public function add_user_budget_column($columns) {
        $columns['mdb_budget'] = __('Discount Budget', 'membership-discount-budget');
        return $columns;
    }

    /**
     * User budget column content.
     *
     * @param string $output Column output.
     * @param string $column_name Column name.
     * @param int $user_id User ID.
     * @return string Modified output.
     */
    public function user_budget_column_content($output, $column_name, $user_id) {
        if ('mdb_budget' === $column_name) {
            $budget = mdb_get_current_budget($user_id);
            
            if ($budget) {
                $output = sprintf(
                    '%s / %s',
                    wc_price($budget->remaining_budget),
                    wc_price($budget->total_budget)
                );
                
                // Add progress bar
                $percentage = ($budget->remaining_budget / $budget->total_budget) * 100;
                $output .= '<div class="mdb-progress"><div class="mdb-progress-bar" style="width: ' . esc_attr($percentage) . '%;"></div></div>';
            } else {
                $output = __('No budget', 'membership-discount-budget');
            }
        }
        
        return $output;
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_admin_assets() {
        wp_enqueue_style(
            'mdb-admin-styles',
            MDB_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            MDB_VERSION
        );
        
        wp_register_script(
            'mdb-admin-scripts',
            MDB_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'wp-util'),
            MDB_VERSION,
            true
        );
        // Add localization for JavaScript
    wp_localize_script('mdb-admin-scripts', 'mdbL10n', array(
        'edit_budget' => __('Edit Budget', 'membership-discount-budget'),
        'budget_amount' => __('Budget Amount', 'membership-discount-budget'),
        'cancel' => __('Cancel', 'membership-discount-budget'),
        'save' => __('Save', 'membership-discount-budget'),
        'confirm_reset' => __('Are you sure you want to reset this budget?', 'membership-discount-budget'),
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mdb-admin-nonce')
    ));
    
    // Enqueue the script after localization
    wp_enqueue_script('mdb-admin-scripts');
    }
}
