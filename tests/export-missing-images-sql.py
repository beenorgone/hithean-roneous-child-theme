"""Run the PHP-generated production query against representative order/meta fixtures."""
from pathlib import Path
import datetime as dt
import sqlite3
import subprocess

root = Path(__file__).resolve().parent
now = dt.datetime.now(dt.timezone.utc)
checks = 0
for mode in ('legacy', 'hpos'):
    db = sqlite3.connect(':memory:')
    db.executescript('''
    CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_date_gmt TEXT);
    CREATE TABLE wp_wc_orders (id INTEGER PRIMARY KEY, type TEXT, status TEXT, date_created_gmt TEXT);
    CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT);
    CREATE TABLE wp_wc_orders_meta (order_id INTEGER, meta_key TEXT, meta_value TEXT);
    ''')
    def order(id, days=5, status='wc-processing', type='shop_order'):
        date = (now-dt.timedelta(days=days)).strftime('%Y-%m-%d %H:%M:%S')
        for table in ('wp_posts','wp_wc_orders'):
            db.execute(f'INSERT INTO {table} VALUES (?,?,?,?)',(id,type,status,date))
    def meta(id, key, value, table=None):
        table = table or ('wp_wc_orders_meta' if mode=='hpos' else 'wp_postmeta')
        db.execute(f'INSERT INTO {table} VALUES (?,?,?)',(id,key,value))
    order(1);order(2);meta(2,'warehouse_export_images','https://site/photo.jpg')
    order(3);meta(3,'warehouse_export_images',' \n\r\t ')
    order(4);meta(4,'warehouse_export_images','');meta(4,'warehouse_export_images','https://site/existing.jpg')
    order(5,status='wc-cancelled');order(6,status='wc-failed');order(7,status='wc-refunded');order(8,status='wc-checkout-draft')
    order(9,type='shop_order_refund');order(10,days=91);order(11,days=-1)
    order(12,days=45,status='wc-shipping');order(13,days=75,status='wc-completed')
    order(14);meta(14,'_spx_date','2026-11-15')
    order(15,status='wc-completed')
    order(16);meta(16,'warehouse_export_images','https://legacy/photo.jpg','wp_postmeta')
    def query(days=30,status='',page=1):
        sql=subprocess.check_output(['php',str(root/'export-missing-images.php'),'--sql',mode,str(days),status,str(page)],text=True)
        return [r[0] for r in db.execute(sql)]
    expected={1,3,15}
    assert set(query())==expected, (mode,query())
    assert set(query(60))==expected|{12}
    assert set(query(90))==expected|{12,13}
    assert query(60,'wc-shipping')==[12]
    assert set(query(90,'wc-completed'))=={13,15}
    checks+=5
    for id in range(100,145):order(id,days=1)
    first,second,third=query(page=1),query(page=2),query(page=3)
    # SQL returns 21 rows so the endpoint can expose a 20-row page + next flag.
    assert len(first)==21 and len(second)==21 and set(first[:20]).isdisjoint(second[:20])
    assert len(third)==8 and len(set(first[:20]+second[:20]+third))==48
    checks+=2
print(f'OK ({checks} SQL fixture checks across legacy and HPOS)')
