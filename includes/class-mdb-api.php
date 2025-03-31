<?php
/**
 * API handler class
 * 
 * @package Membership_Discount_Budget
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles API endpoints
 */
class MDB_API {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register REST API endpoints
        add_action('rest_api_init', array($this, 'register_endpoints'));
    }
    
    /**
     * Register REST API endpoints
     */
    public function register_endpoints() {
        register_rest_route('membership-discount-budget/v1', '/budget', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user_budget'),
            'permission_callback' => array($this, 'check_user_permission'),
        ));
        
        register_rest_route('membership-discount-budget/v1', '/budget/history', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_budget_history'),
            'permission_callback' => array($this, 'check_user_permission'),
        ));
        
        // Admin endpoints
        register_rest_route('membership-discount-budget/v1', '/admin/budgets', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_all_budgets'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
        
        register_rest_route('membership-discount-budget/v1', '/admin/budget/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_budget'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args' => array(
                'remaining_budget' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= 0;
                    }
                ),
            ),
        ));
    }
    
    /**
     * Check if user has permission to access endpoint
     *
     * @return bool
     */
    public function check_user_permission() {
        return is_user_logged_in();
    }
    
    /**
     * Check if user has admin permission
     *
     * @return bool
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }
    
    /**
     * Get current user budget
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_user_budget($request) {
        $user_id = get_current_user_id();
        $budget = MDB()->budget->get_user_current_budget($user_id);
        
        if (!$budget) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('No budget found for this user.', 'membership-discount-budget')
            ), 404);
        }
        
        // Format budget data
        $budget_data = array(
            'id' => $budget->id,
            'user_id' => $budget->user_id,
            'membership_id' => $budget->membership_id,
            'total_budget' => (float) $budget->total_budget,
            'used_amount' => (float) $budget->used_amount,
            'remaining_budget' => (float) $budget->remaining_budget,
            'month' => $budget->month,
            'year' => $budget->year,
            'created_at' => $budget->created_at,
            'updated_at' => $budget->updated_at,
            'formatted' => array(
                'total_budget' => wc_price($budget->total_budget),
                'used_amount' => wc_price($budget->used_amount),
                'remaining_budget' => wc_price($budget->remaining_budget),
                'percentage_used' => $budget->total_budget > 0 ? round(($budget->used_amount / $budget->total_budget) * 100, 2) : 0,
            )
        );
        
        return new WP_REST_Response(array(
            'success' => true,
            'budget' => $budget_data
        ), 200);
    }
    
    /**
     * Get budget history
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_budget_history($request) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        $limit = isset($request['limit']) ? intval($request['limit']) : 12;
        $limit = min(24, max(1, $limit)); // Ensure limit is between 1 and 24
        
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        // Get budget history
        $budgets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY year DESC, month DESC LIMIT %d",
            $user_id, $limit
        ));
        
        if (empty($budgets)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('No budget history found for this user.', 'membership-discount-budget')
            ), 404);
        }
        
        // Format budget data
        $budget_history = array();
        
        foreach ($budgets as $budget) {
            $budget_data = array(
                'id' => $budget->id,
                'total_budget' => (float) $budget->total_budget,
                'used_amount' => (float) $budget->used_amount,
                'remaining_budget' => (float) $budget->remaining_budget,
                'month' => $budget->month,
                'year' => $budget->year,
                'month_name' => date('F', mktime(0, 0, 0, $budget->month, 1)),
                'formatted' => array(
                    'total_budget' => wc_price($budget->total_budget),
                    'used_amount' => wc_price($budget->used_amount),
                    'remaining_budget' => wc_price($budget->remaining_budget),
                    'percentage_used' => $budget->total_budget > 0 ? round(($budget->used_amount / $budget->total_budget) * 100, 2) : 0,
                )
            );
            
            $budget_history[] = $budget_data;
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'history' => $budget_history
        ), 200);
    }
    
    /**
     * Get all budgets (admin only)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function get_all_budgets($request) {
        global $wpdb;
        
        $month = isset($request['month']) ? intval($request['month']) : date('n');
        $year = isset($request['year']) ? intval($request['year']) : date('Y');
        
        $table = $wpdb->prefix . 'membership_discount_budget';
        
        // Get all budgets for the specified month and year
        $budgets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE month = %d AND year = %d ORDER BY user_id ASC",
            $month, $year
        ));
        
        if (empty($budgets)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('No budgets found for this period.', 'membership-discount-budget')
            ), 404);
        }
        
        // Format budget data
        $budget_data = array();
        
        foreach ($budgets as $budget) {
            $user = get_user_by('id', $budget->user_id);
            $membership = wc_memberships_get_user_membership($budget->membership_id);
            
            $budget_item = array(
                'id' => $budget->id,
                'user_id' => $budget->user_id,
                'user_name' => $user ? $user->display_name : __('Unknown User', 'membership-discount-budget'),
                'user_email' => $user ? $user->user_email : '',
                'membership_id' => $budget->membership_id,
                'membership_plan' => $membership ? get_the_title($membership->get_plan_id()) : __('Unknown Plan', 'membership-discount-budget'),
                'total_budget' => (float) $budget->total_budget,
                'used_amount' => (float) $budget->used_amount,
                'remaining_budget' => (float) $budget->remaining_budget,
                'month' => $budget->month,
                'year' => $budget->year,
                'created_at' => $budget->created_at,
                'updated_at' => $budget->updated_at,
                'formatted' => array(
                    'total_budget' => wc_price($budget->total_budget),
                    'used_amount' => wc_price($budget->used_amount),
                    'remaining_budget' => wc_price($budget->remaining_budget),
                    'percentage_used' => $budget->total_budget > 0 ? round(($budget->used_amount / $budget->total_budget) * 100, 2) : 0,
                )
            );
            
            $budget_data[] = $budget_item;
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'budgets' => $budget_data
        ), 200);
    }
    
    /**
     * Update budget (admin only)
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function update_budget($request) {
        $budget_id = $request['id'];
        $new_budget = isset($request['remaining_budget']) ? floatval($request['remaining_budget']) : 0;
        
        $result = MDB()->budget->update_user_budget($budget_id, $new_budget);
        
        if (!$result) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Budget not found or could not be updated.', 'membership-discount-budget')
            ), 404);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => __('Budget updated successfully.', 'membership-discount-budget')
        ), 200);
    }
}