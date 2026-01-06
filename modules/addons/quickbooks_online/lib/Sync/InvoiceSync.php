<?php
/**
 * Invoice Synchronization Service
 *
 * Handles syncing WHMCS invoices to QuickBooks Online invoices.
 */

namespace QuickBooksOnline\Sync;

use WHMCS\Database\Capsule;
use QuickBooksOnline\QuickBooksClient;
use QuickBooksOnline\Logger;

class InvoiceSync
{
    private $qbClient;
    private $clientSync;
    private $logger;
    private $settings;

    public function __construct(QuickBooksClient $qbClient, ClientSync $clientSync, array $settings = [])
    {
        $this->qbClient = $qbClient;
        $this->clientSync = $clientSync;
        $this->settings = $settings;
        $this->logger = new Logger();
    }

    /**
     * Sync a single invoice to QuickBooks
     */
    public function syncInvoice($invoiceId, $force = false)
    {
        try {
            // Get WHMCS invoice data
            $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

            if (!$invoice) {
                throw new \Exception("Invoice not found: {$invoiceId}");
            }

            // Check if we should sync zero amount invoices
            if (!$this->shouldSyncZeroInvoices() && floatval($invoice->total) == 0) {
                return [
                    'success' => true,
                    'action' => 'skipped',
                    'message' => 'Zero amount invoice skipped',
                ];
            }

            // Check if already synced
            $mapping = Capsule::table('mod_quickbooks_invoices')
                ->where('whmcs_invoice_id', $invoiceId)
                ->first();

            // If locked and not forcing, skip
            if ($mapping && $mapping->locked && !$force) {
                return [
                    'success' => true,
                    'action' => 'skipped',
                    'message' => 'Invoice is locked',
                    'qb_invoice_id' => $mapping->qb_invoice_id,
                ];
            }

            // Ensure client is synced first
            $qbCustomerId = $this->clientSync->getQbCustomerId($invoice->userid);

            if (!$qbCustomerId) {
                // Sync the client first
                $clientResult = $this->clientSync->syncClient($invoice->userid);

                if (!$clientResult['success']) {
                    throw new \Exception('Failed to sync client: ' . $clientResult['message']);
                }

                $qbCustomerId = $clientResult['qb_customer_id'];
            }

            // Get invoice items
            $items = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)
                ->get();

            // Prepare invoice data
            $invoiceData = $this->prepareInvoiceData($invoice, $items, $qbCustomerId);

            if ($mapping) {
                // Update existing invoice
                $existingInvoice = $this->qbClient->getInvoice($mapping->qb_invoice_id);

                if (!isset($existingInvoice['Invoice'])) {
                    throw new \Exception('Failed to retrieve existing invoice from QuickBooks');
                }

                $invoiceData['Id'] = $mapping->qb_invoice_id;
                $invoiceData['SyncToken'] = $existingInvoice['Invoice']['SyncToken'];

                $response = $this->qbClient->updateInvoice($invoiceData);
                $action = 'update';
            } else {
                // Create new invoice
                $response = $this->qbClient->createInvoice($invoiceData);
                $action = 'create';
            }

            if (!isset($response['Invoice']['Id'])) {
                throw new \Exception('Failed to sync invoice to QuickBooks');
            }

            $qbInvoiceId = $response['Invoice']['Id'];
            $syncToken = $response['Invoice']['SyncToken'];

            // Update or create mapping
            $mappingData = [
                'qb_invoice_id' => $qbInvoiceId,
                'qb_sync_token' => $syncToken,
                'last_sync' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($mapping) {
                Capsule::table('mod_quickbooks_invoices')
                    ->where('whmcs_invoice_id', $invoiceId)
                    ->update($mappingData);
            } else {
                $mappingData['whmcs_invoice_id'] = $invoiceId;
                $mappingData['created_at'] = date('Y-m-d H:i:s');
                Capsule::table('mod_quickbooks_invoices')->insert($mappingData);
            }

            // Log success
            $this->logger->log('invoice', $action, $invoiceId, $qbInvoiceId, 'success', "Invoice synced successfully");

            return [
                'success' => true,
                'action' => $action,
                'qb_invoice_id' => $qbInvoiceId,
                'message' => 'Invoice synced successfully',
            ];

        } catch (\Exception $e) {
            $this->logger->log('invoice', 'sync', $invoiceId, null, 'error', $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync multiple invoices
     */
    public function syncInvoices(array $invoiceIds, $force = false)
    {
        $results = [
            'total' => count($invoiceIds),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        foreach ($invoiceIds as $invoiceId) {
            $result = $this->syncInvoice($invoiceId, $force);

            if ($result['success']) {
                if (isset($result['action']) && $result['action'] === 'skipped') {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } else {
                $results['failed']++;
            }

            $results['details'][$invoiceId] = $result;
        }

        return $results;
    }

    /**
     * Sync all invoices
     */
    public function syncAllInvoices($force = false, $limit = 100, $offset = 0)
    {
        $invoices = Capsule::table('tblinvoices')
            ->select('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $invoiceIds = $invoices->pluck('id')->toArray();

        return $this->syncInvoices($invoiceIds, $force);
    }

    /**
     * Sync invoices by status
     */
    public function syncInvoicesByStatus($status, $force = false, $limit = 100)
    {
        $invoices = Capsule::table('tblinvoices')
            ->select('id')
            ->where('status', $status)
            ->limit($limit)
            ->get();

        $invoiceIds = $invoices->pluck('id')->toArray();

        return $this->syncInvoices($invoiceIds, $force);
    }

    /**
     * Sync invoices by date range
     */
    public function syncInvoicesByDateRange($startDate, $endDate, $force = false)
    {
        $invoices = Capsule::table('tblinvoices')
            ->select('id')
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $invoiceIds = $invoices->pluck('id')->toArray();

        return $this->syncInvoices($invoiceIds, $force);
    }

    /**
     * Get unsynced invoices
     */
    public function getUnsyncedInvoices($limit = 100)
    {
        return Capsule::table('tblinvoices as i')
            ->leftJoin('mod_quickbooks_invoices as qb', 'i.id', '=', 'qb.whmcs_invoice_id')
            ->whereNull('qb.id')
            ->select('i.*')
            ->limit($limit)
            ->get();
    }

    /**
     * Get synced invoices
     */
    public function getSyncedInvoices($limit = 100, $offset = 0)
    {
        return Capsule::table('tblinvoices as i')
            ->join('mod_quickbooks_invoices as qb', 'i.id', '=', 'qb.whmcs_invoice_id')
            ->select('i.*', 'qb.qb_invoice_id', 'qb.locked', 'qb.last_sync')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Lock an invoice mapping
     */
    public function lockInvoice($invoiceId)
    {
        return Capsule::table('mod_quickbooks_invoices')
            ->where('whmcs_invoice_id', $invoiceId)
            ->update(['locked' => true, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Unlock an invoice mapping
     */
    public function unlockInvoice($invoiceId)
    {
        return Capsule::table('mod_quickbooks_invoices')
            ->where('whmcs_invoice_id', $invoiceId)
            ->update(['locked' => false, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Unlink an invoice (remove mapping)
     */
    public function unlinkInvoice($invoiceId)
    {
        return Capsule::table('mod_quickbooks_invoices')
            ->where('whmcs_invoice_id', $invoiceId)
            ->delete();
    }

    /**
     * Get QB invoice ID for a WHMCS invoice
     */
    public function getQbInvoiceId($invoiceId)
    {
        $mapping = Capsule::table('mod_quickbooks_invoices')
            ->where('whmcs_invoice_id', $invoiceId)
            ->first();

        return $mapping ? $mapping->qb_invoice_id : null;
    }

    /**
     * Prepare invoice data for QuickBooks
     */
    private function prepareInvoiceData($invoice, $items, $qbCustomerId)
    {
        $lineItems = [];
        $lineNum = 1;

        foreach ($items as $item) {
            // Get or create QB item
            $qbItemId = $this->getOrCreateQbItem($item);

            $lineItem = [
                'LineNum' => $lineNum,
                'Amount' => round(floatval($item->amount), 2),
                'DetailType' => 'SalesItemLineDetail',
                'Description' => substr($item->description, 0, 4000),
                'SalesItemLineDetail' => [
                    'Qty' => 1,
                    'UnitPrice' => round(floatval($item->amount), 2),
                ],
            ];

            if ($qbItemId) {
                $lineItem['SalesItemLineDetail']['ItemRef'] = [
                    'value' => $qbItemId,
                ];
            }

            // Handle tax if applicable
            if (floatval($item->taxed) > 0) {
                $taxCodeId = $this->getTaxCodeId($invoice->taxrate);
                if ($taxCodeId) {
                    $lineItem['SalesItemLineDetail']['TaxCodeRef'] = [
                        'value' => $taxCodeId,
                    ];
                }
            }

            $lineItems[] = $lineItem;
            $lineNum++;
        }

        // Add tax line if there's tax
        $taxAmount = floatval($invoice->tax) + floatval($invoice->tax2);
        if ($taxAmount > 0) {
            // QuickBooks handles tax differently - we'll set up tax later
        }

        $invoiceData = [
            'CustomerRef' => [
                'value' => $qbCustomerId,
            ],
            'Line' => $lineItems,
            'TxnDate' => $invoice->date,
            'DueDate' => $invoice->duedate,
            'DocNumber' => (string) $invoice->id,
            'PrivateNote' => 'WHMCS Invoice #' . $invoice->id,
        ];

        // Set invoice status
        // QB doesn't have direct status mapping, but we can use custom fields or notes

        // Handle currency
        if ($this->settings['multi_currency'] ?? false) {
            $currency = $this->getClientCurrency($invoice->userid);
            if ($currency && $currency !== 'USD') {
                $invoiceData['CurrencyRef'] = [
                    'value' => $currency,
                ];
            }
        }

        return $invoiceData;
    }

    /**
     * Get or create a QuickBooks item for an invoice line
     */
    private function getOrCreateQbItem($item)
    {
        // Check if we have a mapping for this type of item
        $itemType = $this->determineItemType($item);
        $itemId = $item->relid ?: 0;

        $mapping = Capsule::table('mod_quickbooks_items')
            ->where('whmcs_item_type', $itemType)
            ->where('whmcs_item_id', $itemId)
            ->first();

        if ($mapping) {
            return $mapping->qb_item_id;
        }

        // Try to find or create a generic item in QuickBooks
        $itemName = $this->generateItemName($item, $itemType);

        // Check if item exists in QB
        $existingItem = $this->qbClient->findItem($itemName);

        if ($existingItem) {
            // Save mapping
            Capsule::table('mod_quickbooks_items')->insert([
                'whmcs_item_type' => $itemType,
                'whmcs_item_id' => $itemId,
                'qb_item_id' => $existingItem['Id'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return $existingItem['Id'];
        }

        // Create new item in QB
        try {
            $incomeAccountId = $this->getDefaultIncomeAccountId();

            $newItem = $this->qbClient->createItem([
                'Name' => substr($itemName, 0, 100),
                'Type' => 'Service',
                'IncomeAccountRef' => [
                    'value' => $incomeAccountId,
                ],
            ]);

            if (isset($newItem['Item']['Id'])) {
                // Save mapping
                Capsule::table('mod_quickbooks_items')->insert([
                    'whmcs_item_type' => $itemType,
                    'whmcs_item_id' => $itemId,
                    'qb_item_id' => $newItem['Item']['Id'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                return $newItem['Item']['Id'];
            }
        } catch (\Exception $e) {
            // Log error but continue - invoice can still be created without item ref
            $this->logger->log('item', 'create', $itemId, null, 'error', $e->getMessage());
        }

        return null;
    }

    /**
     * Determine item type from invoice item
     */
    private function determineItemType($item)
    {
        $type = strtolower($item->type ?? '');

        switch ($type) {
            case 'hosting':
            case 'hostingsetup':
                return 'product';
            case 'addon':
            case 'addonsetup':
                return 'addon';
            case 'domainregister':
            case 'domaintransfer':
            case 'domainrenew':
                return 'domain';
            case 'invoice':
            case 'latefee':
            case 'credit':
                return 'fee';
            default:
                return 'other';
        }
    }

    /**
     * Generate item name for QuickBooks
     */
    private function generateItemName($item, $itemType)
    {
        switch ($itemType) {
            case 'product':
                if ($item->relid) {
                    $product = Capsule::table('tblproducts')
                        ->where('id', $item->relid)
                        ->first();
                    if ($product) {
                        return 'Product: ' . $product->name;
                    }
                }
                return 'Hosting Service';

            case 'addon':
                if ($item->relid) {
                    $addon = Capsule::table('tbladdons')
                        ->where('id', $item->relid)
                        ->first();
                    if ($addon) {
                        return 'Addon: ' . $addon->name;
                    }
                }
                return 'Product Addon';

            case 'domain':
                return 'Domain Service';

            case 'fee':
                return 'Fee';

            default:
                return 'WHMCS Service';
        }
    }

    /**
     * Get default income account ID
     */
    private function getDefaultIncomeAccountId()
    {
        // Try to get from settings
        $setting = Capsule::table('mod_quickbooks_settings')
            ->where('setting_key', 'default_income_account')
            ->first();

        if ($setting && $setting->setting_value) {
            return $setting->setting_value;
        }

        // Get first income account from QB
        $accounts = $this->qbClient->getIncomeAccounts();

        if (isset($accounts['QueryResponse']['Account'][0]['Id'])) {
            return $accounts['QueryResponse']['Account'][0]['Id'];
        }

        throw new \Exception('No income account found in QuickBooks');
    }

    /**
     * Get tax code ID for a tax rate
     */
    private function getTaxCodeId($taxRate)
    {
        // Check mapping
        $mapping = Capsule::table('mod_quickbooks_taxes')
            ->where('whmcs_tax_id', intval($taxRate * 100)) // Use rate as identifier
            ->first();

        if ($mapping) {
            return $mapping->qb_tax_code_id;
        }

        return null;
    }

    /**
     * Get client currency
     */
    private function getClientCurrency($clientId)
    {
        $client = Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first();

        if ($client && $client->currency) {
            $currency = Capsule::table('tblcurrencies')
                ->where('id', $client->currency)
                ->first();

            if ($currency) {
                return $currency->code;
            }
        }

        return null;
    }

    /**
     * Check if zero invoices should be synced
     */
    private function shouldSyncZeroInvoices()
    {
        return isset($this->settings['sync_zero_invoices']) && $this->settings['sync_zero_invoices'];
    }
}
