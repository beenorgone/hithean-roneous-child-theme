"""Execute the generated PHP backfill SQL against legacy and HPOS fixtures.

Run: python3 tests/order-return-backfill.py
SQLite verifies query membership; this does not replace a live MySQL/Woo test.
"""
import datetime as dt
import json
import pathlib
import sqlite3
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
php = r'''
ob_start();
require 'tests/order-universal-search.php';
ob_end_clean();
$out = [];
foreach ([false, true] as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    $t = thean_ops_step_tables();
    $wpdb->queries = [];
    thean_ops_step_unsynced_ids(100);
    $out[] = [$t, 'SELECT o.' . $t['id'] . ' ' . thean_ops_step_open_sql($t), $wpdb->queries[0], THEAN_OPS_STEP_VERSION];
}
echo json_encode($out);
'''
queries = json.loads(subprocess.check_output(['php', '-r', php], cwd=ROOT))
now = dt.datetime.now(dt.timezone.utc)
checks = 0
for tables, base, unsynced, current_version in queries:
    db = sqlite3.connect(':memory:')
    t = tables
    db.execute(f"CREATE TABLE {t['orders']} ({t['id']} INTEGER PRIMARY KEY, "
               f"{t['status']} TEXT, {t['type']} TEXT, {t['date']} TEXT)")
    db.execute(f"CREATE TABLE {t['meta']} ({t['fk']} INTEGER, meta_key TEXT, meta_value TEXT)")

    def order(oid, status='cancelled', request=None, step=None, age=100,
              version=None, kind='shop_order'):
        created = (now - dt.timedelta(days=age)).strftime('%Y-%m-%d %H:%M:%S')
        db.execute(f"INSERT INTO {t['orders']} VALUES (?, ?, ?, ?)",
                   (oid, 'wc-' + status, kind, created))
        for key, value in [('return_status', request), ('_thean_ops_step', step),
                           ('_thean_ops_step_v', version)]:
            if value is not None:
                db.execute(f"INSERT INTO {t['meta']} VALUES (?, ?, ?)", (oid, key, value))

    # Missing/wrong/previous-version steps must not hide a pending return.
    order(10696, request='Chờ hoàn (Không giao được)')
    order(2, request=' Đang hoàn hàng ', step='none', version='6')
    order(3, 'refunded', request='Chờ hoàn', step='payment')
    order(4, 'failed', request='Chờ hoàn')
    order(5, 'completed', request='Chờ hoàn')
    order(6)  # cancelled without return stays excluded
    order(7, request='Hủy yêu cầu')
    order(8, request=' Đã nhận hàng hoàn (Có sự cố) ')
    order(9, request='   ')
    order(10, request='Chờ hoàn', age=400)  # age gate remains effective
    order(11, request='Chờ hoàn', kind='shop_order_refund')
    order(12, 'processing')
    order(13, 'completed', age=10)  # recent proof audit remains eligible
    order(14, request='Đã nhận hàng hoàn', step='return')  # clean stale steps
    order(15, step='return')  # return deleted outside hooks
    order(16, request='Chờ hoàn', version=current_version)  # sweep/flush still revisit
    expected = {10696, 2, 3, 4, 5, 12, 13, 14, 15, 16}
    actual = {row[0] for row in db.execute(base)}
    assert actual == expected, (t['orders'], actual, expected)
    pending = {row[0] for row in db.execute(unsynced)}
    assert pending == expected - {16}, (t['orders'], pending)
    checks += 2
    db.close()

print(f'OK ({checks} SQL result checks across legacy and HPOS)')
