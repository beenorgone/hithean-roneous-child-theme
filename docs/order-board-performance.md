# Order board loading and performance verification

The first board request loads only 10 orders from the requested tab (URL tab,
then remembered tab, then first nonempty tab). It does not preload other tabs
or automatically fetch another page. Load more requests up to 20 further orders
using the number of rows already shown as the offset.

## Data and cache behavior

- Board counts and first-page IDs use a 10-second transient. Empty results are
  cached too. Later offsets are queried directly.
- Keys include storage mode through the board SQL, filter configuration, workflow
  version, WooCommerce order-cache version, and a board revision. Dates in the key
  are rounded to the minute; actual SQL uses the precise cutoff. Results can be
  stale for at most the remaining 10-second TTL without an invalidation hook.
- Order saves, watched metadata changes, changed stored workflow steps, explicit
  Refresh, and Clear cache invalidate the revision. Writes that bypass all hooks
  are bounded by the TTL and the existing reconciliation sweep.
- Only data is cached here. Row HTML, action controls, and permission checks are
  generated for the current user. No customer data is persisted in browser storage.
- Displayed orders still have their stored step checked/repaired. Their loaded
  objects and computed workflow results are reused during row rendering.
- The browser retains visited tabs for 10 seconds. Changing tabs aborts prior work;
  late responses cannot replace the current tab or reset its loading state.

## Background processing

Opening the board schedules `thean_ops_step_backfill` without scanning orders.
A worker processes up to 20 unsynced orders and schedules another batch after
10 seconds if more remain. Scheduling checks are throttled for 60 seconds;
failed scheduling is retryable. The existing 50-order / 10-minute reconciliation
sweep remains in place, as do save/metadata hooks and the manual Clear cache action.

Workers require functioning WP-Cron. If `DISABLE_WP_CRON` is enabled, an existing
server scheduler must invoke WordPress cron. Merely scheduling an event does not
prove it ran. After deploying, verify the event drains a known unsynced order and
that its next batch is scheduled when a backlog exists. Refresh the board after
the worker finishes to see newly classified orders.

## Local checks

```sh
php tests/order-universal-search.php
node tests/order-universal-search-board.cjs
php tests/order-hpos-compat.php
php -l custom-functions/woocommerce/orders/order-universal-search.php
php -l custom-functions/woocommerce/orders/order-ops-step.php
node --check custom-functions/woocommerce/orders/assets/order-universal-search.js
git diff --check
```

These are helper/SQL and controlled network/DOM tests, not a running WordPress,
real-browser, database query-plan, cron execution, or server-latency benchmark.

## Live acceptance measurements

Target: at least 95% of page navigations show actionable first-page orders within
2,000 ms. Measure cold and warm caches separately; record server load, user roles,
order volume, browser/network conditions, and concurrency. Use a populated tab
and ensure the native Woo list is off (`thean_wc_list` absent). Opening an empty
tab intentionally does not produce the first-orders-visible measure.

1. In the existing authenticated browser, open DevTools Network and reload the
   order list. Verify one initial `thean_order_usearch_board` request with
   `offset=0`, `limit=10`, and no `all=1`. There should be no automatic second page.
2. After rows appear, inspect this in the Console:

   ```js
   ({
     firstOrdersVisibleMs: performance.getEntriesByName('thean-board-first-visible')[0]?.duration,
     pageTtfbMs: performance.getEntriesByType('navigation')[0]?.responseStart
   })
   ```

   `firstOrdersVisibleMs` starts at navigation time zero and ends after two
   animation frames following insertion of the first populated page. It includes
   page startup, request time, and rendering; it is a browser paint proxy.
3. Inspect the AJAX response's `Server-Timing` header: `board_query`, `board_sync`,
   `board_render`, and `board_total`. These begin inside the authenticated handler;
   they exclude WordPress bootstrap, network, response transfer, and shutdown work.
   Search responses expose `search_query`, `search_render`, and `search_total`.
4. Gather at least 30 samples per cold/warm scenario and compare the 95th
   percentile. Test a large populated tab, another tab, Load more, explicit
   Refresh, and concurrent staff sessions. Do not use Clear cache to simulate
   a cold cache: it also performs a workflow rebuild. Use an isolated staging
   cache or wait for expiry without changing orders.
5. Verify an order moved by another session appears in the correct tab after
   Refresh; check proof-overlap orders, internal/old-order exclusions, stale-step
   repair, empty tabs, and roles with different action permissions. Check that
   existing search commands and bulk actions still behave as before.

If page startup already exceeds the latency budget, profile WordPress/plugin
bootstrap before changing search semantics or adding database indexes. If search
SQL dominates, collect the actual queries and EXPLAIN plans on staging; the
substring-matching contract is unchanged by this optimization.
