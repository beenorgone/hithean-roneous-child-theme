# Order board loading and performance verification

The first board request loads only 10 orders from the requested tab (URL tab,
then remembered tab, then first nonempty tab). It does not preload other tabs
or automatically fetch another page. Load more requests up to 20 further orders
using the number of rows already shown as the offset.

## Date ranges

The shared **Ngày tạo** selector applies to both search and the board. It defaults
to **Mặc định: 30 / 90 ngày**: 30 rolling days from order creation (UTC), except
return/preorder steps use 90 days. General search also recognizes the pre-order
status. Select 7, 30, 90 days, or all time to override this default; explicit
selections apply equally to every tab. The proof tab retains its existing
60-day business limit, and all board ranges retain the existing age lock and
operational exclusions. All-time search is not subject to the board age lock.

In default mode, exact ID/display-number candidates can match older orders;
other free-text matches remain date-limited. Explicit ranges apply to IDs too.
The selected range is included in requests and server cache keys. Changing it
clears browser caches, cancels obsolete requests, and restarts the visible board
and search from page one. The URL parameter `thean_range` preserves explicit
selections for shared links; a fresh URL without it uses the default.

Date restrictions are applied to each search source before its candidate LIMIT.
Search result chips and the board status show the effective range. Empty search
results offer **Tìm trong toàn bộ thời gian** to expand the shared range.

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

## Incremental updates

Successful order mutations emit `thean:order-updated` with `orderId` or
`orderIds`. The board requests `thean_order_usearch_rows` for those IDs only
(up to 50 per batch). It reuses freshly loaded order objects/flows, checks tab
membership using the selected date range and existing business exclusions, and
renders only those orders. Tab counts are refreshed with metadata aggregate
queries; other order objects/rows are not reloaded.

The client replaces a row in place if it remains in the same tab, removes it
from old tabs, and appends it to cached destination tabs. The proof tab may
coexist with the main workflow tab. Recent patches are also merged into the
first response for a destination that was not yet opened. Existing unrelated
row nodes, selections, and scroll position remain intact. Search results only
patch existing affected rows, removing those that no longer match the query.
An affected open preview can fetch just that order's detail again.

Load more uses a descending order-ID cursor. Displayed row count is separate
from the scan cursor; adding an older order or removing a row cannot shift
subsequent pages. Appended orders are deduplicated when later pagination reaches
them, and visited tab caches retain the accumulated rows/cursor.

Pending list responses wait for row synchronization and merge recent patches
before rendering. Date-range changes reject obsolete patch generations. Row
synchronization errors show a retry that repeats only the row read, never the
mutation or a full-tab load. Manual Refresh and range changes can still reload
a tab explicitly.

The quick-action payment, customer-confirmation, export, return, flow, VAT,
and bulk integrations emit IDs only after a successful mutation response.
Local tests mock these writes; no live financial/order action is used for tests.

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
node tests/order-universal-search-row-updates.cjs
node tests/order-admin-update-events.cjs
python3 tests/order-universal-search-ranges.py
php tests/order-hpos-compat.php
php -l custom-functions/woocommerce/orders/order-universal-search.php
php -l custom-functions/woocommerce/orders/order-ops-step.php
node --check custom-functions/woocommerce/orders/assets/order-universal-search.js
git diff --check
```

The DOM regression suite requires `jsdom` on the Node module search path.

These are helper/SQL, SQLite date-boundary fixtures, and controlled network/DOM tests, not a running WordPress,
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
5. Change ranges while requests are pending, check shared URLs, and verify
   30/90-day boundaries for ordinary, return/preorder, and proof tabs.
6. Verify an order moved by another session appears in the correct tab after
   Refresh; check proof-overlap orders, internal/old-order exclusions, stale-step
   repair, empty tabs, and roles with different action permissions. Check that
   existing search commands and bulk actions still behave as before.

If page startup already exceeds the latency budget, profile WordPress/plugin
bootstrap before changing search semantics or adding database indexes. If search
SQL dominates, collect the actual queries and EXPLAIN plans on staging; the
substring-matching contract is unchanged by this optimization.

When a deployed page has no **Ngày tạo** selector, compare its authenticated
HTML and served JS/CSS with the deployed commit/active theme. The JS should
contain `currentRange` and the CSS should contain `.thean-ous-bar__range`.
Check an asset URL with a fresh version parameter before attributing this to
browser cache. A matching Git remote alone does not prove server deployment.
