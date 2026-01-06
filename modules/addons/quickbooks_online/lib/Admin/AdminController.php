<?php
/**
 * Admin Controller
 *
 * Handles admin interface for QuickBooks Online module.
 */

namespace QuickBooksOnline\Admin;

use WHMCS\Database\Capsule;
use QuickBooksOnline\QuickBooksClient;
use QuickBooksOnline\Logger;
use QuickBooksOnline\Sync\ClientSync;
use QuickBooksOnline\Sync\InvoiceSync;
use QuickBooksOnline\Sync\PaymentSync;
use QuickBooksOnline\Sync\CreditSync;

class AdminController
{
    private $vars;
    private $modulelink;
    private $settings;
    private $qbClient;

    public function __construct($vars)
    {
        $this->vars = $vars;
        $this->modulelink = $vars['modulelink'];
        $this->settings = $this->getModuleSettings();

        // Include required files
        require_once dirname(__DIR__) . '/QuickBooksClient.php';
        require_once dirname(__DIR__) . '/Logger.php';
        require_once dirname(__DIR__) . '/Sync/ClientSync.php';
        require_once dirname(__DIR__) . '/Sync/InvoiceSync.php';
        require_once dirname(__DIR__) . '/Sync/PaymentSync.php';
        require_once dirname(__DIR__) . '/Sync/CreditSync.php';

        // Initialize QB client if configured
        if (!empty($this->settings['client_id']) && !empty($this->settings['client_secret'])) {
            $redirectUri = $this->getRedirectUri();
            $this->qbClient = new QuickBooksClient(
                $this->settings['client_id'],
                $this->settings['client_secret'],
                $redirectUri,
                $this->settings['environment'] ?? 'sandbox'
            );
        }
    }

    /**
     * Dispatch action to appropriate handler
     */
    public function dispatch($action)
    {
        // Handle OAuth callback
        if (isset($_GET['code']) && isset($_GET['realmId'])) {
            return $this->handleOAuthCallback();
        }

        // Handle POST actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            return $this->handlePostAction($_POST['action']);
        }

        // Handle page actions
        switch ($action) {
            case 'dashboard':
                return $this->renderDashboard();
            case 'connection':
                return $this->renderConnection();
            case 'clients':
                return $this->renderClients();
            case 'invoices':
                return $this->renderInvoices();
            case 'payments':
                return $this->renderPayments();
            case 'credits':
                return $this->renderCredits();
            case 'taxes':
                return $this->renderTaxes();
            case 'gateways':
                return $this->renderGateways();
            case 'items':
                return $this->renderItems();
            case 'settings':
                return $this->renderSettings();
            case 'logs':
                return $this->renderLogs();
            default:
                return $this->renderDashboard();
        }
    }

    /**
     * Handle OAuth callback from QuickBooks
     */
    private function handleOAuthCallback()
    {
        try {
            $code = $_GET['code'];
            $realmId = $_GET['realmId'];

            $this->qbClient->exchangeCodeForTokens($code, $realmId);

            return $this->renderSuccess('Successfully connected to QuickBooks Online!') . $this->renderConnection();
        } catch (\Exception $e) {
            return $this->renderError('Failed to connect: ' . $e->getMessage()) . $this->renderConnection();
        }
    }

    /**
     * Handle POST actions
     */
    private function handlePostAction($action)
    {
        switch ($action) {
            case 'disconnect':
                return $this->handleDisconnect();
            case 'sync_client':
                return $this->handleSyncClient();
            case 'sync_all_clients':
                return $this->handleSyncAllClients();
            case 'sync_invoice':
                return $this->handleSyncInvoice();
            case 'sync_all_invoices':
                return $this->handleSyncAllInvoices();
            case 'sync_payment':
                return $this->handleSyncPayment();
            case 'sync_all_payments':
                return $this->handleSyncAllPayments();
            case 'sync_credit':
                return $this->handleSyncCredit();
            case 'sync_all_credits':
                return $this->handleSyncAllCredits();
            case 'save_tax_mapping':
                return $this->handleSaveTaxMapping();
            case 'save_gateway_mapping':
                return $this->handleSaveGatewayMapping();
            case 'save_settings':
                return $this->handleSaveSettings();
            case 'lock_item':
                return $this->handleLockItem();
            case 'unlock_item':
                return $this->handleUnlockItem();
            case 'unlink_item':
                return $this->handleUnlinkItem();
            case 'clear_logs':
                return $this->handleClearLogs();
            default:
                return $this->renderDashboard();
        }
    }

    /**
     * Render dashboard page
     */
    private function renderDashboard()
    {
        $html = '<h2>QuickBooks Online Dashboard</h2>';

        // Check connection status
        if (!$this->qbClient || !$this->qbClient->isConnected()) {
            $html .= $this->renderWarning('QuickBooks Online is not connected. <a href="' . $this->modulelink . '&action=connection">Connect now</a>');
            return $html;
        }

        // Get sync statistics
        $logger = new Logger();
        $stats = $logger->getStats(7);

        // Get counts
        $clientsTotal = Capsule::table('tblclients')->count();
        $clientsSynced = Capsule::table('mod_quickbooks_clients')->count();
        $invoicesTotal = Capsule::table('tblinvoices')->count();
        $invoicesSynced = Capsule::table('mod_quickbooks_invoices')->count();
        $paymentsTotal = Capsule::table('tblaccounts')->where('amountin', '>', 0)->count();
        $paymentsSynced = Capsule::table('mod_quickbooks_payments')->count();

        $html .= '
        <div class="row">
            <div class="col-md-3">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Clients</h3>
                    </div>
                    <div class="panel-body text-center">
                        <h2>' . $clientsSynced . ' / ' . $clientsTotal . '</h2>
                        <p>Synced</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Invoices</h3>
                    </div>
                    <div class="panel-body text-center">
                        <h2>' . $invoicesSynced . ' / ' . $invoicesTotal . '</h2>
                        <p>Synced</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Payments</h3>
                    </div>
                    <div class="panel-body text-center">
                        <h2>' . $paymentsSynced . ' / ' . $paymentsTotal . '</h2>
                        <p>Synced</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <h3 class="panel-title">Last 7 Days</h3>
                    </div>
                    <div class="panel-body text-center">
                        <h2 class="text-success">' . $stats['success'] . '</h2>
                        <p>Successful Syncs</p>
                        <h4 class="text-danger">' . $stats['error'] . ' errors</h4>
                    </div>
                </div>
            </div>
        </div>';

        // Quick actions
        $html .= '
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Quick Actions</h3>
            </div>
            <div class="panel-body">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="sync_all_clients">
                    <button type="submit" class="btn btn-primary">Sync All Clients</button>
                </form>
                <form method="post" style="display:inline; margin-left: 10px;">
                    <input type="hidden" name="action" value="sync_all_invoices">
                    <button type="submit" class="btn btn-primary">Sync All Invoices</button>
                </form>
                <form method="post" style="display:inline; margin-left: 10px;">
                    <input type="hidden" name="action" value="sync_all_payments">
                    <button type="submit" class="btn btn-primary">Sync All Payments</button>
                </form>
            </div>
        </div>';

        // Recent errors
        $recentErrors = $logger->getRecentErrors(5);
        if ($recentErrors->count() > 0) {
            $html .= '
            <div class="panel panel-danger">
                <div class="panel-heading">
                    <h3 class="panel-title">Recent Errors</h3>
                </div>
                <table class="table table-condensed">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Type</th>
                            <th>WHMCS ID</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>';

            foreach ($recentErrors as $error) {
                $html .= '
                    <tr>
                        <td>' . $error->created_at . '</td>
                        <td>' . ucfirst($error->type) . '</td>
                        <td>' . $error->whmcs_id . '</td>
                        <td>' . htmlspecialchars($error->message) . '</td>
                    </tr>';
            }

            $html .= '
                    </tbody>
                </table>
            </div>';
        }

        return $html;
    }

    /**
     * Render connection page
     */
    private function renderConnection()
    {
        $html = '<h2>QuickBooks Online Connection</h2>';

        if (!$this->qbClient) {
            $html .= $this->renderError('Please configure your QuickBooks OAuth credentials in the module settings first.');
            return $html;
        }

        $connectionStatus = $this->qbClient->getConnectionStatus();

        if ($connectionStatus['connected']) {
            // Show connected status
            try {
                $companyInfo = $this->qbClient->getCompanyInfo();
                $companyName = $companyInfo['CompanyInfo']['CompanyName'] ?? 'Unknown';
            } catch (\Exception $e) {
                $companyName = 'Unable to retrieve';
            }

            $html .= '
            <div class="panel panel-success">
                <div class="panel-heading">
                    <h3 class="panel-title"><i class="fas fa-check-circle"></i> Connected</h3>
                </div>
                <div class="panel-body">
                    <p><strong>Company:</strong> ' . htmlspecialchars($companyName) . '</p>
                    <p><strong>Realm ID:</strong> ' . $connectionStatus['realm_id'] . '</p>
                    <p><strong>Environment:</strong> ' . ucfirst($this->settings['environment'] ?? 'sandbox') . '</p>
                    <p><strong>Access Token Valid:</strong> ' . ($connectionStatus['access_token_valid'] ? 'Yes' : 'No') . '</p>
                    <p><strong>Refresh Token Valid:</strong> ' . ($connectionStatus['refresh_token_valid'] ? 'Yes' : 'No') . '</p>

                    <hr>

                    <form method="post" onsubmit="return confirm(\'Are you sure you want to disconnect from QuickBooks?\');">
                        <input type="hidden" name="action" value="disconnect">
                        <button type="submit" class="btn btn-danger">Disconnect</button>
                    </form>
                </div>
            </div>';
        } else {
            // Show connect button
            $authUrl = $this->qbClient->getAuthorizationUrl();

            $html .= '
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">Connect to QuickBooks Online</h3>
                </div>
                <div class="panel-body">
                    <p>Click the button below to connect your QuickBooks Online account.</p>
                    <p><strong>Environment:</strong> ' . ucfirst($this->settings['environment'] ?? 'sandbox') . '</p>

                    <hr>

                    <a href="' . $authUrl . '" class="btn btn-success btn-lg">
                        <i class="fas fa-plug"></i> Connect to QuickBooks
                    </a>
                </div>
            </div>

            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">Setup Instructions</h3>
                </div>
                <div class="panel-body">
                    <ol>
                        <li>Go to <a href="https://developer.intuit.com" target="_blank">Intuit Developer Portal</a></li>
                        <li>Create a new app or select an existing one</li>
                        <li>Get your Client ID and Client Secret from the app dashboard</li>
                        <li>Add this Redirect URI to your app: <code>' . $this->getRedirectUri() . '</code></li>
                        <li>Configure the credentials in the module settings</li>
                        <li>Click "Connect to QuickBooks" above</li>
                    </ol>
                </div>
            </div>';
        }

        return $html;
    }

    /**
     * Render clients page
     */
    private function renderClients()
    {
        $html = '<h2>Client Sync</h2>';

        if (!$this->isConnected()) {
            return $html . $this->renderNotConnected();
        }

        // Get clients with sync status
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $clients = Capsule::table('tblclients as c')
            ->leftJoin('mod_quickbooks_clients as qb', 'c.id', '=', 'qb.whmcs_client_id')
            ->select('c.id', 'c.firstname', 'c.lastname', 'c.companyname', 'c.email',
                'qb.qb_customer_id', 'qb.locked', 'qb.last_sync')
            ->orderBy('c.id', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $totalClients = Capsule::table('tblclients')->count();
        $totalPages = ceil($totalClients / $perPage);

        // Bulk action form
        $html .= '
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Bulk Actions</h3>
            </div>
            <div class="panel-body">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="sync_all_clients">
                    <button type="submit" class="btn btn-primary">Sync All Unsynced Clients</button>
                </form>
            </div>
        </div>';

        // Clients table
        $html .= '
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Email</th>
                    <th>QB Customer ID</th>
                    <th>Last Sync</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($clients as $client) {
            $statusClass = $client->qb_customer_id ? 'success' : 'warning';
            $lockIcon = $client->locked ? '<i class="fas fa-lock"></i> ' : '';

            $html .= '
            <tr class="' . $statusClass . '">
                <td>' . $client->id . '</td>
                <td>' . $lockIcon . htmlspecialchars($client->firstname . ' ' . $client->lastname) . '</td>
                <td>' . htmlspecialchars($client->companyname ?: '-') . '</td>
                <td>' . htmlspecialchars($client->email) . '</td>
                <td>' . ($client->qb_customer_id ?: '<em>Not synced</em>') . '</td>
                <td>' . ($client->last_sync ?: '-') . '</td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="sync_client">
                        <input type="hidden" name="client_id" value="' . $client->id . '">
                        <button type="submit" class="btn btn-xs btn-primary">Sync</button>
                    </form>';

            if ($client->qb_customer_id) {
                if ($client->locked) {
                    $html .= '
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="unlock_item">
                        <input type="hidden" name="type" value="client">
                        <input type="hidden" name="id" value="' . $client->id . '">
                        <button type="submit" class="btn btn-xs btn-warning">Unlock</button>
                    </form>';
                } else {
                    $html .= '
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="lock_item">
                        <input type="hidden" name="type" value="client">
                        <input type="hidden" name="id" value="' . $client->id . '">
                        <button type="submit" class="btn btn-xs btn-info">Lock</button>
                    </form>';
                }

                $html .= '
                    <form method="post" style="display:inline;" onsubmit="return confirm(\'Unlink this client?\');">
                        <input type="hidden" name="action" value="unlink_item">
                        <input type="hidden" name="type" value="client">
                        <input type="hidden" name="id" value="' . $client->id . '">
                        <button type="submit" class="btn btn-xs btn-danger">Unlink</button>
                    </form>';
            }

            $html .= '</td></tr>';
        }

        $html .= '</tbody></table>';

        // Pagination
        $html .= $this->renderPagination($page, $totalPages, 'clients');

        return $html;
    }

    /**
     * Render invoices page
     */
    private function renderInvoices()
    {
        $html = '<h2>Invoice Sync</h2>';

        if (!$this->isConnected()) {
            return $html . $this->renderNotConnected();
        }

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $invoices = Capsule::table('tblinvoices as i')
            ->leftJoin('mod_quickbooks_invoices as qb', 'i.id', '=', 'qb.whmcs_invoice_id')
            ->leftJoin('tblclients as c', 'i.userid', '=', 'c.id')
            ->select('i.id', 'i.userid', 'i.date', 'i.duedate', 'i.total', 'i.status',
                'c.firstname', 'c.lastname',
                'qb.qb_invoice_id', 'qb.locked', 'qb.last_sync')
            ->orderBy('i.id', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $totalInvoices = Capsule::table('tblinvoices')->count();
        $totalPages = ceil($totalInvoices / $perPage);

        $html .= '
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Bulk Actions</h3>
            </div>
            <div class="panel-body">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="sync_all_invoices">
                    <button type="submit" class="btn btn-primary">Sync All Unsynced Invoices</button>
                </form>
            </div>
        </div>';

        $html .= '
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Client</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>QB Invoice ID</th>
                    <th>Last Sync</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($invoices as $invoice) {
            $statusClass = $invoice->qb_invoice_id ? 'success' : 'warning';
            $lockIcon = $invoice->locked ? '<i class="fas fa-lock"></i> ' : '';

            $html .= '
            <tr class="' . $statusClass . '">
                <td>' . $lockIcon . $invoice->id . '</td>
                <td>' . htmlspecialchars($invoice->firstname . ' ' . $invoice->lastname) . '</td>
                <td>' . $invoice->date . '</td>
                <td>' . number_format($invoice->total, 2) . '</td>
                <td>' . $invoice->status . '</td>
                <td>' . ($invoice->qb_invoice_id ?: '<em>Not synced</em>') . '</td>
                <td>' . ($invoice->last_sync ?: '-') . '</td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="sync_invoice">
                        <input type="hidden" name="invoice_id" value="' . $invoice->id . '">
                        <button type="submit" class="btn btn-xs btn-primary">Sync</button>
                    </form>';

            if ($invoice->qb_invoice_id) {
                if ($invoice->locked) {
                    $html .= '
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="unlock_item">
                        <input type="hidden" name="type" value="invoice">
                        <input type="hidden" name="id" value="' . $invoice->id . '">
                        <button type="submit" class="btn btn-xs btn-warning">Unlock</button>
                    </form>';
                } else {
                    $html .= '
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="lock_item">
                        <input type="hidden" name="type" value="invoice">
                        <input type="hidden" name="id" value="' . $invoice->id . '">
                        <button type="submit" class="btn btn-xs btn-info">Lock</button>
                    </form>';
                }
            }

            $html .= '</td></tr>';
        }

        $html .= '</tbody></table>';
        $html .= $this->renderPagination($page, $totalPages, 'invoices');

        return $html;
    }

    /**
     * Render payments page
     */
    private function renderPayments()
    {
        $html = '<h2>Payment Sync</h2>';

        if (!$this->isConnected()) {
            return $html . $this->renderNotConnected();
        }

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $payments = Capsule::table('tblaccounts as t')
            ->leftJoin('mod_quickbooks_payments as qb', 't.id', '=', 'qb.whmcs_transaction_id')
            ->leftJoin('tblinvoices as i', 't.invoiceid', '=', 'i.id')
            ->leftJoin('tblclients as c', 'i.userid', '=', 'c.id')
            ->where('t.amountin', '>', 0)
            ->select('t.id', 't.invoiceid', 't.date', 't.gateway', 't.amountin', 't.transid',
                'c.firstname', 'c.lastname',
                'qb.qb_payment_id', 'qb.locked', 'qb.last_sync')
            ->orderBy('t.id', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $totalPayments = Capsule::table('tblaccounts')->where('amountin', '>', 0)->count();
        $totalPages = ceil($totalPayments / $perPage);

        $html .= '
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Bulk Actions</h3>
            </div>
            <div class="panel-body">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="sync_all_payments">
                    <button type="submit" class="btn btn-primary">Sync All Unsynced Payments</button>
                </form>
            </div>
        </div>';

        $html .= '
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Trans #</th>
                    <th>Invoice #</th>
                    <th>Client</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Gateway</th>
                    <th>QB Payment ID</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($payments as $payment) {
            $statusClass = $payment->qb_payment_id ? 'success' : 'warning';

            $html .= '
            <tr class="' . $statusClass . '">
                <td>' . $payment->id . '</td>
                <td>' . ($payment->invoiceid ?: '-') . '</td>
                <td>' . htmlspecialchars(($payment->firstname ?? '') . ' ' . ($payment->lastname ?? '')) . '</td>
                <td>' . $payment->date . '</td>
                <td>' . number_format($payment->amountin, 2) . '</td>
                <td>' . htmlspecialchars($payment->gateway) . '</td>
                <td>' . ($payment->qb_payment_id ?: '<em>Not synced</em>') . '</td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="sync_payment">
                        <input type="hidden" name="transaction_id" value="' . $payment->id . '">
                        <button type="submit" class="btn btn-xs btn-primary">Sync</button>
                    </form>
                </td>
            </tr>';
        }

        $html .= '</tbody></table>';
        $html .= $this->renderPagination($page, $totalPages, 'payments');

        return $html;
    }

    /**
     * Render credits page
     */
    private function renderCredits()
    {
        $html = '<h2>Credits & Refunds Sync</h2>';

        if (!$this->isConnected()) {
            return $html . $this->renderNotConnected();
        }

        // Credits section
        $credits = Capsule::table('tblcredit as c')
            ->leftJoin('mod_quickbooks_credits as qb', function ($join) {
                $join->on('c.id', '=', 'qb.whmcs_credit_id')
                    ->where('qb.type', '=', 'credit');
            })
            ->leftJoin('tblclients as cl', 'c.clientid', '=', 'cl.id')
            ->select('c.*', 'cl.firstname', 'cl.lastname', 'qb.qb_creditmemo_id', 'qb.last_sync')
            ->orderBy('c.id', 'desc')
            ->limit(25)
            ->get();

        $html .= '
        <h3>Account Credits</h3>
        <div class="panel panel-default">
            <div class="panel-body">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="sync_all_credits">
                    <input type="hidden" name="credit_type" value="credit">
                    <button type="submit" class="btn btn-primary">Sync All Credits</button>
                </form>
            </div>
        </div>

        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Client</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>QB Credit Memo ID</th>
                    <th>Last Sync</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($credits as $credit) {
            $statusClass = $credit->qb_creditmemo_id ? 'success' : 'warning';

            $html .= '
            <tr class="' . $statusClass . '">
                <td>' . $credit->id . '</td>
                <td>' . htmlspecialchars($credit->firstname . ' ' . $credit->lastname) . '</td>
                <td>' . number_format($credit->amount, 2) . '</td>
                <td>' . $credit->date . '</td>
                <td>' . ($credit->qb_creditmemo_id ?: '<em>Not synced</em>') . '</td>
                <td>' . ($credit->last_sync ?: '-') . '</td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="sync_credit">
                        <input type="hidden" name="credit_id" value="' . $credit->id . '">
                        <input type="hidden" name="credit_type" value="credit">
                        <button type="submit" class="btn btn-xs btn-primary">Sync</button>
                    </form>
                </td>
            </tr>';
        }

        $html .= '</tbody></table>';

        // Refunds section
        $refunds = Capsule::table('tblaccounts as t')
            ->leftJoin('mod_quickbooks_credits as qb', function ($join) {
                $join->on('t.id', '=', 'qb.whmcs_credit_id')
                    ->where('qb.type', '=', 'refund');
            })
            ->leftJoin('tblinvoices as i', 't.invoiceid', '=', 'i.id')
            ->leftJoin('tblclients as c', 'i.userid', '=', 'c.id')
            ->where('t.amountout', '>', 0)
            ->select('t.*', 'c.firstname', 'c.lastname', 'qb.qb_refund_id', 'qb.last_sync')
            ->orderBy('t.id', 'desc')
            ->limit(25)
            ->get();

        $html .= '
        <h3>Refunds</h3>
        <div class="panel panel-default">
            <div class="panel-body">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="sync_all_credits">
                    <input type="hidden" name="credit_type" value="refund">
                    <button type="submit" class="btn btn-primary">Sync All Refunds</button>
                </form>
            </div>
        </div>

        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Trans #</th>
                    <th>Client</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>QB Refund ID</th>
                    <th>Last Sync</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($refunds as $refund) {
            $statusClass = $refund->qb_refund_id ? 'success' : 'warning';

            $html .= '
            <tr class="' . $statusClass . '">
                <td>' . $refund->id . '</td>
                <td>' . htmlspecialchars(($refund->firstname ?? '') . ' ' . ($refund->lastname ?? '')) . '</td>
                <td>' . number_format($refund->amountout, 2) . '</td>
                <td>' . $refund->date . '</td>
                <td>' . ($refund->qb_refund_id ?: '<em>Not synced</em>') . '</td>
                <td>' . ($refund->last_sync ?: '-') . '</td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="sync_credit">
                        <input type="hidden" name="credit_id" value="' . $refund->id . '">
                        <input type="hidden" name="credit_type" value="refund">
                        <button type="submit" class="btn btn-xs btn-primary">Sync</button>
                    </form>
                </td>
            </tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Render taxes mapping page
     */
    private function renderTaxes()
    {
        $html = '<h2>Tax Mapping</h2>';

        if (!$this->isConnected()) {
            return $html . $this->renderNotConnected();
        }

        // Get QB tax codes
        try {
            $qbTaxCodes = $this->qbClient->getAllTaxCodes();
            $taxCodes = $qbTaxCodes['QueryResponse']['TaxCode'] ?? [];
        } catch (\Exception $e) {
            $taxCodes = [];
            $html .= $this->renderWarning('Could not fetch tax codes from QuickBooks: ' . $e->getMessage());
        }

        // Get WHMCS taxes (simplified - using tax rates)
        $whmcsTaxes = Capsule::table('tbltax')->get();

        // Get existing mappings
        $mappings = Capsule::table('mod_quickbooks_taxes')->get()->keyBy('whmcs_tax_id');

        $html .= '
        <form method="post">
            <input type="hidden" name="action" value="save_tax_mapping">

            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>WHMCS Tax</th>
                        <th>Rate</th>
                        <th>QuickBooks Tax Code</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($whmcsTaxes as $tax) {
            $currentMapping = $mappings->get($tax->id);

            $html .= '
                <tr>
                    <td>' . htmlspecialchars($tax->name) . '</td>
                    <td>' . $tax->taxrate . '%</td>
                    <td>
                        <select name="tax_mapping[' . $tax->id . ']" class="form-control">
                            <option value="">-- Not Mapped --</option>';

            foreach ($taxCodes as $qbTax) {
                $selected = ($currentMapping && $currentMapping->qb_tax_code_id == $qbTax['Id']) ? 'selected' : '';
                $html .= '<option value="' . $qbTax['Id'] . '" ' . $selected . '>' . htmlspecialchars($qbTax['Name']) . '</option>';
            }

            $html .= '
                        </select>
                    </td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>

            <button type="submit" class="btn btn-primary">Save Tax Mappings</button>
        </form>';

        return $html;
    }

    /**
     * Render payment gateways mapping page
     */
    private function renderGateways()
    {
        $html = '<h2>Payment Gateway Mapping</h2>';

        if (!$this->isConnected()) {
            return $html . $this->renderNotConnected();
        }

        // Get QB payment methods
        try {
            $qbPaymentMethods = $this->qbClient->getAllPaymentMethods();
            $paymentMethods = $qbPaymentMethods['QueryResponse']['PaymentMethod'] ?? [];
        } catch (\Exception $e) {
            $paymentMethods = [];
        }

        // Get QB bank accounts
        try {
            $qbAccounts = $this->qbClient->getBankAccounts();
            $bankAccounts = $qbAccounts['QueryResponse']['Account'] ?? [];
        } catch (\Exception $e) {
            $bankAccounts = [];
        }

        // Get WHMCS gateways
        $whmcsGateways = Capsule::table('tblpaymentgateways')
            ->select('gateway')
            ->distinct()
            ->get();

        // Get existing mappings
        $mappings = Capsule::table('mod_quickbooks_payment_methods')->get()->keyBy('whmcs_gateway');

        $html .= '
        <form method="post">
            <input type="hidden" name="action" value="save_gateway_mapping">

            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>WHMCS Gateway</th>
                        <th>QB Payment Method</th>
                        <th>QB Deposit Account</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($whmcsGateways as $gateway) {
            $currentMapping = $mappings->get($gateway->gateway);

            $html .= '
                <tr>
                    <td>' . htmlspecialchars($gateway->gateway) . '</td>
                    <td>
                        <select name="gateway_mapping[' . htmlspecialchars($gateway->gateway) . '][payment_method]" class="form-control">
                            <option value="">-- Not Mapped --</option>';

            foreach ($paymentMethods as $pm) {
                $selected = ($currentMapping && $currentMapping->qb_payment_method_id == $pm['Id']) ? 'selected' : '';
                $html .= '<option value="' . $pm['Id'] . '" ' . $selected . '>' . htmlspecialchars($pm['Name']) . '</option>';
            }

            $html .= '
                        </select>
                    </td>
                    <td>
                        <select name="gateway_mapping[' . htmlspecialchars($gateway->gateway) . '][deposit_account]" class="form-control">
                            <option value="">-- Undeposited Funds --</option>';

            foreach ($bankAccounts as $account) {
                $selected = ($currentMapping && $currentMapping->qb_deposit_account_id == $account['Id']) ? 'selected' : '';
                $html .= '<option value="' . $account['Id'] . '" ' . $selected . '>' . htmlspecialchars($account['Name']) . '</option>';
            }

            $html .= '
                        </select>
                    </td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>

            <button type="submit" class="btn btn-primary">Save Gateway Mappings</button>
        </form>';

        return $html;
    }

    /**
     * Render items/products mapping page
     */
    private function renderItems()
    {
        $html = '<h2>Products/Items Mapping</h2>';

        if (!$this->isConnected()) {
            return $html . $this->renderNotConnected();
        }

        // Get QB items
        try {
            $qbItems = $this->qbClient->getAllItems();
            $items = $qbItems['QueryResponse']['Item'] ?? [];
        } catch (\Exception $e) {
            $items = [];
            $html .= $this->renderWarning('Could not fetch items from QuickBooks: ' . $e->getMessage());
        }

        // Get existing mappings
        $mappings = Capsule::table('mod_quickbooks_items')->get();

        $html .= '
        <h3>QuickBooks Items</h3>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>QB Item ID</th>
                    <th>Name</th>
                    <th>Type</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($items as $item) {
            $html .= '
            <tr>
                <td>' . $item['Id'] . '</td>
                <td>' . htmlspecialchars($item['Name']) . '</td>
                <td>' . ($item['Type'] ?? 'Unknown') . '</td>
            </tr>';
        }

        $html .= '</tbody></table>';

        $html .= '
        <h3>Current Mappings</h3>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>WHMCS Item Type</th>
                    <th>WHMCS Item ID</th>
                    <th>QB Item ID</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($mappings as $mapping) {
            $html .= '
            <tr>
                <td>' . htmlspecialchars($mapping->whmcs_item_type) . '</td>
                <td>' . $mapping->whmcs_item_id . '</td>
                <td>' . $mapping->qb_item_id . '</td>
                <td>
                    <form method="post" style="display:inline;" onsubmit="return confirm(\'Remove this mapping?\');">
                        <input type="hidden" name="action" value="unlink_item">
                        <input type="hidden" name="type" value="item">
                        <input type="hidden" name="id" value="' . $mapping->id . '">
                        <button type="submit" class="btn btn-xs btn-danger">Remove</button>
                    </form>
                </td>
            </tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Render settings page
     */
    private function renderSettings()
    {
        $html = '<h2>Additional Settings</h2>';

        // Get current settings
        $settings = Capsule::table('mod_quickbooks_settings')->pluck('setting_value', 'setting_key');

        // Get income accounts for default selection
        $incomeAccounts = [];
        if ($this->isConnected()) {
            try {
                $qbAccounts = $this->qbClient->getIncomeAccounts();
                $incomeAccounts = $qbAccounts['QueryResponse']['Account'] ?? [];
            } catch (\Exception $e) {
                // Ignore
            }
        }

        $html .= '
        <form method="post">
            <input type="hidden" name="action" value="save_settings">

            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">Default Accounts</h3>
                </div>
                <div class="panel-body">
                    <div class="form-group">
                        <label>Default Income Account</label>
                        <select name="settings[default_income_account]" class="form-control">
                            <option value="">-- Select Account --</option>';

        foreach ($incomeAccounts as $account) {
            $selected = (($settings['default_income_account'] ?? '') == $account['Id']) ? 'selected' : '';
            $html .= '<option value="' . $account['Id'] . '" ' . $selected . '>' . htmlspecialchars($account['Name']) . '</option>';
        }

        $html .= '
                        </select>
                    </div>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">Sync Options</h3>
                </div>
                <div class="panel-body">
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="settings[sync_on_create]" value="1" ' . (($settings['sync_on_create'] ?? '') ? 'checked' : '') . '>
                            Auto-sync when clients/invoices/payments are created
                        </label>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="settings[sync_on_update]" value="1" ' . (($settings['sync_on_update'] ?? '') ? 'checked' : '') . '>
                            Auto-sync when clients/invoices are updated
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Log Retention (days)</label>
                        <input type="number" name="settings[log_retention_days]" class="form-control" style="width:150px;" value="' . ($settings['log_retention_days'] ?? 30) . '">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>';

        return $html;
    }

    /**
     * Render logs page
     */
    private function renderLogs()
    {
        $html = '<h2>Sync Logs</h2>';

        $logger = new Logger();

        // Filters
        $filters = [];
        if (!empty($_GET['filter_type'])) {
            $filters['type'] = $_GET['filter_type'];
        }
        if (!empty($_GET['filter_status'])) {
            $filters['status'] = $_GET['filter_status'];
        }

        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $logs = $logger->getLogs($filters, $perPage, $offset);
        $totalLogs = $logger->getLogsCount($filters);
        $totalPages = ceil($totalLogs / $perPage);

        // Filter form
        $html .= '
        <div class="panel panel-default">
            <div class="panel-body">
                <form method="get" class="form-inline">
                    <input type="hidden" name="module" value="quickbooks_online">
                    <input type="hidden" name="action" value="logs">

                    <div class="form-group">
                        <select name="filter_type" class="form-control">
                            <option value="">All Types</option>
                            <option value="client" ' . (($filters['type'] ?? '') == 'client' ? 'selected' : '') . '>Client</option>
                            <option value="invoice" ' . (($filters['type'] ?? '') == 'invoice' ? 'selected' : '') . '>Invoice</option>
                            <option value="payment" ' . (($filters['type'] ?? '') == 'payment' ? 'selected' : '') . '>Payment</option>
                            <option value="credit" ' . (($filters['type'] ?? '') == 'credit' ? 'selected' : '') . '>Credit</option>
                            <option value="refund" ' . (($filters['type'] ?? '') == 'refund' ? 'selected' : '') . '>Refund</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <select name="filter_status" class="form-control">
                            <option value="">All Status</option>
                            <option value="success" ' . (($filters['status'] ?? '') == 'success' ? 'selected' : '') . '>Success</option>
                            <option value="error" ' . (($filters['status'] ?? '') == 'error' ? 'selected' : '') . '>Error</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-default">Filter</button>
                </form>

                <form method="post" style="display:inline; float:right;" onsubmit="return confirm(\'Clear all logs?\');">
                    <input type="hidden" name="action" value="clear_logs">
                    <button type="submit" class="btn btn-danger">Clear All Logs</button>
                </form>
            </div>
        </div>';

        $html .= '
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Action</th>
                    <th>WHMCS ID</th>
                    <th>QB ID</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($logs as $log) {
            $statusClass = $log->status == 'success' ? 'success' : 'danger';

            $html .= '
            <tr class="' . $statusClass . '">
                <td>' . $log->id . '</td>
                <td>' . $log->created_at . '</td>
                <td>' . ucfirst($log->type) . '</td>
                <td>' . $log->action . '</td>
                <td>' . $log->whmcs_id . '</td>
                <td>' . ($log->qb_id ?: '-') . '</td>
                <td><span class="label label-' . $statusClass . '">' . ucfirst($log->status) . '</span></td>
                <td>' . htmlspecialchars(substr($log->message ?? '', 0, 100)) . '</td>
            </tr>';
        }

        $html .= '</tbody></table>';
        $html .= $this->renderPagination($page, $totalPages, 'logs', $filters);

        return $html;
    }

    // ========================================
    // Action Handlers
    // ========================================

    private function handleDisconnect()
    {
        try {
            $this->qbClient->disconnect();
            return $this->renderSuccess('Disconnected from QuickBooks Online.') . $this->renderConnection();
        } catch (\Exception $e) {
            return $this->renderError('Failed to disconnect: ' . $e->getMessage()) . $this->renderConnection();
        }
    }

    private function handleSyncClient()
    {
        $clientId = intval($_POST['client_id'] ?? 0);

        if (!$clientId) {
            return $this->renderError('Invalid client ID') . $this->renderClients();
        }

        $clientSync = new ClientSync($this->qbClient, $this->settings);
        $result = $clientSync->syncClient($clientId, true);

        if ($result['success']) {
            return $this->renderSuccess('Client synced successfully (QB ID: ' . $result['qb_customer_id'] . ')') . $this->renderClients();
        } else {
            return $this->renderError('Sync failed: ' . $result['message']) . $this->renderClients();
        }
    }

    private function handleSyncAllClients()
    {
        $clientSync = new ClientSync($this->qbClient, $this->settings);

        // Get unsynced clients
        $unsynced = $clientSync->getUnsyncedClients(100);
        $clientIds = $unsynced->pluck('id')->toArray();

        if (empty($clientIds)) {
            return $this->renderInfo('All clients are already synced.') . $this->renderClients();
        }

        $result = $clientSync->syncClients($clientIds);

        return $this->renderSuccess("Synced {$result['success']} clients. Failed: {$result['failed']}, Skipped: {$result['skipped']}") . $this->renderClients();
    }

    private function handleSyncInvoice()
    {
        $invoiceId = intval($_POST['invoice_id'] ?? 0);

        if (!$invoiceId) {
            return $this->renderError('Invalid invoice ID') . $this->renderInvoices();
        }

        $clientSync = new ClientSync($this->qbClient, $this->settings);
        $invoiceSync = new InvoiceSync($this->qbClient, $clientSync, $this->settings);
        $result = $invoiceSync->syncInvoice($invoiceId, true);

        if ($result['success']) {
            return $this->renderSuccess('Invoice synced successfully (QB ID: ' . ($result['qb_invoice_id'] ?? 'N/A') . ')') . $this->renderInvoices();
        } else {
            return $this->renderError('Sync failed: ' . $result['message']) . $this->renderInvoices();
        }
    }

    private function handleSyncAllInvoices()
    {
        $clientSync = new ClientSync($this->qbClient, $this->settings);
        $invoiceSync = new InvoiceSync($this->qbClient, $clientSync, $this->settings);

        $unsynced = $invoiceSync->getUnsyncedInvoices(100);
        $invoiceIds = $unsynced->pluck('id')->toArray();

        if (empty($invoiceIds)) {
            return $this->renderInfo('All invoices are already synced.') . $this->renderInvoices();
        }

        $result = $invoiceSync->syncInvoices($invoiceIds);

        return $this->renderSuccess("Synced {$result['success']} invoices. Failed: {$result['failed']}, Skipped: {$result['skipped']}") . $this->renderInvoices();
    }

    private function handleSyncPayment()
    {
        $transactionId = intval($_POST['transaction_id'] ?? 0);

        if (!$transactionId) {
            return $this->renderError('Invalid transaction ID') . $this->renderPayments();
        }

        $clientSync = new ClientSync($this->qbClient, $this->settings);
        $invoiceSync = new InvoiceSync($this->qbClient, $clientSync, $this->settings);
        $paymentSync = new PaymentSync($this->qbClient, $clientSync, $invoiceSync, $this->settings);
        $result = $paymentSync->syncPayment($transactionId, true);

        if ($result['success']) {
            return $this->renderSuccess('Payment synced successfully (QB ID: ' . ($result['qb_payment_id'] ?? 'N/A') . ')') . $this->renderPayments();
        } else {
            return $this->renderError('Sync failed: ' . $result['message']) . $this->renderPayments();
        }
    }

    private function handleSyncAllPayments()
    {
        $clientSync = new ClientSync($this->qbClient, $this->settings);
        $invoiceSync = new InvoiceSync($this->qbClient, $clientSync, $this->settings);
        $paymentSync = new PaymentSync($this->qbClient, $clientSync, $invoiceSync, $this->settings);

        $unsynced = $paymentSync->getUnsyncedPayments(100);
        $transactionIds = $unsynced->pluck('id')->toArray();

        if (empty($transactionIds)) {
            return $this->renderInfo('All payments are already synced.') . $this->renderPayments();
        }

        $result = $paymentSync->syncPayments($transactionIds);

        return $this->renderSuccess("Synced {$result['success']} payments. Failed: {$result['failed']}, Skipped: {$result['skipped']}") . $this->renderPayments();
    }

    private function handleSyncCredit()
    {
        $creditId = intval($_POST['credit_id'] ?? 0);
        $creditType = $_POST['credit_type'] ?? 'credit';

        if (!$creditId) {
            return $this->renderError('Invalid credit ID') . $this->renderCredits();
        }

        $clientSync = new ClientSync($this->qbClient, $this->settings);
        $creditSync = new CreditSync($this->qbClient, $clientSync, $this->settings);

        if ($creditType === 'refund') {
            $result = $creditSync->syncRefund($creditId, true);
        } else {
            $result = $creditSync->syncCredit($creditId, true);
        }

        if ($result['success']) {
            return $this->renderSuccess('Credit/Refund synced successfully') . $this->renderCredits();
        } else {
            return $this->renderError('Sync failed: ' . $result['message']) . $this->renderCredits();
        }
    }

    private function handleSyncAllCredits()
    {
        $creditType = $_POST['credit_type'] ?? 'credit';

        $clientSync = new ClientSync($this->qbClient, $this->settings);
        $creditSync = new CreditSync($this->qbClient, $clientSync, $this->settings);

        if ($creditType === 'refund') {
            $result = $creditSync->syncAllRefunds();
        } else {
            $result = $creditSync->syncAllCredits();
        }

        return $this->renderSuccess("Synced {$result['success']} items. Failed: {$result['failed']}, Skipped: {$result['skipped']}") . $this->renderCredits();
    }

    private function handleSaveTaxMapping()
    {
        $mappings = $_POST['tax_mapping'] ?? [];

        foreach ($mappings as $whmcsTaxId => $qbTaxCodeId) {
            if ($qbTaxCodeId) {
                Capsule::table('mod_quickbooks_taxes')->updateOrInsert(
                    ['whmcs_tax_id' => $whmcsTaxId],
                    [
                        'qb_tax_code_id' => $qbTaxCodeId,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]
                );
            } else {
                Capsule::table('mod_quickbooks_taxes')->where('whmcs_tax_id', $whmcsTaxId)->delete();
            }
        }

        return $this->renderSuccess('Tax mappings saved successfully.') . $this->renderTaxes();
    }

    private function handleSaveGatewayMapping()
    {
        $mappings = $_POST['gateway_mapping'] ?? [];

        foreach ($mappings as $gateway => $mapping) {
            if (!empty($mapping['payment_method'])) {
                Capsule::table('mod_quickbooks_payment_methods')->updateOrInsert(
                    ['whmcs_gateway' => $gateway],
                    [
                        'qb_payment_method_id' => $mapping['payment_method'],
                        'qb_deposit_account_id' => $mapping['deposit_account'] ?: null,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]
                );
            } else {
                Capsule::table('mod_quickbooks_payment_methods')->where('whmcs_gateway', $gateway)->delete();
            }
        }

        return $this->renderSuccess('Gateway mappings saved successfully.') . $this->renderGateways();
    }

    private function handleSaveSettings()
    {
        $settings = $_POST['settings'] ?? [];

        foreach ($settings as $key => $value) {
            Capsule::table('mod_quickbooks_settings')->updateOrInsert(
                ['setting_key' => $key],
                [
                    'setting_value' => $value,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        }

        return $this->renderSuccess('Settings saved successfully.') . $this->renderSettings();
    }

    private function handleLockItem()
    {
        $type = $_POST['type'] ?? '';
        $id = intval($_POST['id'] ?? 0);

        switch ($type) {
            case 'client':
                $clientSync = new ClientSync($this->qbClient, $this->settings);
                $clientSync->lockClient($id);
                return $this->renderSuccess('Client locked.') . $this->renderClients();
            case 'invoice':
                $clientSync = new ClientSync($this->qbClient, $this->settings);
                $invoiceSync = new InvoiceSync($this->qbClient, $clientSync, $this->settings);
                $invoiceSync->lockInvoice($id);
                return $this->renderSuccess('Invoice locked.') . $this->renderInvoices();
        }

        return $this->renderDashboard();
    }

    private function handleUnlockItem()
    {
        $type = $_POST['type'] ?? '';
        $id = intval($_POST['id'] ?? 0);

        switch ($type) {
            case 'client':
                $clientSync = new ClientSync($this->qbClient, $this->settings);
                $clientSync->unlockClient($id);
                return $this->renderSuccess('Client unlocked.') . $this->renderClients();
            case 'invoice':
                $clientSync = new ClientSync($this->qbClient, $this->settings);
                $invoiceSync = new InvoiceSync($this->qbClient, $clientSync, $this->settings);
                $invoiceSync->unlockInvoice($id);
                return $this->renderSuccess('Invoice unlocked.') . $this->renderInvoices();
        }

        return $this->renderDashboard();
    }

    private function handleUnlinkItem()
    {
        $type = $_POST['type'] ?? '';
        $id = intval($_POST['id'] ?? 0);

        switch ($type) {
            case 'client':
                $clientSync = new ClientSync($this->qbClient, $this->settings);
                $clientSync->unlinkClient($id);
                return $this->renderSuccess('Client unlinked.') . $this->renderClients();
            case 'invoice':
                $clientSync = new ClientSync($this->qbClient, $this->settings);
                $invoiceSync = new InvoiceSync($this->qbClient, $clientSync, $this->settings);
                $invoiceSync->unlinkInvoice($id);
                return $this->renderSuccess('Invoice unlinked.') . $this->renderInvoices();
            case 'item':
                Capsule::table('mod_quickbooks_items')->where('id', $id)->delete();
                return $this->renderSuccess('Item mapping removed.') . $this->renderItems();
        }

        return $this->renderDashboard();
    }

    private function handleClearLogs()
    {
        $logger = new Logger();
        $logger->clearAllLogs();
        return $this->renderSuccess('All logs cleared.') . $this->renderLogs();
    }

    // ========================================
    // Helper Methods
    // ========================================

    private function getModuleSettings()
    {
        return [
            'client_id' => $this->vars['client_id'] ?? '',
            'client_secret' => $this->vars['client_secret'] ?? '',
            'environment' => $this->vars['environment'] ?? 'sandbox',
            'auto_sync' => $this->vars['auto_sync'] ?? false,
            'sync_zero_invoices' => $this->vars['sync_zero_invoices'] ?? false,
            'tax_custom_field' => $this->vars['tax_custom_field'] ?? '',
        ];
    }

    private function getRedirectUri()
    {
        $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
        return rtrim($systemUrl, '/') . '/admin/addonmodules.php?module=quickbooks_online';
    }

    private function isConnected()
    {
        return $this->qbClient && $this->qbClient->isConnected();
    }

    private function renderNotConnected()
    {
        return $this->renderWarning('QuickBooks Online is not connected. <a href="' . $this->modulelink . '&action=connection">Connect now</a>');
    }

    private function renderSuccess($message)
    {
        return '<div class="alert alert-success">' . $message . '</div>';
    }

    private function renderError($message)
    {
        return '<div class="alert alert-danger">' . htmlspecialchars($message) . '</div>';
    }

    private function renderWarning($message)
    {
        return '<div class="alert alert-warning">' . $message . '</div>';
    }

    private function renderInfo($message)
    {
        return '<div class="alert alert-info">' . $message . '</div>';
    }

    private function renderPagination($currentPage, $totalPages, $action, $extraParams = [])
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<nav><ul class="pagination">';

        $baseUrl = $this->modulelink . '&action=' . $action;
        foreach ($extraParams as $key => $value) {
            $baseUrl .= '&' . urlencode($key) . '=' . urlencode($value);
        }

        // Previous
        if ($currentPage > 1) {
            $html .= '<li><a href="' . $baseUrl . '&page=' . ($currentPage - 1) . '">&laquo;</a></li>';
        }

        // Pages
        for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
            $active = $i == $currentPage ? 'active' : '';
            $html .= '<li class="' . $active . '"><a href="' . $baseUrl . '&page=' . $i . '">' . $i . '</a></li>';
        }

        // Next
        if ($currentPage < $totalPages) {
            $html .= '<li><a href="' . $baseUrl . '&page=' . ($currentPage + 1) . '">&raquo;</a></li>';
        }

        $html .= '</ul></nav>';

        return $html;
    }
}
