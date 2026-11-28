<?php
/** Regression: direct matching, stale acknowledgments, serialized writes and bypassing UI guards. No WP/HTTP. */
define('ABSPATH', __DIR__); define('DAY_IN_SECONDS', 86400); define('ARRAY_A', 'ARRAY_A');
$hooks=[]; $orders=[]; $notes=[]; $caps=true; $source=''; $candidate_rows=[]; $hpos=true; $options=[];
function add_action($name,$fn,...$args){$GLOBALS['hooks'][$name][]=$fn;}
function add_filter($name,$fn,...$args){$GLOBALS['hooks'][$name][]=$fn;}
function apply_filters($name,$value){return $value;}
function wp_timezone(){return new DateTimeZone('Asia/Ho_Chi_Minh');}
function wp_json_encode($value){return json_encode($value);}
function wp_unslash($value){return $value;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function sanitize_textarea_field($value){return trim(strip_tags($value));}
function wc_get_order_notes($args){return [];}
function wc_get_order_status_name($s){return $s;}
function wp_strip_all_tags($s){return strip_tags($s);}
function home_url($path){return 'https://example.test'.$path;}
function add_query_arg($key,$value,$url){return $url.'?'.$key.'='.$value;}
function absint($value){return abs((int)$value);}
function get_current_user_id(){return 7;}
function current_user_can($cap){return $GLOBALS['caps'];}
function check_ajax_referer($action,$key){if(($_POST[$key]??'')!=='valid')throw new RuntimeException('bad nonce');}
function wp_generate_uuid4(){static $n=0;return 'manual-'.++$n;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function wp_next_scheduled(...$args){return false;}
function wp_schedule_single_event($at,$hook,...$args){$GLOBALS['scheduled'][]=[$at,$hook];return true;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function thean_order_lock_cutoff_ts(){return time()-365*DAY_IN_SECONDS;}
function thean_ops_board_invalidate(){$GLOBALS['invalidated']=($GLOBALS['invalidated']??0)+1;}
function thean_order_change_source(){return $GLOBALS['source'];}
function wp_doing_ajax(){return true;}
function is_wp_error($value){return $value instanceof WP_Error;}
function wc_get_order($id){return isset($GLOBALS['orders'][$id])?clone $GLOBALS['orders'][$id]:false;}
function wc_get_orders($args){return array_values(array_map('wc_get_order',array_intersect($args['include'],array_keys($GLOBALS['orders']))));}
function thean_ops_step_tables(){return $GLOBALS['hpos']?['orders'=>'wp_wc_orders','meta'=>'wp_wc_orders_meta','id'=>'id','fk'=>'order_id','type'=>'type','status'=>'status','date'=>'date_created_gmt','email_col'=>'billing_email']:['orders'=>'wp_posts','meta'=>'wp_postmeta','id'=>'ID','fk'=>'post_id','type'=>'post_type','status'=>'post_status','date'=>'post_date_gmt','email_col'=>''];}
function thean_delivery_issue($order){return ['on'=>$order->get_meta('_thean_delivery_issue')==='1','note'=>$order->get_meta('_thean_delivery_issue_note'),'at'=>100,'by'=>'Staff'];}
function thean_delivery_issue_apply($order,$op,$note){$order->delete_meta_data('_thean_delivery_issue');$order->save_meta_data();return [true,'done'];}
class WP_Error {function __construct(public $code, public $message){} function get_error_message(){return $this->message;}function get_error_code(){return $this->code;}}
class WC_Data_Exception extends RuntimeException {function __construct($code,$message){parent::__construct($message);}}
class JsonResult extends RuntimeException {function __construct(public $success,public $payload,public $status=200){}}
function wp_send_json_success($data){throw new JsonResult(true,$data);}
function wp_send_json_error($data,$status=200){throw new JsonResult(false,$data,$status);}
class FakeMeta {function __construct(public $key,public $value){} function get_data(){return ['key'=>$this->key,'value'=>$this->value];}}
class FakeDate extends DateTimeImmutable {function date_i18n($format){return $this->format($format);}}
class WC_Order {
    public array $meta=[]; public array $changes=[]; public string $status='processing'; public string $email=''; public string $bp='';public string $sp='';public int $customer=0; public int $created=1700000000; public $status_transition=false;
    function __construct(public int $id){}
    function get_order_number(){return $this->id;} function get_formatted_order_total(){return '100.000 ₫';}
    function get_id(){return $this->id;} function get_type(){return 'shop_order';} function get_status(){return $this->changes['status']??$this->status;}
    function get_date_created(){return new FakeDate('@'.$this->created);}function get_customer_id(){return $this->customer;}
    function get_billing_email(){return $this->email;}function get_billing_phone(){return $this->bp;}function get_shipping_phone(){return $this->sp;}
    function get_meta($key){return $this->meta[$key]??'';}function get_meta_data(){return array_map(fn($k,$v)=>new FakeMeta($k,$v),array_keys($this->meta),array_values($this->meta));}
    function update_meta_data($key,$value){$this->meta[$key]=$value;}function delete_meta_data($key){unset($this->meta[$key]);}
    function read_meta_data($force){$this->meta=$GLOBALS['orders'][$this->id]->meta;}
    function save_meta_data(){$GLOBALS['orders'][$this->id]->meta=$this->meta;}
    function add_order_note($note,...$args){$GLOBALS['notes'][]=$note;}
    function get_changes(){return $this->changes;}function get_data(){return ['status'=>$this->status,'date_paid'=>null,'date_completed'=>null];}
    function set_status($status){$this->changes['status']=$status;}function set_date_paid($date){}function set_date_completed($date){}
}
class FakeDB {
    public $prefix='wp_'; public $last_error='';public $locked=true;public $queries=[];
    function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[sd]/',function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:"'".addslashes($v)."'";},$sql);}
    function get_var($sql){$this->queries[]=$sql;return str_contains($sql,'GET_LOCK')?($this->locked?1:0):1;}
    function get_col($sql){$this->queries[]=$sql;return [];}
    function get_results($sql,...$args){$this->queries[]=$sql;return $GLOBALS['candidate_rows'];}
    function esc_like($value){return addcslashes($value,'_%');}
}
$wpdb=new FakeDB();
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-issues-site.php';
require dirname(__DIR__).'/custom-functions/woocommerce/orders/order-issues.php';
$checks=0;
function check($ok,$message){$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function row($order){return ['id'=>$order->id,'customer'=>$order->customer,'email'=>$order->email,'billing_phone'=>$order->bp,'shipping_phone'=>$order->sp,'created'=>gmdate('Y-m-d H:i:s',$order->created),'status'=>'wc-'.$order->status];}
function ajax($post){$_POST=$post+['nonce'=>'valid','order_id'=>1];try{thean_issue_ajax();}catch(JsonResult $r){return $r;}throw new RuntimeException('no JSON');}
$a=new WC_Order(1);$b=new WC_Order(2);$orders=[1=>$a,2=>$b];
foreach(['0901 234 567','84901234567','+84 901-234-567','0084901234567']as$phone)check(thean_issue_phone($phone)==='0901234567','VN canonical phone '.$phone);
check(thean_issue_phone('123')==='','short invalid phone');
$a->bp='0901234567';$b->sp='+84 901 234 567';$b->created=$a->created+DAY_IN_SECONDS;
check(thean_issue_match(thean_issue_identity($a),thean_issue_identity($b))===['Cùng SĐT'],'inclusive 24h, cross billing/shipping');
$b->created++;check(!thean_issue_match(thean_issue_identity($a),thean_issue_identity($b)),'outside 24h');$b->created--;
$b->sp='';check(!thean_issue_match(thean_issue_identity($a),thean_issue_identity($b)),'guest zero IDs and empty email never match');
$a->customer=$b->customer=3;check(thean_issue_match(thean_issue_identity($a),thean_issue_identity($b))===['Cùng tài khoản'],'registered customer');
$a->email=' Buyer@Example.test ';$b->email='buyer@example.test';check(count(thean_issue_match(thean_issue_identity($a),thean_issue_identity($b)))===2,'email case/space normalization');
$c=new WC_Order(3);$c->email=$b->email;$b->sp='0901234567';$a->customer=0;$a->email='';
check(!thean_issue_match(thean_issue_identity($a),thean_issue_identity($c)),'does not transitively match A-phone-B-email-C');
foreach(['cancelled','failed','refunded','draft','checkout-draft','chua-ghi-nhan','da-ghi-nhan']as$status){$b->status=$status;check(!thean_issue_eligible($b),'excluded '.$status);}
$b->status='processing';$b->meta['_spx_date']='2026-11-29';check(!thean_issue_eligible($b),'Shopee aggregate excluded');$b->meta=[];
$candidate_rows=[row($a),row($b)];
check(thean_issue_sync([$a])===true,'batch sync');$record=thean_issue_records($a)['auto_nearby_orders'];
check(thean_issue_blocks($record),'unreviewed auto issue blocks');check($a->get_meta('_thean_incidents_blocked')==='1','derived block index');
$record['ack_fingerprint']=$record['fingerprint'];check(!thean_issue_blocks($record),'matching review unblocks');
$before=$record['fingerprint'];$b->status='shipping';$found=thean_issue_detect_nearby($a,[thean_issue_identity($b)]);check($found['fingerprint']===$before,'ordinary workflow does not invalidate review');
$b->created--;check(thean_issue_detect_nearby($a,[thean_issue_identity($b)])['fingerprint']!==$before,'date change invalidates review');$b->created++;
$record['fingerprint']='new';check(thean_issue_blocks($record),'changed evidence requires recheck');
$record['status']='resolved';check(!thean_issue_blocks($record),'resolved never blocks');
$notesBefore=count($notes);$wpdb->queries=[];thean_issue_sync([$a]);check(count($notes)===$notesBefore,'unchanged read emits no duplicate notes');
check(count(array_filter($wpdb->queries,fn($q)=>str_contains($q,'GET_LOCK')))===0,'unchanged list read has no per-row lock or forced meta read');
foreach([true,false]as$hpos){$wpdb->queries=[];thean_issue_candidates([$a]);$sql=end($wpdb->queries);check(str_contains($sql,$hpos?'wp_wc_orders':'wp_posts'),'storage adapter');check(str_contains($sql,'LIMIT 5001')&&str_contains($sql,'BETWEEN'),'bounded date query');}
$hpos=true;$wpdb->locked=false;check(is_wp_error(thean_issue_sync([$a], true)),'lock contention reports error');$wpdb->locked=true;
$wpdb->queries=[];$r=thean_issue_locked(1,function(){throw new RuntimeException('failure');});check(is_wp_error($r)&&str_contains(end($wpdb->queries),'RELEASE_LOCK'),'failure still releases lock');
$candidate_rows=array_fill(0,THEAN_ISSUE_CANDIDATE_LIMIT+1,row($a));check(is_wp_error(thean_issue_candidates([$a])),'saturation is explicit, never false no-match');$candidate_rows=[];
$wpdb->last_error='simulated';check(is_wp_error(thean_issue_candidates([$a])),'DB error not swallowed');$wpdb->last_error='';
// Use no automatic match for manual transition tests (payload needs no unrelated order objects).
$r=ajax(['op'=>'create','note'=>'Cần kiểm tra địa chỉ','mode'=>'resolve']);check($r->success,'create manual');
$manual=array_values(array_filter($r->payload['records'],fn($r)=>$r['type']==='manual'))[0];
check($manual['blocked'],'manual resolve mode blocks');
$r=ajax(['op'=>'start','instance'=>$manual['id'],'token'=>'stale']);check(!$r->success&&$r->status===409,'stale token rejected');
$r=ajax(['op'=>'start','instance'=>$manual['id'],'token'=>$manual['token']]);check($r->success,'start processing');
$manual=array_values(array_filter($r->payload['records'],fn($r)=>$r['type']==='manual'))[0];
$r=ajax(['op'=>'resolve','instance'=>$manual['id'],'token'=>$manual['token'],'note'=>'']);check(!$r->success,'resolution requires note');
check(thean_issue_action_error($a)!=='','server guards even without UI');
$source='cron';check(thean_issue_action_error($a)==='','automatic jobs unchanged');$source='';
$a->changes=['status'=>'shipping'];$a->status_transition=true;try{thean_issue_guard_status($a);throw new RuntimeException('guard should throw');}catch(WC_Data_Exception $e){check($a->changes['status']===$a->status&&!$a->status_transition,'restore status and cancel transition');}$a->changes=[];
foreach(['on-hold','cancelled','failed','refunded','pre-order']as$target)check(!thean_issue_forward_status($target),'allowed destination '.$target);
$r=ajax(['op'=>'resolve','instance'=>$manual['id'],'token'=>$manual['token'],'note'=>'Đã xác minh']);check($r->success,'resolve manual with result');
check(thean_issue_action_error($a)==='','resolved issue no longer blocks');
$a->meta['_thean_delivery_issue']='1';$a->meta['_thean_delivery_issue_note']='Khách hẹn lại';
check(isset(thean_issue_all($a)['delivery']),'legacy delivery adapter');check(!thean_issue_blocks(thean_issue_all($a)['delivery']),'legacy delivery adds no new block');
$_POST=['issue_filter'=>'delivery'];$sql=thean_issue_filter_sql();check(str_contains($sql,'_thean_delivery_issue')&&str_contains($sql,'EXISTS'),'delivery filter includes legacy source');
$_POST=['issue_filter'=>'blocked'];check(!str_contains(thean_issue_filter_sql(),'_thean_delivery_issue'),'legacy does not become blocking');
$_POST=['issue_filter'=>'unknown'];check(thean_issue_filter()==='','invalid filter ignored');
$caps=false;$r=ajax(['op'=>'create','note'=>'bad']);check(!$r->success&&$r->status===403,'permissions enforced');$caps=true;
try{ajax(['nonce'=>'bad','op'=>'read']);throw new RuntimeException('nonce accepted');}catch(RuntimeException $e){check($e->getMessage()==='bad nonce','nonce first');}
// End-to-end review invalidation: a new direct match cannot reuse the old confirmation.
$candidate_rows=[row($a),row($b)];
$r=ajax(['op'=>'read']);$auto=array_values(array_filter($r->payload['records'],fn($r)=>$r['type']==='nearby_orders'))[0];
check($auto['blocked'],'reappearing detection reopens incident');
$r=ajax(['op'=>'ack','instance'=>$auto['id'],'token'=>$auto['token']]);check($r->success,'ack current set');
check(thean_issue_action_error($a)==='','review unblocks even with warning-only delivery');
$c->bp=$a->bp;$c->created=$a->created;$orders[3]=$c;$candidate_rows[]=row($c);
$r=ajax(['op'=>'ack','instance'=>$auto['id'],'token'=>$auto['token']]);check(!$r->success&&$r->status===409,'new order rejects stale review');
check(thean_issue_action_error($a)!=='','new match blocks again');
try { $hooks['devvn_ghtk_create_order_products'][0]([], null, $a); throw new RuntimeException('carrier guard bypassed'); }
catch (JsonResult $r) { check(!$r->success, 'carrier creation filter stops before returning shipment products'); }
$emptyOrder=new WC_Order(4);$emptyOrder->created=time();$orders[4]=$emptyOrder;$wpdb->queries=[];
check(thean_issue_sync([$emptyOrder])===true && !$emptyOrder->meta && !$wpdb->queries, 'no contacts/no incidents: no candidate SQL, locks or empty meta writes');
// Both overdue clocks use explicit source times and strict >7-day boundaries.
$clock=1900000000;$late=new WC_Order(10);$late->created=$clock-7*DAY_IN_SECONDS;
check(!thean_issue_detect_order_overdue($late, [], $clock), 'exactly 7 days is not overdue');
$late->created--;check((bool)thean_issue_detect_order_overdue($late, [], $clock), 'older than 7 days from creation');
$late->status='pre-order';check(!thean_issue_detect_order_overdue($late, [], $clock), 'preorders excluded from created clock');
$late->status='processing';$fields=thean_issue_site_fields();
foreach(array_keys(thean_issue_shortage_options()) as $tag){$late->meta[$fields['handling'][0]]=[$tag];check(!thean_issue_detect_order_overdue($late, [], $clock), 'shortage tag excluded: '.$tag);}
$late->meta=[];
foreach(['delivered','completed','cancelled','refunded','failed']as$st){$late->status=$st;check(!thean_issue_detect_order_overdue($late, [], $clock), 'closed clock excludes '.$st);}
$late->status='partial-shipped';check((bool)thean_issue_detect_order_overdue($late, [], $clock), 'partial delivery is not finished');
$late->status='shipping';$late->meta[$fields['shipper'][0]]=['ghtk'];
check(!thean_issue_detect_ghtk_overdue($late, [], $clock), 'missing handoff date never guessed');
$late->meta[$fields['dispatch'][0]]='2030-01-01';$handoff=thean_issue_dispatch_at($late);
check($handoff===strtotime('2030-01-01 00:00:00 +0700'), 'handoff date interpreted in site timezone');
check(!thean_issue_detect_ghtk_overdue($late, [], $handoff+7*DAY_IN_SECONDS), 'GHTK exact 7-day boundary');
check((bool)thean_issue_detect_ghtk_overdue($late, [], $handoff+7*DAY_IN_SECONDS+1), 'GHTK clock starts at handoff, not order date');
$late->status='packaging';check(!thean_issue_detect_ghtk_overdue($late, [], $handoff+8*DAY_IN_SECONDS), 'selected carrier/date alone is not handoff');
$late->status='shipping';$late->meta[$fields['shipper'][0]]=['viettel'];check(!thean_issue_detect_ghtk_overdue($late, [], $handoff+8*DAY_IN_SECONDS), 'non-GHTK carrier excluded');
$late->meta[$fields['shipper'][0]]=['ghtk'];$late->meta[$fields['dispatch'][0]]='2030-02-31';check(!thean_issue_dispatch_at($late), 'invalid dates rejected');
$warnings=[['type'=>'order_overdue','status'=>'open','mode'=>'warn'],['type'=>'ghtk_overdue','status'=>'open','mode'=>'warn']];
check(thean_issue_primary_group($warnings)==='ghtk_overdue', 'specific GHTK issue has primary priority');
$warnings[]=['type'=>'manual','status'=>'open','mode'=>'resolve'];check(thean_issue_primary_group($warnings)==='manual', 'blocking incident sorts before warnings');
$_POST=['issue_filter'=>'order_overdue'];check(thean_issue_view_group($warnings)==='order_overdue', 'specific filter groups under the chosen issue type');$_POST=[];
check(thean_issue_primary_group([['type'=>'manual','status'=>'resolved','mode'=>'resolve']])==='', 'resolved incidents leave grouped flow');
check(!thean_issue_blocks(['type'=>'ghtk_overdue','status'=>'open','mode'=>'warn']), 'overdue warning does not block remediation');
check(isset(thean_issue_groups()['incident_order_overdue'],thean_issue_groups()['incident_ghtk_overdue']), 'registered groups include new types');
$options['thean_incidents_scan_version']=THEAN_ISSUE_SCAN_VERSION;$options['thean_incidents_cursor_version']=THEAN_ISSUE_SCAN_VERSION;
$wpdb->queries=[];$scheduled=[];$hooks['thean_order_issues_scan'][0]();
check(count($scheduled)===1&&$scheduled[0][1]==='thean_order_issues_scan'&&$scheduled[0][0]>=time()+3599,'completed sweep schedules next hourly check');
check($options['thean_incidents_scan_cursor']===0,'completed sweep resets cursor for passage-of-time detection');
check(str_contains(implode(' ', $wpdb->queries),'wc-completed')&&str_contains(implode(' ', $wpdb->queries),'LIMIT 20'),'recurring sweep is bounded and omits finished deliveries');
check(str_contains(end($wpdb->queries),'RELEASE_LOCK'),'scan cursor protected by released advisory lock');
// Real workspace endpoint must stop both confirmation paths before changing confirmation meta.
require dirname(__DIR__) . '/custom-functions/woocommerce/orders/order-workspace.php';
foreach (['cod_confirm','preorder_confirm'] as $task) {
    $_POST=['nonce'=>'valid','action'=>'thean_order_workspace','order_id'=>1,'task'=>$task,'result'=>'confirmed'];
    try { thean_ow_ajax(); throw new RuntimeException('confirmation bypassed'); }
    catch (JsonResult $response) { check(!$response->success && str_contains($response->payload['message'],'sự cố đang chặn'), 'workspace confirms only after incident review: '.$task); }
}
// Shared source and loader evidence: no drift, no unregistered AJAX/cron dependency.
$root=dirname(__DIR__);$sibling=dirname($root).'/'.(basename($root)==='hithean-roneous-child-theme'?'theanmarket-wr-nitro-child-theme':'hithean-roneous-child-theme');
foreach(['order-issues.php','assets/order-issues.js','assets/order-issues.css']as$file)if(is_file($sibling.'/custom-functions/woocommerce/orders/'.$file))check(file_get_contents($root.'/custom-functions/woocommerce/orders/'.$file)===file_get_contents($sibling.'/custom-functions/woocommerce/orders/'.$file),'shared parity '.$file);
check(str_contains(file_get_contents($root.'/custom-functions/core/module-loader.php'),"woocommerce/orders/order-issues.php'"),'always-loaded module serves AJAX/cron');
echo "OK ($checks incident checks)\n";
