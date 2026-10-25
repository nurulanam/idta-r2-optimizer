# IDTA R2 Optimizer

Cloudflare R2 storage optimization, connection analyzer, and lifecycle management for WooCommerce IDP verification documents.

Built for **WordPress 6.0+**, **WooCommerce 7.0+** (Full HPOS Compatibility), and **PHP 8.1+** (strict types, zero external AWS SDK dependencies).

---

## Overview

In the IDTA ecosystem, customer verification uploads (`portrait.jpg`, `license-front.jpg`, `license-back.jpg`, `signature.png`) are stored in a Cloudflare R2 bucket under a timestamped folder prefix (e.g. `2026/08/01/063701-213/`). When an application checkout completes, WooCommerce stores this relative folder path in the `_idp_assets` order meta key, while the store origin is recorded in `_idp_order_from`.

### The Problem
- **Orphaned Storage Accumulation**: Abandoned checkouts, failed payment attempts, automated test transactions, and deleted orders leave behind thousands of document folders taking up gigabytes of unattached R2 storage.
- **Missing or Incomplete Assets**: Customers occasionally experience upload drops, leaving orders referencing folders missing crucial documents like license backs or signatures.
- **Blind Storage Costs**: Store managers lack visibility into which R2 objects belong to real customer orders and which are abandoned files racking up storage bills.

### The Solution
**IDTA R2 Optimizer** bridges WooCommerce with Cloudflare R2:
- Traverses R2 buckets and cross-references folder prefixes against WooCommerce order tables (`wp_wc_orders_meta` for HPOS, falling back to `wp_postmeta`).
- Classifies each folder into visual health statuses:
  - <span style="color:#16a34a;font-weight:600;">Green [CONNECTED]</span>: Folder matches an active WooCommerce order.
  - <span style="color:#dc2626;font-weight:600;">Red [ORPHANED]</span>: Folder exists in R2 with files, but no order references it.
  - <span style="color:#d97706;font-weight:600;">Amber [INCOMPLETE]</span>: Order references the folder, but one or more standard documents are missing in R2.
- Calculates reclaimable disk space and monthly cost savings based on Cloudflare R2 standard rates ($0.015/GB-month).
- Provides single and bulk cleanup with **Dry-Run simulation** and hard safety guardrails that prevent accidental deletion of connected order files.
- Automatically audits buckets on a background schedule, sends storage threshold email alerts, purges stale abandoned files, and provides a full WP-CLI command suite.

---

## Core Features

| Feature | Description |
| --- | --- |
| **Pure PHP SigV4 S3 Client** | Implements native AWS Signature Version 4 HMAC-SHA256 authentication. No heavyweight AWS SDK dependency. |
| **HPOS & Legacy Dual Support** | Seamlessly queries both WooCommerce High-Performance Order Storage (custom tables) and classic postmeta. |
| **Live Chunked Scanner** | Discovers bucket folders in batches via `ListObjectsV2` continuation tokens, preventing PHP timeouts on large buckets. |
| **Summary KPI Cards** | Live tracking of Total Folders, Total Storage, Connected Orders, Orphaned Folders, Incomplete Folders, and Monthly Dollar Savings. |
| **Interactive Explorer Table** | Filter tabs (`All`, `Connected`, `Orphaned`, `Incomplete`), date range picker (Year/Month), live search, and pagination. |
| **Document Inspection Drawer** | Click "Inspect" to view signed previews of all 4 document images, linked order summary, and file metadata (size, ETag, date). |
| **Safety Guardrails** | Deleting connected orders requires explicit administrative confirmation. Connected orders are automatically excluded from bulk cleanup. |
| **Dry-Run Preview** | Preview candidate folders count, files count, and reclaimable MB before confirming permanent deletion. |
| **Order Remapping** | Link an orphaned folder directly to a WooCommerce order ID from the UI, updating `_idp_assets` and turning the row Green. |
| **Audit Trail & CSV Export** | Logs every deletion, force override, remapping, and bulk cleanup to `wp_idta_r2_logs`. Export records to CSV at any time. |
| **Automated Background Audits** | Action Scheduler / WP-Cron periodic background jobs to re-index buckets and warm transients for instant dashboard load times. |
| **Storage Threshold Alerts** | Dispatches an alert email to the site administrator when orphaned storage volume exceeds a configured size (e.g. > 5 GB). |
| **Automated Retention Purging** | Optional automated purge rule for orphaned folders older than 30, 60, or 90 days. Connected orders are strictly preserved. |
| **WP-CLI Terminal Automation** | Full command-line interface (`wp r2-optimizer`) for terminal scripting, cron pipelines, and server administration. |

---

## Installation & Setup

### Requirements
- **PHP**: 8.1 or higher (with `hash`, `curl`, and `simplexml` extensions).
- **WordPress**: 6.0 or higher.
- **WooCommerce**: 7.0 or higher.

### Manual Installation
1. Upload the plugin files to `/wp-content/plugins/idta-r2-optimizer/` (or install the distribution zip file via **Plugins → Add New → Upload Plugin**).
2. Activate the plugin in **WordPress → Plugins**.
3. Navigate to **WooCommerce → R2 Optimizer → Settings**.

### Cloudflare R2 Credentials
Enter your Cloudflare R2 API credentials:
- **Cloudflare Account ID**: Found on your Cloudflare dashboard URL or R2 overview page.
- **Access Key ID**: 32-character S3 API token key.
- **Secret Access Key**: 64-character S3 API secret key.
- **Default Active Bucket**: Primary bucket storing order verification assets (default: `idta-html`).
- **Custom S3 Endpoint (Optional)**: Automatically defaults to `https://{account_id}.r2.cloudflarestorage.com`.

Click **Test R2 Connection** to verify API communication in real time before saving.

### Enterprise `wp-config.php` Overrides
Credentials can be defined as constants in `wp-config.php` for version-controlled or multi-environment deployments:

```php
define( 'IDTA_R2_ACCOUNT_ID', 'your-cloudflare-account-id' );
define( 'IDTA_R2_ACCESS_KEY', 'your-access-key-id' );
define( 'IDTA_R2_SECRET_KEY', 'your-secret-access-key' );
define( 'IDTA_R2_DEFAULT_BUCKET', 'idta-html' );
define( 'IDTA_R2_ENDPOINT', 'https://your-account-id.r2.cloudflarestorage.com' );
```
When constants are present, the settings UI displays locked indicator badges and respects the constant definitions.

---

## Source-to-Bucket Mapping

Different front-end checkout portals can upload verification files to different Cloudflare R2 buckets. The plugin automatically reads detected front-end source slugs from `\IDTA\PDF\Order_Data::sources()` (or historical order meta):

| Source Slug (`_idp_order_from`) | Default Upload Target | Target Bucket |
| --- | --- | --- |
| `idta` | Primary IDTA application | `idta-html` |
| `idpa` | IDPA companion portal | `idpa-html` |
| *(fallback)* | Unmatched or custom origins | Configured Default Bucket |

Configure explicit bucket mappings under **WooCommerce → R2 Optimizer → Settings → Front-End Source to R2 Bucket Mappings**.

---

## Document Specifications

Inside each order asset folder prefix, the plugin expects 4 canonical verification documents:

| Asset Key | Canonical Filename | Document Description |
| --- | --- | --- |
| `passport_photo` | `portrait.jpg` | Customer passport photograph |
| `license_front` | `license-front.jpg` | Driver licence front side scan |
| `license_back` | `license-back.jpg` | Driver licence back side scan |
| `signature` | `signature.png` | Customer signature with transparent background |

### Filter Extensibility
Expected filenames can be extended or overridden via the WordPress filter:

```php
add_filter( 'idta_r2_expected_asset_files', function ( array $files ): array {
    $files['utility_bill'] = 'proof-of-address.pdf';
    return $files;
} );
```

---

## WP-CLI Command Suite

IDTA R2 Optimizer includes a full WP-CLI command suite under the `wp r2-optimizer` namespace:

### 1. Test Connectivity
Verifies credentials and lists detected buckets:
```bash
wp r2-optimizer test
```

### 2. List Buckets
Lists all Cloudflare R2 buckets in the account:
```bash
wp r2-optimizer list-buckets
wp r2-optimizer list-buckets --format=json
```

### 3. Scan & Analyze Bucket
Scans folders and reports health and connection metrics:
```bash
# Scan default bucket
wp r2-optimizer scan

# Scan specific bucket and date prefix
wp r2-optimizer scan --bucket=idta-html --prefix=2026/08/

# Output folder list in JSON format
wp r2-optimizer scan --format=json
```

### 4. Clean Orphaned Folders
Cleans orphaned folders with mandatory dry-run safety by default:
```bash
# Dry-run preview (reports candidate folders, files, and reclaimable MB)
wp r2-optimizer clean --dry-run

# Permanently delete orphaned folders older than 60 days
wp r2-optimizer clean --no-dry-run --days=60

# Non-interactive automated cleanup for cron scripts
wp r2-optimizer clean --no-dry-run --days=30 --yes
```

### 5. Remap Folder to Order
Links an unattached R2 folder to a WooCommerce order:
```bash
wp r2-optimizer remap --folder=2026/08/01/063701-213/ --order-id=12345
```

### 6. View Operations Audit Trail
Inspects historical operations from the terminal:
```bash
wp r2-optimizer logs
wp r2-optimizer logs --limit=50 --action=BATCH_CLEANUP
wp r2-optimizer logs --format=csv > r2-audit-export.csv
```

---

## Database Schema & Cleanup

### Audit Log Table (`wp_idta_r2_logs`)
Created automatically upon plugin activation to maintain compliance and traceability:

```sql
CREATE TABLE wp_idta_r2_logs (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id bigint(20) unsigned NOT NULL DEFAULT 0,
    user_display_name varchar(250) NOT NULL DEFAULT '',
    action_type varchar(50) NOT NULL,
    bucket varchar(100) NOT NULL DEFAULT '',
    folder_path text NOT NULL,
    order_id bigint(20) unsigned DEFAULT NULL,
    status varchar(20) NOT NULL DEFAULT 'success',
    details longtext DEFAULT NULL,
    PRIMARY KEY  (id),
    KEY action_type (action_type),
    KEY order_id (order_id),
    KEY timestamp (timestamp)
);
```

### Uninstallation (`uninstall.php`)
When deleting the plugin through WordPress:
- All transient caches (`idta_r2_scan_progress`, `idta_r2_buckets_cache`) are removed.
- Scheduled Action Scheduler and WP-Cron jobs are cancelled.
- **Data Preservation Guardrail**: Settings and `wp_idta_r2_logs` are preserved by default to protect historical records. To completely purge all database tables and settings upon deletion, add this flag to `wp-config.php`:
  ```php
  define( 'IDTA_R2_DELETE_DATA', true );
  ```

---

## Architecture & Directory Structure

```
idta-r2-optimizer/
├── idta-r2-optimizer.php          # Main plugin bootstrap & HPOS declaration
├── uninstall.php                  # Safe cleanup on uninstallation
├── README.md                      # Comprehensive developer & administrator documentation
├── roadmap.md                     # Architecture specification & phase completion log
├── bin/
│   └── build-plugin-zip.sh        # Production packaging and minification script
├── dist/
│   └── idta-r2-optimizer-v1.0.0.zip # Standalone production distribution package
├── tests/
│   └── test-suite.php             # Master automated QA & verification test harness
├── assets/
│   ├── css/
│   │   ├── admin.css              # Dashboard, KPI cards, table & badge styling
│   │   └── admin.min.css          # Production minified stylesheet
│   └── js/
│       ├── admin.js               # Interactive table, AJAX scanner, modals
│       └── admin.min.js           # Production minified script
├── includes/
│   ├── class-autoloader.php       # PSR-4 dynamic class autoloader
│   ├── class-plugin.php           # Central singleton service container
│   ├── enum-connection-status.php # Status enum: Connected, Orphaned, Broken
│   ├── class-bucket-record.php    # Bucket record DTO
│   ├── class-folder-record.php    # Analyzed folder DTO with metrics
│   ├── class-order-asset-record.php # Order asset cross-reference DTO
│   ├── class-r2-signer.php        # Native AWS SigV4 HMAC-SHA256 signature generator
│   ├── class-r2-client.php        # S3 REST API client (List, Get, Delete, Batch)
│   ├── class-source-mapper.php    # IDTA PDF upload source & bucket resolver
│   ├── class-order-indexer.php    # WooCommerce HPOS & postmeta asset lookup engine
│   ├── class-folder-scanner.php   # R2 folder prefix traversal & batch engine
│   ├── class-connection-analyzer.php # Green (Connected) vs Red (Orphaned) classifier
│   ├── class-folder-manager.php   # Deletions, batch cleanup, order remapping
│   ├── class-admin-page.php       # Admin dashboard controller & views
│   ├── class-audit-logger.php     # Deletion & manipulation audit logger
│   ├── class-scheduler.php        # Action Scheduler / WP-Cron background automation
│   └── class-cli-commands.php     # WP-CLI terminal command suite
└── templates/
    └── admin/
        ├── dashboard.php          # Main explorer view (KPIs, toolbar, table)
        ├── settings.php           # Cloudflare R2 credentials, bucket mapping, retention
        ├── modal-inspect.php      # Document inspection drawer with image previews
        ├── modal-remap.php        # Link orphaned folder to WooCommerce Order modal
        ├── modal-dry-run.php      # Dry run bulk deletion simulation modal
        └── modal-audit-log.php    # Operations history & CSV export modal
```

---

## Building & Verification

Run the master test suite:
```bash
php tests/test-suite.php
```

Package a production-ready distribution archive:
```bash
chmod +x bin/build-plugin-zip.sh
bin/build-plugin-zip.sh
```
Outputs a clean zip archive in `dist/idta-r2-optimizer-v1.0.0.zip` ready for production deployment.

---

## License
Proprietary — International Drivers Association (IDTA). All rights reserved.

