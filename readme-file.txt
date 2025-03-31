# Membership Discount Budget

## Description

Membership Discount Budget is a WordPress plugin that manages a monthly discount budget for WooCommerce Membership users. It allows you to set a budget limit on the total discounts that members can receive each month.

## Features

- Set a monthly budget amount for member discounts
- Track discount usage per user
- Display budget information on cart and account pages
- Prevent checkout if budget would be exceeded
- Admin dashboard for managing user budgets
- Budget reporting and analytics
- REST API for integrations
- Option to carry over unused budget to the next month
- Bulk management of user budgets

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- WooCommerce Memberships

## Installation

1. Upload the `membership-discount-budget` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Membership Budget in the admin menu to configure settings

## Configuration

### General Settings

- **Monthly Budget Amount**: Set the default monthly budget amount each member receives
- **Membership Plans**: Select which membership plans should have discount budgets
- **Carry Over Budget**: Enable to allow unused budget to carry over to the next month

### Advanced Settings

- **Cart Notice Position**: Choose where to display budget information on the cart page
- **Enable Debug Logging**: Enable logging for troubleshooting

## Usage

### For Administrators

1. **View User Budgets**: Go to Membership Budget > User Budgets to view and manage individual user budgets
2. **View Reports**: Go to Membership Budget > Reports to see budget usage statistics
3. **Reset Budgets**: You can reset individual budgets or bulk reset multiple budgets from the User Budgets page

### For Members

Members will see their budget information:

1. On their My Account page
2. On the Cart page when they add products with membership discounts
3. During checkout, they will be prevented from checking out if their purchase would exceed their budget

## Hooks and Filters

The plugin provides several hooks and filters for developers to extend its functionality:

### Actions

- `mdb_initialized`: Fired after the plugin is initialized
- `mdb_activated`: Fired when the plugin is activated
- `mdb_deactivated`: Fired when the plugin is deactivated
- `mdb_before_budget_creation`: Fired before a new budget is created
- `mdb_after_budget_creation`: Fired after a new budget is created
- `mdb_before_budget_update`: Fired before a budget is updated
- `mdb_after_budget_update`: Fired after a budget is updated
- `mdb_before_budget_reset`: Fired before a budget is reset
- `mdb_after_budget_reset`: Fired after a budget is reset
- `mdb_before_discount_usage_update`: Fired before discount usage is updated
- `mdb_after_discount_usage_update`: Fired after discount usage is updated

### Filters

- `mdb_monthly_budget_amount`: Filter the monthly budget amount for a user
- `mdb_carryover_amount`: Filter the carryover amount for a user
- `mdb_override_budget_validation`: Override budget validation check
- `mdb_product_discount_percentage`: Filter the discount percentage for a product
- `mdb_cart_item_discount`: Filter the discount amount for a cart item
- `mdb_cart_total_discount`: Filter the total discount amount for the cart
- `mdb_order_item_discount`: Filter the discount amount for an order item
- `mdb_order_total_discount`: Filter the total discount amount for an order
- `mdb_enable_api`: Enable or disable the REST API

## API Endpoints

### Customer Endpoints

- `GET /wp-json/membership-discount-budget/v1/budget`: Get current user's budget
- `GET /wp-json/membership-discount-budget/v1/budget/history`: Get budget history for current user

### Admin Endpoints

- `GET /wp-json/membership-discount-budget/v1/admin/budgets`: Get all budgets for a period
- `PUT /wp-json/membership-discount-budget/v1/admin/budget/{id}`: Update a budget

## Changelog

### 1.1.0
- Improved code organization with separate classes
- Added caching for better performance
- Added REST API endpoints
- Added reporting functionality
- Added bulk management features
- Added dashboard widget for users
- Improved UI and UX

### 1.0.0
- Initial release

## Support

For support, please contact the plugin author.

## License

This plugin is licensed under the GPL v2 or later.