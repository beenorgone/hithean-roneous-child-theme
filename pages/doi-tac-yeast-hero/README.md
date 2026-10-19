# Yeast Hero B2B landing page

The WordPress **Landing Page** template reads `doi-tac-yeast-hero.html` from this directory when the page slug is `doi-tac-yeast-hero`. The theme loads the shared An New Chapter CSS/JS plus the scoped CSS/JS in this directory for that slug.

## Activate on hithean.com

1. Deploy the theme files.
2. Create a WordPress page with slug `doi-tac-yeast-hero`, title `Hợp tác phát triển ngành hàng protein cùng Yeast Hero`, and template **Landing Page**. Keep it as a draft for content review, then publish when approved.
3. Confirm the four WooCommerce products still use IDs `3977` (Original), `7696` (Avocado Matcha), `4690` (Overnight), and `8548` (Midnight Silk). The product switcher pulls their current image, name, nutrition label, and permalink from WooCommerce.
4. Check the embedded IVAR signup form and a real authorized test lead. Its source is the existing allowlisted `src=hithean`; this form has no structured field for partnership model, so the visitor is asked to put the model in the note field. Do not assume a CTA click alone captures that intent.
5. Review product wording, documentation availability, and partnership terms with the content and sales owners. Set the SEO title/description in the site's SEO plugin. Clear page/CDN cache and check the published route on desktop and mobile.

For a local preview, run `python3 pages/doi-tac-yeast-hero/build-preview.py`, then serve the repository root and open `/pages/doi-tac-yeast-hero/preview.html`. The builder uses the public B2B slide when available; it requires `beautifulsoup4` and falls back to a simple four-product preview if the site is unavailable. The form is a labeled, non-submitting mockup. On WordPress, `[anc_product_switcher]` and `[company_info]` render at runtime and the real IVAR form embeds on hithean.com.

Each `[anc_product_card]` also loads that product's QC test and legal-profile widgets from the validated IVAR embed fields and existing server-side cache. Both options default to `auto`; use `qc_widget="off"` or `legal_widget="off"` to suppress one, or `qc_widget="shortcode:registered_tag"` / `legal_widget="shortcode:registered_tag"` to render a registered shortcode instead. Override shortcodes receive `product_id` and `sku`. The site-wide **ERP > WooCommerce > Proof embeds** visibility setting still controls who can see them (its default is admin only). The static preview does not execute these WordPress widgets.
