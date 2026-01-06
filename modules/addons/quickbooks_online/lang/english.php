<?php
/**
 * QuickBooks Online for WHMCS - English Language File
 */

$_ADDONLANG['module_name'] = 'QuickBooks Online';
$_ADDONLANG['module_description'] = 'Sync clients, invoices, payments, and refunds with QuickBooks Online';

// Dashboard
$_ADDONLANG['dashboard'] = 'Dashboard';
$_ADDONLANG['clients'] = 'Clients';
$_ADDONLANG['invoices'] = 'Invoices';
$_ADDONLANG['payments'] = 'Payments';
$_ADDONLANG['credits_refunds'] = 'Credits & Refunds';

// Configuration
$_ADDONLANG['connection'] = 'Connection';
$_ADDONLANG['tax_mapping'] = 'Tax Mapping';
$_ADDONLANG['payment_gateways'] = 'Payment Gateways';
$_ADDONLANG['products_items'] = 'Products/Items';
$_ADDONLANG['settings'] = 'Settings';
$_ADDONLANG['sync_logs'] = 'Sync Logs';

// Actions
$_ADDONLANG['sync'] = 'Sync';
$_ADDONLANG['sync_all'] = 'Sync All';
$_ADDONLANG['lock'] = 'Lock';
$_ADDONLANG['unlock'] = 'Unlock';
$_ADDONLANG['unlink'] = 'Unlink';
$_ADDONLANG['save'] = 'Save';
$_ADDONLANG['connect'] = 'Connect';
$_ADDONLANG['disconnect'] = 'Disconnect';
$_ADDONLANG['filter'] = 'Filter';
$_ADDONLANG['clear_logs'] = 'Clear Logs';

// Status
$_ADDONLANG['synced'] = 'Synced';
$_ADDONLANG['not_synced'] = 'Not Synced';
$_ADDONLANG['locked'] = 'Locked';
$_ADDONLANG['connected'] = 'Connected';
$_ADDONLANG['not_connected'] = 'Not Connected';
$_ADDONLANG['success'] = 'Success';
$_ADDONLANG['error'] = 'Error';
$_ADDONLANG['skipped'] = 'Skipped';

// Messages
$_ADDONLANG['connect_success'] = 'Successfully connected to QuickBooks Online!';
$_ADDONLANG['connect_error'] = 'Failed to connect to QuickBooks Online.';
$_ADDONLANG['disconnect_success'] = 'Disconnected from QuickBooks Online.';
$_ADDONLANG['sync_success'] = 'Sync completed successfully.';
$_ADDONLANG['sync_error'] = 'Sync failed.';
$_ADDONLANG['settings_saved'] = 'Settings saved successfully.';
$_ADDONLANG['mappings_saved'] = 'Mappings saved successfully.';

// Table Headers
$_ADDONLANG['id'] = 'ID';
$_ADDONLANG['name'] = 'Name';
$_ADDONLANG['email'] = 'Email';
$_ADDONLANG['company'] = 'Company';
$_ADDONLANG['date'] = 'Date';
$_ADDONLANG['amount'] = 'Amount';
$_ADDONLANG['total'] = 'Total';
$_ADDONLANG['status'] = 'Status';
$_ADDONLANG['gateway'] = 'Gateway';
$_ADDONLANG['last_sync'] = 'Last Sync';
$_ADDONLANG['actions'] = 'Actions';
$_ADDONLANG['qb_id'] = 'QB ID';
$_ADDONLANG['message'] = 'Message';
$_ADDONLANG['type'] = 'Type';
$_ADDONLANG['action'] = 'Action';
$_ADDONLANG['time'] = 'Time';

// Sync Types
$_ADDONLANG['type_client'] = 'Client';
$_ADDONLANG['type_invoice'] = 'Invoice';
$_ADDONLANG['type_payment'] = 'Payment';
$_ADDONLANG['type_credit'] = 'Credit';
$_ADDONLANG['type_refund'] = 'Refund';

// Settings Labels
$_ADDONLANG['client_id'] = 'Client ID';
$_ADDONLANG['client_secret'] = 'Client Secret';
$_ADDONLANG['environment'] = 'Environment';
$_ADDONLANG['sandbox'] = 'Sandbox';
$_ADDONLANG['production'] = 'Production';
$_ADDONLANG['auto_sync'] = 'Auto Sync';
$_ADDONLANG['sync_zero_invoices'] = 'Sync Zero Amount Invoices';
$_ADDONLANG['tax_id_custom_field'] = 'Tax ID Custom Field';
$_ADDONLANG['default_income_account'] = 'Default Income Account';
$_ADDONLANG['sync_on_create'] = 'Sync on Create';
$_ADDONLANG['sync_on_update'] = 'Sync on Update';
$_ADDONLANG['log_retention_days'] = 'Log Retention (days)';

// Help Text
$_ADDONLANG['help_client_id'] = 'QuickBooks Online OAuth 2.0 Client ID from Intuit Developer Portal';
$_ADDONLANG['help_client_secret'] = 'QuickBooks Online OAuth 2.0 Client Secret';
$_ADDONLANG['help_environment'] = 'Use sandbox for testing, production for live';
$_ADDONLANG['help_auto_sync'] = 'Enable automatic synchronization via hooks';
$_ADDONLANG['help_sync_zero_invoices'] = 'Include invoices with zero amount in sync';
$_ADDONLANG['help_tax_custom_field'] = 'WHMCS custom field name containing Tax ID/VAT number';

// Warnings
$_ADDONLANG['warning_not_connected'] = 'QuickBooks Online is not connected. Please connect first.';
$_ADDONLANG['warning_not_configured'] = 'Please configure your QuickBooks OAuth credentials first.';
$_ADDONLANG['warning_token_expiring'] = 'Your QuickBooks connection will expire soon. Please reconnect.';

// Confirmations
$_ADDONLANG['confirm_disconnect'] = 'Are you sure you want to disconnect from QuickBooks?';
$_ADDONLANG['confirm_unlink'] = 'Are you sure you want to unlink this item?';
$_ADDONLANG['confirm_clear_logs'] = 'Are you sure you want to clear all logs?';
