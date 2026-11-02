# Warehouse Lark delivery

- Send an AI mismatch alert immediately in the background, regardless of export confirmation.
- Collect export confirmations in a fixed 30-minute window opened by the first confirmation; send one summary per window.
- Keep alert deduplication separate from confirmation delivery. Preserve the alerts-only filter.
- Validate webhook and Lark application responses, retain failure status, and limit retries.
- Verify timing, concurrent enrollment locks, filters, deduplication and delivery failures with a standalone PHP harness. Live delivery requires the complete webhook and a running WP-Cron.

## Detailed alerts and follow-up

1. Add AI findings, warehouse staff names, order links and photo links to Lark cards. Webhook-only bots cannot upload images for inline previews.
2. Add a URL button that opens an authenticated confirmation form. Require staff capability, a fresh review identity and a POST nonce; record actor/time without confirming shipment or changing the AI verdict.
3. Remind every 30 minutes until AI has passed the current photo set. Acknowledgment alone does not stop reminders; bind new AI results to the photos actually reviewed.
4. Verify card safety/size, evidence freshness, reminder scheduling, acknowledgment authorization/idempotency and existing batching behavior.
