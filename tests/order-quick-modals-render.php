<?php
// Stub WP tối thiểu để in HTML 4 popup thao tác nhanh — dùng bởi tests/order-quick-modals-ui.cjs.
define('ABSPATH', '/');
function add_action(...$a) {} function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES);} function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function current_user_can($c){return true;} function admin_url($p=''){return '/wp-admin/'.$p;} function wp_create_nonce($n){return 'n_'.$n;}
function wp_json_encode($v){return json_encode($v, JSON_UNESCAPED_UNICODE);} function get_option($k,$d=null){return $k==='woocommerce_bacs_accounts'?[['bank_name'=>'ACB','account_number'=>'8700507']]:$d;}
function hithean_order_admin_is_list_screen(){return true;} function hithean_order_admin_export_checklist(){return ['Đủ <b>SP</b>','Số lượng'];}
function hithean_payment_bank_accounts(){return ['ACB 51364767','VietinBank 113600098383'];}
require __DIR__ . '/../custom-functions/woocommerce/orders/order-quick-modals.php';
thean_qm_render();
