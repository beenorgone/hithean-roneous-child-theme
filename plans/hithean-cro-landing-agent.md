# CRO Landing Agent — production setup

The child theme includes a weekly CRO workspace at **WP Admin → CRO Landing**.
It is intentionally aggregate-only: no buyer details or individual order data are
stored in its report tables, sent to Lark, or passed to the optional AI rewrite.

## 1. GA4 access

1. Create a Google service account and grant its `client_email` **Viewer** access
   to the GA4 property.
2. Keep the downloaded JSON outside the web root, readable only by the WordPress
   PHP user, for example `/etc/hithean/ga4-cro-service-account.json`.
3. In `wp-config.php`, add:

   ```php
   define('HITHEAN_CRO_GA4_SERVICE_ACCOUNT_JSON', '/etc/hithean/ga4-cro-service-account.json');
   ```

4. In **CRO Landing → Cài đặt**, add the numeric GA4 Property ID, the allowed
   landing paths, and the Lark incoming-bot webhook.

GA4 must collect the standard ecommerce events `add_to_cart`, `begin_checkout`,
and `purchase`. The report calls GA4's `landingPagePlusQueryString` dimension;
`purchase` attribution is therefore GA4 session/landing attribution, not an
inference from WooCommerce referrer data.

## 2. Scheduling and first run

The feature schedules the report at 09:00 every Monday in the WordPress site
timezone. Production must invoke WP-Cron independently of visitor traffic, for
example every five minutes using the server's existing cron mechanism:

```sh
php /absolute/path/to/wordpress/wp-cron.php >/dev/null 2>&1
```

After saving configuration, use **Chạy lại tuần hoàn chỉnh gần nhất** once. Check
the GA4/WooCommerce reconciliation, the report's Lark status, and the Kanban
cards before relying on the next scheduled run.

## 3. Operating rules

- A landing needs 100 sessions before the agent creates conversion recommendations.
  Purchase-rate conclusions also need at least five purchases.
- The agent can create cards but never edits landing pages. Move work through
  `Mới → Đang xem → Đã duyệt → Đang làm → Đã triển khai → Đo kết quả`.
- Treat results after deployment as an observation, not proof of causality, until
  a comparable post-change measurement period has elapsed.
- Lark webhooks are restricted to HTTPS Lark/Feishu domains; service-account
  credentials never belong in the database or this repository.
