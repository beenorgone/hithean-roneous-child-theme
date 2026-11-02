# Product purchase experience

## Scope and source of truth

Improve the single-product area before `section-icons`, using the existing WooCommerce gallery, product links, proof widgets, forms and cart-preview flow. Do not replace product content or correct product weights without verifying the product record. No hardcoded discount percentages, quantities, free-shipping thresholds, expiry dates or product-specific prices.

WooCommerce supplies the effective base price, regular/sale markup, stock, purchase limits and selected variation. Flycart Discount Rules supplies eligible tiers, promotional messages and matched discount details. The theme only presents these results. Free-shipping messages are offers, not a claim that a shipping rate is free: destination, shipping method, cart and plugin conditions remain authoritative at checkout.

## Price state machine

1. Show WooCommerce's normal price/sale markup initially. Keep the original form available without JavaScript.
2. On a committed quantity or variation change, invalidate the previous quote immediately and debounce a read-only server request. A variation must be selected before quoting it. Cancel superseded requests and reject late responses.
3. Load the published requested product/variation server-side, enforce parent relationship, purchasability, stock and quantity limits, and obtain fresh WooCommerce base/sale data. Build the baseline with WooCommerce price getters/formatters: `get_price_html()` also runs the plugin's catalogue/quantity-one filters and cannot prove eligibility for the selected quantity. Keep this baseline separate from the matched quote for loading/error fallback.
4. Ask the installed plugin's `advanced_woo_discount_rules_get_product_discount_price_from_custom_price` filter for **all** matched discount details for this exact product and quantity, using the same calculation path as its `awdr_get_product_discount` endpoint. Do not read rules tables or guess eligibility from a title, percentage or tier price.
5. Use a rule price only when the plugin returns applied product-price rule details and valid numeric prices. No match, including an on-sale exclusion, keeps WooCommerce's own sale/regular price. A valid rule can calculate from regular price or sale price according to plugin configuration; do not take the minimum or stack an additional percentage in the theme.
6. Do not flatten cart-level adjustments, mixed-price/BOGO/set offers or product addons into a fabricated unit price/subtotal. Keep the native price and explain that the cart confirms those offers. API/network/schema errors show an unavailable quote, never a previously selected discount.
7. Show a clearly labelled **product subtotal**, not an order total or final shipping/tax/coupon promise. Revalidate on cart updates and page return. Do not persist personalized quotes in localStorage/transients/full-page cache.

## Sale cases and verification matrix

| WooCommerce state | Discount Rules outcome | Display |
| --- | --- | --- |
| Regular price only | No plugin / no matching rule | Native regular price and product subtotal |
| Active sale | No rule / sale product excluded | Native regular strikeout + sale, no extra discount |
| Active sale | Matching rule based on sale price | Plugin-computed price, no second calculation from sale |
| Active sale | Matching rule based on regular price | Plugin-computed price even if it differs from a theme-computed minimum |
| Scheduled future / expired sale | No matching rule | Current WooCommerce effective price; retain timing notice |
| Scheduled sale boundary | Matching rule | Fresh server calculation; no browser-clock discount decision |
| Variable product unselected/reset | Unknown | No numeric subtotal; prompt to select options |
| Selected variation | Match/no match specific to variation | Its own native sale or plugin result; never the first child's price |
| Rule is disabled, expired or targets a different product | No match | Native WooCommerce price |
| Coupon / role / cart conditions fail | No match | Native WooCommerce price; no eligibility inferred from a table |
| Rule is an order/cart adjustment or mixed-price offer | Deferred | Preserve native display; confirm full calculation in cart |
| Addon, grouped or external product | Unsupported product subtotal | Native purchase flow and plugin content remain usable |
| Sold individually, limited stock, fractional/minimum quantity | Invalid selection | Do not clamp and silently buy a different quantity; reject or follow native input validity |
| Slow request, quantity/variation changes, failed quote | Pending/unavailable | Discard stale response, no previous discount claim |

## Layout and interactions

- Desktop: constrained two-column hero; native gallery with one horizontal thumbnail row; title, effective price, short description, product links, plugin tiers, promotion and purchase form in decision order.
- Mobile: use CSS layout to place title/price/short description before the gallery while keeping their original `.summary` DOM ancestry for plugin selectors. Keep the native form visible as a fallback. A single mobile sticky purchase bar mirrors the native quantity and submits the native form so variation/addon validation runs.
- Turn only unambiguous integer plugin ranges into optional quantity-selection actions. Never parse money or discount percentages from their labels. Unsupported ranges keep their native table.
- Move the mobile content-details and Lucky Wheel controls into a normal-flow utility row. Disable Lucky Wheel automatic opening only on single products; keep manual opening and other page contexts.
- Reveal the desktop bottom navigator after the hero purchase area rather than covering the initial hero. Respect proof visibility/capability checks and existing dialogs.

## Deployment and rollback

Enqueue product-only CSS/JS with file modification versions. The new read-only action is loaded through the existing conditional AJAX loader, with nonce validation, published-product checks, bounded inputs and no-cache responses. No database migration or rule/settings changes.

Validate PHP/JS syntax, price/eligibility regression cases, native form fallback, mobile overlap and desktop layout. Browser replay of production HTML and plugin responses proves integration presentation only; it does not prove a running WordPress/plugin checkout. Before production sign-off, smoke-test real sale exclusions, regular-vs-sale calculation, variation changes and cart totals in the installed plugin environment. Do not create a paid order for verification.

After deployment purge affected product page/asset caches and test a guest session. Roll back the scoped source changes and purge caches; product prices and rule configuration are untouched.

## Validation recorded on 2026-11-02

- Read the installed plugin's public 2.6.16 JavaScript and the corresponding official WordPress.org PHP source. Live read-only requests confirmed the difference between the display endpoint (880,000 for quantities 1–3 in this configuration) and the matched-details endpoint (no rule for 1, 836,000 for 2, 792,000 for 3). This implementation uses the latter calculation filter server-side.
- `php tests/product-purchase-pricing.php`: 50 assertions, covering sale exclusions, eligible sale-price and regular-price rules, no double discount, native fallback separate from rule prices, selected variation scope, malformed/mixed/cart-level results, nonce, publication/password protection, stock and quantity limits.
- PHP lint and JavaScript syntax checks pass for the scoped files.
- Local browser replay of public production HTML, native WooCommerce gallery assets, the new CSS/JS and PHP-generated quote fixtures: validated sale exclusion, eligible regular-price rule, failed quotes, superseded requests, variation reset/selection events, one native sticky submission with the selected quantity, required-field validation, sold-individually controls, and JavaScript-disabled native form fallback.
- Stable viewport checks at 320, 360, 390, 768, 1366 and 1440px: no horizontal overflow; `section-icons` retains full viewport width. No application JavaScript exceptions in the final replay.
- These are local/replay checks, not production execution of the new PHP module. No WordPress/WP-CLI runtime is installed in this checkout. The existing coupon-lookup integration test requires `wp eval-file` and cannot run as a standalone PHP script here. Real rule configuration, tax/location/user conditions and cart reconciliation remain deployment smoke checks.

References: [Flycart display settings](https://docs.flycart.org/en/articles/4164050-display-settings), [on-sale exclusions](https://docs.flycart.org/en/articles/4328402-how-to-exclude-products-from-the-discount), [official plugin source, version 2.6.16](https://downloads.wordpress.org/plugin/woo-discount-rules.2.6.16.zip).
