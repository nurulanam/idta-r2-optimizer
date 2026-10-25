# IDTA R2 Optimizer Plugin — Development Roadmap

> **Target Environment**: WordPress 6.0+ | WooCommerce 7.0+ (Full HPOS Compatibility) | PHP 8.1 / 8.2 / 8.3 (Strict Types, Zero Deprecations)\
> \*\***Plugin Slug**: `idta-r2-optimizer`\
> \*\***Namespace**: `IDTA\R2Optimizer`

---

## Executive Summary & Vision

The **IDTA R2 Optimizer** is a high-performance WordPress & WooCommerce administrative plugin designed to audit, synchronize, clean, and manipulate document assets stored in **Cloudflare R2** object storage.

In the IDTA ecosystem, front-end applications upload customer verification documents (`portrait.jpg`, `license-front.jpg`, `license-back.jpg`, `signature.png`) into a dated folder prefix inside Cloudflare R2 (e.g. `2026/08/01/063701-213`). When an order is completed, WooCommerce stores this path in the `_idp_assets` meta key, while the source site identifier is tracked in `_idp_order_from`.

Due to abandoned checkouts, failed sessions, testing uploads, and order cancellations, R2 storage accumulates substantial orphaned document folders that incur unnecessary storage costs and complicate compliance.

This plugin provides:

1. **Direct Cloudflare R2 Authentication & Bucket Management**: Seamless connection using S3-compatible credentials.
2. **Intelligent Bucket-to-Order Mapping**: Automatic matching between `idta-pdf` upload sources (`idta`, `idpa`) and R2 buckets.
3. **Folder Connection & Health Status**: Visual classification of all folders in R2:
   - <span style="color: rgb(22, 163, 74);">**● Green \[Connected\]**</span>: Folder is confirmed attached to an existing, valid WooCommerce order.
   - <span style="color: rgb(220, 38, 38);">**● Red \[Orphaned / Unconnected\]**</span>: Folder exists in R2 with no corresponding WooCommerce order.
   - <span style="color: rgb(217, 119, 6);">**● Amber \[Missing / Broken Assets\]**</span>: Order exists, but one or more required asset files are missing in R2.
4. **Data Manipulation & Optimization Suite**:
   - Delete orphaned folders (single and batch).
   - Re-link orphaned folders to WooCommerce orders.
   - Update / edit `_idp_assets` metadata directly.
   - Dry-run protection, deletion logs, and automated retention policies.

---

## Phase 1: Architecture, WordPress Standards & Foundation

### Objective

Establish a modern, secure, extensible plugin architecture complying strictly with WordPress Coding Standards (WPCS) and modern PHP (8.1+), declaring full WooCommerce High-Performance Order Storage (HPOS) compatibility.

### Subphases & Tasks

- [x] **1.1 Plugin Scaffold & Header Declaration**

  - Create the primary plugin file `idta-r2-optimizer.php`.
  - Declare standard WordPress headers (Plugin Name, URI, Description, Version `1.0.0`, Requires at least `6.0`, Requires PHP `8.1`, Author, License `GPL-2.0-or-later`, Text Domain `idta-r2-optimizer`).
  - Declare `declare(strict_types=1);` and root namespace `IDTA\R2Optimizer`.
  - Prevent direct script execution via `defined( 'ABSPATH' ) || exit;`.

- [x] **1.2 HPOS (High-Performance Order Storage) Compatibility**

  - Register `before_woocommerce_init` action hook.
  - Declare full compatibility with `custom_order_tables` via `\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true )`.

- [x] **1.3 Class Autoloader Implementation**

  - Implement a lightweight, zero-dependency PSR-4 compliant autoloader in `includes/class-autoloader.php`.
  - Ensure dynamic class loading without manual `require` chains or heavy third-party packages.

- [x] **1.4 Modern PHP Standards & Deprecation-Free Codebase**

  - Strict enforcement of PHP 8.1+ features:
    - Typed properties and pure return type declarations (`: void`, `: string`, `: int`, `: bool`, `: array`, `?object`).
    - Enums for folder statuses (`Connection_Status::CONNECTED`, `Connection_Status::ORPHANED`, `Connection_Status::BROKEN`).
    - Match expressions (`match()`) and nullsafe operators (`?->`).
    - Modern string functions (`str_starts_with()`, `str_contains()`, `str_ends_with()`).
  - Zero deprecated functions:
    - No `utf8_encode()` / `utf8_decode()`.
    - Explicit null checks before calling string or array functions to avoid PHP 8.1 deprecation notices (`Passing null to parameter #1 of type string is deprecated`).
    - No dynamic property creation without `#[AllowDynamicProperties]`.

- [x] **1.5 Security, Roles & Capability Architecture**

  - Restrict all plugin endpoints and admin screens to users with `manage_woocommerce` capability.
  - Implement strict CSRF protection via WordPress nonces across all forms and AJAX/REST actions.
  - Implement full input sanitization (`sanitize_text_field()`, `sanitize_key()`, `esc_url_raw()`) and output escaping (`esc_html()`, `esc_attr()`, `esc_url()`).
  - Create `uninstall.php` to handle clean removal of settings and temporary caches if uninstalled.

---

## Phase 2: Cloudflare R2 Authentication & S3 API Engine

### Objective

Build a lightweight, reliable, high-performance Cloudflare R2 API client that communicates with R2 via its S3-compatible API using native WordPress HTTP infrastructure (`wp_remote_request`).

### Subphases & Tasks

- [x] **2.1 Cloudflare R2 Credential Architecture**

  - Design secure credential storage under WordPress options `idta_r2_settings`:
    - `account_id`: Cloudflare 32-character Account ID.
    - `access_key_id`: Cloudflare R2 Token Access Key ID.
    - `secret_access_key`: Cloudflare R2 Secret Access Key (masked in UI).
    - `default_bucket`: Default active bucket name.
    - `custom_endpoint`: Optional custom S3 endpoint (defaults to `https://{account_id}.r2.cloudflarestorage.com`).
  - Support `wp-config.php` constant overrides (`IDTA_R2_ACCOUNT_ID`, `IDTA_R2_ACCESS_KEY`, `IDTA_R2_SECRET_KEY`) for enterprise security.

- [x] **2.2 Native AWS Signature v4 (SigV4) Signer**

  - Implement `includes/class-r2-signer.php`:
    - Generate HMAC-SHA256 SigV4 authentication headers without requiring the massive AWS SDK for PHP.
    - Handles Canonical Requests, String-to-Sign, Derived Signing Keys, and Authorization headers.
    - Region set to `auto` (as required by Cloudflare R2).
    - Service set to `s3`.

- [x] **2.3 S3-Compatible R2 Client (**`includes/class-r2-client.php`**)**

  - Implement core REST methods utilizing `wp_remote_request()`:
    - `list_buckets()`: Fetches all buckets in the account (`GET /`).
    - `list_objects( string $bucket, string $prefix, string $delimiter = '/', ?string $continuation_token = null )`: Fetches prefixes and objects.
    - `get_object_metadata( string $bucket, string $key )`: Fetches headers (size, MIME type, ETag, last modified).
    - `delete_object( string $bucket, string $key )`: Deletes a single key.
    - `delete_objects_batch( string $bucket, array $keys )`: Multi-object XML batch deletion (`POST /?delete`).
    - `copy_object( string $bucket, string $source_key, string $dest_key )`: Copies/renames objects.

- [x] **2.4 Connection Health & Verification Service**

  - Implement `test_connection()` method to validate credentials against the R2 endpoint.
  - Return clear, actionable error messages (e.g., Invalid credentials, Account ID mismatch, Network timeout).
  - Admin AJAX endpoint `idta_r2_test_connection` for immediate real-time feedback on the settings screen.

- [x] **2.5 Bucket Enumeration & Inspection**

  - Retrieve the full list of available buckets in the authenticated Cloudflare account.
  - Parse XML response into structured DTOs (`Bucket_Record`: name, creation date).
  - Populate bucket selector dropdowns in the administrative UI.

---

## Phase 3: IDTA PDF Integration & Source-to-Bucket Mapping

### Objective

Integrate with the existing `idta-pdf` plugin (`Order_Data::sources()`) to automatically resolve which Cloudflare R2 bucket corresponds to each order source.

### Subphases & Tasks

- [x] **3.1 IDTA PDF Source Detector (**`includes/class-source-mapper.php`**)**

  - Detect if `idta-pdf` is active.
  - Read configured upload sources via `\IDTA\PDF\Order_Data::sources()`:
    - Example: `'idta' => 'https://idta-upload.shamim66ewu.workers.dev/files/'`
    - Example: `'idpa' => 'https://pub-1c2e77688359483ca692ff2d8369b41b.r2.dev/'`
  - Fallback to reading the database option `idta_pdf_verification` if called outside the active hook lifecycle.

- [x] **3.2 Automatic & Manual Bucket Resolver**

  - Match public domain patterns to R2 bucket names:
    - Parse `.r2.dev` public bucket URLs.
    - Parse Cloudflare Worker proxy URLs (`workers.dev`) to extract associated bucket names.
  - Provide an administrative mapping configuration interface:
    - `idta` source ➔ Select R2 Bucket (e.g. `idta-uploads`).
    - `idpa` source ➔ Select R2 Bucket (e.g. `idpa-uploads`).
    - Unmatched sources ➔ Default Fallback Bucket.

- [x] **3.3 Asset Naming Specifications**

  - Register standard file keys and expected filenames per order folder (aligned with `\IDTA\PDF\Order_Data::ASSET_FILES`):
    - `portrait.jpg` (Passport Photo)
    - `license-front.jpg` (Driver Licence Front)
    - `license-back.jpg` (Driver Licence Back)
    - `signature.png` (Signature Image)
  - Allow extensibility via filter `idta_r2_expected_asset_files`.

---

## Phase 4: Folder Scanner, Indexing & Connection Analyzer

### Objective

Scan R2 buckets for dated asset folders, cross-reference them against WooCommerce order records in MySQL (HPOS and legacy postmeta), and classify connection health.

### Subphases & Tasks

- [x] **4.1 WooCommerce Order Asset Indexer (**`includes/class-order-indexer.php`**)**

  - Query all orders having `_idp_assets` meta populated.
  - Support both database storage engines seamlessly:
    - **HPOS Table**: `wp_wc_orders_meta` where `meta_key = '_idp_assets'`.
    - **Legacy Table**: `wp_postmeta` where `meta_key = '_idp_assets'`.
  - Fetch key cross-reference fields:
    - Order ID
    - Order Status (`wc-completed`, `wc-processing`, `wc-pending`, etc.)
    - Date Created
    - Customer Name & Billing Email
    - Order Source (`_idp_order_from`)
    - Asset Folder Path (`_idp_assets`, normalized without leading/trailing slashes, e.g. `2026/08/01/063701-213`).
  - Cache indexed lookup map in transient / memory for rapid matching during scans.

- [x] **4.2 Cloudflare R2 Folder Traversal Engine (**`includes/class-folder-scanner.php`**)**

  - Use `ListObjectsV2` with `delimiter = '/'` to discover top-level year prefixes (`2026/`, `2025/`).
  - Step through hierarchical date paths (`YYYY/MM/DD/`) to identify unique document folder prefixes (`YYYY/MM/DD/HHMMSS-XXX/`).
  - Read object keys inside each folder to identify uploaded assets and total byte sizes.
  - Implement pagination with `continuation_token` to handle buckets containing 50,000+ files without timeouts.

- [x] **4.3 Connection Analysis & Classification Logic**

  - Compare R2 folder paths against the WooCommerce Order Index:
    - **Green \[CONNECTED\]**:
      - R2 folder matches an active order's `_idp_assets`.
      - Contains metadata: Linked Order ID, Customer Name, Status, Date.
    - **Red \[ORPHANED\]**:
      - R2 folder exists with files, but no WooCommerce order has matching `_idp_assets`.
      - Typical cause: abandoned checkout, failed upload, test transaction, deleted order.
    - **Amber \[BROKEN / PARTIAL\]**:
      - Order references the folder, but one or more of the 4 standard files (`portrait.jpg`, `license-front.jpg`, etc.) are missing in R2.
  - Calculate folder metrics: Total files count, folder size in KB/MB, oldest file timestamp, latest file timestamp.

- [x] **4.4 Asynchronous Batch Processing & Action Scheduler Integration**

  - For large buckets, implement chunked scanning via WooCommerce Action Scheduler or WordPress Transients:
    - Scan in batches of 100 folders per step.
    - Store progress in transient table `idta_r2_scan_progress`.
    - Provide a real-time progress bar on the admin dashboard via AJAX polling.

---

## Phase 5: Administrative Dashboard & Interactive Folder Explorer

### Objective

Create a clean, intuitive, modern WordPress administrative dashboard with overview metrics, interactive filterable tables, and visual status indicators.

### Subphases & Tasks

- [ ] **5.1 Admin Menu & Screen Registration (**`includes/class-admin-page.php`**)**

  - Register top-level / sub-menu page under WooCommerce: `WooCommerce → R2 Optimizer` (slug: `idta-r2-optimizer`).
  - Enqueue dedicated, scoped styles (`assets/css/admin.css`) and JavaScript (`assets/js/admin.js`).
  - Localize script with REST/AJAX nonces, API endpoints, and i18n text strings.

- [ ] **5.2 Storage & Connection Metric Cards**

  - Display summary KPI cards at the top of the screen:
    - **Total Buckets**: Number of R2 buckets detected.
    - **Total Folders Scanned**: Total folder count in active bucket.
    - **Connected Folders**: <span style="color: rgb(22, 163, 74);">**Green count**</span> & storage volume.
    - **Orphaned Folders**: <span style="color: rgb(220, 38, 38);">**Red count**</span> & reclaimable storage (MB/GB).
    - **Broken Orders**: <span style="color: rgb(217, 119, 6);">**Amber count**</span> requiring attention.
    - **Estimated Monthly Savings**: Calculation of storage cost reclaimed upon deletion.

- [ ] **5.3 Interactive Folder Explorer Table**

  - Custom table with server-side pagination and sorting:
    - **Status Column**: Clear visual pill badge (Green `Connected`, Red `Orphaned`, Amber `Incomplete`).
    - **Folder Path**: Monospace path (e.g. `2026/08/01/063701-213/`).
    - **Linked Order**: Order link `#12345 — John Doe` (or `— None —` for orphaned).
    - **Files Count**: Number of files detected (e.g. `4 files`).
    - **Total Size**: Formatted size (e.g. `1.84 MB`).
    - **Last Modified**: Human-readable date and time.
    - **Actions**: Inspect, Remap / Link, Delete.
  - **Quick Filters**:
    - Filter tabs: `All`, `Connected (Green)`, `Orphaned (Red)`, `Incomplete (Amber)`.
    - Date range filter: Year / Month dropdowns.
    - Search box: Filter by folder path, Order ID, or customer name.

- [ ] **5.4 Folder Inspection Drawer / Modal**

  - Clicking "Inspect" opens a modal displaying:
    - Image previews for all files in the folder (`portrait.jpg`, `license-front.jpg`, `license-back.jpg`, `signature.png`).
    - Direct signed R2 download link or public URL preview.
    - Detailed metadata table (File name, size, ETag, MIME type).
    - Associated WooCommerce order summary card (if connected).

---

## Phase 6: Data Manipulation & Storage Optimization Suite

### Objective

Provide comprehensive, secure data manipulation features allowing shop managers to delete orphaned files, clean storage, and re-link/update order data.

### Subphases & Tasks

- [ ] **6.1 Single Folder Deletion (**`delete_folder`**)**

  - Action to delete all objects inside an orphaned folder prefix.
  - Guardrail: Hard block on deleting <span style="color: rgb(22, 163, 74);">**Green (Connected)**</span> folders unless an explicit admin override flag is checked.
  - Instant removal from R2 using `delete_objects_batch()`.
  - AJAX response updates KPI cards and removes row without full page refresh.

- [ ] **6.2 Bulk Orphaned Cleanup Tool**

  - Bulk action: "Delete Selected Orphaned Folders" or "Delete All Orphaned Older than X Days".
  - **Dry Run Mode**:
    - Preview the list of folders to be deleted and total MB to be reclaimed before executing.
  - **Safety Check**: Verify that zero connected orders are in the deletion queue.
  - Chunked execution to prevent PHP execution timeouts on bulk deletions.

- [ ] **6.3 Order Data Remapping & Update Tool (**`remap_folder`**)**

  - Allows an administrator to connect an orphaned folder to a WooCommerce order:
    - Enter Order ID to associate.
    - Updates WooCommerce order meta: `_idp_assets` set to the folder path.
    - Triggers cache invalidation so the folder immediately turns <span style="color: rgb(22, 163, 74);">**Green**</span>.
  - Allows modifying or correcting the `_idp_assets` path on existing orders directly from the UI.
  - Option to move / rename folder path in R2 if customer data needs reorganization.

- [ ] **6.4 Audit Trail & Safety Logging (**`includes/class-audit-logger.php`**)**

  - Log all deletion, remapping, and configuration actions to database table `wp_idta_r2_logs`:
    - Timestamp
    - User ID & Display Name
    - Action Type (`DELETE_ORPHAN`, `FORCE_DELETE`, `REMAP_ORDER`, `BATCH_CLEANUP`)
    - Folder Path & Bucket
    - Affected Order ID
    - Result / Status
  - Export audit log to CSV for compliance and record-keeping.

---

## Phase 7: Automation, Scheduled Tasks & WP-CLI Integration

### Objective

Enable hands-free background maintenance and automated retention policies, accompanied by a full suite of WP-CLI commands for terminal automation.

### Subphases & Tasks

- [ ] **7.1 Scheduled Background Audits**

  - Daily or weekly Action Scheduler task to re-index and scan buckets.
  - Cache results in WordPress transients for instant dashboard load times.
  - Email notification summary to store administrator if orphaned storage exceeds a configurable threshold (e.g. &gt; 5 GB).

- [ ] **7.2 Automated Orphan Retention Policy**

  - Optional setting: "Automatically purge orphaned folders older than \[ 30 / 60 / 90 \] days".
  - Ensures abandoned checkout uploads do not accumulate indefinitely.
  - Strict exclusion for any connected order folders regardless of age.

- [ ] **7.3 WP-CLI Command Suite (**`includes/class-cli-commands.php`**)**

  - Implement `wp r2-optimizer` commands:
    - `wp r2-optimizer test`: Test R2 connectivity.
    - `wp r2-optimizer list-buckets`: List all available R2 buckets.
    - `wp r2-optimizer scan [--bucket=<name>]`: Run full folder scan and report stats.
    - `wp r2-optimizer clean [--dry-run] [--days=<n>]`: Clean orphaned folders via CLI.
    - `wp r2-optimizer remap --folder=<path> --order-id=<id>`: Link folder to order.

---

## Phase 8: Quality Assurance, Security Audit & Build Packaging

### Objective

Execute rigorous automated and manual validation to ensure zero PHP deprecations, complete WordPress/WooCommerce compliance, high reliability, and streamlined distribution.

### Subphases & Tasks

- [ ] **8.1 PHPStan & Static Analysis**

  - Run PHPStan at Level 8 / Level Max.
  - Verify 100% type coverage, absence of deprecated function calls, and strict null safety.

- [ ] **8.2 WPCS & Code Standards Linting**

  - Run `phpcs` with `WordPress-Core`, `WordPress-Docs`, and `WooCommerce-Core` rulesets.
  - Verify complete escaping, sanitization, nonce checks, and database query formatting.

- [ ] **8.3 Unit & Mock Integration Tests**

  - Test SigV4 signing against AWS canonical test vectors.
  - Test R2 XML parsing (Bucket listing, Object listing, Batch delete responses).
  - Test HPOS and legacy postmeta query accuracy.

- [ ] **8.4 Automated Plugin Build Script (**`bin/build-plugin-zip.sh`**)**

  - Create standard distribution build script:
    - Cleans dev artifacts (`tests/`, `.git/`, `.github/`, `node_modules/`).
    - Validates plugin headers and PHP syntax across all files.
    - Packages clean, production-ready zip archive `idta-r2-optimizer-v1.0.0.zip`.

---

## Phase-by-Phase Implementation Schedule

```
Phase 1: Architecture, WordPress Standards & Foundation (Days 1–2)
   └── Plugin Scaffold ➔ HPOS Compatibility ➔ Autoloader ➔ PHP 8.1+ Strict Setup

Phase 2: Cloudflare R2 Authentication & S3 API Engine (Days 3–4)
   └── SigV4 Signer ➔ R2 S3 Client ➔ Health Check ➔ Bucket Discovery

Phase 3: IDTA PDF Integration & Source-to-Bucket Mapping (Day 5)
   └── Order_Data::sources() Resolver ➔ Domain Matcher ➔ Asset Specs

Phase 4: Folder Scanner, Indexing & Connection Analyzer (Days 6–8)
   └── HPOS Order Indexer ➔ R2 Prefix Traverser ➔ Green/Red Analyzer ➔ Cache

Phase 5: Administrative Dashboard & Interactive Folder Explorer (Days 9–11)
   └── KPI Metrics ➔ Interactive Filterable Table ➔ Image Inspector Modal

Phase 6: Data Manipulation & Storage Optimization Suite (Days 12–14)
   └── Single & Batch Deletion ➔ Order Remapping ➔ Guardrails ➔ Audit Log

Phase 7: Automation, Scheduled Tasks & WP-CLI Integration (Days 15–16)
   └── Action Scheduler Jobs ➔ Retention Policies ➔ WP-CLI Suite

Phase 8: Quality Assurance, Security Audit & Build Packaging (Days 17–18)
   └── PHPStan Max ➔ WPCS Lint ➔ End-to-End Testing ➔ Production Zip Build
```

---

## File & Directory Structure

```
idta-r2-optimizer/
├── idta-r2-optimizer.php          # Main plugin bootstrap & HPOS declaration
├── uninstall.php                  # Safe cleanup on uninstallation
├── bin/
│   └── build-plugin-zip.sh        # Production zip packager
├── assets/
│   ├── css/
│   │   ├── admin.css              # Dashboard, KPI cards, table & badge styling
│   │   └── admin.min.css
│   └── js/
│       ├── admin.js               # Interactive table, AJAX scanner, modals
│       └── admin.min.js
├── includes/
│   ├── class-autoloader.php       # PSR-4 dynamic class autoloader
│   ├── class-plugin.php           # Central singleton service container
│   ├── class-r2-signer.php        # Native AWS SigV4 HMAC-SHA256 signature generator
│   ├── class-r2-client.php        # S3 REST API client (List, Get, Delete, Batch)
│   ├── class-source-mapper.php    # IDTA PDF upload source & bucket resolver
│   ├── class-order-indexer.php    # WooCommerce HPOS & postmeta asset lookup engine
│   ├── class-folder-scanner.php   # R2 folder prefix traversal & batch engine
│   ├── class-connection-analyzer.php # Green (Connected) vs Red (Orphaned) classifier
│   ├── class-admin-page.php       # Admin dashboard controller & views
│   ├── class-audit-logger.php     # Deletion & manipulation audit logger
│   └── class-cli-commands.php     # WP-CLI terminal command suite
└── templates/
    └── admin/
        ├── dashboard.php          # Main view (KPIs, table, modals)
        ├── settings.php           # Cloudflare R2 credentials & bucket mapping
        └── modal-inspect.php      # Image preview & document inspection drawer
```