<?php
/**
 * User budgets list table.
 *
 * @package Membership_Discount_Budget
 */

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * MDB_User_Budgets_List_Table Class.
 */
class MDB_User_Budgets_List_Table extends WP_List_Table {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(array(
            'singular' => 'budget',
            'plural'   => 'budgets',
            'ajax'     => false,
        ));
    }

    /**
     * Get table columns.
     *
     * @return array
     */
    public function get_columns() {
        return array(
            'cb'              => '<input type="checkbox" />',
            'user'            => __('User', 'membership-discount-budget'),
            'membership_plan' => __('Membership Plan', 'membership-discount-budget'),
            'total_budget'    => __('Total Budget', 'membership-discount-budget'),
            'used_amount'     => __('Used Amount', 'membership-discount-budget'),
            'remaining'       => __('Remaining', 'membership-discount-budget'),
            'usage_percent'   => __('Usage %', 'membership-discount-budget'),
            'date'            => __('Month / Year', 'membership-discount-budget'),
            'reset_date'      => __('Next Payment Date', 'membership-discount-budget'),
            'actions'         => __('Actions', 'membership-discount-budget'),
        );
    }

    /**
     * Get sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'user'          => array('user_id', false),
            'total_budget'  => array('total_budget', false),
            'used_amount'   => array('used_amount', false),
            'remaining'     => array('remaining_budget', false),
            'usage_percent' => array('usage_percent', false),
            'date'          => array('date', false),
        );
    }

    /**
     * Get bulk actions.
     *
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'bulk-reset' => __('Reset Budget', 'membership-discount-budget'),
        );
    }

    /**
     * Process bulk actions.
     */
    public function process_bulk_action() {
        // Handled in the admin class
    }

    /**
     * Prepare items for the table.
     */
    public function prepare_items() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'membership_discount_budget';
        
        // Set up pagination
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = $wpdb->get_var("SELECT COUNT(id) FROM {$table_name}");
        
        // Set up columns
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        // Process bulk actions
        $this->process_bulk_action();
        
        // Handle search
        $search = isset($_REQUEST['s']) ? trim($_REQUEST['s']) : '';
        
        // Handle month/year filter
        $month = isset($_REQUEST['month']) ? intval($_REQUEST['month']) : current_time('n');
        $year = isset($_REQUEST['year']) ? intval($_REQUEST['year']) : current_time('Y');
        
        // Prepare the query
        $query = "SELECT b.*, u.display_name 
                 FROM {$table_name} AS b
                 LEFT JOIN {$wpdb->users} AS u ON b.user_id = u.ID
                 WHERE b.month = %d AND b.year = %d";
        
        $query_args = array($month, $year);
        
        // Add search
        if (!empty($search)) {
            $query .= " AND (u.display_name LIKE %s OR u.user_email LIKE %s)";
            $query_args[] = '%' . $wpdb->esc_like($search) . '%';
            $query_args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        // Add sorting
        $orderby = isset($_REQUEST['orderby']) ? $_REQUEST['orderby'] : 'user_id';
        $order = isset($_REQUEST['order']) ? $_REQUEST['order'] : 'ASC';
        
        // Map custom orderby values
        $orderby_mapping = array(
            'user' => 'user_id',
            'total_budget' => 'total_budget',
            'used_amount' => 'used_amount',
            'remaining' => 'remaining_budget',
            'usage_percent' => '(used_amount / total_budget)',
            'date' => 'CONCAT(year, month)',
        );
        
        $orderby = isset($orderby_mapping[$orderby]) ? $orderby_mapping[$orderby] : 'user_id';
        $order = in_array(strtoupper($order), array('ASC', 'DESC')) ? strtoupper($order) : 'ASC';
        
        $query .= " ORDER BY {$orderby} {$order}";
        
        // Add pagination
        $query .= " LIMIT %d OFFSET %d";
        $query_args[] = $per_page;
        $query_args[] = ($current_page - 1) * $per_page;
        
        // Get the results
        $this->items = $wpdb->get_results($wpdb->prepare($query, $query_args));
        
        // Set up pagination
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ));
    }

    /**
     * Extra controls to be displayed between bulk actions and pagination.
     *
     * @param string $which 'top' or 'bottom' table section.
     */
    protected function extra_tablenav($which) {
        if ('top' === $which) {
            $month = isset($_REQUEST['month']) ? intval($_REQUEST['month']) : current_time('n');
            $year = isset($_REQUEST['year']) ? intval($_REQUEST['year']) : current_time('Y');
            ?>
            <div class="alignleft actions">
                <label for="filter-by-month" class="screen-reader-text"><?php _e('Filter by month', 'membership-discount-budget'); ?></label>
                <select name="month" id="filter-by-month">
                    <?php
                    for ($m = 1; $m <= 12; $m++) {
                        printf(
                            '<option value="%1$d" %2$s>%3$s</option>',
                            $m,
                            selected($month, $m, false),
                            date_i18n('F', strtotime("2023-{$m}-01"))
                        );
                    }
                    ?>
                </select>
                
                <label for="filter-by-year" class="screen-reader-text"><?php _e('Filter by year', 'membership-discount-budget'); ?></label>
                <select name="year" id="filter-by-year">
                    <?php
                    $current_year = current_time('Y');
                    for ($y = $current_year - 2; $y <= $current_year + 1; $y++) {
                        printf(
                            '<option value="%1$d" %2$s>%1$d</option>',
                            $y,
                            selected($year, $y, false)
                        );
                    }
                    ?>
                </select>
                
                <?php submit_button(__('Filter', 'membership-discount-budget'), '', 'filter_action', false); ?>
            </div>
            <?php
        }
    }

    /**
     * Render the checkbox column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="%1$s[]" value="%2$s" />',
            $this->_args['singular'],
            $item->user_id
        );
    }

    /**
     * Render the user column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_user($item) {
        $user = get_user_by('id', $item->user_id);
        
        if (!$user) {
            return __('Unknown User', 'membership-discount-budget');
        }
        
        $edit_link = get_edit_user_link($user->ID);
        
        $output = '<strong><a href="' . esc_url($edit_link) . '">' . esc_html($user->display_name) . '</a></strong>';
        $output .= '<br><span class="description">' . esc_html($user->user_email) . '</span>';
        
        return $output;
    }

    /**
     * Render the membership plan column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_membership_plan($item) {
        if (!function_exists('wc_memberships_get_membership_plan')) {
            return __('Unknown Plan', 'membership-discount-budget');
        }
        
        $plan = wc_memberships_get_membership_plan($item->membership_id);
        
        if (!$plan) {
            return __('Unknown Plan', 'membership-discount-budget');
        }
        
        return esc_html($plan->get_name());
    }

    /**
     * Render the total budget column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_total_budget($item) {
        return wc_price($item->total_budget);
    }

    /**
     * Render the used amount column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_used_amount($item) {
        return wc_price($item->used_amount);
    }

    /**
     * Render the remaining column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_remaining($item) {
        return wc_price($item->remaining_budget);
    }

    /**
     * Render the usage percent column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_usage_percent($item) {
        if ($item->total_budget <= 0) {
            return '0%';
        }
        
        $percentage = ($item->used_amount / $item->total_budget) * 100;
        
        $output = sprintf('%.1f%%', $percentage);
        
        // Add progress bar
        $output .= '<div class="mdb-progress">';
        $output .= '<div class="mdb-progress-bar ' . ($percentage > 50 ? 'warning' : '') . '" style="width: ' . esc_attr($percentage) . '%;"></div>';
        $output .= '</div>';
        
        return $output;
    }

    /**
     * Render the date column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_date($item) {
        $date = date_i18n('F Y', strtotime("{$item->year}-{$item->month}-01"));
        return esc_html($date);
    }

    /**
     * Render the reset date column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_reset_date($item) {
        $next_payment = mdb_get_next_payment_date($item->user_id);
        
        if (!$next_payment) {
            return __('Unknown', 'membership-discount-budget');
        }
        
        return date_i18n(get_option('date_format'), strtotime($next_payment));
    }

    /**
     * Render the actions column.
     *
     * @param object $item Current item.
     * @return string
     */
    public function column_actions($item) {
        $actions = array(
            'edit' => sprintf(
                '<a href="#" class="mdb-edit-budget" data-user="%1$s" data-budget="%2$s">%3$s</a>',
                esc_attr($item->user_id),
                esc_attr($item->total_budget),
                __('Edit', 'membership-discount-budget')
            ),
            'reset' => sprintf(
                '<a href="#" class="mdb-reset-budget" data-user="%1$s">%2$s</a>',
                esc_attr($item->user_id),
                __('Reset', 'membership-discount-budget')
            ),
        );
        
        return implode(' | ', $actions);
    }

    /**
     * Render a column that doesn't have a specific method.
     *
     * @param object $item Current item.
     * @param string $column_name Column name.
     * @return string
     */
    public function column_default($item, $column_name) {
        return print_r($item, true);
    }
}
