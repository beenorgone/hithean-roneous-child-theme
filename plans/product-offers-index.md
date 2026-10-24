# Product offers index and /uu-dai/

The index reads structured promotion definitions from Discount Rules, Product Addon, WooCommerce sale prices and the existing lucky wheel. It keeps the weekly/manual workflow, evidence review and Hithean landing page. Public HTML crawling is no longer the default collector.

## Shared source rules

- Each source rule has one private `hithean_offer` record and stable source identity (`wdr:<id>`, `addon:<product>:<choice>`, `sale:<product-or-variation>`, `wheel:<reward>`). Multiple indexed `_ho_product` associations link a shared rule to its eligible products.
- The admin Kanban/table shows only each rule title. Selecting a title opens a keyboard-accessible modal with the current state, configuration evidence, product list and existing review/restore actions. Escape, the close button or the backdrop dismisses the modal and returns focus to its title. Approve/reject/pin operates on that canonical record. Rejected cards can be restored to pending review with an audited action; restoration never publishes or refreshes source verification, and the pending decision survives an unchanged sync, including automatic sale sources. Removed source records cannot be restored this way. A single approval covers all matching products, including newly eligible products on a later sync.
- Approval is tied to the complete configuration hash. Changes to eligibility, rates, conditions, schedule, limits, priority or exclusivity require review again. Ordinary usage-counter increments do not invalidate approval; an exhausted limit withdraws the rule. Matching text never merges distinct source rule IDs.
- Source timestamps are authoritative. WDR stores wall-clock timestamps; the adapter converts them with the WordPress timezone before expiry comparisons. Reviewers cannot extend a plugin rule's schedule. Public labels retain exact cutoff times rather than assuming an end-of-day deadline.
- Restricted customer/role rules and unknown product filters are blocked from generic retail publication. Other conditional rules remain review-first, with editable public copy and the full source configuration visible only to authorized managers. No assigned/private customer coupon is collected or generated.

## Source adapters

- **Discount Rules:** read enabled, non-deleted definitions from its rules table, including future schedules. The frontend model omits future rules, so the adapter uses a read-only definition query and the actual plugin `Filter::matchFilters()` for product/category/tag/SKU/attribute/sale eligibility. Variation matches attach to their parent with a qualification when only some variations qualify. Cart/customer conditions are not simulated by altering sessions or carts. Bulk tiers, cumulative quantities, simple discounts, shipping thresholds, priorities and limits remain part of the evidence.
- **Product Addon:** reuse its enabled-state, parsed addon and display-choice functions. Grouped choices retain their complete quantity ranges and need explicit review. Free-text sale messages are separate review proposals. Independently configured per-product addons remain separate sources even when wording matches.
- **WooCommerce:** use stored sale/regular prices and schedules via `edit` context, avoiding a second application of dynamic price filters. Variation sales retain their own price and schedule. Clear sale prices can be accepted automatically.
- **Lucky wheel:** read enabled reward definitions only. Rewards are described as an opportunity to spin/claim a coupon, never as a guaranteed automatic benefit. All active rewards form one `wheel:program` source with the union of eligible products and a single review decision. Any reward configuration change invalidates that decision. The admin and landing page show one summary card, without enumerating prizes or repeating the wheel on each product; full configuration stays in the private source evidence. Legacy per-reward sources are withdrawn after a successful wheel sync; generated customer coupons and contact data are excluded.

## Worker, migration and publication

A new default database run has shared-rule tasks plus product tasks. Manual per-product sync refreshes that product's local sources and recomputes shared-rule catalog membership. The worker preserves its lock, cursor, retries, watchdog, stop control and single active queue. It never requests product HTML in database mode.

Source families and individual products are marked complete independently. Only a successful complete source read may withdraw an absent/disabled rule. Failed families preserve existing data; completed scopes reconcile safely. After a product's first successful database sync, its legacy frontend-derived records are marked removed without carrying unverified text-based decisions into the new rule identities.

Public snapshots are committed atomically after a run. Boundary events preserve the last committed snapshot while collection is in progress. A source rule projects onto all associated products, and each product card combines its approved rules. A Discount Rules quantity rule that applies to two or more published products is shown once as a group card instead of on each product card. The card is titled **Mua nhiều giảm giá**, followed by its category names when the rule filters by category. Its **Xem nhóm sản phẩm** button opens a modal that lists each product with a thumbnail, name, live price and stock status. Each row links to its product. Without JavaScript the button links to that category, falling back to the shop. It lists the eligible products, which are also searchable. Under the **Mua nhiều giảm giá** filter it moves ahead of the other cards; its position in **Tất cả** stays the same. Shared free-shipping rules are grouped the same way under **Miễn phí vận chuyển**. A rule whose filters are only `all_products` is shown as one shop-wide card. That card reads "Áp dụng cho mọi sản phẩm", has a **Mua sắm ngay** button to the shop and no product list. The lucky wheel never auto-opens on `/uu-dai/`, where its program card opens it on request. Client filters and expiry checks operate on each individual rule so one expired benefit does not hide other valid benefits on the same product. A 8-day freshness limit applies when sources cannot be verified.

The snapshot is cached in non-autoload options. `/uu-dai/` HTML bypasses full-page caching to enforce freshness/expiry even when cron is delayed. Theme changes clear the feature's scheduled hooks. Other site pages retain their cache behavior. CTA, cart and filter buttons use Hithean's square corners; decorative badges and card surfaces retain their own styling.

## Operations

Open **WooCommerce → Ưu đãi sản phẩm**, choose **Đồng bộ ngay**, then inspect and approve source rules. Each rule displays its product list and a **Duyệt rule cho N sản phẩm** action. Use the public-copy field to confirm complete customer-facing conditions without exposing internal titles or private codes.

```bash
wp hithean offers scan
wp hithean offers scan --product-id=123
wp hithean offers status
```

`scan` queues work and returns an actual run ID; it does not claim synchronous completion. The weekly schedule and public visibility are separate switches and remain off by default. Existing saved switches are preserved. An existing `/uu-dai/` page is never overwritten; use `templates/template-landing-page.php` and the existing `pages/uu-dai/uu-dai.html` shortcode source.

Use the existing WordPress server runner, or configure one with the real WordPress root:

```cron
*/5 * * * * cd /absolute/path/to/wordpress && /usr/local/bin/wp cron event run --due-now --quiet
```

The five-minute runner only dispatches due WordPress jobs; offer collection runs weekly when enabled or on manual request. Existing daily offer events migrate to the weekly hook. The 8-day freshness limit allows one weekly interval plus a one-day retry margin; explicit offer expiry remains immediate. Do not install a duplicate runner. A manual run does not move the weekly schedule. Disable the weekly switch for future jobs and use Stop for a current run. Snapshot rollback is available when no run is active; expired/stale offers remain hidden, and a later publication recalculates from source records. Run history is retained for 30 days, up to 100 entries.

## Validation

```bash
php tests/offers-structured-test.php
php tests/landing-template-test.php
php tests/offers-test.php # PHP DOM required for the retained HTML extractor regression tests
```

The structured harness checks one-rule/multiple-product approval, exclusions, new matching products, distinct source IDs, stale forms, source changes, private/unknown rules, dates, limits, failed-source retention, per-product grouping, database-only execution and button corners. The legacy extractor remains tested independently. The landing-template harness exercises the real file-based template to prevent literal shortcode output.

Before publishing real offers, verify the actual installed plugin's filter results and PHP compatibility, inspect a full database sync, and review real source evidence. Browser checks must cover mobile/desktop filtering, multiple rules on one product, independent expiry and square CTA corners. A local fixture preview does not establish live admin authentication, server cron execution or customer checkout discount outcomes.
