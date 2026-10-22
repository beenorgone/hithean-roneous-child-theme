# Product offers index and /uu-dai/

Approved scope: public Hithean product pages only, retail customers, rule-based extraction plus the existing theme AI provider, controlled automatic publication, manual admin and WP-CLI entry points. No discount rules or prices are created or changed by this index.

## Components

- `custom-functions/marketing/offers.php` registers a private offer CPT, daily scheduler, resumable worker, shortcodes, admin and CLI modules. Settings and public snapshots are non-autoload options. Records and source decisions are stored in post meta.
- Product URLs come from published WooCommerce parent products. Public HTML is fetched without login/session cookies. Each redirect must retain the configured home origin; the WordPress safe HTTP client also checks destinations. HTTP timeout: 20 seconds; maximum HTML: 2 MiB; two retries at 60/120 seconds. Requests are sequential, in batches of up to ten with a twenty-second budget checked between products. A single AI call can extend a batch; a ten-minute worker lock and delayed watchdog protect continuation.
- Structured automatic gifts and unambiguous simple-product sale prices can be accepted automatically. Quantity tables, choices, role restrictions, variable prices, unstructured prose and incomplete dates require review. AI selects exact source evidence; every AI proposal remains review-first and keeps the whole source snippet.
- Full date ranges use Asia/Ho_Chi_Minh. Never infer a missing year. An explicit source offer without a deadline can remain active while freshly verified. Offers become stale after 48 hours without a successful read. A failed fetch does not constitute removal.
- The worker records its cursor after each product. One active run covers both cron and manual calls. A boundary event cannot expose an in-progress collection. A completed or stopped run commits a snapshot, keeping fresh data for any failed/unprocessed products.
- `/uu-dai/` renders `[hithean_offers]` through the existing Landing Page template. The snapshot is cached in the database; this page's HTML is deliberately excluded from full-page caching so expiry and freshness remain correct when cron is late. Client-side checks also hide offers that expire while the page stays open. Other pages retain their existing cache behavior.
- The lucky wheel stays manually accessible on `/uu-dai/` and no longer opens automatically there. Both source and minified wheel JavaScript include the change.

## Initial rollout

The code ships with automatic scans **off** and public snapshot visibility **off**. Manual collection is available before either switch is enabled.

1. Deploy the theme files using the normal deployment procedure. Confirm WooCommerce and PHP DOM are available in the WordPress PHP runtime. CLI PHP may have a different extension configuration.
2. Open **WooCommerce → Ưu đãi sản phẩm** and run **Quét ngay**. Inspect progress, warnings, source evidence, structural quantity constraints and the review board.
3. If `/uu-dai/` does not exist, use **Tạo trang nháp /uu-dai/**. This action creates a draft with `templates/template-landing-page.php`; it never replaces an existing page. For an existing page, back up its post content/template first, then choose Landing Page in the page editor. The file-based landing content comes from `pages/uu-dai/uu-dai.html`.
4. Review proposals, confirming retail eligibility, complete conditions, scope and dates. Confirm the first scan against current public product pages. A reviewer may set a verified end date or explicitly confirm that the source publishes no deadline.
5. Enable public snapshot visibility, publish the page, and inspect its actual desktop/mobile appearance with the real parent theme/plugins. Enable the 24-hour schedule when the server runner is confirmed.

Example server cron, with the real WordPress root supplied by the operator:

```cron
*/5 * * * * cd /absolute/path/to/wordpress && /usr/local/bin/wp cron event run --due-now --quiet
```

Use the existing server runner if one is present. Do not add a duplicate cron. The event interval is 24 hours from activation; a manual scan does not move that schedule. The five-minute runner bounds dispatch delay, while total scan time depends on catalog size, network and AI response time.

## Manual invocation and operations

```bash
wp hithean offers scan
wp hithean offers scan --product-id=123
wp hithean offers status
```

`scan` enqueues work and returns a run ID; it does not claim synchronous completion. `status` reports the actual cursor, result, last successful scan, warnings and next scheduled event. The normal WordPress cron runner processes queued work.

The admin screen provides Kanban/table views, per-product collection, stop-after-current-product, source evidence, approve/reject, pinning, progress polling and 30-day run history. Admin mutations require `manage_woocommerce` plus a nonce. Scan requests are throttled per user and globally coalesced into the active run. Public visitors cannot start scans or read internal logs.

A rejection or approval survives only unchanged source text and structural conditions. An offer removed then reintroduced receives a new decision. Public order-wide offers are deduplicated by exact text and list only source products where they were observed; the index does not claim they apply to the entire catalog. Product cards do not add or sum independent discount values.

Disable the daily switch to stop future scheduled collections. Use the stop button for the current run. Snapshot rollback is available after the run stops; a later scan/publication recalculates from stored offers. Expired/stale offers remain hidden even after rollback. Retain the page content/template backup for a complete page rollback. Theme switching clears this feature's scheduled hooks.

## Validation

Standalone regression harness (PHP DOM required):

```bash
php tests/offers-test.php
```

Covers real-shaped gift/shipping markup, date ambiguity, price/variation handling, source scoping, redirects, locks, reconciliation, decisions, stale/expired/future offers, public preview gating, queue coalescing, retries and snapshot commits. No production writes, HTTP or AI calls are made by the harness.

Implementation validation also includes PHP/JavaScript syntax checks, a read-only extraction against current Matcha product HTML, and browser checks on a clearly labeled local fixture preview. A local preview cannot establish production cron execution, live admin authorization, AI provider availability or appearance with all production assets; those must be confirmed after deployment.
