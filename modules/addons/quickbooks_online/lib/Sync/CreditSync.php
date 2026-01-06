<?php
/**
 * Credit/Refund Synchronization Service
 *
 * Handles syncing WHMCS credits and refunds to QuickBooks Online.
 */

namespace QuickBooksOnline\Sync;

use WHMCS\Database\Capsule;
use QuickBooksOnline\QuickBooksClient;
use QuickBooksOnline\Logger;

class CreditSync
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
     * Sync a credit to QuickBooks as Credit Memo
     */
    public function syncCredit($creditId, $force = false)
    {
        try {
            // Get WHMCS credit data
            $credit = Capsule::table('tblcredit')->where('id', $creditId)->first();

            if (!$credit) {
                throw new \Exception("Credit not found: {$creditId}");
            }

            // Check if already synced
            $mapping = Capsule::table('mod_quickbooks_credits')
                ->where('whmcs_credit_id', $creditId)
                ->where('type', 'credit')
                ->first();

            // If locked and not forcing, skip
            if ($mapping && $mapping->locked && !$force) {
                return [
                    'success' => true,
                    'action' => 'skipped',
                    'message' => 'Credit is locked',
                    'qb_creditmemo_id' => $mapping->qb_creditmemo_id,
                ];
            }

            // Ensure client is synced first
            $qbCustomerId = $this->clientSync->getQbCustomerId($credit->clientid);

            if (!$qbCustomerId) {
                // Sync the client first
                $clientResult = $this->clientSync->syncClient($credit->clientid);

                if (!$clientResult['success']) {
                    throw new \Exception('Failed to sync client: ' . $clientResult['message']);
                }

                $qbCustomerId = $clientResult['qb_customer_id'];
            }

            // Prepare credit memo data
            $creditMemoData = $this->prepareCreditMemoData($credit, $qbCustomerId);

            if ($mapping && $mapping->qb_creditmemo_id) {
                // Update existing credit memo
                $existingCreditMemo = $this->qbClient->getCreditMemo($mapping->qb_creditmemo_id);

                if (!isset($existingCreditMemo['CreditMemo'])) {
                    throw new \Exception('Failed to retrieve existing credit memo from QuickBooks');
                }

                $creditMemoData['Id'] = $mapping->qb_creditmemo_id;
                $creditMemoData['SyncToken'] = $existingCreditMemo['CreditMemo']['SyncToken'];

                $response = $this->qbClient->updateCreditMemo($creditMemoData);
                $action = 'update';
            } else {
                // Create new credit memo
                $response = $this->qbClient->createCreditMemo($creditMemoData);
                $action = 'create';
            }

            if (!isset($response['CreditMemo']['Id'])) {
                throw new \Exception('Failed to sync credit memo to QuickBooks');
            }

            $qbCreditMemoId = $response['CreditMemo']['Id'];

            // Update or create mapping
            $mappingData = [
                'qb_creditmemo_id' => $qbCreditMemoId,
                'last_sync' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($mapping) {
                Capsule::table('mod_quickbooks_credits')
                    ->where('whmcs_credit_id', $creditId)
                    ->where('type', 'credit')
                    ->update($mappingData);
            } else {
                $mappingData['whmcs_credit_id'] = $creditId;
                $mappingData['type'] = 'credit';
                $mappingData['created_at'] = date('Y-m-d H:i:s');
                Capsule::table('mod_quickbooks_credits')->insert($mappingData);
            }

            // Log success
            $this->logger->log('credit', $action, $creditId, $qbCreditMemoId, 'success', "Credit synced successfully");

            return [
                'success' => true,
                'action' => $action,
                'qb_creditmemo_id' => $qbCreditMemoId,
                'message' => 'Credit synced successfully',
            ];

        } catch (\Exception $e) {
            $this->logger->log('credit', 'sync', $creditId, null, 'error', $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync a refund transaction to QuickBooks
     */
    public function syncRefund($transactionId, $force = false)
    {
        try {
            // Get WHMCS refund transaction data
            $transaction = Capsule::table('tblaccounts')->where('id', $transactionId)->first();

            if (!$transaction) {
                throw new \Exception("Transaction not found: {$transactionId}");
            }

            // Verify it's a refund (amount out)
            if (floatval($transaction->amountout) <= 0) {
                return [
                    'success' => false,
                    'message' => 'Transaction is not a refund',
                ];
            }

            // Check if already synced
            $mapping = Capsule::table('mod_quickbooks_credits')
                ->where('whmcs_credit_id', $transactionId)
                ->where('type', 'refund')
                ->first();

            // If locked and not forcing, skip
            if ($mapping && $mapping->locked && !$force) {
                return [
                    'success' => true,
                    'action' => 'skipped',
                    'message' => 'Refund is locked',
                    'qb_refund_id' => $mapping->qb_refund_id,
                ];
            }

            // Get invoice to find client
            $invoice = Capsule::table('tblinvoices')
                ->where('id', $transaction->invoiceid)
                ->first();

            if (!$invoice) {
                throw new \Exception('Invoice not found for refund');
            }

            // Ensure client is synced
            $qbCustomerId = $this->clientSync->getQbCustomerId($invoice->userid);

            if (!$qbCustomerId) {
                $clientResult = $this->clientSync->syncClient($invoice->userid);

                if (!$clientResult['success']) {
                    throw new \Exception('Failed to sync client: ' . $clientResult['message']);
                }

                $qbCustomerId = $clientResult['qb_customer_id'];
            }

            // Prepare refund receipt data
            $refundData = $this->prepareRefundReceiptData($transaction, $qbCustomerId);

            // Create refund receipt (QuickBooks doesn't support updating refund receipts easily)
            $response = $this->qbClient->createRefundReceipt($refundData);
            $action = $mapping ? 'update' : 'create';

            if (!isset($response['RefundReceipt']['Id'])) {
                throw new \Exception('Failed to sync refund to QuickBooks');
            }

            $qbRefundId = $response['RefundReceipt']['Id'];

            // Update or create mapping
            $mappingData = [
                'qb_refund_id' => $qbRefundId,
                'last_sync' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($mapping) {
                Capsule::table('mod_quickbooks_credits')
                    ->where('whmcs_credit_id', $transactionId)
                    ->where('type', 'refund')
                    ->update($mappingData);
            } else {
                $mappingData['whmcs_credit_id'] = $transactionId;
                $mappingData['type'] = 'refund';
                $mappingData['created_at'] = date('Y-m-d H:i:s');
                Capsule::table('mod_quickbooks_credits')->insert($mappingData);
            }

            // Log success
            $this->logger->log('refund', $action, $transactionId, $qbRefundId, 'success', "Refund synced successfully");

            return [
                'success' => true,
                'action' => $action,
                'qb_refund_id' => $qbRefundId,
                'message' => 'Refund synced successfully',
            ];

        } catch (\Exception $e) {
            $this->logger->log('refund', 'sync', $transactionId, null, 'error', $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync multiple credits
     */
    public function syncCredits(array $creditIds, $force = false)
    {
        $results = [
            'total' => count($creditIds),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        foreach ($creditIds as $creditId) {
            $result = $this->syncCredit($creditId, $force);

            if ($result['success']) {
                if (isset($result['action']) && $result['action'] === 'skipped') {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } else {
                $results['failed']++;
            }

            $results['details'][$creditId] = $result;
        }

        return $results;
    }

    /**
     * Sync all credits
     */
    public function syncAllCredits($force = false, $limit = 100, $offset = 0)
    {
        $credits = Capsule::table('tblcredit')
            ->select('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $creditIds = $credits->pluck('id')->toArray();

        return $this->syncCredits($creditIds, $force);
    }

    /**
     * Sync all refund transactions
     */
    public function syncAllRefunds($force = false, $limit = 100, $offset = 0)
    {
        $transactions = Capsule::table('tblaccounts')
            ->where('amountout', '>', 0)
            ->whereNotNull('invoiceid')
            ->select('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $results = [
            'total' => $transactions->count(),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        foreach ($transactions as $transaction) {
            $result = $this->syncRefund($transaction->id, $force);

            if ($result['success']) {
                if (isset($result['action']) && $result['action'] === 'skipped') {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } else {
                $results['failed']++;
            }

            $results['details'][$transaction->id] = $result;
        }

        return $results;
    }

    /**
     * Get unsynced credits
     */
    public function getUnsyncedCredits($limit = 100)
    {
        return Capsule::table('tblcredit as c')
            ->leftJoin('mod_quickbooks_credits as qb', function ($join) {
                $join->on('c.id', '=', 'qb.whmcs_credit_id')
                    ->where('qb.type', '=', 'credit');
            })
            ->whereNull('qb.id')
            ->select('c.*')
            ->limit($limit)
            ->get();
    }

    /**
     * Get unsynced refunds
     */
    public function getUnsyncedRefunds($limit = 100)
    {
        return Capsule::table('tblaccounts as t')
            ->leftJoin('mod_quickbooks_credits as qb', function ($join) {
                $join->on('t.id', '=', 'qb.whmcs_credit_id')
                    ->where('qb.type', '=', 'refund');
            })
            ->where('t.amountout', '>', 0)
            ->whereNotNull('t.invoiceid')
            ->whereNull('qb.id')
            ->select('t.*')
            ->limit($limit)
            ->get();
    }

    /**
     * Get synced credits
     */
    public function getSyncedCredits($limit = 100, $offset = 0)
    {
        return Capsule::table('tblcredit as c')
            ->join('mod_quickbooks_credits as qb', function ($join) {
                $join->on('c.id', '=', 'qb.whmcs_credit_id')
                    ->where('qb.type', '=', 'credit');
            })
            ->select('c.*', 'qb.qb_creditmemo_id', 'qb.locked', 'qb.last_sync')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Get synced refunds
     */
    public function getSyncedRefunds($limit = 100, $offset = 0)
    {
        return Capsule::table('tblaccounts as t')
            ->join('mod_quickbooks_credits as qb', function ($join) {
                $join->on('t.id', '=', 'qb.whmcs_credit_id')
                    ->where('qb.type', '=', 'refund');
            })
            ->select('t.*', 'qb.qb_refund_id', 'qb.locked', 'qb.last_sync')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Lock a credit/refund mapping
     */
    public function lockCredit($creditId, $type = 'credit')
    {
        return Capsule::table('mod_quickbooks_credits')
            ->where('whmcs_credit_id', $creditId)
            ->where('type', $type)
            ->update(['locked' => true, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Unlock a credit/refund mapping
     */
    public function unlockCredit($creditId, $type = 'credit')
    {
        return Capsule::table('mod_quickbooks_credits')
            ->where('whmcs_credit_id', $creditId)
            ->where('type', $type)
            ->update(['locked' => false, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Unlink a credit/refund (remove mapping)
     */
    public function unlinkCredit($creditId, $type = 'credit')
    {
        return Capsule::table('mod_quickbooks_credits')
            ->where('whmcs_credit_id', $creditId)
            ->where('type', $type)
            ->delete();
    }

    /**
     * Prepare credit memo data for QuickBooks
     */
    private function prepareCreditMemoData($credit, $qbCustomerId)
    {
        $itemId = $this->getDefaultCreditItemId();

        $creditMemoData = [
            'CustomerRef' => [
                'value' => $qbCustomerId,
            ],
            'Line' => [
                [
                    'Amount' => round(floatval($credit->amount), 2),
                    'DetailType' => 'SalesItemLineDetail',
                    'Description' => $credit->description ?: 'Account Credit',
                    'SalesItemLineDetail' => [
                        'Qty' => 1,
                        'UnitPrice' => round(floatval($credit->amount), 2),
                    ],
                ],
            ],
            'TxnDate' => $credit->date,
            'PrivateNote' => 'WHMCS Credit #' . $credit->id,
        ];

        if ($itemId) {
            $creditMemoData['Line'][0]['SalesItemLineDetail']['ItemRef'] = [
                'value' => $itemId,
            ];
        }

        return $creditMemoData;
    }

    /**
     * Prepare refund receipt data for QuickBooks
     */
    private function prepareRefundReceiptData($transaction, $qbCustomerId)
    {
        $itemId = $this->getDefaultRefundItemId();

        $refundData = [
            'CustomerRef' => [
                'value' => $qbCustomerId,
            ],
            'Line' => [
                [
                    'Amount' => round(floatval($transaction->amountout), 2),
                    'DetailType' => 'SalesItemLineDetail',
                    'Description' => $transaction->description ?: 'Refund',
                    'SalesItemLineDetail' => [
                        'Qty' => 1,
                        'UnitPrice' => round(floatval($transaction->amountout), 2),
                    ],
                ],
            ],
            'TxnDate' => $transaction->date,
            'PrivateNote' => 'WHMCS Refund Transaction #' . $transaction->id,
        ];

        if ($itemId) {
            $refundData['Line'][0]['SalesItemLineDetail']['ItemRef'] = [
                'value' => $itemId,
            ];
        }

        // Add payment method if mapped
        $paymentMethodId = $this->getQbPaymentMethodId($transaction->gateway);
        if ($paymentMethodId) {
            $refundData['PaymentMethodRef'] = [
                'value' => $paymentMethodId,
            ];
        }

        // Add deposit account if configured
        $depositAccountId = $this->getQbDepositAccountId($transaction->gateway);
        if ($depositAccountId) {
            $refundData['DepositToAccountRef'] = [
                'value' => $depositAccountId,
            ];
        }

        return $refundData;
    }

    /**
     * Get default credit item ID
     */
    private function getDefaultCreditItemId()
    {
        $setting = Capsule::table('mod_quickbooks_settings')
            ->where('setting_key', 'default_credit_item')
            ->first();

        return $setting ? $setting->setting_value : null;
    }

    /**
     * Get default refund item ID
     */
    private function getDefaultRefundItemId()
    {
        $setting = Capsule::table('mod_quickbooks_settings')
            ->where('setting_key', 'default_refund_item')
            ->first();

        return $setting ? $setting->setting_value : null;
    }

    /**
     * Get QB payment method ID for a WHMCS gateway
     */
    private function getQbPaymentMethodId($gateway)
    {
        $mapping = Capsule::table('mod_quickbooks_payment_methods')
            ->where('whmcs_gateway', $gateway)
            ->first();

        return $mapping ? $mapping->qb_payment_method_id : null;
    }

    /**
     * Get QB deposit account ID for a WHMCS gateway
     */
    private function getQbDepositAccountId($gateway)
    {
        $mapping = Capsule::table('mod_quickbooks_payment_methods')
            ->where('whmcs_gateway', $gateway)
            ->first();

        return $mapping ? $mapping->qb_deposit_account_id : null;
    }
}
