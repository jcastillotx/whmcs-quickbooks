<?php
/**
 * QuickBooks Online for WHMCS
 *
 * A comprehensive integration module for syncing WHMCS data with QuickBooks Online.
 *
 * @package    WHMCS
 * @subpackage QuickBooksOnline
 * @version    1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Module configuration
 */
function quickbooks_online_config()
{
    return [
        'name' => 'QuickBooks Online',
        'description' => 'Sync clients, invoices, payments, and refunds with QuickBooks Online',
        'version' => '1.0.0',
        'author' => 'WHMCS QuickBooks Integration',
        'language' => 'english',
        'fields' => [
            'client_id' => [
                'FriendlyName' => 'Client ID',
                'Type' => 'text',
                'Size' => '50',
                'Description' => 'QuickBooks Online OAuth 2.0 Client ID',
            ],
            'client_secret' => [
                'FriendlyName' => 'Client Secret',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'QuickBooks Online OAuth 2.0 Client Secret',
            ],
            'environment' => [
                'FriendlyName' => 'Environment',
                'Type' => 'dropdown',
                'Options' => 'sandbox,production',
                'Default' => 'sandbox',
                'Description' => 'QuickBooks environment (sandbox for testing, production for live)',
            ],
            'auto_sync' => [
                'FriendlyName' => 'Auto Sync',
                'Type' => 'yesno',
                'Description' => 'Enable automatic synchronization via hooks',
            ],
            'sync_zero_invoices' => [
                'FriendlyName' => 'Sync Zero Amount Invoices',
                'Type' => 'yesno',
                'Description' => 'Include invoices with zero amount in sync',
            ],
            'tax_custom_field' => [
                'FriendlyName' => 'Tax ID Custom Field',
                'Type' => 'text',
                'Size' => '30',
                'Description' => 'WHMCS custom field name for Tax ID/VAT number',
            ],
        ],
    ];
}

/**
 * Module activation
 */
function quickbooks_online_activate()
{
    try {
        // Create OAuth tokens table
        if (!Capsule::schema()->hasTable('mod_quickbooks_oauth')) {
            Capsule::schema()->create('mod_quickbooks_oauth', function ($table) {
                $table->increments('id');
                $table->string('realm_id', 50)->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->integer('access_token_expires')->nullable();
                $table->integer('refresh_token_expires')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
            });
        }

        // Create sync mappings table for clients
        if (!Capsule::schema()->hasTable('mod_quickbooks_clients')) {
            Capsule::schema()->create('mod_quickbooks_clients', function ($table) {
                $table->increments('id');
                $table->integer('whmcs_client_id')->unique();
                $table->string('qb_customer_id', 50);
                $table->boolean('locked')->default(false);
                $table->timestamp('last_sync')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
            });
        }

        // Create sync mappings table for invoices
        if (!Capsule::schema()->hasTable('mod_quickbooks_invoices')) {
            Capsule::schema()->create('mod_quickbooks_invoices', function ($table) {
                $table->increments('id');
                $table->integer('whmcs_invoice_id')->unique();
                $table->string('qb_invoice_id', 50);
                $table->string('qb_sync_token', 20)->nullable();
                $table->boolean('locked')->default(false);
                $table->timestamp('last_sync')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
            });
        }

        // Create sync mappings table for payments
        if (!Capsule::schema()->hasTable('mod_quickbooks_payments')) {
            Capsule::schema()->create('mod_quickbooks_payments', function ($table) {
                $table->increments('id');
                $table->integer('whmcs_transaction_id')->unique();
                $table->string('qb_payment_id', 50);
                $table->boolean('locked')->default(false);
                $table->timestamp('last_sync')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
            });
        }

        // Create sync mappings table for credits/refunds
        if (!Capsule::schema()->hasTable('mod_quickbooks_credits')) {
            Capsule::schema()->create('mod_quickbooks_credits', function ($table) {
                $table->increments('id');
                $table->integer('whmcs_credit_id');
                $table->string('type', 20); // credit, refund
                $table->string('qb_creditmemo_id', 50)->nullable();
                $table->string('qb_refund_id', 50)->nullable();
                $table->boolean('locked')->default(false);
                $table->timestamp('last_sync')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->unique(['whmcs_credit_id', 'type']);
            });
        }

        // Create tax mappings table
        if (!Capsule::schema()->hasTable('mod_quickbooks_taxes')) {
            Capsule::schema()->create('mod_quickbooks_taxes', function ($table) {
                $table->increments('id');
                $table->integer('whmcs_tax_id')->unique();
                $table->string('qb_tax_code_id', 50);
                $table->string('qb_tax_rate_id', 50)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
            });
        }

        // Create payment method mappings table
        if (!Capsule::schema()->hasTable('mod_quickbooks_payment_methods')) {
            Capsule::schema()->create('mod_quickbooks_payment_methods', function ($table) {
                $table->increments('id');
                $table->string('whmcs_gateway', 100)->unique();
                $table->string('qb_payment_method_id', 50);
                $table->string('qb_deposit_account_id', 50)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
            });
        }

        // Create sync log table
        if (!Capsule::schema()->hasTable('mod_quickbooks_logs')) {
            Capsule::schema()->create('mod_quickbooks_logs', function ($table) {
                $table->increments('id');
                $table->string('type', 50); // client, invoice, payment, credit, refund
                $table->string('action', 50); // create, update, delete
                $table->integer('whmcs_id');
                $table->string('qb_id', 50)->nullable();
                $table->string('status', 20); // success, error
                $table->text('message')->nullable();
                $table->text('request_data')->nullable();
                $table->text('response_data')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['type', 'created_at']);
            });
        }

        // Create items/products mapping table
        if (!Capsule::schema()->hasTable('mod_quickbooks_items')) {
            Capsule::schema()->create('mod_quickbooks_items', function ($table) {
                $table->increments('id');
                $table->string('whmcs_item_type', 50); // product, addon, domain, configoption
                $table->integer('whmcs_item_id');
                $table->string('qb_item_id', 50);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
                $table->unique(['whmcs_item_type', 'whmcs_item_id']);
            });
        }

        // Create settings table for additional configuration
        if (!Capsule::schema()->hasTable('mod_quickbooks_settings')) {
            Capsule::schema()->create('mod_quickbooks_settings', function ($table) {
                $table->increments('id');
                $table->string('setting_key', 100)->unique();
                $table->text('setting_value')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent();
            });
        }

        return [
            'status' => 'success',
            'description' => 'QuickBooks Online module activated successfully. Please configure your OAuth credentials.',
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Error activating module: ' . $e->getMessage(),
        ];
    }
}

/**
 * Module deactivation
 */
function quickbooks_online_deactivate()
{
    try {
        // Drop all module tables
        $tables = [
            'mod_quickbooks_oauth',
            'mod_quickbooks_clients',
            'mod_quickbooks_invoices',
            'mod_quickbooks_payments',
            'mod_quickbooks_credits',
            'mod_quickbooks_taxes',
            'mod_quickbooks_payment_methods',
            'mod_quickbooks_logs',
            'mod_quickbooks_items',
            'mod_quickbooks_settings',
        ];

        foreach ($tables as $table) {
            Capsule::schema()->dropIfExists($table);
        }

        return [
            'status' => 'success',
            'description' => 'QuickBooks Online module deactivated successfully.',
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Error deactivating module: ' . $e->getMessage(),
        ];
    }
}

/**
 * Module upgrade
 */
function quickbooks_online_upgrade($vars)
{
    $version = $vars['version'];

    // Add upgrade logic for future versions here

    return [
        'status' => 'success',
        'description' => 'Upgrade completed successfully.',
    ];
}

/**
 * Admin area output
 */
function quickbooks_online_output($vars)
{
    $modulelink = $vars['modulelink'];
    $action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';

    // Include admin controller
    require_once __DIR__ . '/lib/Admin/AdminController.php';

    $controller = new \QuickBooksOnline\Admin\AdminController($vars);

    echo $controller->dispatch($action);
}

/**
 * Admin area sidebar output
 */
function quickbooks_online_sidebar($vars)
{
    $modulelink = $vars['modulelink'];

    $sidebar = '<span class="header"><i class="fas fa-sync"></i> QuickBooks Sync</span>
        <ul class="menu">
            <li><a href="' . $modulelink . '&action=dashboard">Dashboard</a></li>
            <li><a href="' . $modulelink . '&action=clients">Clients</a></li>
            <li><a href="' . $modulelink . '&action=invoices">Invoices</a></li>
            <li><a href="' . $modulelink . '&action=payments">Payments</a></li>
            <li><a href="' . $modulelink . '&action=credits">Credits & Refunds</a></li>
        </ul>
        <span class="header"><i class="fas fa-cog"></i> Configuration</span>
        <ul class="menu">
            <li><a href="' . $modulelink . '&action=connection">Connection</a></li>
            <li><a href="' . $modulelink . '&action=taxes">Tax Mapping</a></li>
            <li><a href="' . $modulelink . '&action=gateways">Payment Gateways</a></li>
            <li><a href="' . $modulelink . '&action=items">Products/Items</a></li>
            <li><a href="' . $modulelink . '&action=settings">Settings</a></li>
        </ul>
        <span class="header"><i class="fas fa-history"></i> Logs</span>
        <ul class="menu">
            <li><a href="' . $modulelink . '&action=logs">Sync Logs</a></li>
        </ul>';

    return $sidebar;
}
