# Save Plan And Optimize Hithean Product Taxonomy Performance

## Summary
- Work in `hithean-roneous-child-theme`, because the live URL loads `themes/roneous-child` assets.
- First save this plan to `code plans/hithean-product-taxonomy-performance-cro-plan.md`.
- Then optimize the product taxonomy page so products appear immediately and unnecessary frontend assets are not loaded on category pages.

## Implementation Changes
- Add the plan markdown file under `code plans/`.
- Scope global assets:
  - Update `custom-functions/core/swiper-slider.php` so Swiper only loads where sliders are used, not product taxonomy archives.
  - Update `custom-functions/marketing/popup-widget.php` so popup CSS/JS does not load on product taxonomy pages unless explicitly enabled.
  - Add/deploy catalog asset cleanup to dequeue `wc-cart-fragments` outside cart, checkout, account, and product pages.
- Improve product image rendering:
  - On shop/category/tag archives, set the first visible product thumbnail to `loading="eager"` and `fetchpriority="high"`.
  - Keep later product thumbnails lazy.
- Improve taxonomy CRO/UI:
  - Make category intro compact with product count and primary `Xem sản phẩm` action.
  - Make product cards more stable with fixed image aspect ratio.
  - Keep `Thêm vào giỏ` primary and `Xem chi tiết` secondary.

## Test Plan
- Run `php -l` on changed PHP files.
- Recheck the live/category HTML and browser rendering:
  - First product image should not wait for lazyload JS.
  - Product section should appear without layout jumps.
  - AJAX add-to-cart still works.
  - Cart/checkout/account pages still retain cart behavior.
  - Homepage/page sliders and intentional popup pages still load their assets.
- Compare asset list before/after to confirm fewer scripts on product taxonomy pages.

## Assumptions
- Implementation path is `/home/beenorgone/Documents/Drives/gDriveProjects/Working/Websites/hithean-roneous-child-theme`.
- Product taxonomy pages do not intentionally need Swiper or popup assets.
- Commit message to provide after implementation: `Optimize WooCommerce taxonomy product grid performance and CRO`
