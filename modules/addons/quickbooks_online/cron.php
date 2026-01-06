<?php
/**
 * QuickBooks Online Cron Job
 *
 * This file should be called by cron to automatically sync data with QuickBooks Online.
 *
 * Usage:
 *   php -q /path/to/whmcs/modules/addons/quickbooks_online/cron.php [action] [options]
 *
 * Actions:
 *   sync-all          - Sync all unsynced data (default)
 *   sync-clients      - Sync only clients
 *   sync-invoices     - Sync only invoices
 *   sync-payments     - Sync only payments
 *   sync-credits      - Sync only credits and refunds
 *   cleanup-logs      - Clean up old log entries
 *
 * Options:
 *   --limit=N         - Limit number of items to sync (default: 100)
 *   --force           - Force sync even if already synced
 *   --quiet           - Suppress output
 *
 * Example crontab entry (run every 15 minutes):
 *   */15 * * * * php -q /path/to/whmcs/modules/addons/quickbooks_online/cron.php sync-all --limit=50
 */

// Determine WHMCS root path
$whmcsPath = dirname(dirname(dirname(__DIR__)));

// Initialize WHMCS
require_once $whmcsPath . '/init.php';
require_once $whmcsPath . '/includes/functions.php';

use WHMCS\Database\Capsule;

// Include module files
require_once __DIR__ . '/lib/QuickBooksClient.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Sync/ClientSync.php';
require_once __DIR__ . '/lib/Sync/InvoiceSync.php';
require_once __DIR__ . '/lib/Sync/PaymentSync.php';
require_once __DIR__ . '/lib/Sync/CreditSync.php';

use QuickBooksOnline\QuickBooksClient;
use QuickBooksOnline\Logger;
use QuickBooksOnline\Sync\ClientSync;
use QuickBooksOnline\Sync\InvoiceSync;
use QuickBooksOnline\Sync\PaymentSync;
use QuickBooksOnline\Sync\CreditSync;

// Parse command line arguments
$action = isset($argv[1]) ? $argv[1] : 'sync-all';
$options = parseOptions($argv);

$limit = isset($options['limit']) ? intval($options['limit']) : 100;
$force = isset($options['force']);
$quiet = isset($options['quiet']);

// Get module settings
$settings = getModuleSettings();

if (empty($settings['client_id']) || empty($settings['client_secret'])) {
    output("Error: QuickBooks Online module is not configured.", $quiet);
    exit(1);
}

// Initialize QuickBooks client
$systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
$redirectUri = rtrim($systemUrl, '/') . '/admin/addonmodules.php?module=quickbooks_online';

$qbClient = new QuickBooksClient(
    $settings['client_id'],
    $settings['client_secret'],
    $redirectUri,
    $settings['environment']
);

// Check connection
if (!$qbClient->isConnected()) {
    output("Error: QuickBooks Online is not connected. Please connect via the admin panel.", $quiet);
    exit(1);
}

// Ensure token is valid
try {
    $qbClient->ensureValidToken();
} catch (\Exception $e) {
    output("Error: Failed to validate token: " . $e->getMessage(), $quiet);
    exit(1);
}

// Initialize sync services
$clientSync = new ClientSync($qbClient, $settings);
$invoiceSync = new InvoiceSync($qbClient, $clientSync, $settings);
$paymentSync = new PaymentSync($qbClient, $clientSync, $invoiceSync, $settings);
$creditSync = new CreditSync($qbClient, $clientSync, $settings);
$logger = new Logger();

// Execute action
output("QuickBooks Online Sync - " . date('Y-m-d H:i:s'), $quiet);
output("Action: $action, Limit: $limit, Force: " . ($force ? 'Yes' : 'No'), $quiet);
output("", $quiet);

try {
    switch ($action) {
        case 'sync-all':
            syncAll($clientSync, $invoiceSync, $paymentSync, $creditSync, $limit, $force, $quiet);
            break;

        case 'sync-clients':
            syncClients($clientSync, $limit, $force, $quiet);
            break;

        case 'sync-invoices':
            syncInvoices($invoiceSync, $limit, $force, $quiet);
            break;

        case 'sync-payments':
            syncPayments($paymentSync, $limit, $force, $quiet);
            break;

        case 'sync-credits':
            syncCredits($creditSync, $limit, $force, $quiet);
            break;

        case 'cleanup-logs':
            cleanupLogs($logger, $settings, $quiet);
            break;

        default:
            output("Unknown action: $action", $quiet);
            output("Available actions: sync-all, sync-clients, sync-invoices, sync-payments, sync-credits, cleanup-logs", $quiet);
            exit(1);
    }
} catch (\Exception $e) {
    output("Error: " . $e->getMessage(), $quiet);
    exit(1);
}

output("", $quiet);
output("Sync completed at " . date('Y-m-d H:i:s'), $quiet);
exit(0);

// ============================================
// Functions
// ============================================

function syncAll($clientSync, $invoiceSync, $paymentSync, $creditSync, $limit, $force, $quiet)
{
    // Sync clients first
    output("Syncing clients...", $quiet);
    $unsyncedClients = $clientSync->getUnsyncedClients($limit);
    if ($unsyncedClients->count() > 0) {
        $result = $clientSync->syncClients($unsyncedClients->pluck('id')->toArray(), $force);
        output("  Clients - Success: {$result['success']}, Failed: {$result['failed']}, Skipped: {$result['skipped']}", $quiet);
    } else {
        output("  No unsynced clients found.", $quiet);
    }

    // Sync invoices
    output("Syncing invoices...", $quiet);
    $unsyncedInvoices = $invoiceSync->getUnsyncedInvoices($limit);
    if ($unsyncedInvoices->count() > 0) {
        $result = $invoiceSync->syncInvoices($unsyncedInvoices->pluck('id')->toArray(), $force);
        output("  Invoices - Success: {$result['success']}, Failed: {$result['failed']}, Skipped: {$result['skipped']}", $quiet);
    } else {
        output("  No unsynced invoices found.", $quiet);
    }

    // Sync payments
    output("Syncing payments...", $quiet);
    $unsyncedPayments = $paymentSync->getUnsyncedPayments($limit);
    if ($unsyncedPayments->count() > 0) {
        $result = $paymentSync->syncPayments($unsyncedPayments->pluck('id')->toArray(), $force);
        output("  Payments - Success: {$result['success']}, Failed: {$result['failed']}, Skipped: {$result['skipped']}", $quiet);
    } else {
        output("  No unsynced payments found.", $quiet);
    }

    // Sync credits
    output("Syncing credits...", $quiet);
    $unsyncedCredits = $creditSync->getUnsyncedCredits($limit);
    if ($unsyncedCredits->count() > 0) {
        $result = $creditSync->syncCredits($unsyncedCredits->pluck('id')->toArray(), $force);
        output("  Credits - Success: {$result['success']}, Failed: {$result['failed']}, Skipped: {$result['skipped']}", $quiet);
    } else {
        output("  No unsynced credits found.", $quiet);
    }

    // Sync refunds
    output("Syncing refunds...", $quiet);
    $unsyncedRefunds = $creditSync->getUnsyncedRefunds($limit);
    if ($unsyncedRefunds->count() > 0) {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
        foreach ($unsyncedRefunds as $refund) {
            $result = $creditSync->syncRefund($refund->id, $force);
            if ($result['success']) {
                if (isset($result['action']) && $result['action'] === 'skipped') {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } else {
                $results['failed']++;
            }
        }
        output("  Refunds - Success: {$results['success']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}", $quiet);
    } else {
        output("  No unsynced refunds found.", $quiet);
    }
}

function syncClients($clientSync, $limit, $force, $quiet)
{
    $unsyncedClients = $clientSync->getUnsyncedClients($limit);

    if ($unsyncedClients->count() == 0) {
        output("No unsynced clients found.", $quiet);
        return;
    }

    output("Found {$unsyncedClients->count()} unsynced clients.", $quiet);

    $result = $clientSync->syncClients($unsyncedClients->pluck('id')->toArray(), $force);

    output("Results:", $quiet);
    output("  Success: {$result['success']}", $quiet);
    output("  Failed: {$result['failed']}", $quiet);
    output("  Skipped: {$result['skipped']}", $quiet);
}

function syncInvoices($invoiceSync, $limit, $force, $quiet)
{
    $unsyncedInvoices = $invoiceSync->getUnsyncedInvoices($limit);

    if ($unsyncedInvoices->count() == 0) {
        output("No unsynced invoices found.", $quiet);
        return;
    }

    output("Found {$unsyncedInvoices->count()} unsynced invoices.", $quiet);

    $result = $invoiceSync->syncInvoices($unsyncedInvoices->pluck('id')->toArray(), $force);

    output("Results:", $quiet);
    output("  Success: {$result['success']}", $quiet);
    output("  Failed: {$result['failed']}", $quiet);
    output("  Skipped: {$result['skipped']}", $quiet);
}

function syncPayments($paymentSync, $limit, $force, $quiet)
{
    $unsyncedPayments = $paymentSync->getUnsyncedPayments($limit);

    if ($unsyncedPayments->count() == 0) {
        output("No unsynced payments found.", $quiet);
        return;
    }

    output("Found {$unsyncedPayments->count()} unsynced payments.", $quiet);

    $result = $paymentSync->syncPayments($unsyncedPayments->pluck('id')->toArray(), $force);

    output("Results:", $quiet);
    output("  Success: {$result['success']}", $quiet);
    output("  Failed: {$result['failed']}", $quiet);
    output("  Skipped: {$result['skipped']}", $quiet);
}

function syncCredits($creditSync, $limit, $force, $quiet)
{
    // Sync credits
    $unsyncedCredits = $creditSync->getUnsyncedCredits($limit);

    if ($unsyncedCredits->count() > 0) {
        output("Found {$unsyncedCredits->count()} unsynced credits.", $quiet);
        $result = $creditSync->syncCredits($unsyncedCredits->pluck('id')->toArray(), $force);
        output("Credits - Success: {$result['success']}, Failed: {$result['failed']}, Skipped: {$result['skipped']}", $quiet);
    } else {
        output("No unsynced credits found.", $quiet);
    }

    // Sync refunds
    $unsyncedRefunds = $creditSync->getUnsyncedRefunds($limit);

    if ($unsyncedRefunds->count() > 0) {
        output("Found {$unsyncedRefunds->count()} unsynced refunds.", $quiet);
        $results = ['success' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($unsyncedRefunds as $refund) {
            $result = $creditSync->syncRefund($refund->id, $force);
            if ($result['success']) {
                if (isset($result['action']) && $result['action'] === 'skipped') {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } else {
                $results['failed']++;
            }
        }
        output("Refunds - Success: {$results['success']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}", $quiet);
    } else {
        output("No unsynced refunds found.", $quiet);
    }
}

function cleanupLogs($logger, $settings, $quiet)
{
    $days = isset($settings['log_retention_days']) ? intval($settings['log_retention_days']) : 30;

    output("Cleaning up logs older than $days days...", $quiet);

    $deleted = $logger->cleanupLogs($days);

    output("Deleted $deleted log entries.", $quiet);
}

function getModuleSettings()
{
    $settings = [];

    // Get addon module settings
    $addonSettings = Capsule::table('tbladdonmodules')
        ->where('module', 'quickbooks_online')
        ->pluck('value', 'setting');

    foreach ($addonSettings as $key => $value) {
        $settings[$key] = $value;
    }

    // Get additional settings from custom table
    try {
        $additionalSettings = Capsule::table('mod_quickbooks_settings')
            ->pluck('setting_value', 'setting_key');

        foreach ($additionalSettings as $key => $value) {
            $settings[$key] = $value;
        }
    } catch (\Exception $e) {
        // Table may not exist yet
    }

    return $settings;
}

function parseOptions($argv)
{
    $options = [];

    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            $arg = substr($arg, 2);
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg, 2);
                $options[$key] = $value;
            } else {
                $options[$arg] = true;
            }
        }
    }

    return $options;
}

function output($message, $quiet = false)
{
    if (!$quiet) {
        echo $message . PHP_EOL;
    }
}
