<?php
/**
 * QuickBooks Online WHMCS Hooks
 *
 * Provides real-time synchronization with QuickBooks Online when
 * clients, invoices, and payments are created or updated in WHMCS.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Check if auto-sync is enabled
 */
function quickbooks_is_autosync_enabled()
{
    static $enabled = null;

    if ($enabled === null) {
        try {
            $setting = Capsule::table('tbladdonmodules')
                ->where('module', 'quickbooks_online')
                ->where('setting', 'auto_sync')
                ->value('value');

            $enabled = $setting === 'on' || $setting === '1';
        } catch (\Exception $e) {
            $enabled = false;
        }
    }

    return $enabled;
}

/**
 * Check if sync on create is enabled
 */
function quickbooks_sync_on_create_enabled()
{
    try {
        $setting = Capsule::table('mod_quickbooks_settings')
            ->where('setting_key', 'sync_on_create')
            ->value('setting_value');

        return $setting === '1';
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Check if sync on update is enabled
 */
function quickbooks_sync_on_update_enabled()
{
    try {
        $setting = Capsule::table('mod_quickbooks_settings')
            ->where('setting_key', 'sync_on_update')
            ->value('setting_value');

        return $setting === '1';
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Get QuickBooks client instance
 */
function quickbooks_get_client()
{
    static $client = null;

    if ($client === null) {
        try {
            $settings = Capsule::table('tbladdonmodules')
                ->where('module', 'quickbooks_online')
                ->pluck('value', 'setting');

            if (empty($settings['client_id']) || empty($settings['client_secret'])) {
                return null;
            }

            require_once __DIR__ . '/lib/QuickBooksClient.php';

            $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
            $redirectUri = rtrim($systemUrl, '/') . '/admin/addonmodules.php?module=quickbooks_online';

            $client = new \QuickBooksOnline\QuickBooksClient(
                $settings['client_id'],
                $settings['client_secret'],
                $redirectUri,
                $settings['environment'] ?? 'sandbox'
            );

            if (!$client->isConnected()) {
                return null;
            }
        } catch (\Exception $e) {
            error_log('QuickBooks Hook Error: ' . $e->getMessage());
            return null;
        }
    }

    return $client;
}

/**
 * Sync a client to QuickBooks
 */
function quickbooks_sync_client($clientId, $isUpdate = false)
{
    if (!quickbooks_is_autosync_enabled()) {
        return;
    }

    if ($isUpdate && !quickbooks_sync_on_update_enabled()) {
        return;
    }

    if (!$isUpdate && !quickbooks_sync_on_create_enabled()) {
        return;
    }

    $client = quickbooks_get_client();
    if (!$client) {
        return;
    }

    try {
        require_once __DIR__ . '/lib/Logger.php';
        require_once __DIR__ . '/lib/Sync/ClientSync.php';

        $settings = Capsule::table('tbladdonmodules')
            ->where('module', 'quickbooks_online')
            ->pluck('value', 'setting')
            ->toArray();

        $clientSync = new \QuickBooksOnline\Sync\ClientSync($client, $settings);
        $clientSync->syncClient($clientId);
    } catch (\Exception $e) {
        error_log('QuickBooks Client Sync Error: ' . $e->getMessage());
    }
}

/**
 * Sync an invoice to QuickBooks
 */
function quickbooks_sync_invoice($invoiceId, $isUpdate = false)
{
    if (!quickbooks_is_autosync_enabled()) {
        return;
    }

    if ($isUpdate && !quickbooks_sync_on_update_enabled()) {
        return;
    }

    if (!$isUpdate && !quickbooks_sync_on_create_enabled()) {
        return;
    }

    $client = quickbooks_get_client();
    if (!$client) {
        return;
    }

    try {
        require_once __DIR__ . '/lib/Logger.php';
        require_once __DIR__ . '/lib/Sync/ClientSync.php';
        require_once __DIR__ . '/lib/Sync/InvoiceSync.php';

        $settings = Capsule::table('tbladdonmodules')
            ->where('module', 'quickbooks_online')
            ->pluck('value', 'setting')
            ->toArray();

        $clientSync = new \QuickBooksOnline\Sync\ClientSync($client, $settings);
        $invoiceSync = new \QuickBooksOnline\Sync\InvoiceSync($client, $clientSync, $settings);
        $invoiceSync->syncInvoice($invoiceId);
    } catch (\Exception $e) {
        error_log('QuickBooks Invoice Sync Error: ' . $e->getMessage());
    }
}

/**
 * Sync a payment to QuickBooks
 */
function quickbooks_sync_payment($invoiceId, $transactionId = null)
{
    if (!quickbooks_is_autosync_enabled()) {
        return;
    }

    if (!quickbooks_sync_on_create_enabled()) {
        return;
    }

    $client = quickbooks_get_client();
    if (!$client) {
        return;
    }

    try {
        require_once __DIR__ . '/lib/Logger.php';
        require_once __DIR__ . '/lib/Sync/ClientSync.php';
        require_once __DIR__ . '/lib/Sync/InvoiceSync.php';
        require_once __DIR__ . '/lib/Sync/PaymentSync.php';

        $settings = Capsule::table('tbladdonmodules')
            ->where('module', 'quickbooks_online')
            ->pluck('value', 'setting')
            ->toArray();

        $clientSync = new \QuickBooksOnline\Sync\ClientSync($client, $settings);
        $invoiceSync = new \QuickBooksOnline\Sync\InvoiceSync($client, $clientSync, $settings);
        $paymentSync = new \QuickBooksOnline\Sync\PaymentSync($client, $clientSync, $invoiceSync, $settings);

        // Find the transaction ID if not provided
        if (!$transactionId) {
            $transaction = Capsule::table('tblaccounts')
                ->where('invoiceid', $invoiceId)
                ->where('amountin', '>', 0)
                ->orderBy('id', 'desc')
                ->first();

            if ($transaction) {
                $transactionId = $transaction->id;
            }
        }

        if ($transactionId) {
            $paymentSync->syncPayment($transactionId);
        }
    } catch (\Exception $e) {
        error_log('QuickBooks Payment Sync Error: ' . $e->getMessage());
    }
}

/**
 * Sync a credit to QuickBooks
 */
function quickbooks_sync_credit($clientId, $amount)
{
    if (!quickbooks_is_autosync_enabled()) {
        return;
    }

    if (!quickbooks_sync_on_create_enabled()) {
        return;
    }

    $client = quickbooks_get_client();
    if (!$client) {
        return;
    }

    try {
        require_once __DIR__ . '/lib/Logger.php';
        require_once __DIR__ . '/lib/Sync/ClientSync.php';
        require_once __DIR__ . '/lib/Sync/CreditSync.php';

        $settings = Capsule::table('tbladdonmodules')
            ->where('module', 'quickbooks_online')
            ->pluck('value', 'setting')
            ->toArray();

        // Find the most recent credit for this client
        $credit = Capsule::table('tblcredit')
            ->where('clientid', $clientId)
            ->orderBy('id', 'desc')
            ->first();

        if ($credit) {
            $clientSync = new \QuickBooksOnline\Sync\ClientSync($client, $settings);
            $creditSync = new \QuickBooksOnline\Sync\CreditSync($client, $clientSync, $settings);
            $creditSync->syncCredit($credit->id);
        }
    } catch (\Exception $e) {
        error_log('QuickBooks Credit Sync Error: ' . $e->getMessage());
    }
}

// ============================================
// Hook Registrations
// ============================================

/**
 * Client Created Hook
 */
add_hook('ClientAdd', 1, function ($vars) {
    quickbooks_sync_client($vars['userid'], false);
});

/**
 * Client Updated Hook
 */
add_hook('ClientEdit', 1, function ($vars) {
    quickbooks_sync_client($vars['userid'], true);
});

/**
 * Invoice Created Hook
 */
add_hook('InvoiceCreated', 1, function ($vars) {
    quickbooks_sync_invoice($vars['invoiceid'], false);
});

/**
 * Invoice Updated/Modified Hook
 */
add_hook('UpdateInvoiceTotal', 1, function ($vars) {
    quickbooks_sync_invoice($vars['invoiceid'], true);
});

/**
 * Invoice Paid Hook
 */
add_hook('InvoicePaid', 1, function ($vars) {
    // First update the invoice in QB (status change)
    quickbooks_sync_invoice($vars['invoiceid'], true);

    // Then sync the payment
    quickbooks_sync_payment($vars['invoiceid']);
});

/**
 * Payment Added Hook
 */
add_hook('InvoicePaymentAdded', 1, function ($vars) {
    quickbooks_sync_payment($vars['invoiceid']);
});

/**
 * Add Invoice Payment Hook (manual payment)
 */
add_hook('AddInvoicePayment', 1, function ($vars) {
    quickbooks_sync_payment($vars['invoiceid']);
});

/**
 * Credit Added Hook
 */
add_hook('CreditAdded', 1, function ($vars) {
    quickbooks_sync_credit($vars['clientid'], $vars['amount']);
});

/**
 * Refund Processed Hook
 */
add_hook('RefundProcessed', 1, function ($vars) {
    if (!quickbooks_is_autosync_enabled()) {
        return;
    }

    if (!quickbooks_sync_on_create_enabled()) {
        return;
    }

    $client = quickbooks_get_client();
    if (!$client) {
        return;
    }

    try {
        require_once __DIR__ . '/lib/Logger.php';
        require_once __DIR__ . '/lib/Sync/ClientSync.php';
        require_once __DIR__ . '/lib/Sync/CreditSync.php';

        $settings = Capsule::table('tbladdonmodules')
            ->where('module', 'quickbooks_online')
            ->pluck('value', 'setting')
            ->toArray();

        // Find the refund transaction
        $transaction = Capsule::table('tblaccounts')
            ->where('invoiceid', $vars['invoiceid'])
            ->where('amountout', '>', 0)
            ->orderBy('id', 'desc')
            ->first();

        if ($transaction) {
            $clientSync = new \QuickBooksOnline\Sync\ClientSync($client, $settings);
            $creditSync = new \QuickBooksOnline\Sync\CreditSync($client, $clientSync, $settings);
            $creditSync->syncRefund($transaction->id);
        }
    } catch (\Exception $e) {
        error_log('QuickBooks Refund Sync Error: ' . $e->getMessage());
    }
});

/**
 * Invoice Cancelled Hook
 */
add_hook('InvoiceCancelled', 1, function ($vars) {
    // Update invoice in QB to reflect cancelled status
    quickbooks_sync_invoice($vars['invoiceid'], true);
});

/**
 * Invoice Refunded Hook
 */
add_hook('InvoiceRefunded', 1, function ($vars) {
    // The refund transaction will be synced by RefundProcessed hook
    // Just update the invoice
    quickbooks_sync_invoice($vars['invoiceid'], true);
});

/**
 * Daily Cron Hook - For maintenance tasks
 */
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        // Clean up old logs
        $logRetention = Capsule::table('mod_quickbooks_settings')
            ->where('setting_key', 'log_retention_days')
            ->value('setting_value');

        if (!$logRetention) {
            $logRetention = 30;
        }

        require_once __DIR__ . '/lib/Logger.php';
        $logger = new \QuickBooksOnline\Logger();
        $logger->cleanupLogs($logRetention);

        // Check and refresh token if needed
        $client = quickbooks_get_client();
        if ($client && $client->isConnected()) {
            try {
                $client->ensureValidToken();
            } catch (\Exception $e) {
                error_log('QuickBooks Token Refresh Error: ' . $e->getMessage());
            }
        }
    } catch (\Exception $e) {
        error_log('QuickBooks Daily Cron Error: ' . $e->getMessage());
    }
});
