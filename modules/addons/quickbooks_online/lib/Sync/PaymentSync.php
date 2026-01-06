<?php
/**
 * Payment Synchronization Service
 *
 * Handles syncing WHMCS payments/transactions to QuickBooks Online payments.
 */

namespace QuickBooksOnline\Sync;

use WHMCS\Database\Capsule;
use QuickBooksOnline\QuickBooksClient;
use QuickBooksOnline\Logger;

class PaymentSync
{
    private $qbClient;
    private $clientSync;
    private $invoiceSync;
    private $logger;
    private $settings;

    public function __construct(
        QuickBooksClient $qbClient,
        ClientSync $clientSync,
        InvoiceSync $invoiceSync,
        array $settings = []
    ) {
        $this->qbClient = $qbClient;
        $this->clientSync = $clientSync;
        $this->invoiceSync = $invoiceSync;
        $this->settings = $settings;
        $this->logger = new Logger();
    }

    /**
     * Sync a single payment/transaction to QuickBooks
     */
    public function syncPayment($transactionId, $force = false)
    {
        try {
            // Get WHMCS transaction data
            $transaction = Capsule::table('tblaccounts')->where('id', $transactionId)->first();

            if (!$transaction) {
                throw new \Exception("Transaction not found: {$transactionId}");
            }

            // Skip refunds (handled separately)
            if (floatval($transaction->amountin) == 0 && floatval($transaction->amountout) > 0) {
                return [
                    'success' => true,
                    'action' => 'skipped',
                    'message' => 'Refund transaction - handled by CreditSync',
                ];
            }

            // Check if already synced
            $mapping = Capsule::table('mod_quickbooks_payments')
                ->where('whmcs_transaction_id', $transactionId)
                ->first();

            // If locked and not forcing, skip
            if ($mapping && $mapping->locked && !$force) {
                return [
                    'success' => true,
                    'action' => 'skipped',
                    'message' => 'Payment is locked',
                    'qb_payment_id' => $mapping->qb_payment_id,
                ];
            }

            // Get invoice ID from transaction
            $invoiceId = $transaction->invoiceid;

            if (!$invoiceId) {
                throw new \Exception('Transaction has no associated invoice');
            }

            // Ensure invoice is synced first
            $qbInvoiceId = $this->invoiceSync->getQbInvoiceId($invoiceId);

            if (!$qbInvoiceId) {
                // Sync the invoice first
                $invoiceResult = $this->invoiceSync->syncInvoice($invoiceId);

                if (!$invoiceResult['success']) {
                    throw new \Exception('Failed to sync invoice: ' . $invoiceResult['message']);
                }

                $qbInvoiceId = $invoiceResult['qb_invoice_id'];
            }

            // Get client ID and QB customer ID
            $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            $qbCustomerId = $this->clientSync->getQbCustomerId($invoice->userid);

            if (!$qbCustomerId) {
                throw new \Exception('Client not synced to QuickBooks');
            }

            // Prepare payment data
            $paymentData = $this->preparePaymentData($transaction, $qbCustomerId, $qbInvoiceId);

            if ($mapping) {
                // Update existing payment
                $existingPayment = $this->qbClient->getPayment($mapping->qb_payment_id);

                if (!isset($existingPayment['Payment'])) {
                    throw new \Exception('Failed to retrieve existing payment from QuickBooks');
                }

                $paymentData['Id'] = $mapping->qb_payment_id;
                $paymentData['SyncToken'] = $existingPayment['Payment']['SyncToken'];

                $response = $this->qbClient->updatePayment($paymentData);
                $action = 'update';
            } else {
                // Create new payment
                $response = $this->qbClient->createPayment($paymentData);
                $action = 'create';
            }

            if (!isset($response['Payment']['Id'])) {
                throw new \Exception('Failed to sync payment to QuickBooks');
            }

            $qbPaymentId = $response['Payment']['Id'];

            // Update or create mapping
            $mappingData = [
                'qb_payment_id' => $qbPaymentId,
                'last_sync' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($mapping) {
                Capsule::table('mod_quickbooks_payments')
                    ->where('whmcs_transaction_id', $transactionId)
                    ->update($mappingData);
            } else {
                $mappingData['whmcs_transaction_id'] = $transactionId;
                $mappingData['created_at'] = date('Y-m-d H:i:s');
                Capsule::table('mod_quickbooks_payments')->insert($mappingData);
            }

            // Log success
            $this->logger->log('payment', $action, $transactionId, $qbPaymentId, 'success', "Payment synced successfully");

            return [
                'success' => true,
                'action' => $action,
                'qb_payment_id' => $qbPaymentId,
                'message' => 'Payment synced successfully',
            ];

        } catch (\Exception $e) {
            $this->logger->log('payment', 'sync', $transactionId, null, 'error', $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync multiple payments
     */
    public function syncPayments(array $transactionIds, $force = false)
    {
        $results = [
            'total' => count($transactionIds),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        foreach ($transactionIds as $transactionId) {
            $result = $this->syncPayment($transactionId, $force);

            if ($result['success']) {
                if (isset($result['action']) && $result['action'] === 'skipped') {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } else {
                $results['failed']++;
            }

            $results['details'][$transactionId] = $result;
        }

        return $results;
    }

    /**
     * Sync all payments
     */
    public function syncAllPayments($force = false, $limit = 100, $offset = 0)
    {
        $transactions = Capsule::table('tblaccounts')
            ->where('amountin', '>', 0)
            ->whereNotNull('invoiceid')
            ->select('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $transactionIds = $transactions->pluck('id')->toArray();

        return $this->syncPayments($transactionIds, $force);
    }

    /**
     * Sync payments for a specific invoice
     */
    public function syncPaymentsForInvoice($invoiceId, $force = false)
    {
        $transactions = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->where('amountin', '>', 0)
            ->select('id')
            ->get();

        $transactionIds = $transactions->pluck('id')->toArray();

        return $this->syncPayments($transactionIds, $force);
    }

    /**
     * Sync payments by date range
     */
    public function syncPaymentsByDateRange($startDate, $endDate, $force = false)
    {
        $transactions = Capsule::table('tblaccounts')
            ->where('amountin', '>', 0)
            ->whereNotNull('invoiceid')
            ->whereBetween('date', [$startDate, $endDate])
            ->select('id')
            ->get();

        $transactionIds = $transactions->pluck('id')->toArray();

        return $this->syncPayments($transactionIds, $force);
    }

    /**
     * Get unsynced payments
     */
    public function getUnsyncedPayments($limit = 100)
    {
        return Capsule::table('tblaccounts as t')
            ->leftJoin('mod_quickbooks_payments as qb', 't.id', '=', 'qb.whmcs_transaction_id')
            ->where('t.amountin', '>', 0)
            ->whereNotNull('t.invoiceid')
            ->whereNull('qb.id')
            ->select('t.*')
            ->limit($limit)
            ->get();
    }

    /**
     * Get synced payments
     */
    public function getSyncedPayments($limit = 100, $offset = 0)
    {
        return Capsule::table('tblaccounts as t')
            ->join('mod_quickbooks_payments as qb', 't.id', '=', 'qb.whmcs_transaction_id')
            ->select('t.*', 'qb.qb_payment_id', 'qb.locked', 'qb.last_sync')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Lock a payment mapping
     */
    public function lockPayment($transactionId)
    {
        return Capsule::table('mod_quickbooks_payments')
            ->where('whmcs_transaction_id', $transactionId)
            ->update(['locked' => true, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Unlock a payment mapping
     */
    public function unlockPayment($transactionId)
    {
        return Capsule::table('mod_quickbooks_payments')
            ->where('whmcs_transaction_id', $transactionId)
            ->update(['locked' => false, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Unlink a payment (remove mapping)
     */
    public function unlinkPayment($transactionId)
    {
        return Capsule::table('mod_quickbooks_payments')
            ->where('whmcs_transaction_id', $transactionId)
            ->delete();
    }

    /**
     * Get QB payment ID for a WHMCS transaction
     */
    public function getQbPaymentId($transactionId)
    {
        $mapping = Capsule::table('mod_quickbooks_payments')
            ->where('whmcs_transaction_id', $transactionId)
            ->first();

        return $mapping ? $mapping->qb_payment_id : null;
    }

    /**
     * Prepare payment data for QuickBooks
     */
    private function preparePaymentData($transaction, $qbCustomerId, $qbInvoiceId)
    {
        $paymentData = [
            'CustomerRef' => [
                'value' => $qbCustomerId,
            ],
            'TotalAmt' => round(floatval($transaction->amountin), 2),
            'TxnDate' => $transaction->date,
            'Line' => [
                [
                    'Amount' => round(floatval($transaction->amountin), 2),
                    'LinkedTxn' => [
                        [
                            'TxnId' => $qbInvoiceId,
                            'TxnType' => 'Invoice',
                        ],
                    ],
                ],
            ],
            'PrivateNote' => 'WHMCS Transaction #' . $transaction->id,
        ];

        // Add payment method reference if mapped
        $paymentMethodId = $this->getQbPaymentMethodId($transaction->gateway);
        if ($paymentMethodId) {
            $paymentData['PaymentMethodRef'] = [
                'value' => $paymentMethodId,
            ];
        }

        // Add deposit account if configured
        $depositAccountId = $this->getQbDepositAccountId($transaction->gateway);
        if ($depositAccountId) {
            $paymentData['DepositToAccountRef'] = [
                'value' => $depositAccountId,
            ];
        }

        // Add payment reference (transaction ID from gateway)
        if ($transaction->transid) {
            $paymentData['PaymentRefNum'] = substr($transaction->transid, 0, 21);
        }

        return $paymentData;
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
