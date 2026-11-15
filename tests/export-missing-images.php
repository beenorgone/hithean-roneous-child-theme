<?php
/** Isolated authorization/render tests plus the actual SQL against SQLite fixtures. */
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
function add_action(...$args) {}
function absint($v) { return abs((int) $v); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($v)); }
function wc_get_order_statuses() { return ['wc-processing'=>'Đang xử lý','wc-shipping'=>'Đang giao','wc-completed'=>'Hoàn thành','wc-cancelled'=>'Hủy','wc-failed'=>'Thất bại','wc-refunded'=>'Hoàn tiền','wc-checkout-draft'=>'Nháp']; }
function current_user_can($cap) { return $GLOBALS['allowed'][$cap] ?? true; }
function check_ajax_referer(...$args) { return $GLOBALS['nonce_ok'] ?? true; }
class JsonResponse extends Exception { function __construct(public $success, public $data, public $status) { parent::__construct(); } }
function wp_send_json_error($data,$status=200) { throw new JsonResponse(false,$data,$status); }
function wp_send_json_success($data) { throw new JsonResponse(true,$data,200); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_attr($v) { return esc_html($v); }
function esc_url($v) { return esc_html($v); }
function get_stylesheet_directory() { return dirname(__DIR__); }
function get_stylesheet_directory_uri() { return '/theme'; }
function admin_url($path) { return '/wp-admin/'.$path; }
function wp_create_nonce($action) { return 'fixture-'.$action; }
function wc_get_order_status_name($status) { return wc_get_order_statuses()['wc-'.$status] ?? $status; }
class FixtureItem { function get_name(){return '<script>bad</script> Protein';} function get_quantity(){return 2;} }
class FixtureDate { function date_i18n($format) { return '15/11/2026 10:00'; } }
class FixtureOrder {
 function __construct(public $id) {}
 function get_id(){return $this->id;} function get_order_number(){return $this->id.'.1';}
 function get_date_created(){return new FixtureDate();} function get_status(){return 'shipping';}
 function get_edit_order_url(){return '/wp-admin/order.php?id='.$this->id;}
 function get_formatted_billing_full_name(){return '<b>Fixture customer</b>';}
 function get_items(){return [new FixtureItem()];}
}
function wc_get_orders($args) { $GLOBALS['order_args']=$args; return array_map(fn($id)=>new FixtureOrder($id),array_reverse($args['include'])); }
class FixtureDB {
 public $prefix='wp_', $posts='wp_posts', $postmeta='wp_postmeta', $last_error='', $sql='', $calls=0;
 function prepare($sql,...$values) { foreach($values as $v) $sql=preg_replace('/%[sd]/', "'".str_replace("'","''",(string)$v)."'",$sql,1); return $sql; }
 function get_col($sql) { $this->calls++;$this->sql=$sql;return range(1,21); }
}
$wpdb=new FixtureDB();
eval('namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { public static function custom_orders_table_usage_is_enabled(){return $GLOBALS["hpos"]??false;} }');
require dirname(__DIR__).'/custom-functions/woocommerce/orders/order-export-missing-images.php';
if (($argv[1]??'')==='--sql') {
 $GLOBALS['hpos']=($argv[2]??'')==='hpos';
 thean_export_missing_ids(thean_export_missing_filters(['days'=>$argv[3]??30,'status'=>$argv[4]??'','page'=>$argv[5]??1]));
 echo $wpdb->sql;exit;
}
if (($argv[1]??'')==='--cards') { echo thean_export_missing_cards([101,102,103]);exit; }
if (($argv[1]??'')==='--render') { echo thean_export_missing_shell();exit; }
$checks=0;
function check($v,$label){global $checks;$checks++;if(!$v)throw new RuntimeException($label);}
check(thean_export_missing_filters([])===['days'=>30,'status'=>'','page'=>1], 'default is 30 days and page 1');
check(thean_export_missing_filters(['days'=>31,'status'=>'wc-cancelled','page'=>-1])===['days'=>30,'status'=>'','page'=>1], 'unsupported filters cannot widen query');
check(thean_export_missing_filters(['days'=>90,'status'=>'wc-shipping','page'=>100000])===['days'=>90,'status'=>'wc-shipping','page'=>10000], 'allowed filters and page cap');
$result=thean_export_missing_ids(thean_export_missing_filters([]));
check(count($result['ids'])===20 && $result['has_more'], '21st ID is only the next-page sentinel');
$html=thean_export_missing_cards([2,1]);
check(strpos($html,'#2.1')<strpos($html,'#1.1'), 'database order preserved after bulk hydration');
check(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;') && str_contains($html,'&lt;b&gt;Fixture'), 'customer and product strings escaped');
check($GLOBALS['order_args']['limit']===2 && $GLOBALS['order_args']['include']===[2,1], 'only visible orders hydrated');
check(str_contains(thean_export_missing_shell(),'value="30" selected'), 'shell defaults to 30 days');
foreach (['manage_woocommerce','upload_files','nonce'] as $blocked) {
 $GLOBALS['allowed']=[];$GLOBALS['nonce_ok']=true;
 if ($blocked==='nonce')$GLOBALS['nonce_ok']=false;else $GLOBALS['allowed'][$blocked]=false;
 $before=$wpdb->calls;
 try { thean_export_missing_ajax();throw new RuntimeException('expected denial'); } catch(JsonResponse $r) { check(!$r->success && $r->status===403, 'permission/nonce denied: '.$blocked); }
 check($before===$wpdb->calls, 'unauthorized request never queries orders');
}
$GLOBALS['allowed']=[];$GLOBALS['nonce_ok']=true;$_POST=['days'=>60,'status'=>'wc-shipping'];
try {thean_export_missing_ajax();} catch(JsonResponse $r){check($r->success && $r->data['days']===60 && $r->data['status']==='wc-shipping' && $r->data['has_more'], 'endpoint retains filters and pagination');}
$wpdb->last_error='private SQL error';
try {thean_export_missing_ajax();} catch(JsonResponse $r){check(!$r->success && $r->status===500 && !str_contains(json_encode($r->data),'private'), 'database errors fail closed without leaking SQL');}
echo "OK ($checks missing-image backend checks)\n";
$command=['python3',__DIR__.'/export-missing-images-sql.py'];
$proc=proc_open($command,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);exit(proc_close($proc));
