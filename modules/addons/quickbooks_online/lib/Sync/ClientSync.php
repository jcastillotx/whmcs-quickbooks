<?php
/**
 * Client Synchronization Service
 *
 * Handles syncing WHMCS clients to QuickBooks Online customers.
 */

namespace QuickBooksOnline\Sync;

use WHMCS\Database\Capsule;
use QuickBooksOnline\QuickBooksClient;
use QuickBooksOnline\Logger;

class ClientSync
{
    private $qbClient;
    private $logger;
    private $settings;

    public function __construct(QuickBooksClient $qbClient, array $settings = [])
    {
        $this->qbClient = $qbClient;
        $this->settings = $settings;
        $this->logger = new Logger();
    }

    /**
     * Sync a single client to QuickBooks
     */
    public function syncClient($clientId, $force = false)
    {
        try {
            // Get WHMCS client data
            $client = Capsule::table('tblclients')->where('id', $clientId)->first();

            if (!$client) {
                throw new \Exception("Client not found: {$clientId}");
            }

            // Check if already synced
            $mapping = Capsule::table('mod_quickbooks_clients')
                ->where('whmcs_client_id', $clientId)
                ->first();

            // If locked and not forcing, skip
            if ($mapping && $mapping->locked && !$force) {
                return [
                    'success' => true,
                    'action' => 'skipped',
                    'message' => 'Client is locked',
                    'qb_customer_id' => $mapping->qb_customer_id,
                ];
            }

            // Prepare customer data
            $customerData = $this->prepareCustomerData($client);

            if ($mapping) {
                // Update existing customer
                $existingCustomer = $this->qbClient->getCustomer($mapping->qb_customer_id);

                if (!isset($existingCustomer['Customer'])) {
                    throw new \Exception('Failed to retrieve existing customer from QuickBooks');
                }

                $customerData['Id'] = $mapping->qb_customer_id;
                $customerData['SyncToken'] = $existingCustomer['Customer']['SyncToken'];

                $response = $this->qbClient->updateCustomer($customerData);
                $action = 'update';
            } else {
                // Check if customer exists in QB by email
                $existingCustomer = $this->qbClient->findCustomer($client->email);

                if ($existingCustomer) {
                    // Link existing customer
                    $customerData['Id'] = $existingCustomer['Id'];
                    $customerData['SyncToken'] = $existingCustomer['SyncToken'];
                    $response = $this->qbClient->updateCustomer($customerData);
                    $action = 'link';
                } else {
                    // Create new customer
                    $response = $this->qbClient->createCustomer($customerData);
                    $action = 'create';
                }
            }

            if (!isset($response['Customer']['Id'])) {
                throw new \Exception('Failed to sync customer to QuickBooks');
            }

            $qbCustomerId = $response['Customer']['Id'];

            // Update or create mapping
            $mappingData = [
                'qb_customer_id' => $qbCustomerId,
                'last_sync' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($mapping) {
                Capsule::table('mod_quickbooks_clients')
                    ->where('whmcs_client_id', $clientId)
                    ->update($mappingData);
            } else {
                $mappingData['whmcs_client_id'] = $clientId;
                $mappingData['created_at'] = date('Y-m-d H:i:s');
                Capsule::table('mod_quickbooks_clients')->insert($mappingData);
            }

            // Log success
            $this->logger->log('client', $action, $clientId, $qbCustomerId, 'success', "Client synced successfully");

            return [
                'success' => true,
                'action' => $action,
                'qb_customer_id' => $qbCustomerId,
                'message' => 'Client synced successfully',
            ];

        } catch (\Exception $e) {
            $this->logger->log('client', 'sync', $clientId, null, 'error', $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync multiple clients
     */
    public function syncClients(array $clientIds, $force = false)
    {
        $results = [
            'total' => count($clientIds),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        foreach ($clientIds as $clientId) {
            $result = $this->syncClient($clientId, $force);

            if ($result['success']) {
                if (isset($result['action']) && $result['action'] === 'skipped') {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } else {
                $results['failed']++;
            }

            $results['details'][$clientId] = $result;
        }

        return $results;
    }

    /**
     * Sync all clients
     */
    public function syncAllClients($force = false, $limit = 100, $offset = 0)
    {
        $clients = Capsule::table('tblclients')
            ->select('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $clientIds = $clients->pluck('id')->toArray();

        return $this->syncClients($clientIds, $force);
    }

    /**
     * Get unsynced clients
     */
    public function getUnsyncedClients($limit = 100)
    {
        return Capsule::table('tblclients as c')
            ->leftJoin('mod_quickbooks_clients as qb', 'c.id', '=', 'qb.whmcs_client_id')
            ->whereNull('qb.id')
            ->select('c.*')
            ->limit($limit)
            ->get();
    }

    /**
     * Get synced clients
     */
    public function getSyncedClients($limit = 100, $offset = 0)
    {
        return Capsule::table('tblclients as c')
            ->join('mod_quickbooks_clients as qb', 'c.id', '=', 'qb.whmcs_client_id')
            ->select('c.*', 'qb.qb_customer_id', 'qb.locked', 'qb.last_sync')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Lock a client mapping
     */
    public function lockClient($clientId)
    {
        return Capsule::table('mod_quickbooks_clients')
            ->where('whmcs_client_id', $clientId)
            ->update(['locked' => true, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Unlock a client mapping
     */
    public function unlockClient($clientId)
    {
        return Capsule::table('mod_quickbooks_clients')
            ->where('whmcs_client_id', $clientId)
            ->update(['locked' => false, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Unlink a client (remove mapping)
     */
    public function unlinkClient($clientId)
    {
        return Capsule::table('mod_quickbooks_clients')
            ->where('whmcs_client_id', $clientId)
            ->delete();
    }

    /**
     * Get QB customer ID for a WHMCS client
     */
    public function getQbCustomerId($clientId)
    {
        $mapping = Capsule::table('mod_quickbooks_clients')
            ->where('whmcs_client_id', $clientId)
            ->first();

        return $mapping ? $mapping->qb_customer_id : null;
    }

    /**
     * Prepare customer data for QuickBooks
     */
    private function prepareCustomerData($client)
    {
        // Build display name (must be unique in QB)
        $displayName = trim($client->firstname . ' ' . $client->lastname);
        if ($client->companyname) {
            $displayName = $client->companyname;
        }

        // Append client ID to ensure uniqueness
        $displayName .= ' (' . $client->id . ')';

        $customerData = [
            'DisplayName' => substr($displayName, 0, 100),
            'CompanyName' => $client->companyname ? substr($client->companyname, 0, 100) : null,
            'GivenName' => substr($client->firstname, 0, 25),
            'FamilyName' => substr($client->lastname, 0, 25),
            'PrimaryEmailAddr' => [
                'Address' => $client->email,
            ],
            'BillAddr' => [
                'Line1' => substr($client->address1, 0, 500),
                'Line2' => $client->address2 ? substr($client->address2, 0, 500) : null,
                'City' => substr($client->city, 0, 255),
                'CountrySubDivisionCode' => substr($client->state, 0, 255),
                'PostalCode' => substr($client->postcode, 0, 30),
                'Country' => substr($client->country, 0, 255),
            ],
            'Active' => $client->status === 'Active',
        ];

        // Add phone number if available
        if ($client->phonenumber) {
            $customerData['PrimaryPhone'] = [
                'FreeFormNumber' => substr($client->phonenumber, 0, 30),
            ];
        }

        // Add tax registration number if custom field is configured
        if (!empty($this->settings['tax_custom_field'])) {
            $taxId = $this->getClientCustomField($client->id, $this->settings['tax_custom_field']);
            if ($taxId) {
                $customerData['PrimaryTaxIdentifier'] = substr($taxId, 0, 20);
            }
        }

        // Add notes
        $customerData['Notes'] = 'WHMCS Client ID: ' . $client->id;

        // Filter out null values
        $customerData = array_filter($customerData, function ($value) {
            return $value !== null;
        });

        // Filter null values from nested arrays
        if (isset($customerData['BillAddr'])) {
            $customerData['BillAddr'] = array_filter($customerData['BillAddr'], function ($value) {
                return $value !== null;
            });
        }

        return $customerData;
    }

    /**
     * Get custom field value for a client
     */
    private function getClientCustomField($clientId, $fieldName)
    {
        $field = Capsule::table('tblcustomfields')
            ->where('fieldname', $fieldName)
            ->where('type', 'client')
            ->first();

        if (!$field) {
            return null;
        }

        $value = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $field->id)
            ->where('relid', $clientId)
            ->first();

        return $value ? $value->value : null;
    }

    /**
     * Import customers from QuickBooks to WHMCS
     */
    public function importFromQuickBooks($qbCustomerId = null)
    {
        // This would be used to import QB customers to WHMCS
        // Implementation depends on specific requirements
        throw new \Exception('Import from QuickBooks not yet implemented');
    }
}
