<?php
/**
 * Order incidents: per-instance order meta, delivery adapter and server-side action policy.
 * GIỐNG HỆT theanmarket ↔ hithean; edit together and run tests/order-issues.php.
 * No new tables. Evidence holds order IDs/reasons, never customer contact copies.
 * Records live with their order; private order notes own the audit trail.
 * Always loaded by core/module-loader.php (AJAX, cron and saves need the same policy).
 */
if (!defined('ABSPATH')) exit;

const THEAN_ISSUE_PREFIX = '_thean_issue_';
const THEAN_ISSUE_SCAN_VERSION = '2';
const THEAN_ISSUE_CANDIDATE_LIMIT = 5000;

/** Register incident types; providers own detection, transitions and blocking policy. */
function thean_issue_types(): array
{
    return apply_filters('thean_order_issue_types', [
        'nearby_orders' => ['priority' => 40, 'label' => 'Khách đặt nhiều đơn gần nhau', 'mode' => 'review', 'detect' => 'thean_issue_detect_nearby', 'transitions' => ['start', 'ack'], 'help' => 'Liên hệ khách xác minh những đơn cần giữ, rồi xác nhận đã kiểm tra từng đơn.'],
        'order_overdue' => ['priority' => 20, 'label' => '7 ngày chưa giao xong', 'mode' => 'warn', 'detect' => 'thean_issue_detect_order_overdue', 'transitions' => ['start'], 'help' => 'Kiểm tra tiến độ, liên hệ khách / kho / đơn vị giao và cập nhật đơn. Tự kết thúc khi đơn giao xong hoặc không còn thuộc điều kiện cảnh báo.'],
        'ghtk_overdue' => ['priority' => 10, 'label' => '7 ngày GHTK chưa giao xong', 'mode' => 'warn', 'detect' => 'thean_issue_detect_ghtk_overdue', 'transitions' => ['start'], 'help' => 'Tra vận đơn GHTK và liên hệ đơn vị vận chuyển. Mốc tính là ngày bắt đầu giao, không phải ngày tạo vận đơn.'],
        'delivery' => ['priority' => 30, 'label' => 'Sự cố giao hàng', 'mode' => 'warn', 'provider' => 'thean_issue_delivery', 'transitions' => ['resolve'], 'help' => 'Liên hệ khách / đơn vị vận chuyển và ghi kết quả xử lý.'],
        'manual' => ['priority' => 50, 'label' => 'Sự cố ghi nhận thủ công', 'mode' => 'warn', 'transitions' => ['start', 'edit', 'resolve'], 'help' => 'Kiểm tra nội dung và ghi kết quả khi xử lý xong.'],
    ]);
}

/** Read a site-owned multi-value field, with explicit precedence and no ad-hoc key guessing. */
function thean_issue_field_values(WC_Order $order, string $field): array
{
    foreach (thean_issue_site_fields()[$field] as $key) {
        $out = [];
        foreach ($order->get_meta_data() as $meta) {
            $data = $meta->get_data();
            if ($data['key'] !== $key) continue;
            foreach ((array) $data['value'] as $value) if (is_scalar($value) && trim((string) $value) !== '') $out[] = trim((string) $value);
        }
        if ($out) return array_values(array_unique($out));
    }
    return [];
}

/** Delivery completion is distinct from a partial delivery; failed/accounting orders are out. */
function thean_issue_undelivered(WC_Order $order): bool
{
    return thean_issue_eligible($order) && !in_array($order->get_status(), thean_issue_closed_statuses(), true);
}

/** Parse recorded dispatch dates explicitly in the site timezone; absent/invalid dates stay unknown. */
function thean_issue_dispatch_at(WC_Order $order): int
{
    $values = thean_issue_field_values($order, 'dispatch');
    if (!$values) return 0;
    foreach (['!Y-m-d H:i:s', '!Y-m-d', '!d/m/Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $values[0], wp_timezone());
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) return $date->getTimestamp();
    }
    return 0;
}

/** Stable time-based evidence: the daily age display must not reopen an issue each day. */
function thean_issue_overdue_evidence(WC_Order $order, string $type, int $since, ?int $now = null): array
{
    if (!$since || ($now ?? time()) - $since <= 7 * DAY_IN_SECONDS) return [];
    return ['evidence' => [], 'since_at' => $since, 'fingerprint' => hash('sha256', wp_json_encode([$order->get_id(), $type, $since]))];
}

/** Order-created clock excludes preorders and every canonical shortage tag. */
function thean_issue_detect_order_overdue(WC_Order $order, array $candidates = [], ?int $now = null): array
{
    if (!thean_issue_undelivered($order) || $order->get_status() === 'pre-order'
        || array_intersect(thean_issue_field_values($order, 'handling'), array_keys(thean_issue_shortage_options()))) return [];
    return thean_issue_overdue_evidence($order, 'order_overdue', $order->get_date_created()->getTimestamp(), $now);
}

/** Carrier clock requires GHTK plus a recorded handoff date; never infer it from a label creation. */
function thean_issue_detect_ghtk_overdue(WC_Order $order, array $candidates = [], ?int $now = null): array
{
    if (!thean_issue_undelivered($order) || !in_array('ghtk', array_map('strtolower', thean_issue_field_values($order, 'shipper')), true)) return [];
    $shipping_status = in_array($order->get_status(), ['shipping', 'partial-shipping', 'partial-shipped'], true)
        || ($order->get_status() === 'on-hold' && in_array((string) $order->get_meta('_thean_ow_hold_from'), ['shipping', 'partial-shipping', 'partial-shipped'], true));
    if (!$shipping_status && !in_array('Đã nhập kho vận chuyển', thean_issue_field_values($order, 'handling'), true)) return [];
    return thean_issue_overdue_evidence($order, 'ghtk_overdue', thean_issue_dispatch_at($order), $now);
}

/** Choose one primary group per order: blocking first, then registered operational priority. */
function thean_issue_primary_group(array $records): string
{
    $open = array_values(array_filter($records, static fn($record) => $record['status'] !== 'resolved'));
    if (!$open) return '';
    $types = thean_issue_types();
    usort($open, static function ($a, $b) use ($types) {
        return ((int) thean_issue_blocks($b) <=> (int) thean_issue_blocks($a))
            ?: (($types[$a['type']]['priority'] ?? 100) <=> ($types[$b['type']]['priority'] ?? 100))
            ?: strcmp($a['type'], $b['type']);
    });
    return isset($types[$open[0]['type']]) ? $open[0]['type'] : 'other';
}

/** A specific type filter groups by that matching type; otherwise use the primary incident. */
function thean_issue_view_group(array $records): string
{
    $filter = thean_issue_filter();
    if (isset(thean_issue_types()[$filter])) {
        foreach ($records as $record) if ($record['status'] !== 'resolved' && $record['type'] === $filter) return $filter;
    }
    return thean_issue_primary_group($records);
}

/** Group labels for the general incident board; independent of the order's operating step. */
function thean_issue_groups(): array
{
    $types = thean_issue_types();
    uasort($types, static fn($a, $b) => ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100));
    $groups = [];
    foreach ($types as $key => $type) $groups['incident_' . $key] = $type['label'];
    $groups['incident_other'] = 'Sự cố khác';
    return $groups;
}

/** Open-incident query independent of an optional UI filter; legacy delivery remains authoritative. */
function thean_issue_open_sql(string $alias = 'o'): string
{
    $t = thean_ops_step_tables();
    return "EXISTS (SELECT 1 FROM {$t['meta']} ii WHERE ii.{$t['fk']}={$alias}.{$t['id']} AND ((ii.meta_key='_thean_incidents_open' AND ii.meta_value='1') OR (ii.meta_key='_thean_delivery_issue' AND ii.meta_value='1')))";
}

/** Primary group projection, with a live fallback for unmigrated legacy delivery incidents. */
function thean_issue_group_sql(): string
{
    global $wpdb;
    $filter = thean_issue_filter();
    if (isset(thean_issue_types()[$filter])) return $wpdb->prepare('%s', $filter);
    $t = thean_ops_step_tables();
    $value = "COALESCE(NULLIF((SELECT ig.meta_value FROM {$t['meta']} ig WHERE ig.{$t['fk']}=o.{$t['id']} AND ig.meta_key='_thean_incidents_group' LIMIT 1),''), CASE WHEN EXISTS (SELECT 1 FROM {$t['meta']} di WHERE di.{$t['fk']}=o.{$t['id']} AND di.meta_key='_thean_delivery_issue' AND di.meta_value='1') THEN 'delivery' ELSE 'other' END)";
    $sql = 'CASE ' . $value;
    foreach (array_keys(thean_issue_types()) as $key) $sql .= $wpdb->prepare(' WHEN %s THEN %s', $key, $key);
    return $sql . " ELSE 'other' END";
}

/** Canonical phone for exact identity matching; empty/short values never match. */
function thean_issue_phone(string $value): string
{
    $value = preg_replace('/\D+/', '', $value);
    if (strpos($value, '0084') === 0) $value = substr($value, 2);
    if (strpos($value, '84') === 0 && strlen($value) >= 11) $value = '0' . substr($value, 2);
    return strlen($value) >= 8 && strlen($value) <= 15 ? $value : '';
}

/** Extract only the identity needed for detection, without persisting it. */
function thean_issue_identity(WC_Order $order): array
{
    return [
        'id' => $order->get_id(), 'customer' => (int) $order->get_customer_id(),
        'email' => strtolower(trim($order->get_billing_email())),
        'phones' => array_values(array_unique(array_filter([thean_issue_phone($order->get_billing_phone()), thean_issue_phone($order->get_shipping_phone())]))),
        'created' => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
        'status' => $order->get_status(),
    ];
}

/** One status catalogue for SQL detection and per-order validation. */
function thean_issue_excluded_statuses(): array
{
    return ['cancelled', 'failed', 'refunded', 'trash', 'auto-draft', 'checkout-draft', 'draft', 'chua-ghi-nhan', 'da-ghi-nhan'];
}

/** Completed deliveries plus excluded orders are out of the recurring undelivered sweep. */
function thean_issue_closed_statuses(): array
{
    return array_merge(thean_issue_excluded_statuses(), ['delivered', 'completed']);
}

/** Database status representations share the same domain catalogue as the detectors. */
function thean_issue_status_sql(array $statuses): string
{
    global $wpdb;
    $values = array_unique(array_merge($statuses, array_map(static fn($status) => 'wc-' . $status, $statuses)));
    return implode(',', array_map(static fn($status) => $wpdb->prepare('%s', $status), $values));
}

/** Direct matches only: no transitive grouping through an intermediate order. */
function thean_issue_match(array $a, array $b): array
{
    if (in_array($a['status'], thean_issue_excluded_statuses(), true) || in_array($b['status'], thean_issue_excluded_statuses(), true)) return [];
    if ($a['id'] === $b['id'] || !$a['created'] || !$b['created'] || abs($a['created'] - $b['created']) > DAY_IN_SECONDS) return [];
    $reasons = [];
    if ($a['customer'] > 0 && $a['customer'] === $b['customer']) $reasons[] = 'Cùng tài khoản';
    if (array_intersect($a['phones'], $b['phones'])) $reasons[] = 'Cùng SĐT';
    if ($a['email'] !== '' && $a['email'] === $b['email']) $reasons[] = 'Cùng email';
    return $reasons;
}

/** Exclude failed/replaced orders and non-customer accounting/shipping aggregates. */
function thean_issue_eligible(WC_Order $order): bool
{
    return $order->get_type() === 'shop_order' && $order->get_date_created()
        && !in_array($order->get_status(), thean_issue_excluded_statuses(), true)
        && (string) $order->get_meta('_spx_date') === '';
}

/** Read candidate identities in one bounded query for a batch of displayed/scanned orders. */
function thean_issue_candidates(array $orders)
{
    global $wpdb;
    $windows = [];
    foreach ($orders as $order) {
        if (!thean_issue_eligible($order)) continue;
        $identity = thean_issue_identity($order);
        if (!$identity['customer'] && !$identity['phones'] && $identity['email'] === '') continue;
        $at = $order->get_date_created()->getTimestamp();
        $windows[] = [$at - DAY_IN_SECONDS, $at + DAY_IN_SECONDS];
    }
    if (!$windows) return [];
    sort($windows);
    $merged = [];
    foreach ($windows as $window) {
        $last = count($merged) - 1;
        if ($last >= 0 && $merged[$last][1] >= $window[0]) $merged[$last][1] = max($merged[$last][1], $window[1]);
        else $merged[] = $window;
    }
    $t = thean_ops_step_tables();
    $dates = [];
    foreach ($merged as [$from, $to]) $dates[] = $wpdb->prepare("o.{$t['date']} BETWEEN %s AND %s", gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to));
    $joins = '';
    if ($t['email_col'] !== '') {
        $select = 'o.customer_id AS customer, o.billing_email AS email, b.phone AS billing_phone, sh.phone AS shipping_phone';
        $joins = " LEFT JOIN {$wpdb->prefix}wc_order_addresses b ON b.order_id=o.id AND b.address_type='billing'
                   LEFT JOIN {$wpdb->prefix}wc_order_addresses sh ON sh.order_id=o.id AND sh.address_type='shipping'";
    } else {
        foreach (['cu' => '_customer_user', 'em' => '_billing_email', 'bp' => '_billing_phone', 'sp' => '_shipping_phone'] as $alias => $key) {
            $joins .= " LEFT JOIN {$t['meta']} {$alias} ON {$alias}.{$t['fk']}=o.{$t['id']} AND {$alias}.meta_key='{$key}'";
        }
        $select = 'cu.meta_value AS customer, em.meta_value AS email, bp.meta_value AS billing_phone, sp.meta_value AS shipping_phone';
    }
    $excluded_sql = thean_issue_status_sql(thean_issue_excluded_statuses());
    $rows = $wpdb->get_results("SELECT o.{$t['id']} AS id, o.{$t['date']} AS created, o.{$t['status']} AS status, {$select}
        FROM {$t['orders']} o {$joins}
        WHERE o.{$t['type']}='shop_order' AND (" . implode(' OR ', $dates) . ")
        AND o.{$t['status']} NOT IN ({$excluded_sql})
        AND NOT EXISTS (SELECT 1 FROM {$t['meta']} x WHERE x.{$t['fk']}=o.{$t['id']} AND x.meta_key='_spx_date' AND x.meta_value<>'')
        ORDER BY o.{$t['id']} DESC LIMIT " . (THEAN_ISSUE_CANDIDATE_LIMIT + 1), ARRAY_A);
    if ($wpdb->last_error || !is_array($rows)) {
        error_log('order-issues: candidate query failed');
        return new WP_Error('issue_query', 'Không tra được đơn liên quan. Vui lòng thử lại.');
    }
    if (count($rows) > THEAN_ISSUE_CANDIDATE_LIMIT) return new WP_Error('issue_limit', 'Có quá nhiều đơn trong khoảng kiểm tra. Cần kiểm tra lại theo lô nhỏ hơn.');
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['id']] = [
            'id' => (int) $row['id'], 'customer' => (int) $row['customer'],
            'email' => strtolower(trim((string) $row['email'])),
            'phones' => array_values(array_unique(array_filter([thean_issue_phone((string) $row['billing_phone']), thean_issue_phone((string) $row['shipping_phone'])]))),
            'created' => strtotime($row['created'] . ' UTC'), 'status' => preg_replace('/^wc-/', '', $row['status']),
        ];
    }
    return array_values($out);
}

/** Stable evidence signature includes identity/date changes, but not ordinary workflow progress. */
function thean_issue_detect_nearby(WC_Order $order, array $candidates): array
{
    if (!thean_issue_eligible($order)) return [];
    $identity = thean_issue_identity($order);
    $evidence = []; $signatures = [$identity['id'] => $identity];
    foreach ($candidates as $candidate) {
        $reasons = thean_issue_match($identity, $candidate);
        if (!$reasons) continue;
        $evidence[$candidate['id']] = ['id' => $candidate['id'], 'reasons' => $reasons];
        $signatures[$candidate['id']] = $candidate;
    }
    if (!$evidence) return [];
    ksort($evidence); ksort($signatures);
    foreach ($signatures as &$signature) { unset($signature['status']); sort($signature['phones']); }
    unset($signature);
    return ['evidence' => array_values($evidence), 'fingerprint' => hash('sha256', wp_json_encode($signatures))];
}

/** Read persisted instances; derived filter keys are deliberately outside this prefix. */
function thean_issue_records(WC_Order $order): array
{
    $out = [];
    foreach ($order->get_meta_data() as $meta) {
        $data = $meta->get_data();
        if (strpos($data['key'], THEAN_ISSUE_PREFIX) !== 0 || !is_array($data['value'])) continue;
        $record = $data['value'];
        if (isset($record['id'], $record['type'], $record['status'])) $out[$record['id']] = $record;
    }
    return $out;
}

/** Read the legacy delivery source without copying its data into another record. */
function thean_issue_delivery(WC_Order $order): array
{
    $legacy = thean_delivery_issue($order);
    return !$legacy['on'] ? [] : ['delivery' => [
        'id' => 'delivery', 'type' => 'delivery', 'description' => $legacy['note'], 'status' => 'open',
        'mode' => 'warn', 'created_at' => $legacy['at'], 'fingerprint' => hash('sha256', wp_json_encode($legacy)),
    ]];
}

/** Aggregate all registered sources; unknown types remain visible and fail closed if blocking. */
function thean_issue_all(WC_Order $order): array
{
    $records = thean_issue_records($order);
    foreach (thean_issue_types() as $type) {
        if (!empty($type['provider'])) $records += call_user_func($type['provider'], $order);
    }
    return $records;
}

/** Compute blocking from canonical records, not the disposable SQL summaries. */
function thean_issue_blocks(array $record): bool
{
    if ($record['status'] === 'resolved') return false;
    $mode = $record['mode'] ?? 'resolve';
    return !in_array($mode, ['warn', 'review'], true) || ($mode === 'review' && (empty($record['ack_fingerprint']) || $record['ack_fingerprint'] !== ($record['fingerprint'] ?? '')));
}

/** Serialize mutations per order; requests never leave an option-based lock behind. */
function thean_issue_locked(int $id, callable $callback)
{
    global $wpdb;
    $key = 'oi_' . md5($wpdb->prefix . '|' . $id);
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $key)) !== 1) return new WP_Error('issue_busy', 'Sự cố đang được cập nhật. Vui lòng thử lại.');
    try {
        return $callback();
    } catch (Throwable $error) {
        error_log('order-issues: mutation failed for order ' . $id . ' (' . get_class($error) . ')');
        return new WP_Error('issue_storage', 'Không lưu được sự cố. Vui lòng thử lại.');
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    }
}

/** Derive disposable query fields from canonical records without persisting empty indexes. */
function thean_issue_projection(array $records): array
{
    $open = []; $blocking = false;
    foreach ($records as $record) {
        if ($record['status'] === 'resolved') continue;
        $open[$record['type']] = true;
        $blocking = $blocking || thean_issue_blocks($record);
    }
    ksort($open);
    return ['_thean_incidents_open' => $open ? '1' : '', '_thean_incidents_blocked' => $blocking ? '1' : '', '_thean_incidents_types' => $open ? '|' . implode('|', array_keys($open)) . '|' : '', '_thean_incidents_group' => thean_issue_primary_group($records)];
}

/** Rebuild query projections from authoritative instances and the delivery adapter. */
function thean_issue_index(WC_Order $order): void
{
    $changed = false;
    foreach (thean_issue_projection(thean_issue_all($order)) as $key => $value) {
        if ((string) $order->get_meta($key) === $value) continue;
        if ($value === '') $order->delete_meta_data($key);
        else $order->update_meta_data($key, $value);
        $changed = true;
    }
    // WC saves only dirty metadata. Reads with unchanged evidence do not write projections.
    $order->save_meta_data();
    if ($changed) thean_ops_board_invalidate();
}

/** Ordinary list reads use already-loaded metadata; only changed records need a write lock/re-read. */
function thean_issue_needs_refresh(WC_Order $order, array $candidates): bool
{
    $records = thean_issue_records($order);
    foreach (thean_issue_types() as $type => $definition) {
        if (empty($definition['detect'])) continue;
        $found = call_user_func($definition['detect'], $order, $candidates);
        $record = $records['auto_' . $type] ?? null;
        if ($found && (!$record || $record['status'] === 'resolved' || $record['fingerprint'] !== $found['fingerprint'])) return true;
        if (!$found && $record && $record['status'] !== 'resolved') return true;
    }
    foreach (thean_issue_projection(thean_issue_all($order)) as $key => $value) if ((string) $order->get_meta($key) !== $value) return true;
    return false;
}

/** Refresh automatic instances under the caller's order lock. */
function thean_issue_refresh(WC_Order $order, array $candidates): void
{
    $records = thean_issue_records($order);
    foreach (thean_issue_types() as $type => $definition) {
        if (empty($definition['detect'])) continue;
        $id = 'auto_' . $type;
        $found = call_user_func($definition['detect'], $order, $candidates);
        $record = $records[$id] ?? null;
        if (!$found && (!$record || $record['status'] === 'resolved')) continue;
        if ($found && $record && $record['status'] !== 'resolved' && $record['fingerprint'] === $found['fingerprint']) continue;
        $now = time();
        if (!$record) $record = ['id' => $id, 'type' => $type, 'created_at' => $now, 'created_by' => 0];
        if (!$found) {
            $record['status'] = 'resolved'; $record['resolution'] = 'Điều kiện phát hiện không còn.';
            $record['resolved_at'] = $now; $record['resolved_by'] = 0;
        } else {
            unset($record['resolved_at'], $record['resolved_by']);
            $record = array_merge($record, $found, ['status' => 'open', 'mode' => $definition['mode'], 'description' => $definition['label'], 'ack_fingerprint' => '', 'resolution' => '']);
        }
        $record['updated_at'] = $now; $record['updated_by'] = 0;
        $order->update_meta_data(THEAN_ISSUE_PREFIX . $id, $record);
        thean_ops_board_invalidate();
        $order->add_order_note(($found ? '⚠️ Sự cố: ' : '✅ Tự kết thúc sự cố: ') . $definition['label'], false, false);
    }
    thean_issue_index($order);
}

/** Batch candidate read; each order is then re-read under its own mutation lock. */
function thean_issue_sync(array $orders, bool $force = false)
{
    if (!$orders) return true;
    $candidates = thean_issue_candidates($orders);
    if (is_wp_error($candidates)) {
        // Widely separated search results can span many date windows; narrow only saturated batches.
        if ($candidates->get_error_code() === 'issue_limit' && count($orders) > 1) {
            foreach ($orders as $order) { $result = thean_issue_sync([$order], $force); if (is_wp_error($result)) return $result; }
            return true;
        }
        return $candidates;
    }
    foreach ($orders as $order) {
        if (!$force && !thean_issue_needs_refresh($order, $candidates)) continue;
        $result = thean_issue_locked($order->get_id(), function () use ($order, $candidates) {
            $order->read_meta_data(true);
            thean_issue_refresh($order, $candidates);
            return true;
        });
        if (is_wp_error($result)) return $result;
    }
    return true;
}

/** Server policy for forward manual actions. Automated integrations retain their existing flows. */
function thean_issue_action_error(WC_Order $order): string
{
    $creator = function_exists('order_creator_state') ? order_creator_state() : [];
    if (thean_order_change_source() !== '' && empty($creator['operator_id'])) return '';
    if (!$order->get_id()) return '';
    // Read the persisted status: a requested target must not hide an existing issue.
    $source = wc_get_order($order->get_id());
    if (!$source) return 'Không đọc được sự cố của đơn.';
    $result = thean_issue_sync([$source], true);
    if (is_wp_error($result)) return $result->get_error_message();
    $blocked = []; $types = thean_issue_types();
    foreach (thean_issue_all($source) as $record) {
        if (thean_issue_blocks($record)) $blocked[$record['type']] = $types[$record['type']]['label'] ?? $record['type'];
    }
    return $blocked ? 'Đơn #' . $order->get_id() . ' có sự cố đang chặn: ' . implode('; ', $blocked) . '. Mở Sự cố trên /quan-ly-don/ để kiểm tra và xử lý trước.' : '';
}

/** Whether a status destination advances fulfilment (shared by individual and bulk actions). */
function thean_issue_forward_status(string $status): bool
{
    return !in_array($status, ['on-hold', 'cancelled', 'failed', 'refunded', 'pre-order', 'return-requested', 'chua-ghi-nhan', 'da-ghi-nhan'], true);
}

/** Allow holding/cancelling/returns; check every other status change before transition side effects. */
function thean_issue_guard_status($order): void
{
    if (!$order instanceof WC_Order || !$order->get_id()) return;
    // Recording received money retains its existing transitions. Forward fulfilment endpoints are guarded separately.
    if (($_POST['action'] ?? '') === 'confirm_order_payment') return;
    $changes = $order->get_changes(); $original = $order->get_data();
    if (empty($changes['status']) || $changes['status'] === $original['status']
        || !thean_issue_forward_status($changes['status'])) return;
    $message = thean_issue_action_error($order);
    if ($message === '') return;
    $order->set_status($original['status']);
    foreach (['date_paid', 'date_completed'] as $prop) if (array_key_exists($prop, $changes)) $order->{'set_' . $prop}($original[$prop]);
    Closure::bind(function () { $this->status_transition = false; }, $order, WC_Order::class)();
    throw new WC_Data_Exception('thean_incident_blocked', $message);
}
add_action('woocommerce_before_order_object_save', 'thean_issue_guard_status', 1);

/** Block carrier creation before the plugin builds/posts the shipment payload. */
add_filter('devvn_ghtk_create_order_products', function ($products, $main, $order) {
    if ($order instanceof WC_Order && ($message = thean_issue_action_error($order)) !== '') {
        if (wp_doing_ajax()) wp_send_json_error(['message' => $message]);
        throw new WC_Data_Exception('thean_incident_blocked', $message);
    }
    return $products;
}, 1, 3);

/** Detect updated/new orders in the background, including reverse links that must be cleared. */
add_action('woocommerce_after_order_object_save', function ($order) {
    if (!$order instanceof WC_Order || !$order->get_id() || $order->get_type() !== 'shop_order') return;
    if (!wp_next_scheduled('thean_order_issues_changed', [$order->get_id()])) wp_schedule_single_event(time() + 2, 'thean_order_issues_changed', [$order->get_id()]);
});
add_action('thean_order_issues_changed', function ($id) {
    $order = wc_get_order($id);
    if (!$order) return;
    $old = thean_issue_records($order)['auto_nearby_orders']['evidence'] ?? [];
    $candidates = thean_issue_candidates([$order]);
    if (is_wp_error($candidates)) { error_log('order-issues: change detection incomplete for order ' . (int) $id); return; }
    $ids = [(int) $id];
    foreach ($old as $evidence) $ids[] = (int) $evidence['id'];
    $identity = thean_issue_identity($order);
    foreach ($candidates as $candidate) if (thean_issue_match($identity, $candidate)) $ids[] = $candidate['id'];
    $ids = array_values(array_unique($ids));
    foreach (array_chunk($ids, 20) as $batch) {
        $orders = wc_get_orders(['include' => $batch, 'limit' => count($batch)]);
        $result = thean_issue_sync($orders);
        if (is_wp_error($result)) error_log('order-issues: change refresh incomplete');
    }
});

/** Catch legacy/meta-only delivery and identity updates; own incident writes do not queue themselves. */
function thean_issue_meta_changed($meta_id, $order_id, $key): void
{
    $watched = array_merge(['_thean_delivery_issue', '_thean_delivery_issue_note', '_thean_delivery_issue_at', '_thean_delivery_issue_by', '_spx_date', '_billing_phone', '_shipping_phone', '_billing_email', '_customer_user'], ...array_values(thean_issue_site_fields()));
    if (!in_array($key, $watched, true)) return;
    if (!wp_next_scheduled('thean_order_issues_changed', [(int) $order_id])) wp_schedule_single_event(time() + 2, 'thean_order_issues_changed', [(int) $order_id]);
}
foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_order_meta', 'updated_order_meta', 'deleted_order_meta'] as $hook) add_action($hook, 'thean_issue_meta_changed', 30, 3);

/** Start a bounded initial scan; store only a cursor, never customer data or an ID queue in options. */
function thean_issue_scan_start(): void
{
    if (wp_next_scheduled('thean_order_issues_scan')) return;
    $initial = get_option('thean_incidents_scan_version') !== THEAN_ISSUE_SCAN_VERSION;
    wp_schedule_single_event(time() + ($initial ? 2 : 3600), 'thean_order_issues_scan');
}
add_action('admin_init', function () { if (current_user_can('manage_woocommerce')) thean_issue_scan_start(); });
function thean_issue_scan_run(): void
{
    global $wpdb;
    $t = thean_ops_step_tables();
    $initial = get_option('thean_incidents_scan_version') !== THEAN_ISSUE_SCAN_VERSION;
    if (get_option('thean_incidents_cursor_version') !== THEAN_ISSUE_SCAN_VERSION) { update_option('thean_incidents_scan_cursor', 0, false); update_option('thean_incidents_cursor_version', THEAN_ISSUE_SCAN_VERSION, false); }
    $cursor = (int) get_option('thean_incidents_scan_cursor', 0);
    $active = $initial ? '' : " AND o.{$t['status']} NOT IN (" . thean_issue_status_sql(thean_issue_closed_statuses()) . ')';
    $ids = $wpdb->get_col($wpdb->prepare("SELECT o.{$t['id']} FROM {$t['orders']} o WHERE o.{$t['type']}='shop_order'
        AND o.{$t['date']} >= %s AND o.{$t['id']} > %d {$active} ORDER BY o.{$t['id']} ASC LIMIT 20", gmdate('Y-m-d H:i:s', thean_order_lock_cutoff_ts()), $cursor));
    if ($wpdb->last_error) { error_log('order-issues: scan query failed'); wp_schedule_single_event(time() + 60, 'thean_order_issues_scan'); return; }
    $result = $ids ? thean_issue_sync(wc_get_orders(['include' => $ids, 'limit' => count($ids)])) : true;
    if (is_wp_error($result)) { error_log('order-issues: scan incomplete'); wp_schedule_single_event(time() + 60, 'thean_order_issues_scan'); return; }
    if ($ids) update_option('thean_incidents_scan_cursor', max(array_map('intval', $ids)), false);
    if (count($ids) < 20) {
        update_option('thean_incidents_scan_version', THEAN_ISSUE_SCAN_VERSION, false);
        update_option('thean_incidents_scan_cursor', 0, false);
        wp_schedule_single_event(time() + 3600, 'thean_order_issues_scan');
    } else wp_schedule_single_event(time() + 10, 'thean_order_issues_scan');
}
add_action('thean_order_issues_scan', function () {
    $result = thean_issue_locked(0, 'thean_issue_scan_run');
    if (is_wp_error($result) && !wp_next_scheduled('thean_order_issues_scan')) wp_schedule_single_event(time() + 60, 'thean_order_issues_scan');
});

/** Validate the SQL-filter selection shared by all search/board requests. */
function thean_issue_filter(): string
{
    $raw = $_POST['issue_filter'] ?? $_GET['thean_issue'] ?? '';
    $value = is_scalar($raw) ? sanitize_key(wp_unslash((string) $raw)) : '';
    return in_array($value, array_merge(['', 'open', 'blocked'], array_keys(thean_issue_types())), true) ? $value : '';
}

/** Query disposable summaries plus live legacy delivery flags; all dynamic values are prepared. */
function thean_issue_filter_sql(string $alias = 'o'): string
{
    global $wpdb;
    $filter = thean_issue_filter();
    if ($filter === '') return '1=1';
    $t = thean_ops_step_tables();
    $key = $filter === 'blocked' ? '_thean_incidents_blocked' : ($filter === 'open' ? '_thean_incidents_open' : '_thean_incidents_types');
    $condition = in_array($filter, ['blocked', 'open'], true) ? "ix.meta_value='1'" : $wpdb->prepare('ix.meta_value LIKE %s', '%|' . $wpdb->esc_like($filter) . '|%');
    $key_sql = $wpdb->prepare('%s', $key);
    $sql = "EXISTS (SELECT 1 FROM {$t['meta']} ix WHERE ix.{$t['fk']}={$alias}.{$t['id']} AND ix.meta_key={$key_sql} AND {$condition})";
    if (in_array($filter, ['open', 'delivery'], true)) $sql = '(' . $sql . " OR EXISTS (SELECT 1 FROM {$t['meta']} di WHERE di.{$t['fk']}={$alias}.{$t['id']} AND di.meta_key='_thean_delivery_issue' AND di.meta_value='1'))";
    return $sql;
}

/** Match a refreshed row using the same filter meaning as the SQL projection. */
function thean_issue_matches_filter(WC_Order $order): bool
{
    $filter = thean_issue_filter();
    if ($filter === '') return true;
    foreach (thean_issue_all($order) as $record) {
        if ($record['status'] === 'resolved') continue;
        if ($filter === 'open' || ($filter === 'blocked' && thean_issue_blocks($record)) || $filter === $record['type']) return true;
    }
    return false;
}

/** Render a row entry point even when there is no incident, so staff can report one. */
function thean_issue_button(WC_Order $order, string $error = ''): string
{
    $open = array_filter(thean_issue_all($order), static fn($r) => $r['status'] !== 'resolved');
    $blocked = array_filter($open, 'thean_issue_blocks');
    if (!$open && $error === '' && !current_user_can('manage_woocommerce')) return '';
    $label = $error !== '' ? '⚠ Chưa kiểm tra được sự cố' : ($open ? '⚠ Sự cố (' . count($open) . ')' . ($blocked ? ' · Đang chặn' : '') : '＋ Báo sự cố');
    return '<button type="button" class="thean-incident-badge' . ($blocked || $error !== '' ? ' is-blocked' : '') . '" data-order-issues="' . esc_attr($order->get_id()) . '">' . esc_html($label) . '</button>';
}

/** Enqueue independent shared UI on the existing search/portal asset path. */
function thean_issue_enqueue(): void
{
    thean_issue_scan_start();
    $path = '/custom-functions/woocommerce/orders/assets/order-issues';
    wp_enqueue_style('thean-order-issues', get_stylesheet_directory_uri() . $path . '.css', [], filemtime(get_stylesheet_directory() . $path . '.css'));
    wp_enqueue_script('thean-order-issues', get_stylesheet_directory_uri() . $path . '.js', [], filemtime(get_stylesheet_directory() . $path . '.js'), true);
    wp_add_inline_script('thean-order-issues', 'window.theanIssueCfg=' . wp_json_encode(['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('thean_order_issues')]) . ';', 'before');
}

/** Return safe, minimal UI data and current authoritative evidence token. */
function thean_issue_payload(WC_Order $order): array
{
    $types = thean_issue_types(); $records = [];
    $all = thean_issue_all($order); $ids = [];
    foreach ($all as $record) foreach ($record['evidence'] ?? [] as $item) $ids[] = (int) $item['id'];
    $related = [];
    if ($ids) foreach (wc_get_orders(['include' => array_values(array_unique($ids)), 'limit' => min(count($ids), THEAN_ISSUE_CANDIDATE_LIMIT)]) as $item) {
        $related[$item->get_id()] = ['id' => $item->get_id(), 'number' => $item->get_order_number(), 'at' => $item->get_date_created() ? $item->get_date_created()->date_i18n('d/m/Y H:i') : '', 'status' => wc_get_order_status_name($item->get_status()), 'total' => html_entity_decode(wp_strip_all_tags($item->get_formatted_order_total()), ENT_QUOTES, 'UTF-8'), 'url' => add_query_arg('order_id', $item->get_id(), home_url('/tao-don/'))];
    }
    foreach ($all as $record) {
        $record['label'] = $types[$record['type']]['label'] ?? $record['type'];
        $record['help'] = $types[$record['type']]['help'] ?? '';
        $record['blocked'] = thean_issue_blocks($record);
        $record['actions'] = $record['status'] === 'resolved' ? [] : ($types[$record['type']]['transitions'] ?? []);
        if (!empty($record['ack_fingerprint']) && $record['ack_fingerprint'] === ($record['fingerprint'] ?? '')) $record['actions'] = array_values(array_diff($record['actions'], ['ack']));
        $record['related'] = [];
        foreach ($record['evidence'] ?? [] as $item) if (isset($related[$item['id']])) $record['related'][] = $related[$item['id']] + ['reasons' => $item['reasons']];
        $record['token'] = hash('sha256', wp_json_encode($all[$record['id']]));
        $records[] = $record;
    }
    $history = [];
    foreach (wc_get_order_notes(['order_id' => $order->get_id(), 'type' => 'internal', 'limit' => 50, 'orderby' => 'date_created', 'order' => 'DESC']) as $note) {
        if (preg_match('/(?:Sự cố|sự cố|kiểm tra đơn)/u', $note->content)) $history[] = ['text' => wp_strip_all_tags($note->content), 'at' => $note->date_created ? $note->date_created->date_i18n('d/m/Y H:i') : '', 'by' => $note->added_by];
    }
    return ['id' => $order->get_id(), 'records' => $records, 'history' => $history, 'can_manage' => current_user_can('manage_woocommerce'), 'pending' => get_option('thean_incidents_scan_version') !== THEAN_ISSUE_SCAN_VERSION];
}

/** Read/mutate one incident through one authenticated endpoint. Recheck evidence under the order lock. */
function thean_issue_ajax(): void
{
    check_ajax_referer('thean_order_issues', 'nonce');
    $op = sanitize_key($_POST['op'] ?? 'read');
    if (!current_user_can('manage_woocommerce') && ($op !== 'read' || !current_user_can('edit_shop_orders'))) wp_send_json_error(['message' => 'Không có quyền xử lý sự cố.'], 403);
    $id = absint($_POST['order_id'] ?? 0);
    $order = wc_get_order($id);
    if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') wp_send_json_error(['message' => 'Không tìm thấy đơn.'], 404);
    $result = thean_issue_locked($id, function () use ($id, $op) {
        $order = wc_get_order($id); $order->read_meta_data(true);
        $candidates = thean_issue_candidates([$order]);
        if (is_wp_error($candidates)) return $candidates;
        thean_issue_refresh($order, $candidates);
        if ($op === 'read') return thean_issue_payload($order);
        $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
        if (strlen($note) > 6000) return new WP_Error('issue_note', 'Nội dung quá dài.');
        $records = thean_issue_all($order);
        if ($op === 'create') {
            if ($note === '') return new WP_Error('issue_note', 'Nhập nội dung sự cố.');
            if (count($records) >= 1000) return new WP_Error('issue_limit', 'Đơn đã đạt giới hạn sự cố; hãy cập nhật sự cố hiện có.');
            $record = ['id' => wp_generate_uuid4(), 'type' => 'manual', 'description' => $note, 'status' => 'open', 'mode' => ($_POST['mode'] ?? '') === 'resolve' ? 'resolve' : 'warn', 'created_at' => time(), 'created_by' => get_current_user_id()];
        } else {
            $instance = sanitize_key($_POST['instance'] ?? '');
            if (!isset($records[$instance])) return new WP_Error('issue_missing', 'Sự cố không còn tồn tại.');
            $record = $records[$instance];
            if (!hash_equals(hash('sha256', wp_json_encode($record)), (string) ($_POST['token'] ?? ''))) return new WP_Error('issue_changed', 'Sự cố hoặc đơn liên quan đã thay đổi. Tải lại để kiểm tra.');
            $definition = thean_issue_types()[$record['type']] ?? [];
            if ($record['status'] === 'resolved' || !in_array($op, $definition['transitions'] ?? [], true)) return new WP_Error('issue_action', 'Thao tác không hợp lệ.');
            if ($record['type'] === 'delivery') {
                if ($note === '') return new WP_Error('issue_note', 'Nhập kết quả xử lý.');
                [$ok, $message] = thean_delivery_issue_apply($order, 'resolve', $note);
                if (!$ok) return new WP_Error('issue_delivery', $message);
                thean_issue_index($order);
                thean_ops_board_invalidate();
                return thean_issue_payload($order);
            }
            if ($op === 'edit') {
                if ($note === '') return new WP_Error('issue_note', 'Nhập nội dung sự cố.');
                $record['description'] = $note;
            }
            if ($op === 'start') $record['status'] = 'working';
            if ($op === 'ack') {
                $record['ack_fingerprint'] = $record['fingerprint'];
                $record['ack_by'] = get_current_user_id(); $record['ack_at'] = time();
                $record['status'] = 'working';
            }
            if ($op === 'resolve') {
                if ($note === '') return new WP_Error('issue_note', 'Nhập kết quả xử lý.');
                $record['status'] = 'resolved'; $record['resolution'] = $note;
                $record['resolved_at'] = time(); $record['resolved_by'] = get_current_user_id();
            }
        }
        $record['updated_at'] = time(); $record['updated_by'] = get_current_user_id();
        $order->update_meta_data(THEAN_ISSUE_PREFIX . $record['id'], $record);
        thean_issue_index($order);
        thean_ops_board_invalidate();
        $labels = ['create' => 'Ghi nhận', 'edit' => 'Cập nhật nội dung', 'start' => 'Đang xử lý', 'ack' => 'Đã kiểm tra', 'resolve' => 'Đã xử lý'];
        $order->add_order_note('Sự cố [' . $record['id'] . '] — ' . ($labels[$op] ?? $op) . ($note !== '' ? ': ' . $note : '.'), false, true);
        return thean_issue_payload($order);
    });
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], $result->get_error_code() === 'issue_changed' ? 409 : 400);
    wp_send_json_success($result);
}
add_action('wp_ajax_thean_order_issues', 'thean_issue_ajax');
