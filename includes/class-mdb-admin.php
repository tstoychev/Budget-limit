<?php
/**
 * Admin handler class
 * 
 * @package Membership_Discount_Budget
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles admin functionality
 */
class MDB_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // AJAX handlers
        add_action('wp_ajax_mdb_view_user_budgets', array($this, 'ajax_view_user_budgets'));
        add_action('wp_ajax_mdb_reset_user_budget', array($this, 'ajax_reset_user_budget'));
        add_action('wp_ajax_mdb_update_user_budget', array($this, 'ajax_update_user_budget'));
        add_action('wp_ajax_mdb_bulk_reset_budgets', array($this, 'ajax_bulk_reset_budgets'));
        add_action('wp_ajax_mdb_export_report', array($this, 'ajax_export_report'));
        
        // Add meta box to WooCommerce orders
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
        
        // Add custom column to WooCommerce Memberships
        add_filter('manage_wc_user_membership_posts_columns', array($this, 'add_membership_columns'));
        add_action('manage_wc_user_membership_posts_custom_column', array($this, 'populate_membership_columns'), 10, 2);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            return;
        }
        
        add_menu_page(
            __('Membership Budget', 'membership-discount-budget'),
            __('Membership Budget', 'membership-discount-budget'),
            'manage_options',
            'membership-budget',
            array($this, 'admin_page'),
            'dashicons-money-alt',
            58
        );
        
        add_submenu_page(
            'membership-budget',
            __('User Budgets', 'membership-discount-budget'),
            __('User Budgets', 'membership-discount-budget'),
            'manage_options',
            'membership-budget-users',
            array($this, 'budgets_page')
        );
        
        add_submenu_page(
            'membership-budget',
            __('Reports', 'membership-discount-budget'),
            __('Reports', 'membership-discount-budget'),
            'manage_options',
            'membership-budget-reports',
            array($this, 'reports_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'mdb_settings',
            'mdb_monthly_budget',
            array(
                'type' => 'number',
                'sanitize_callback' => array($this, 'sanitize_decimal'),
                'default' => 200.00
            )
        );
        
        register_setting(
            'mdb_settings',
            'mdb_allowed_plans',
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_plans'),
                'default' => array()
            )
        );
        
        register_setting(
            'mdb_settings',
            'mdb_carryover_budget',
            array(
                'type' => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => false
            )
        );
        
        // New settings for advanced functionality
        register_setting(
            'mdb_settings',
            'mdb_enable_debug_logging',
            array(
                'type' => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default' => false
            )
        );
        
        register_setting(
            'mdb_settings',
            'mdb_cart_notice_position',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'before_cart'
            )
        );
    }
    
    /**
     * Sanitize decimal value
     *
     * @param mixed $value The value to sanitize
     * @return float Sanitized decimal value
     */
    public function sanitize_decimal($value) {
        return floatval($value);
    }
    
    /**
     * Sanitize plans array
     *
     * @param mixed $value The value to sanitize
     * @return array Sanitized array of plans
     */
    public function sanitize_plans($value) {
        if (!is_array($value)) {
            return array();
        }
        
        return array_map('absint', $value);
    }
    
    /**
     * Admin page output
     */
    public function admin_page() {
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'membership-discount-budget'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Membership Discount Budget Settings', 'membership-discount-budget')); ?></h1>
            
            <?php
            // Display settings updated message
            if (isset($_GET['settings-updated'])) {
                add_settings_error('mdb_messages', 'mdb_message', __('Settings saved.', 'membership-discount-budget'), 'updated');
            }
            
            settings_errors('mdb_messages');
            ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('mdb_settings'); ?>
                <?php do_settings_sections('mdb_settings'); ?>
                
                <div id="mdb-settings-tabs" class="nav-tab-wrapper">
                    <a href="#general-settings" class="nav-tab nav-tab-active"><?php esc_html_e('General', 'membership-discount-budget'); ?></a>
                    <a href="#advanced-settings" class="nav-tab"><?php esc_html_e('Advanced', 'membership-discount-budget'); ?></a>
                </div>
                
                <div id="general-settings" class="mdb-settings-panel">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Monthly Budget Amount (BGN)', 'membership-discount-budget')); ?></th>
                            <td>
                                <input type="number" step="0.01" min="0" name="mdb_monthly_budget" value="<?php echo esc_attr(get_option('mdb_monthly_budget', 200.00)); ?>" />
                                <p class="description"><?php echo esc_html(__('The amount of discount budget each member gets each month.', 'membership-discount-budget')); ?></p>
                            </td>
                        </tr>
                        
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Membership Plans', 'membership-discount-budget')); ?></th>
                            <td>
                                <?php
                                $membership_plans = wc_memberships_get_membership_plans();
                                $selected_plans = get_option('mdb_allowed_plans', array());
                                
                                if (empty($membership_plans)) {
                                    echo '<p>' . esc_html__('No membership plans found.', 'membership-discount-budget') . '</p>';
                                } else {
                                    echo '<select name="mdb_allowed_plans[]" multiple style="min-width: 300px; min-height: 100px;">';
                                    foreach ($membership_plans as $plan) {
                                        $selected = in_array($plan->get_id(), (array) $selected_plans) ? 'selected="selected"' : '';
                                        echo '<option value="' . esc_attr($plan->get_id()) . '" ' . $selected . '>' . esc_html($plan->get_name()) . '</option>';
                                    }
                                    echo '</select>';
                                    echo '<p class="description">' . esc_html__('Select which membership plans should have discount budgets. Hold Ctrl/Cmd to select multiple.', 'membership-discount-budget') . '</p>';
                                }
                                ?>
                            </td>
                        </tr>
                        
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Carry Over Budget', 'membership-discount-budget')); ?></th>
                            <td>
                                <input type="checkbox" name="mdb_carryover_budget" value="1" <?php checked(get_option('mdb_carryover_budget', false), true); ?> />
                                <p class="description"><?php echo esc_html(__('If checked, unused discount budget will carry over to the next month.', 'membership-discount-budget')); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="advanced-settings" class="mdb-settings-panel" style="display: none;">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Cart Notice Position', 'membership-discount-budget')); ?></th>
                            <td>
                                <select name="mdb_cart_notice_position">
                                    <option value="before_cart" <?php selected(get_option('mdb_cart_notice_position', 'before_cart'), 'before_cart'); ?>><?php esc_html_e('Before Cart', 'membership-discount-budget'); ?></option>
                                    <option value="after_cart" <?php selected(get_option('mdb_cart_notice_position', 'before_cart'), 'after_cart'); ?>><?php esc_html_e('After Cart', 'membership-discount-budget'); ?></option>
                                    <option value="before_cart_totals" <?php selected(get_option('mdb_cart_notice_position', 'before_cart'), 'before_cart_totals'); ?>><?php esc_html_e('Before Cart Totals', 'membership-discount-budget'); ?></option>
                                </select>
                                <p class="description"><?php echo esc_html(__('Where to display the budget information on the cart page.', 'membership-discount-budget')); ?></p>
                            </td>
                        </tr>
                        
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Enable Debug Logging', 'membership-discount-budget')); ?></th>
                            <td>
                                <input type="checkbox" name="mdb_enable_debug_logging" value="1" <?php checked(get_option('mdb_enable_debug_logging', false), true); ?> />
                                <p class="description"><?php echo esc_html(__('Log plugin activity for debugging purposes.', 'membership-discount-budget')); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(); ?>
            </form>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Tab functionality
                    $('#mdb-settings-tabs .nav-tab').on('click', function(e) {
                        e.preventDefault();
                        
                        // Hide all panels
                        $('.mdb-settings-panel').hide();
                        
                        // Show the selected panel
                        $($(this).attr('href')).show();
                        
                        // Update active tab
                        $('#mdb-settings-tabs .nav-tab').removeClass('nav-tab-active');
                        $(this).addClass('nav-tab-active');
                    });
                });
            </script>
        </div>
        <?php
    }
    
    /**
     * Budgets admin page
     */
    public function budgets_page() {
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'membership-discount-budget'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('User Discount Budgets', 'membership-discount-budget')); ?></h1>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select id="filter-month">
                        <?php for ($i = 1; $i <= 12; $i++) : ?>
                            <option value="<?php echo esc_attr($i); ?>" <?php selected(date('n'), $i); ?>><?php echo esc_html(date('F', mktime(0, 0, 0, $i, 1))); ?></option>
                        <?php endfor; ?>
                    </select>
                    <select id="filter-year">
                        <?php for ($i = date('Y') - 2; $i <= date('Y'); $i++) : ?>
                            <option value="<?php echo esc_attr($i); ?>" <?php selected(date('Y'), $i); ?>><?php echo esc_html($i); ?></option>
                        <?php endfor; ?>
                    </select>
                    <button id="filter-submit" class="button"><?php echo esc_html(__('Filter', 'membership-discount-budget')); ?></button>
                    <button id="bulk-reset" class="button"><?php echo esc_html(__('Bulk Reset Selected', 'membership-discount-budget')); ?></button>
                </div>
                <div class="alignright">
                    <input type="search" id="budget-search" placeholder="<?php esc_attr_e('Search by username...', 'membership-discount-budget'); ?>">
                    <button id="search-submit" class="button"><?php echo esc_html(__('Search', 'membership-discount-budget')); ?></button>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="select-all-budgets"></th>
                        <th><?php echo esc_html(__('User', 'membership-discount-budget')); ?></th>
                        <th><?php echo esc_html(__('Membership Plan', 'membership-discount-budget')); ?></th>
                        <th><?php echo esc_html(__('Total Budget', 'membership-discount-budget')); ?></th>
                        <th><?php echo esc_html(__('Used Amount', 'membership-discount-budget')); ?></th>
                        <th><?php echo esc_html(__('Remaining Budget', 'membership-discount-budget')); ?></th>
                        <th><?php echo esc_html(__('Actions', 'membership-discount-budget')); ?></th>
                    </tr>
                </thead>
                <tbody id="budget-results">
                    <tr>
                        <td colspan="7"><?php echo esc_html(__('Loading...', 'membership-discount-budget')); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Edit Budget Modal -->
            <div id="edit-budget-modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
                <div style="background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 50%;">
                    <h2><?php echo esc_html(__('Edit Budget', 'membership-discount-budget')); ?></h2>
                    <input type="hidden" id="edit-budget-id" value="">
                    <p>
                        <label for="edit-budget-amount"><?php echo esc_html(__('Remaining Budget:', 'membership-discount-budget')); ?></label>
                        <input type="number" id="edit-budget-amount" min="0" step="0.01" style="width: 100%;">
                    </p>
                    <p>
                        <button id="save-budget" class="button button-primary"><?php echo esc_html(__('Save', 'membership-discount-budget')); ?></button>
                        <button id="cancel-edit" class="button"><?php echo esc_html(__('Cancel', 'membership-discount-budget')); ?></button>
                    </p>
                </div>
            </div>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    function loadBudgets() {
                        var month = $('#filter-month').val();
                        var year = $('#filter-year').val();
                        var search = $('#budget-search').val();
                        
                        $('#budget-results').html('<tr><td colspan="7">' + 
                            '<?php echo esc_js(__('Loading...', 'membership-discount-budget')); ?>' + 
                            '</td></tr>');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'mdb_view_user_budgets',
                                month: month,
                                year: year,
                                search: search,
                                nonce: mdbAdminData.nonce
                            },
                            success: function(response) {
                                $('#budget-results').html(response);
                            },
                            error: function() {
                                $('#budget-results').html('<tr><td colspan="7">' + 
                                    '<?php echo esc_js(__('Error loading data', 'membership-discount-budget')); ?>' + 
                                    '</td></tr>');
                            }
                        });
                    }
                    
                    $('#filter-submit, #search-submit').on('click', function(e) {
                        e.preventDefault();
                        loadBudgets();
                    });
                    
                    $('#budget-search').on('keypress', function(e) {
                        if (e.which === 13) {
                            e.preventDefault();
                            loadBudgets();
                        }
                    });
                    
                    // Initial load
                    loadBudgets();
                    
                    // Reset budget
                    $(document).on('click', '.reset-budget', function(e) {
                        e.preventDefault();
                        
                        if (!confirm(mdbAdminData.strings.resetConfirm)) {
                            return;
                        }
                        
                        var budget_id = $(this).data('id');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'mdb_reset_user_budget',
                                budget_id: budget_id,
                                nonce: mdbAdminData.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    loadBudgets();
                                } else {
                                    alert(response.data.message || mdbAdminData.strings.error);
                                }
                            },
                            error: function() {
                                alert(mdbAdminData.strings.error);
                            }
                        });
                    });
                    
                    // Bulk reset
                    $('#bulk-reset').on('click', function(e) {
                        e.preventDefault();
                        
                        var selected = $('.budget-select:checked');
                        
                        if (selected.length === 0) {
                            alert('<?php echo esc_js(__('Please select at least one budget to reset.', 'membership-discount-budget')); ?>');
                            return;
                        }
                        
                        if (!confirm('<?php echo esc_js(__('Are you sure you want to reset all selected budgets? This cannot be undone.', 'membership-discount-budget')); ?>')) {
                            return;
                        }
                        
                        var budget_ids = [];
                        selected.each(function() {
                            budget_ids.push($(this).val());
                        });
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'mdb_bulk_reset_budgets',
                                budget_ids: budget_ids,
                                nonce: mdbAdminData.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    loadBudgets();
                                    alert(response.data.message);
                                } else {
                                    alert(response.data.message || mdbAdminData.strings.error);
                                }
                            },
                            error: function() {
                                alert(mdbAdminData.strings.error);
                            }
                        });
                    });
                    
                    // Select all checkboxes
                    $('#select-all-budgets').on('change', function() {
                        $('.budget-select').prop('checked', $(this).prop('checked'));
                    });
                    
                    // Edit budget modal
                    $(document).on('click', '.edit-budget', function(e) {
                        e.preventDefault();
                        var budget_id = $(this).data('id');
                        var current_budget = $(this).data('budget');
                        
                        $('#edit-budget-id').val(budget_id);
                        $('#edit-budget-amount').val(current_budget);
                        $('#edit-budget-modal').show();
                    });
                    
                    $('#cancel-edit').on('click', function() {
                        $('#edit-budget-modal').hide();
                    });
                    
                    $('#save-budget').on('click', function() {
                        var budget_id = $('#edit-budget-id').val();
                        var new_budget = $('#edit-budget-amount').val();
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'mdb_update_user_budget',
                                budget_id: budget_id,
                                new_budget: new_budget,
                                nonce: mdbAdminData.nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#edit-budget-modal').hide();
                                    loadBudgets();
                                } else {
                                    alert(response.data.message || mdbAdminData.strings.error);
                                }
                            },
                            error: function() {
                                alert(mdbAdminData.strings.error);
                            }
                        });
                    });
                });
            </script>
        </div>
        <?php
    }
    
    /**
     * Reports page
     */
    public function reports_page() {
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'membership-discount-budget'));
        }
        
        // Get current month and year
        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        
        // Get statistics for the selected period
        $stats = $this->get_budget_statistics($month, $year);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Discount Budget Reports', 'membership-discount-budget')); ?></h1>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" action="">
                        <input type="hidden" name="page" value="membership-budget-reports">
                        <select name="month">
                            <?php for ($i = 1; $i <= 12; $i++) : ?>
                                <option value="<?php echo esc_attr($i); ?>" <?php selected($month, $i); ?>><?php echo esc_html(date('F', mktime(0, 0, 0, $i, 1))); ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="year">
                            <?php for ($i = date('Y') - 2; $i <= date('Y'); $i++) : ?>
                                <option value="<?php echo esc_attr($i); ?>" <?php selected($year, $i); ?>><?php echo esc_html($i); ?></option>
                            <?php endfor; ?>
                        </select>
                        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'membership-discount-budget'); ?>">
                    </form>
                </div>
            </div>
            
            <div class="mdb-stats-grid">
                <div class="mdb-stat-card">
                    <h3><?php esc_html_e('Total Budget Allocated', 'membership-discount-budget'); ?></h3>
                    <p class="mdb-stat-value"><?php echo wc_price($stats['total_budget']); ?></p>
                </div>
                
                <div class="mdb-stat-card">
                    <h3><?php esc_html_e('Total Budget Used', 'membership-discount-budget'); ?></h3>
                    <p class="mdb-stat-value"><?php echo wc_price($stats['total_used']); ?></p>
                </div>
                
                <div class="mdb-stat-card">
                    <h3><?php esc_html_e('Total Budget Remaining', 'membership-discount-budget'); ?></h3>
                    <p class="mdb-stat-value"><?php echo wc_price($stats['total_remaining']); ?></p>
                </div>
                
                <div class="mdb-stat-card">
                    <h3><?php esc_html_e('Average Usage Per User', 'membership-discount-budget'); ?></h3>
                    <p class="mdb-stat-value"><?php echo wc_price($stats['avg_used_per_user']); ?></p>
                </div>
                
                <div class="mdb-stat-card">
                    <h3><?php esc_html_e('Users Over 50% Usage', 'membership-discount-budget'); ?></h3>
                    <p class="mdb-stat-value"><?php echo esc_html($stats['users_over_50_percent']); ?></p>
                </div>
                
                <div class="mdb-stat-card">
                    <h3><?php esc_html_e('Users With No Usage', 'membership-discount-budget'); ?></h3>
                    <p class="mdb-stat-value"><?php echo esc_html($stats['users_no_usage']); ?></p>
                </div>
            </div>
            
            <h2><?php esc_html_e('Usage By Membership Plan', 'membership-discount-budget'); ?></h2>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Membership Plan', 'membership-discount-budget'); ?></th>
                        <th><?php esc_html_e('Number of Users', 'membership-discount-budget'); ?></th>
                        <th><?php esc_html_e('Total Budget', 'membership-discount-budget'); ?></th>
                        <th><?php esc_html_e('Total Used', 'membership-discount-budget'); ?></th>
                        <th><?php esc_html_e('Usage Percentage', 'membership-discount-budget'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stats['plan_stats'])) : ?>
                        <tr>
                            <td colspan="5"><?php esc_html_e('No data available for this period.', 'membership-discount-budget'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($stats['plan_stats'] as $plan_id => $plan_data) : ?>
                            <tr>
                                <td><?php echo esc_html($plan_data['name']); ?></td>
                                <td><?php echo esc_html($plan_data['user_count']); ?></td>
                                <td><?php echo wc_price($plan_data['total_budget']); ?></td>
                                <td><?php echo wc_price($plan_data['total_used']); ?></td>
                                <td>
                                    <?php 
                                    $percentage = $plan_data['total_budget'] > 0 
                                        ? round(($plan_data['total_used'] / $plan_data['total_budget']) * 100, 2) 
                                        : 0;
                                    echo esc_html($percentage . '%'); 
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <p>
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=mdb_export_report&month=' . $month . '&year=' . $year . '&nonce=' . wp_create_nonce('mdb-admin-nonce'))); ?>" class="button">
                    <?php esc_html_e('Export to CSV', 'membership-discount-budget'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Get budget statistics for reports
     *
     * @param int $month Month number
     * @param int $year Year
     * @return array Statistics data
     */
    private function get_budget_statistics($month, $year) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        // Initialize stats array
        $stats = array(
            'total_budget' => 0,
            'total_used' => 0,
            'total_remaining' => 0,
            'avg_used_per_user' => 0,
            'users_over_50_percent' => 0,
            'users_no_usage' => 0,
            'plan_stats' => array()
        );
        
        // Get all budgets for the selected period
        $budgets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE month = %d AND year = %d",
            $month, $year
        ));
        
        if (empty($budgets)) {
            return $stats;
        }
        
        $total_users = count($budgets);
        $users_over_50_percent = 0;
        $users_no_usage = 0;
        $plan_data = array();
        
        foreach ($budgets as $budget) {
            // Calculate totals
            $stats['total_budget'] += $budget->total_budget;
            $stats['total_used'] += $budget->used_amount;
            $stats['total_remaining'] += $budget->remaining_budget;
            
            // Check usage thresholds
            $usage_percentage = $budget->total_budget > 0 
                ? ($budget->used_amount / $budget->total_budget) * 100 
                : 0;
            
            if ($usage_percentage >= 50) {
                $users_over_50_percent++;
            }
            
            if ($budget->used_amount == 0) {
                $users_no_usage++;
            }
            
            // Get membership plan data
            $membership = wc_memberships_get_user_membership($budget->membership_id);
            if ($membership) {
                $plan_id = $membership->get_plan_id();
                $plan_name = get_the_title($plan_id);
                
                if (!isset($plan_data[$plan_id])) {
                    $plan_data[$plan_id] = array(
                        'name' => $plan_name,
                        'user_count' => 0,
                        'total_budget' => 0,
                        'total_used' => 0
                    );
                }
                
                $plan_data[$plan_id]['user_count']++;
                $plan_data[$plan_id]['total_budget'] += $budget->total_budget;
                $plan_data[$plan_id]['total_used'] += $budget->used_amount;
            }
        }
        
        // Calculate averages
        $stats['avg_used_per_user'] = $total_users > 0 ? $stats['total_used'] / $total_users : 0;
        $stats['users_over_50_percent'] = $users_over_50_percent;
        $stats['users_no_usage'] = $users_no_usage;
        $stats['plan_stats'] = $plan_data;
        
        return $stats;
    }
    
    /**
     * Add meta box to WooCommerce orders
     */
    public function add_order_meta_box() {
        add_meta_box(
            'mdb_order_discount_budget',
            __('Membership Discount Budget', 'membership-discount-budget'),
            array($this, 'order_meta_box_content'),
            'shop_order',
            'side',
            'default'
        );
    }
    
    /**
     * Order meta box content
     *
     * @param WP_Post $post Post object
     */
    public function order_meta_box_content($post) {
        $order = wc_get_order($post->ID);
        if (!$order) {
            return;
        }
        
        $discount_used = $order->get_meta('_membership_discount_budget_used');
        
        if (empty($discount_used)) {
            echo '<p>' . esc_html__('No membership discount budget was used for this order.', 'membership-discount-budget') . '</p>';
            return;
        }
        
        echo '<p><strong>' . esc_html__('Discount Budget Used:', 'membership-discount-budget') . '</strong> ' . wc_price($discount_used) . '</p>';
        
        // Get user's current budget
        $user_id = $order->get_user_id();
        if ($user_id) {
            $budget = MDB()->budget->get_user_current_budget($user_id);
            
            if ($budget) {
                echo '<p><strong>' . esc_html__('Current Remaining Budget:', 'membership-discount-budget') . '</strong> ' . wc_price($budget->remaining_budget) . '</p>';
            }
        }
    }
    
    /**
     * Add membership columns
     *
     * @param array $columns Current columns
     * @return array Modified columns
     */
    public function add_membership_columns($columns) {
        $new_columns = array();
        
        // Insert budget column before actions
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'membership_status') {
                $new_columns['discount_budget'] = __('Discount Budget', 'membership-discount-budget');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Populate membership columns
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public function populate_membership_columns($column, $post_id) {
        if ('discount_budget' !== $column) {
            return;
        }
        
        $membership = wc_memberships_get_user_membership($post_id);
        if (!$membership) {
            return;
        }
        
        $user_id = $membership->get_user_id();
        $budget = MDB()->budget->get_user_current_budget($user_id);
        
        if ($budget) {
            echo wc_price($budget->remaining_budget) . ' / ' . wc_price($budget->total_budget);
        } else {
            echo '-';
        }
    }
    
    /**
     * AJAX handler for viewing user budgets
     */
    public function ajax_view_user_budgets() {
        check_ajax_referer('mdb-admin-nonce', 'nonce');
        
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'membership-discount-budget')));
        }
        
        global $wpdb;
        
        $month = isset($_POST['month']) ? intval($_POST['month']) : date('n');
        $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        // Base query
        $query = "SELECT b.* FROM $table AS b";
        $where = "WHERE b.month = %d AND b.year = %d";
        $params = array($month, $year);
        
        // Add search condition if provided
        if (!empty($search)) {
            $query .= " LEFT JOIN {$wpdb->users} AS u ON b.user_id = u.ID";
            $where .= " AND (u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)";
            $search_param = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        $query .= " $where ORDER BY b.user_id ASC";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $params));
        
        if (empty($results)) {
            echo '<tr><td colspan="7">' . esc_html__('No budgets found for this period.', 'membership-discount-budget') . '</td></tr>';
            wp_die();
        }
        
        foreach ($results as $row) {
            $user = get_user_by('id', $row->user_id);
            $membership = wc_memberships_get_user_membership($row->membership_id);
            $plan_name = $membership ? get_the_title($membership->get_plan_id()) : __('Unknown Plan', 'membership-discount-budget');
            
            echo '<tr>';
            echo '<td><input type="checkbox" class="budget-select" value="' . esc_attr($row->id) . '"></td>';
            echo '<td>' . esc_html($user ? $user->display_name : __('Unknown User', 'membership-discount-budget')) . ' (#' . esc_html($row->user_id) . ')</td>';
            echo '<td>' . esc_html($plan_name) . '</td>';
            echo '<td>' . wc_price($row->total_budget) . '</td>';
            echo '<td>' . wc_price($row->used_amount) . '</td>';
            echo '<td>' . wc_price($row->remaining_budget) . '</td>';
            echo '<td>
                <a href="#" class="edit-budget" data-id="' . esc_attr($row->id) . '" data-budget="' . esc_attr($row->remaining_budget) . '">' . esc_html__('Edit', 'membership-discount-budget') . '</a> | 
                <a href="#" class="reset-budget" data-id="' . esc_attr($row->id) . '">' . esc_html__('Reset', 'membership-discount-budget') . '</a>
            </td>';
            echo '</tr>';
        }
        
        wp_die();
    }
    
    /**
     * AJAX handler for resetting user budget
     */
    public function ajax_reset_user_budget() {
        check_ajax_referer('mdb-admin-nonce', 'nonce');
        
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'membership-discount-budget')));
        }
        
        $budget_id = isset($_POST['budget_id']) ? intval($_POST['budget_id']) : 0;
        
        $result = MDB()->budget->reset_user_budget($budget_id);
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error(array('message' => __('Budget not found or could not be reset.', 'membership-discount-budget')));
        }
    }
    
    /**
     * AJAX handler for bulk resetting budgets
     */
    public function ajax_bulk_reset_budgets() {
        check_ajax_referer('mdb-admin-nonce', 'nonce');
        
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'membership-discount-budget')));
        }
        
        $budget_ids = isset($_POST['budget_ids']) ? array_map('intval', (array) $_POST['budget_ids']) : array();
        
        if (empty($budget_ids)) {
            wp_send_json_error(array('message' => __('No budgets selected.', 'membership-discount-budget')));
        }
        
        $success_count = 0;
        $failed_count = 0;
        
        foreach ($budget_ids as $budget_id) {
            $result = MDB()->budget->reset_user_budget($budget_id);
            
            if ($result) {
                $success_count++;
            } else {
                $failed_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('%d budgets reset successfully. %d failed.', 'membership-discount-budget'),
                $success_count,
                $failed_count
            )
        ));
    }
    
    /**
     * AJAX handler for updating user budget
     */
    public function ajax_update_user_budget() {
        check_ajax_referer('mdb-admin-nonce', 'nonce');
        
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'membership-discount-budget')));
        }
        
        $budget_id = isset($_POST['budget_id']) ? intval($_POST['budget_id']) : 0;
        $new_budget = isset($_POST['new_budget']) ? floatval($_POST['new_budget']) : 0;
        
        if ($new_budget < 0) {
            wp_send_json_error(array('message' => __('Budget amount cannot be negative.', 'membership-discount-budget')));
        }
        
        $result = MDB()->budget->update_user_budget($budget_id, $new_budget);
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error(array('message' => __('Budget not found or could not be updated.', 'membership-discount-budget')));
        }
    }
    
    /**
     * AJAX handler for exporting reports
     */
    public function ajax_export_report() {
        check_ajax_referer('mdb-admin-nonce', 'nonce');
        
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'membership-discount-budget'));
        }
        
        $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        
        // Get the data
        global $wpdb;
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE month = %d AND year = %d",
            $month, $year
        ));
        
        if (empty($results)) {
            wp_die(__('No data to export.', 'membership-discount-budget'));
        }
        
        // Set CSV headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=budget-report-' . $year . '-' . $month . '.csv');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add CSV headers
        fputcsv($output, array(
            __('User ID', 'membership-discount-budget'),
            __('User Name', 'membership-discount-budget'),
            __('Email', 'membership-discount-budget'),
            __('Membership Plan', 'membership-discount-budget'),
            __('Total Budget', 'membership-discount-budget'),
            __('Used Amount', 'membership-discount-budget'),
            __('Remaining Budget', 'membership-discount-budget'),
            __('Usage %', 'membership-discount-budget'),
        ));
        
        // Add data rows
        foreach ($results as $row) {
            $user = get_user_by('id', $row->user_id);
            $membership = wc_memberships_get_user_membership($row->membership_id);
            $plan_name = $membership ? get_the_title($membership->get_plan_id()) : __('Unknown Plan', 'membership-discount-budget');
            
            $usage_percentage = $row->total_budget > 0 
                ? round(($row->used_amount / $row->total_budget) * 100, 2) 
                : 0;
            
            fputcsv($output, array(
                $row->user_id,
                $user ? $user->display_name : __('Unknown User', 'membership-discount-budget'),
                $user ? $user->user_email : '',
                $plan_name,
                $row->total_budget,
                $row->used_amount,
                $row->remaining_budget,
                $usage_percentage . '%',
            ));
        }
        
        // Close the output stream
        fclose($output);
        exit;
    }
}