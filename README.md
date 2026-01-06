# QuickBooks Online for WHMCS

A comprehensive WHMCS addon module for integrating with QuickBooks Online. Automatically sync clients, invoices, payments, credits, and refunds between WHMCS and QuickBooks Online.

## Features

- **OAuth 2.0 Authentication**: Secure connection to QuickBooks Online using OAuth 2.0
- **Client Sync**: Automatically sync WHMCS clients to QuickBooks customers
- **Invoice Sync**: Export invoices with line items to QuickBooks
- **Payment Sync**: Record payments and apply them to invoices in QuickBooks
- **Credit & Refund Sync**: Sync account credits as Credit Memos and refunds
- **Tax Mapping**: Map WHMCS tax rates to QuickBooks tax codes
- **Payment Gateway Mapping**: Map WHMCS payment gateways to QuickBooks payment methods
- **Auto-Sync via Hooks**: Real-time synchronization when data changes in WHMCS
- **Cron Support**: Scheduled batch synchronization
- **Lock Protection**: Lock synced items to prevent overwrites
- **Comprehensive Logging**: Track all sync operations with detailed logs

## Requirements

- WHMCS 8.0 or higher
- PHP 7.4 or higher
- QuickBooks Online account (US, Canada, UK, Australia supported)
- cURL PHP extension
- JSON PHP extension

## Installation

1. **Upload Files**
   ```
   Copy the `modules/addons/quickbooks_online` folder to your WHMCS installation:
   /path/to/whmcs/modules/addons/quickbooks_online/
   ```

2. **Activate Module**
   - Go to WHMCS Admin > Setup > Addon Modules
   - Find "QuickBooks Online" and click "Activate"
   - Configure access control as needed

3. **Create QuickBooks App**
   - Go to [Intuit Developer Portal](https://developer.intuit.com)
   - Create a new app or select existing
   - Get your Client ID and Client Secret
   - Add the Redirect URI: `https://yourdomain.com/admin/addonmodules.php?module=quickbooks_online`

4. **Configure Module**
   - Go to WHMCS Admin > Addons > QuickBooks Online
   - Enter your Client ID and Client Secret
   - Select Environment (sandbox for testing, production for live)
   - Click "Connect to QuickBooks"
   - Authorize the connection

5. **Set Up Cron Job** (Optional, for automated sync)
   ```bash
   # Run every 15 minutes
   */15 * * * * php -q /path/to/whmcs/modules/addons/quickbooks_online/cron.php sync-all --limit=50

   # Or daily log cleanup
   0 2 * * * php -q /path/to/whmcs/modules/addons/quickbooks_online/cron.php cleanup-logs
   ```

## Configuration

### Module Settings

| Setting | Description |
|---------|-------------|
| Client ID | OAuth 2.0 Client ID from Intuit Developer |
| Client Secret | OAuth 2.0 Client Secret |
| Environment | sandbox (testing) or production (live) |
| Auto Sync | Enable real-time sync via WHMCS hooks |
| Sync Zero Invoices | Include $0 invoices in sync |
| Tax ID Custom Field | WHMCS custom field for Tax ID/VAT number |

### Tax Mapping

Map your WHMCS tax rates to QuickBooks tax codes:
- Go to Addons > QuickBooks Online > Tax Mapping
- Select the corresponding QuickBooks tax code for each WHMCS tax

### Payment Gateway Mapping

Map WHMCS payment gateways to QuickBooks payment methods:
- Go to Addons > QuickBooks Online > Payment Gateways
- Select the payment method and deposit account for each gateway

## Usage

### Manual Sync

1. **Sync Individual Items**
   - Navigate to the Clients, Invoices, or Payments page
   - Click "Sync" next to the item you want to sync

2. **Bulk Sync**
   - Click "Sync All Unsynced" buttons on each page
   - Or use the Dashboard quick actions

### Locking Items

Lock items to prevent them from being overwritten by future syncs:
- Click "Lock" next to any synced item
- Locked items show a lock icon
- Click "Unlock" to allow syncing again

### Viewing Logs

Monitor sync operations:
- Go to Addons > QuickBooks Online > Sync Logs
- Filter by type, status, or date
- View detailed error messages for failed syncs

## Cron Commands

```bash
# Sync all unsynced data
php cron.php sync-all --limit=100

# Sync only clients
php cron.php sync-clients --limit=50

# Sync only invoices
php cron.php sync-invoices --limit=50

# Sync only payments
php cron.php sync-payments --limit=50

# Sync credits and refunds
php cron.php sync-credits --limit=50

# Clean up old logs
php cron.php cleanup-logs

# Options
--limit=N     # Limit items per run
--force       # Force sync even if already synced
--quiet       # Suppress output
```

## Data Mapping

### Clients to Customers

| WHMCS Field | QuickBooks Field |
|-------------|------------------|
| First Name + Last Name | DisplayName |
| Company Name | CompanyName |
| Email | PrimaryEmailAddr |
| Phone | PrimaryPhone |
| Address | BillAddr |
| Tax ID (custom field) | PrimaryTaxIdentifier |

### Invoices

| WHMCS Field | QuickBooks Field |
|-------------|------------------|
| Invoice ID | DocNumber |
| Date | TxnDate |
| Due Date | DueDate |
| Line Items | Line (SalesItemLineDetail) |
| Total | TotalAmt |

### Payments

| WHMCS Field | QuickBooks Field |
|-------------|------------------|
| Amount | TotalAmt |
| Date | TxnDate |
| Gateway | PaymentMethodRef |
| Transaction ID | PaymentRefNum |

## Troubleshooting

### Connection Issues

1. **Invalid Client ID/Secret**
   - Verify credentials in Intuit Developer Portal
   - Ensure app is configured for correct environment

2. **Redirect URI Mismatch**
   - Add exact URI to QuickBooks app settings
   - Include `https://` and correct domain

3. **Token Expired**
   - Access tokens expire after 1 hour
   - Refresh tokens expire after 101 days
   - Reconnect if refresh token expires

### Sync Errors

1. **Customer Not Found**
   - Ensure client is synced before invoices/payments

2. **Duplicate Display Name**
   - Module appends client ID to ensure uniqueness
   - Check for manually created duplicates in QB

3. **Tax Code Not Found**
   - Configure tax mapping in module settings

### Common Error Messages

| Error | Solution |
|-------|----------|
| "Not authenticated" | Reconnect to QuickBooks |
| "Customer already exists" | Check for duplicate in QB |
| "Invalid tax code" | Map tax in configuration |
| "Account not found" | Configure income account in settings |

## Database Tables

The module creates these tables:

- `mod_quickbooks_oauth` - OAuth tokens
- `mod_quickbooks_clients` - Client sync mappings
- `mod_quickbooks_invoices` - Invoice sync mappings
- `mod_quickbooks_payments` - Payment sync mappings
- `mod_quickbooks_credits` - Credit/refund sync mappings
- `mod_quickbooks_taxes` - Tax code mappings
- `mod_quickbooks_payment_methods` - Gateway mappings
- `mod_quickbooks_items` - Product/item mappings
- `mod_quickbooks_settings` - Additional settings
- `mod_quickbooks_logs` - Sync operation logs

## API Endpoints Used

- OAuth 2.0: `appcenter.intuit.com/connect/oauth2`
- Token: `oauth.platform.intuit.com/oauth2/v1/tokens/bearer`
- API (Production): `quickbooks.api.intuit.com/v3/company`
- API (Sandbox): `sandbox-quickbooks.api.intuit.com/v3/company`

## Security Notes

- OAuth credentials are stored in WHMCS addon settings
- Access/refresh tokens are stored encrypted in database
- All API communications use HTTPS
- Tokens are automatically refreshed before expiration

## Support

For issues and feature requests, please open an issue on the repository.

## License

MIT License - See LICENSE file for details.

## Changelog

### Version 1.0.0
- Initial release
- OAuth 2.0 authentication
- Client, Invoice, Payment sync
- Credit and Refund sync
- Tax and Payment Gateway mapping
- Cron job support
- Real-time sync via hooks
- Comprehensive admin interface
- Sync logging and history
