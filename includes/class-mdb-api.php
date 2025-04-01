<?php
/**
 * REST API functionality.
 *
 * @package Membership_Discount_Budget
 */

defined('ABSPATH') || exit;

/**
 * MDB_API Class.
 */
class MDB_API {
    /**
     * Single instance of the class.
     *
     * @var MDB_API
     */
    protected static $_instance = null;

    /**
     * Main class instance.
     *
     * @return MDB_API
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
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_ajax_mdb_export_csv', array($this, 'export_csv'));
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        // Register customer endpoints
        register_rest_route('mdb/v1', '/budget/current', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_current_budget'),
            'permission_callback' => array($this, 'customer_permissions_check'),
        ));
        
        register_rest_route('mdb/v1', '/budget/history', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_budget_history'),
            'permission_callback' => array($this, 'customer_permissions_check'),
        ));
        
        // Register admin endpoints
        register_rest_route('mdb/v1', '/budgets', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_all_budgets'),
            'permission_callback' => array($this, 'admin_permissions_check'),
        ));
        
        register_rest_route('mdb/v1', '/budget/(?P<user_id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_user_budget'),
            'permission_callback' => array($this, 'admin_permissions_check'),
            'args' => array(
                'user_id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));
    }

    /**
     * Check if user has customer permissions.
     *
     * @return bool
     */
    public function customer_permissions_check() {
        return is_user_logged_in();
    }

    /**
     * Check if user has admin permissions.
     *
     * @return bool
     */
    public function admin_permissions_check() {
        return current_user_can('manage_woocommerce');
    }

    /**
     * Get current budget endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_current_budget($request) {
        $user_id = get_current_user_id();
        $budget = mdb_get_current_budget($user_id);
        
        if (!$budget) {
            return new WP_Error('no_budget', __('No budget found for current user.', 'membership-discount-budget'), array('status' => 404));
        }
        
        $budget_data = array(
            'user_id' => $budget->user_id,
            'membership_id' => $budget->membership_id,
            'total_budget' => (float) $budget->total_budget,
            'used_amount' => (float) $budget->used_amount,
            'remaining_budget' => (float) $budget->remaining_budget,
            'month' => (int) $budget->month,
            'year' => (int) $budget->year,
            'created_at' => $budget->created_at,
            'updated_at' => $budget->updated_at,
            'next_payment_date' => mdb_get_next_payment_date($user_id),
        );
        
        return rest_ensure_response($budget_data);
    }

    /**
     * Get budget history endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_budget_history($request) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'membership_discount_budget';
        
        $budgets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE user_id = %d ORDER BY year DESC, month DESC LIMIT 12",
            $user_id
        ));
        
        if (empty($budgets)) {
            return new WP_Error('no_budgets', __('No budget history found for current user.', 'membership-discount-budget'), array('status' => 404));
        }
        
        $budget_data = array();
        
        foreach ($budgets as $budget) {
            $budget_data[] = array(
                'user_id' => $budget->user_id,
                'membership_id' => $budget->membership_id,
                'total_budget' => (float) $budget->total_budget,
                'used_amount' => (float) $budget->used_amount,
                'remaining_budget' => (float) $budget->remaining_budget,
                'month' => (int) $budget->month,
                'year' => (int) $budget->year,
                'created_at' => $budget->created_at,
                'updated_at' => $budget->updated_at,
            );
        }
        
        return rest_ensure_response($budget_data);
    }

    /**
     * Get all budgets endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_all_budgets($request) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'membership_discount_budget';
        
        // Get query parameters
        $month = isset($request['month']) ? intval($request['month']) : current_time('n');
        $year = isset($request['year']) ? intval($request['year']) : current_time('Y');
        
        $budgets = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, u.display_name FROM {$table_name} AS b
            LEFT JOIN {$wpdb->users} AS u ON b.user_id = u.ID
            WHERE b.month = %d AND b.year = %d
            ORDER BY b.user_id ASC",
            $month, $year
        ));
        
        $budget_data = array();
        
        foreach ($budgets as $budget) {
            $user = get_user_by('id', $budget->user_id);
            $user_email = $user ? $user->user_email : '';
            
            $budget_data[] = array(
                'id' => $budget->id,
                'user_id' => $budget->user_id,
                'user_name' => $budget->display_name,
                'user_email' => $user_email,
                'membership_id' => $budget->membership_id,
                'total_budget' => (float) $budget->total_budget,
                'used_amount' => (float) $budget->used_amount,
                'remaining_budget' => (float) $budget->remaining_budget,
                'month' => (int) $budget->month,
                'year' => (int) $budget->year,
                'created_at' => $budget->created_at,
                'updated_at' => $budget->updated_at,
            );
        }
        
        return rest_ensure_response($budget_data);
    }

    /**
     * Update user budget endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function update_user_budget($request) {
        $user_id = $request['user_id'];
        $budget_data = $request->get_json_params();
        
        if (!isset($budget_data['total_budget'])) {
            return new WP_Error('missing_budget_amount', __('Total budget amount is required.', 'membership-discount-budget'), array('status' => 400));
        }
        
        $budget = mdb_get_current_budget($user_id);
        
        if (!$budget) {
            $membership_id = mdb_get_user_membership_id($user_id);
            
            if (!$membership_id) {
                return new WP_Error('no_membership', __('User does not have an active membership.', 'membership-discount-budget'), array('status' => 400));
            }
            
            $update_data = array(
                'user_id' => $user_id,
                'membership_id' => $membership_id,
                'total_budget' => floatval($budget_data['total_budget']),
                'used_amount' => isset($budget_data['used_amount']) ? floatval($budget_data['used_amount']) : 0,
                'remaining_budget' => floatval($budget_data['total_budget']),
            );
        } else {
            $remaining = max(0, floatval($budget_data['total_budget']) - $budget->used_amount);
            
            $update_data = array(
                'id' => $budget->id,
                'user_id' => $user_id,
                'membership_id' => $budget->membership_id,
                'total_budget' => floatval($budget_data['total_budget']),
                'used_amount' => isset($budget_data['used_amount']) ? floatval($budget_data['used_amount']) : $budget->used_amount,
                'remaining_budget' => $remaining,
                'month' => $budget->month,
                'year' => $budget->year,
            );
        }
        
        $result = mdb_update_budget($update_data);
        
        if (!$result) {
            return new WP_Error('update_failed', __('Failed to update budget.', 'membership-discount-budget'), array('status' => 500));
        }
        
        $updated_budget = mdb_get_current_budget($user_id);
        
        $response_data = array(
            'user_id' => $updated_budget->user_id,
            'membership_id' => $updated_budget->membership_id,
            'total_budget' => (float) $updated_budget->total_budget,
            'used_amount' => (float) $updated_budget->used_amount,
            'remaining_budget' => (float) $updated_budget->remaining_budget,
            'month' => (int) $updated_budget->month,
            'year' => (int) $updated_budget->year,
            'created_at' => $updated_budget->created_at,
            'updated_at' => $updated_budget->updated_at,
        );
        
        return rest_ensure_response($response_data);
    }

    /**
     * Export budget data as CSV.
     */
    public function export_csv() {
        // Check admin permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to export data.', 'membership-discount-budget'));
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'membership_discount_budget';
        
        // Get month and year from request
        $month = isset($_GET['month']) ? intval($_GET['month']) : current_time('n');
        $year = isset($_GET['year']) ? intval($_GET['year']) : current_time('Y');
        
        // Get budget data
        $budgets = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, u.display_name, u.user_email FROM {$table_name} AS b
            LEFT JOIN {$wpdb->users} AS u ON b.user_id = u.ID
            WHERE b.month = %d AND b.year = %d
            ORDER BY b.user_id ASC",
            $month, $year
        ));
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=discount-budgets-' . $year . '-' . $month . '.csv');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add CSV headers
        fputcsv($output, array(
            __('User ID', 'membership-discount-budget'),
            __('User Name', 'membership-discount-budget'),
            __('User Email', 'membership-discount-budget'),
            __('Membership Plan', 'membership-discount-budget'),
            __('Total Budget', 'membership-discount-budget'),
            __('Used Amount', 'membership-discount-budget'),
            __('Remaining Budget', 'membership-discount-budget'),
            __('Usage %', 'membership-discount-budget'),
            __('Month', 'membership-discount-budget'),
            __('Year', 'membership-discount-budget'),
            __('Created At', 'membership-discount-budget'),
            __('Updated At', 'membership-discount-budget'),
        ));
        
        // Add data rows
        foreach ($budgets as $budget) {
            $plan_name = __('Unknown Plan', 'membership-discount-budget');
            if (function_exists('wc_memberships_get_membership_plan')) {
                $plan = wc_memberships_get_membership_plan($budget->membership_id);
                if ($plan) {
                    $plan_name = $plan->get_name();
                }
            }
            
            $usage_percent = $budget->total_budget > 0 ? ($budget->used_amount / $budget->total_budget) * 100 : 0;
            
            fputcsv($output, array(
                $budget->user_id,
                $budget->display_name,
                $budget->user_email,
                $plan_name,
                $budget->total_budget,
                $budget->used_amount,
                $budget->remaining_budget,
                sprintf('%.2f%%', $usage_percent),
                date_i18n('F', strtotime("2023-{$budget->month}-01")),
                $budget->year,
                $budget->created_at,
                $budget->updated_at,
            ));
        }
        
        fclose($output);
        exit;
    }
}
