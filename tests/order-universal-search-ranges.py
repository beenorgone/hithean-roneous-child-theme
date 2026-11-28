"""Execute production-generated date predicates on SQLite fixtures (no WordPress).
Run: python3 tests/order-universal-search-ranges.py
"""
from datetime import datetime, timezone, timedelta
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
    foreach (['auto','7','30','90','120','180','270','360','all'] as $range) {
        $rows[$range] = [
            'search' => thean_ous_range_sql($range, 'o', null, 1700000000),
            'board' => thean_ous_range_sql($range, 'o', 's.meta_value', 1700000000),
        ];
    }
    $board = [];
    foreach (['auto','7','30','90','120','180','270','360','all'] as $range) {
        foreach (['picking','return','preorder','proof','cod'] as $tab) {
            $board[$range][$tab] = 'SELECT DISTINCT o.' . thean_ops_step_tables()['id'] . ' ' . thean_ous_board_sql($range) . ' AND ' . thean_ous_board_tab_sql($tab, $range) . ' ORDER BY o.' . thean_ops_step_tables()['id'];
        }
    }
    $issue_sql = 'SELECT DISTINCT o.' . thean_ops_step_tables()['id'] . ' ' . thean_ous_board_sql('all') . ' AND ' . thean_ous_board_tab_sql('issue', 'all');
    $issue_groups_sql = 'SELECT ' . thean_issue_group_sql() . ' AS incident_group, COUNT(DISTINCT o.' . thean_ops_step_tables()['id'] . ') AS n ' . thean_ous_board_sql('all') . ' AND ' . thean_ous_board_tab_sql('issue', 'all') . ' GROUP BY incident_group';
    $memberships = [];
    foreach (['auto','7','30','90','120','180','270','360','all'] as $range) {
        $wpdb->queries = [];
        thean_ous_board_memberships([1,4,5,7,8,9,11], $range);
        $memberships[$range] = end($wpdb->queries);
    }
    $year_cases = [];
    foreach (['2026-01-01 00:00:00 UTC', '2026-12-31 18:00:00 UTC'] as $instant) {
        $timestamp = strtotime($instant);
        $year_cases[] = ['now' => $timestamp, 'sql' => thean_ous_range_sql('year', 'o', null, $timestamp)];
    }
    $out[] = ['hpos' => $hpos, 'queries' => $rows, 'board' => $board, 'memberships' => $memberships, 'issue_sql' => $issue_sql, 'issue_groups_sql' => $issue_groups_sql, 'now' => time(), 'year_cases' => $year_cases];
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
for days in ['120','180','270','360']:
    expected[days] = list(range(1, 12))
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
    # Execute rolling-window boundaries and calendar-year boundaries in site time.
    for days in [120, 180, 270, 360]:
        for age, want in [(days * D, 1), (days * D + 1, 0)]:
            predicate = scope['queries'][str(days)]['search']
            got = db.execute(f"SELECT ({predicate}) FROM (SELECT ? AS {date}) o", (at(age),)).fetchone()[0]
            assert got == want, (hpos, days, age, got)
            checks += 1
    for case in scope['year_cases']:
        local_now = datetime.fromtimestamp(case['now'], timezone.utc) + timedelta(hours=7)
        start = datetime(local_now.year, 1, 1, tzinfo=timezone.utc).timestamp() - 7 * 3600
        end = datetime(local_now.year + 1, 1, 1, tzinfo=timezone.utc).timestamp() - 7 * 3600
        for timestamp, want in [(start-1, 0), (start, 1), (end-1, 1), (end, 0)]:
            value = datetime.fromtimestamp(timestamp, timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
            got = db.execute(f"SELECT ({case['sql']}) FROM (SELECT ? AS {date}) o", (value,)).fetchone()[0]
            assert got == want, (hpos, case, value, got)
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
            if tab == 'cod':
                want = []
            elif tab == 'proof':
                proof_eligible = {'auto': [8, 9], '7': [8], '30': [8, 9],
                                  '90': [4, 7, 8, 9, 11], 'all': [4, 7, 8, 9, 11]}
                want = proof_eligible.get(range_name, [4, 7, 8, 9, 11])
            else:
                want = [oid for oid, _, step, _ in fixtures if oid in eligible and step == tab]
            assert got == want, (hpos, range_name, tab, got, want, sql)
            checks += 1
    for range_name, sql in scope['memberships'].items():
        got = {oid: (step, bool(proof)) for oid, step, cod, proof, incidents, internal in db.execute(sql)}
        eligible = expected.get(range_name, [1, 2, 4, 5, 8, 9])
        proof_ids = {'auto': [8, 9], '7': [8], '30': [8, 9],
                     '90': [4, 7, 8, 9, 11], 'all': [4, 7, 8, 9, 11]}.get(range_name, [4, 7, 8, 9, 11])
        want = {oid: (step, oid in proof_ids) for oid, _, step, _ in fixtures
                if oid in eligible and oid in [1,4,5,7,8,9,11]}
        assert got == want, (hpos, range_name, 'memberships', got, want, sql)
        checks += 1
    # Parallel COD queue includes completed orders with no main step and still
    # observes selected dates, age lock, internal separation and mutation reads.
    created = datetime.fromtimestamp(scope['now'] - D, timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    for oid, step, st, group in [(21, 'none', 'wc-completed', 'cod_fast'),
                                  (22, 'shipping', 'wc-shipping', 'cod'),
                                  (23, 'shipping', 'wc-local-shipping', 'cod_fast'),
                                  (24, 'none', 'wc-completed', 'cod_delivered')]:
        db.execute(f'INSERT INTO {table} VALUES (?,?,?,?,?)', (oid, st, created, 'shop_order', 'customer@example.test'))
        db.execute(f'INSERT INTO {meta} VALUES (?,?,?)', (oid, '_thean_ops_step', step))
        db.execute(f'INSERT INTO {meta} VALUES (?,?,?)', (oid, '_thean_ops_cod', group))
    for range_name in scope['board']:
        got = [row[0] for row in db.execute(scope['board'][range_name]['cod'])]
        assert got == [21, 23, 24], (hpos, range_name, got)
        checks += 1
    db.execute(f'INSERT INTO {meta} VALUES (?,?,?)', (22, '_eim_internal', '1'))
    got = [row[0] for row in db.execute(scope['board']['all']['cod'])]
    assert got == [21, 23, 24], (hpos, got)
    checks += 1
    db.execute(f'DELETE FROM {meta} WHERE {fk}=21 AND meta_key="_thean_ops_cod"')
    got = [row[0] for row in db.execute(scope['board']['all']['cod'])]
    assert got == [23, 24], (hpos, got)
    checks += 1
    # Incidents are a parallel queue: active picking/shipping rows retain their original step.
    db.execute(f'INSERT INTO {meta} VALUES (?,?,?)', (1, '_thean_incidents_open', '1'))
    db.execute(f'INSERT INTO {meta} VALUES (?,?,?)', (1, '_thean_incidents_group', 'order_overdue'))
    db.execute(f'INSERT INTO {meta} VALUES (?,?,?)', (8, '_thean_delivery_issue', '1'))
    issue_ids = {row[0] for row in db.execute(scope['issue_sql'])}
    assert issue_ids == {1, 8}, (hpos, 'parallel incidents', issue_ids)
    membership_rows = list(db.execute(scope['memberships']['all']))
    assert all(bool(incidents) == (oid in {1, 8}) for oid, step, cod, proof, incidents, internal in membership_rows)
    group_counts = dict(db.execute(scope['issue_groups_sql']))
    assert group_counts == {'order_overdue': 1, 'delivery': 1}, (hpos, group_counts)
    checks += 3
    db.close()
print(f'OK ({checks} executed SQL checks)')
