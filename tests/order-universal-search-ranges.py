"""Execute production-generated date predicates on SQLite fixtures (no WordPress).
Run: python3 tests/order-universal-search-ranges.py
"""
from datetime import datetime, timezone
import json
from pathlib import Path
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[1]
php = r'''
ob_start(); require 'tests/order-universal-search.php'; ob_end_clean();
$out = [];
foreach ([true, false] as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    $rows = [];
    foreach (['auto','7','30','90','all'] as $range) {
        $rows[$range] = [
            'search' => thean_ous_range_sql($range, 'o', null, 1700000000),
            'board' => thean_ous_range_sql($range, 'o', 's.meta_value', 1700000000),
        ];
    }
    $board = [];
    foreach (['auto','7','30','90','all'] as $range) {
        foreach (['picking','return','preorder','proof'] as $tab) {
            $board[$range][$tab] = 'SELECT DISTINCT o.' . thean_ops_step_tables()['id'] . ' ' . thean_ous_board_sql($range) . ' AND ' . thean_ous_board_tab_sql($tab, $range) . ' ORDER BY o.' . thean_ops_step_tables()['id'];
        }
    }
    $memberships = [];
    foreach (['auto','7','30','90','all'] as $range) {
        $wpdb->queries = [];
        thean_ous_board_memberships([1,4,5,7,8,9,11], $range);
        $memberships[$range] = end($wpdb->queries);
    }
    $out[] = ['hpos' => $hpos, 'queries' => $rows, 'board' => $board, 'memberships' => $memberships, 'now' => time()];
}
echo json_encode($out);
'''
scopes = json.loads(subprocess.check_output(['php', '-r', php], cwd=root, text=True))

def at(age):
    return datetime.fromtimestamp(1700000000 - age, timezone.utc).strftime('%Y-%m-%d %H:%M:%S')

D = 86400
# Include exact boundaries and one second outside each default exception.
fixtures = [
    (1, 5*D, 'picking', 'wc-processing'),
    (2, 30*D, 'picking', 'wc-processing'),
    (3, 30*D+1, 'picking', 'wc-processing'),
    (4, 60*D, 'return', 'wc-processing'),
    (5, 90*D, 'preorder', 'wc-pre-order'),
    (6, 90*D+1, 'return', 'wc-processing'),
    (7, 60*D, 'shipping', 'wc-processing'),
    (8, 7*D, 'proof', 'wc-completed'),
    (9, 7*D+1, 'proof', 'wc-completed'),
    (10, 60*D, 'confirm', 'wc-pre-order'),
    (11, 31*D, 'proof', 'wc-completed'),
]
expected = {'7': [1, 8], '30': [1, 2, 8, 9],
            '90': [1, 2, 3, 4, 5, 7, 8, 9, 10, 11],
            'all': list(range(1, 12))}
checks = 0
for scope in scopes:
    db = sqlite3.connect(':memory:')
    hpos = scope['hpos']
    table = 'wp_wc_orders' if hpos else 'wp_posts'
    idcol, status, date = ('id', 'status', 'date_created_gmt') if hpos else ('ID', 'post_status', 'post_date_gmt')
    meta, fk = ('wp_wc_orders_meta', 'order_id') if hpos else ('wp_postmeta', 'post_id')
    db.execute(f'CREATE TABLE {table} ({idcol} INTEGER, {status} TEXT, {date} TEXT)')
    db.execute(f'CREATE TABLE {meta} ({fk} INTEGER, meta_key TEXT, meta_value TEXT)')
    for oid, age, step, st in fixtures:
        db.execute(f'INSERT INTO {table} VALUES (?,?,?)', (oid, st, at(age)))
        db.execute(f'INSERT INTO {meta} VALUES (?,?,?)', (oid, '_thean_ops_step', step))
    for range_name, modes in scope['queries'].items():
        for mode, predicate in modes.items():
            sql = f'SELECT o.{idcol} FROM {table} o JOIN {meta} s ON s.{fk}=o.{idcol} WHERE {predicate} ORDER BY o.{idcol}'
            got = [row[0] for row in db.execute(sql)]
            want = expected.get(range_name)
            if range_name == 'auto':
                want = [1, 2, 4, 5, 8, 9] + ([10] if mode == 'search' else [])
            assert got == want, (hpos, range_name, mode, got, want, sql)
            checks += 1
    # Execute complete production board queries too, including proof overlap and
    # the pre-existing business age lock. Move near-boundary rows away from the
    # clock tick; exact boundaries are covered above with a fixed timestamp.
    db.execute(f'DROP TABLE {table}')
    db.execute(f'DELETE FROM {meta}')
    typecol = 'type' if hpos else 'post_type'
    db.execute(f'CREATE TABLE {table} ({idcol} INTEGER, {status} TEXT, {date} TEXT, {typecol} TEXT, billing_email TEXT)')
    for oid, age, step, st in fixtures:
        if age % D == 0:
            age -= 60
        elif age % D == 1:
            age += 60
        created = datetime.fromtimestamp(scope['now'] - age, timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
        db.execute(f'INSERT INTO {table} VALUES (?,?,?,?,?)', (oid, st, created, 'shop_order', 'customer@example.test'))
        db.execute(f'INSERT INTO {meta} VALUES (?,?,?)', (oid, '_thean_ops_step', step))
        if oid in [4, 7, 8, 9, 11]:
            db.execute(f'INSERT INTO {meta} VALUES (?,?,?)', (oid, '_thean_ops_proof', '1'))
    for range_name, tabs in scope['board'].items():
        for tab, sql in tabs.items():
            got = [row[0] for row in db.execute(sql)]
            eligible = expected.get(range_name, [1, 2, 4, 5, 8, 9])
            if tab == 'proof':
                proof_eligible = {'auto': [8, 9], '7': [8], '30': [8, 9],
                                  '90': [4, 7, 8, 9, 11], 'all': [4, 7, 8, 9, 11]}
                want = proof_eligible[range_name]
            else:
                want = [oid for oid, _, step, _ in fixtures if oid in eligible and step == tab]
            assert got == want, (hpos, range_name, tab, got, want, sql)
            checks += 1
    for range_name, sql in scope['memberships'].items():
        got = {oid: (step, bool(proof)) for oid, step, proof in db.execute(sql)}
        eligible = expected.get(range_name, [1, 2, 4, 5, 8, 9])
        proof_ids = {'auto': [8, 9], '7': [8], '30': [8, 9],
                     '90': [4, 7, 8, 9, 11], 'all': [4, 7, 8, 9, 11]}[range_name]
        want = {oid: (step, oid in proof_ids) for oid, _, step, _ in fixtures
                if oid in eligible and oid in [1,4,5,7,8,9,11]}
        assert got == want, (hpos, range_name, 'memberships', got, want, sql)
        checks += 1
    db.close()
print(f'OK ({checks} executed SQL checks)')
