<?php
/**
 * QuickBooks Online API Client
 *
 * Handles OAuth 2.0 authentication and API communication with QuickBooks Online.
 */

namespace QuickBooksOnline;

use WHMCS\Database\Capsule;

class QuickBooksClient
{
    const OAUTH_BASE_URL_PRODUCTION = 'https://appcenter.intuit.com/connect/oauth2';
    const OAUTH_BASE_URL_SANDBOX = 'https://appcenter.intuit.com/connect/oauth2';
    const TOKEN_URL_PRODUCTION = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    const TOKEN_URL_SANDBOX = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    const API_BASE_URL_PRODUCTION = 'https://quickbooks.api.intuit.com/v3/company';
    const API_BASE_URL_SANDBOX = 'https://sandbox-quickbooks.api.intuit.com/v3/company';
    const REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $environment;
    private $realmId;
    private $accessToken;
    private $refreshToken;
    private $accessTokenExpires;
    private $refreshTokenExpires;

    /**
     * Constructor
     */
    public function __construct($clientId, $clientSecret, $redirectUri, $environment = 'sandbox')
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->environment = $environment;

        $this->loadTokens();
    }

    /**
     * Get OAuth authorization URL
     */
    public function getAuthorizationUrl($state = null)
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'scope' => 'com.intuit.quickbooks.accounting',
            'redirect_uri' => $this->redirectUri,
            'state' => $state ?: bin2hex(random_bytes(16)),
        ];

        return self::OAUTH_BASE_URL_PRODUCTION . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens
     */
    public function exchangeCodeForTokens($code, $realmId)
    {
        $tokenUrl = $this->getTokenUrl();

        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $postData = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        $response = $this->curlRequest($tokenUrl, 'POST', $postData, $headers);

        if (isset($response['access_token'])) {
            $this->realmId = $realmId;
            $this->accessToken = $response['access_token'];
            $this->refreshToken = $response['refresh_token'];
            $this->accessTokenExpires = time() + $response['expires_in'];
            $this->refreshTokenExpires = time() + $response['x_refresh_token_expires_in'];

            $this->saveTokens();

            return true;
        }

        throw new \Exception('Failed to exchange code for tokens: ' . json_encode($response));
    }

    /**
     * Refresh access token
     */
    public function refreshAccessToken()
    {
        if (empty($this->refreshToken)) {
            throw new \Exception('No refresh token available');
        }

        $tokenUrl = $this->getTokenUrl();

        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $postData = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ]);

        $response = $this->curlRequest($tokenUrl, 'POST', $postData, $headers);

        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            $this->refreshToken = $response['refresh_token'];
            $this->accessTokenExpires = time() + $response['expires_in'];
            $this->refreshTokenExpires = time() + $response['x_refresh_token_expires_in'];

            $this->saveTokens();

            return true;
        }

        throw new \Exception('Failed to refresh access token: ' . json_encode($response));
    }

    /**
     * Check if access token is expired and refresh if needed
     */
    public function ensureValidToken()
    {
        if (empty($this->accessToken)) {
            throw new \Exception('Not authenticated. Please connect to QuickBooks Online.');
        }

        // Refresh if token expires in less than 5 minutes
        if ($this->accessTokenExpires && ($this->accessTokenExpires - time()) < 300) {
            $this->refreshAccessToken();
        }

        return true;
    }

    /**
     * Check if connected to QuickBooks
     */
    public function isConnected()
    {
        return !empty($this->accessToken) && !empty($this->realmId);
    }

    /**
     * Check if refresh token is still valid
     */
    public function isRefreshTokenValid()
    {
        if (empty($this->refreshToken) || empty($this->refreshTokenExpires)) {
            return false;
        }

        // Refresh token is valid for 101 days
        return $this->refreshTokenExpires > time();
    }

    /**
     * Disconnect from QuickBooks (revoke tokens)
     */
    public function disconnect()
    {
        if (!empty($this->refreshToken)) {
            try {
                $headers = [
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                    'Content-Type: application/json',
                ];

                $postData = json_encode(['token' => $this->refreshToken]);

                $this->curlRequest(self::REVOKE_URL, 'POST', $postData, $headers);
            } catch (\Exception $e) {
                // Ignore revoke errors
            }
        }

        // Clear stored tokens
        Capsule::table('mod_quickbooks_oauth')->truncate();

        $this->realmId = null;
        $this->accessToken = null;
        $this->refreshToken = null;
        $this->accessTokenExpires = null;
        $this->refreshTokenExpires = null;

        return true;
    }

    /**
     * Make API request to QuickBooks
     */
    public function apiRequest($endpoint, $method = 'GET', $data = null)
    {
        $this->ensureValidToken();

        $baseUrl = $this->getApiBaseUrl();
        $url = $baseUrl . '/' . $this->realmId . '/' . ltrim($endpoint, '/');

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ];

        $response = $this->curlRequest($url, $method, $data ? json_encode($data) : null, $headers);

        return $response;
    }

    /**
     * Query QuickBooks entities
     */
    public function query($query)
    {
        $this->ensureValidToken();

        $baseUrl = $this->getApiBaseUrl();
        $url = $baseUrl . '/' . $this->realmId . '/query?query=' . urlencode($query);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ];

        $response = $this->curlRequest($url, 'GET', null, $headers);

        return $response;
    }

    // ========================================
    // Customer Methods
    // ========================================

    /**
     * Create a customer in QuickBooks
     */
    public function createCustomer($customerData)
    {
        return $this->apiRequest('customer', 'POST', $customerData);
    }

    /**
     * Update a customer in QuickBooks
     */
    public function updateCustomer($customerData)
    {
        return $this->apiRequest('customer', 'POST', $customerData);
    }

    /**
     * Get a customer by ID
     */
    public function getCustomer($customerId)
    {
        return $this->apiRequest('customer/' . $customerId);
    }

    /**
     * Find customer by email or display name
     */
    public function findCustomer($email = null, $displayName = null)
    {
        if ($email) {
            $query = "SELECT * FROM Customer WHERE PrimaryEmailAddr = '" . addslashes($email) . "'";
        } elseif ($displayName) {
            $query = "SELECT * FROM Customer WHERE DisplayName = '" . addslashes($displayName) . "'";
        } else {
            return null;
        }

        $result = $this->query($query);

        if (isset($result['QueryResponse']['Customer'][0])) {
            return $result['QueryResponse']['Customer'][0];
        }

        return null;
    }

    /**
     * Get all customers
     */
    public function getAllCustomers($startPosition = 1, $maxResults = 1000)
    {
        $query = "SELECT * FROM Customer STARTPOSITION {$startPosition} MAXRESULTS {$maxResults}";
        return $this->query($query);
    }

    // ========================================
    // Invoice Methods
    // ========================================

    /**
     * Create an invoice in QuickBooks
     */
    public function createInvoice($invoiceData)
    {
        return $this->apiRequest('invoice', 'POST', $invoiceData);
    }

    /**
     * Update an invoice in QuickBooks
     */
    public function updateInvoice($invoiceData)
    {
        return $this->apiRequest('invoice', 'POST', $invoiceData);
    }

    /**
     * Get an invoice by ID
     */
    public function getInvoice($invoiceId)
    {
        return $this->apiRequest('invoice/' . $invoiceId);
    }

    /**
     * Delete/void an invoice
     */
    public function voidInvoice($invoiceId, $syncToken)
    {
        $data = [
            'Id' => $invoiceId,
            'SyncToken' => $syncToken,
        ];
        return $this->apiRequest('invoice?operation=void', 'POST', $data);
    }

    /**
     * Get all invoices
     */
    public function getAllInvoices($startPosition = 1, $maxResults = 1000)
    {
        $query = "SELECT * FROM Invoice STARTPOSITION {$startPosition} MAXRESULTS {$maxResults}";
        return $this->query($query);
    }

    // ========================================
    // Payment Methods
    // ========================================

    /**
     * Create a payment in QuickBooks
     */
    public function createPayment($paymentData)
    {
        return $this->apiRequest('payment', 'POST', $paymentData);
    }

    /**
     * Update a payment in QuickBooks
     */
    public function updatePayment($paymentData)
    {
        return $this->apiRequest('payment', 'POST', $paymentData);
    }

    /**
     * Get a payment by ID
     */
    public function getPayment($paymentId)
    {
        return $this->apiRequest('payment/' . $paymentId);
    }

    /**
     * Void a payment
     */
    public function voidPayment($paymentId, $syncToken)
    {
        $data = [
            'Id' => $paymentId,
            'SyncToken' => $syncToken,
        ];
        return $this->apiRequest('payment?operation=void', 'POST', $data);
    }

    /**
     * Get all payments
     */
    public function getAllPayments($startPosition = 1, $maxResults = 1000)
    {
        $query = "SELECT * FROM Payment STARTPOSITION {$startPosition} MAXRESULTS {$maxResults}";
        return $this->query($query);
    }

    // ========================================
    // Credit Memo / Refund Methods
    // ========================================

    /**
     * Create a credit memo in QuickBooks
     */
    public function createCreditMemo($creditMemoData)
    {
        return $this->apiRequest('creditmemo', 'POST', $creditMemoData);
    }

    /**
     * Update a credit memo in QuickBooks
     */
    public function updateCreditMemo($creditMemoData)
    {
        return $this->apiRequest('creditmemo', 'POST', $creditMemoData);
    }

    /**
     * Get a credit memo by ID
     */
    public function getCreditMemo($creditMemoId)
    {
        return $this->apiRequest('creditmemo/' . $creditMemoId);
    }

    /**
     * Create a refund receipt in QuickBooks
     */
    public function createRefundReceipt($refundData)
    {
        return $this->apiRequest('refundreceipt', 'POST', $refundData);
    }

    /**
     * Get a refund receipt by ID
     */
    public function getRefundReceipt($refundId)
    {
        return $this->apiRequest('refundreceipt/' . $refundId);
    }

    // ========================================
    // Item/Product Methods
    // ========================================

    /**
     * Create an item in QuickBooks
     */
    public function createItem($itemData)
    {
        return $this->apiRequest('item', 'POST', $itemData);
    }

    /**
     * Update an item in QuickBooks
     */
    public function updateItem($itemData)
    {
        return $this->apiRequest('item', 'POST', $itemData);
    }

    /**
     * Get an item by ID
     */
    public function getItem($itemId)
    {
        return $this->apiRequest('item/' . $itemId);
    }

    /**
     * Find item by name
     */
    public function findItem($name)
    {
        $query = "SELECT * FROM Item WHERE Name = '" . addslashes($name) . "'";
        $result = $this->query($query);

        if (isset($result['QueryResponse']['Item'][0])) {
            return $result['QueryResponse']['Item'][0];
        }

        return null;
    }

    /**
     * Get all items
     */
    public function getAllItems($startPosition = 1, $maxResults = 1000)
    {
        $query = "SELECT * FROM Item STARTPOSITION {$startPosition} MAXRESULTS {$maxResults}";
        return $this->query($query);
    }

    // ========================================
    // Account Methods
    // ========================================

    /**
     * Get all accounts
     */
    public function getAllAccounts()
    {
        $query = "SELECT * FROM Account MAXRESULTS 1000";
        return $this->query($query);
    }

    /**
     * Get income accounts
     */
    public function getIncomeAccounts()
    {
        $query = "SELECT * FROM Account WHERE AccountType = 'Income' MAXRESULTS 1000";
        return $this->query($query);
    }

    /**
     * Get bank accounts
     */
    public function getBankAccounts()
    {
        $query = "SELECT * FROM Account WHERE AccountType = 'Bank' MAXRESULTS 1000";
        return $this->query($query);
    }

    // ========================================
    // Tax Methods
    // ========================================

    /**
     * Get all tax codes
     */
    public function getAllTaxCodes()
    {
        $query = "SELECT * FROM TaxCode MAXRESULTS 1000";
        return $this->query($query);
    }

    /**
     * Get all tax rates
     */
    public function getAllTaxRates()
    {
        $query = "SELECT * FROM TaxRate MAXRESULTS 1000";
        return $this->query($query);
    }

    // ========================================
    // Payment Method Methods
    // ========================================

    /**
     * Get all payment methods
     */
    public function getAllPaymentMethods()
    {
        $query = "SELECT * FROM PaymentMethod MAXRESULTS 1000";
        return $this->query($query);
    }

    /**
     * Create payment method
     */
    public function createPaymentMethod($data)
    {
        return $this->apiRequest('paymentmethod', 'POST', $data);
    }

    // ========================================
    // Company Info
    // ========================================

    /**
     * Get company info
     */
    public function getCompanyInfo()
    {
        return $this->apiRequest('companyinfo/' . $this->realmId);
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Get token URL based on environment
     */
    private function getTokenUrl()
    {
        return $this->environment === 'production'
            ? self::TOKEN_URL_PRODUCTION
            : self::TOKEN_URL_SANDBOX;
    }

    /**
     * Get API base URL based on environment
     */
    private function getApiBaseUrl()
    {
        return $this->environment === 'production'
            ? self::API_BASE_URL_PRODUCTION
            : self::API_BASE_URL_SANDBOX;
    }

    /**
     * Make cURL request
     */
    private function curlRequest($url, $method = 'GET', $data = null, $headers = [])
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL error: ' . $error);
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = 'API Error (HTTP ' . $httpCode . ')';
            if (isset($decoded['Fault']['Error'][0]['Message'])) {
                $errorMessage .= ': ' . $decoded['Fault']['Error'][0]['Message'];
                if (isset($decoded['Fault']['Error'][0]['Detail'])) {
                    $errorMessage .= ' - ' . $decoded['Fault']['Error'][0]['Detail'];
                }
            }
            throw new \Exception($errorMessage);
        }

        return $decoded;
    }

    /**
     * Load tokens from database
     */
    private function loadTokens()
    {
        $tokens = Capsule::table('mod_quickbooks_oauth')->first();

        if ($tokens) {
            $this->realmId = $tokens->realm_id;
            $this->accessToken = $tokens->access_token;
            $this->refreshToken = $tokens->refresh_token;
            $this->accessTokenExpires = $tokens->access_token_expires;
            $this->refreshTokenExpires = $tokens->refresh_token_expires;
        }
    }

    /**
     * Save tokens to database
     */
    private function saveTokens()
    {
        $data = [
            'realm_id' => $this->realmId,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'access_token_expires' => $this->accessTokenExpires,
            'refresh_token_expires' => $this->refreshTokenExpires,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $existing = Capsule::table('mod_quickbooks_oauth')->first();

        if ($existing) {
            Capsule::table('mod_quickbooks_oauth')->where('id', $existing->id)->update($data);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            Capsule::table('mod_quickbooks_oauth')->insert($data);
        }
    }

    /**
     * Get realm ID
     */
    public function getRealmId()
    {
        return $this->realmId;
    }

    /**
     * Get connection status info
     */
    public function getConnectionStatus()
    {
        return [
            'connected' => $this->isConnected(),
            'realm_id' => $this->realmId,
            'access_token_expires' => $this->accessTokenExpires,
            'refresh_token_expires' => $this->refreshTokenExpires,
            'access_token_valid' => $this->accessTokenExpires ? ($this->accessTokenExpires > time()) : false,
            'refresh_token_valid' => $this->isRefreshTokenValid(),
        ];
    }
}
