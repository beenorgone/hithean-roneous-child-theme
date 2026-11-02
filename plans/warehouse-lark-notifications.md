# Warehouse Lark delivery

- Send an AI mismatch alert immediately in the background, regardless of export confirmation.
- Collect export confirmations in a fixed 30-minute window opened by the first confirmation; send one summary per window.
- Keep alert deduplication separate from confirmation delivery. Preserve the alerts-only filter.
- Validate webhook and Lark application responses, retain failure status, and limit retries.
- Verify timing, concurrent enrollment locks, filters, deduplication and delivery failures with a standalone PHP harness. Live delivery requires the complete webhook and a running WP-Cron.
