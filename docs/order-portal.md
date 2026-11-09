# Standalone order workspace

`/quan-ly-don/` provides the existing order board, search, quick actions, product
and export tools, plus a **Tạo đơn** tab. Theanmarket also retains its Shopee
workspace; Hithean retains its own metadata and action adapters.

## Loading and access

- The existing frontend module registry loads `order-portal.php` only for the
  exact normalized `/quan-ly-don` path. It has no AJAX or shortcode loader entry.
- `order-navigation.php` is required on WooCommerce order admin requests (before
  `admin_init`), lazily at `admin_menu` on other admin pages (menu links only), and
  by the portal itself. Frontend and AJAX requests load neither.
- The portal requires login and `edit_shop_orders` or `manage_woocommerce` before
  requiring any UI modules. Individual existing endpoints still check their own
  nonces, capabilities, order locks, payment and inventory rules.
- The document sends no-cache, noindex and same-origin framing headers. It renders
  an independent HTML shell and prints explicit assets/renderers without invoking
  `wp_head`, `wp_footer`, `admin_head` or `admin_footer`.
- The creator is the existing `/tao-don/?thean_order_embed=1` page, loaded only
  when its tab opens. That mode prints explicit dependencies plus the payment hub
  bridge, preserving the original form/configuration and server-side behavior.
  Its iframe stays mounted across tab switches. Nothing is saved by switching tabs.
- A successful creator save reports its order ID to the parent. The parent checks
  the exact iframe source, origin and positive integer ID before requesting the
  existing incremental row refresh. It never replays the create/save request.
- Opening directly with `?thean_workspace=create` defers the initial board read
  until the Orders tab is selected. `order_id` can open an existing order in the
  creator; the existing creator endpoint controls edit permissions.

## First board page and timings

The portal pre-renders the first board page of the tab the client will open
(`?thean_board`, else the `thean_ous_tab` cookie the board JS writes with its
localStorage tab) through `thean_ous_board_payload()`, the same function the
`thean_order_usearch_board` AJAX handler uses, and prints it as
`theanOusCfg.boardInitial`. The client uses it once, only when the requested tab
and range match; otherwise it does the usual AJAX read. `?thean_workspace=create`
and any server error skip pre-rendering. `print-pxk.php` is loaded so row actions
match the admin-ajax rows. Plugins that add order actions only under `is_admin()`
would be missing from pre-rendered rows until the next refresh.

The document is buffered and sends `Server-Timing` (`wp_boot`, `board_*`,
`portal_render`, `portal_total`); compare with the board AJAX `Server-Timing`.

## Navigation and fallback

Order list menu links are rewritten on every non-AJAX admin page: the theme loader
requires `order-navigation.php` at `admin_menu` (PHP_INT_MAX) and calls
`thean_order_portal_rewrite_menu()`. Order screens load it earlier for the redirect.
Existing HPOS/legacy list URLs redirect early on safe GET navigation. Board/date
and text-search parameters survive; native order statuses become `/tt` searches.
New-order and Phone Orders navigation opens the creator tab.

POSTs, AJAX, edit screens, nonce-bearing and bulk-action URLs remain in WooCommerce.
Unsupported native filters and trash/draft lists also retain the native list.
`thean_wc_list=1` explicitly bypasses redirection; the portal header links to it.
No database page or rewrite flush is required: routing uses the same path-driven
frontend module mechanism as the existing full-page tools.

## Validation and rollout

Run `php tests/order-portal.php`, the order search/workflow/HPOS/review suites, and
`node tests/order-workspace-tabs.cjs` with a current jsdom (26 was used locally).
Also run the board and incremental-row DOM tests, PHP lint and `git diff --check`.

Local DOM tests cover iframe lazy loading/preservation and rejected/accepted
cross-window messages. Local browser fixtures verify the shell layout; these
are not authenticated WordPress or live order/payment/shipping validation.

After deploying both themes, verify login/403, both legacy and HPOS navigation,
the Woo fallback, board reads and all popups on each site. Review Query Monitor
on the new document and its AJAX reads separately: the document no longer runs
the admin lifecycle, but the existing AJAX endpoints still use WordPress admin
bootstrap. Record actual server timings before claiming a production speedup.
