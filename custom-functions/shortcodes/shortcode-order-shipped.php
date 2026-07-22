<?php
if (!defined('ABSPATH')) exit;
/**
 * CONFIGURATION & HELPERS - HITHEAN VERSION
 */

// 1. Cấu hình danh sách Shipper khớp với Meta Box của Hithean
function ost_get_shippers() {
    // Key => Label (Lưu ý: DB lưu Key)
    return [
        'Giao Hang Tiet Kiem' => 'Giao Hang Tiet Kiem',
        'Ahamove'             => 'Ahamove',
        'Nhat Tin Logistics'  => 'Nhat Tin Logistics',
        'Viettel Post'        => 'Viettel Post',
		'self'				  => 'THEAN',
		'ghtk' => 'Giao Hang Tiet Kiem',
		'ahamove' => 'Ahamove',
		'viettel' => 'Viettel Post',		
    ];
}

// Lấy nhãn hiển thị cho phương thức thanh toán
function ost_get_payment_label($method) {
    $method = strtoupper($method);
    if ($method === 'COD') return 'COD';
    if ($method === 'BACS') return 'BACS';
    return ucfirst(strtolower($method));
}

// Xử lý link ảnh từ meta data
function ost_get_order_images($meta_value) {
    $image_urls = [];
    if (is_array($meta_value)) {
        $image_urls = $meta_value;
    } elseif (is_string($meta_value) && !empty($meta_value)) {
        $image_urls = array_filter(array_map('trim', explode("\n", $meta_value)));
    }
    return $image_urls;
}

// Render HTML hình ảnh (Lazyload & EIO attributes)
function ost_render_image_thumbs($image_urls) {
    if (empty($image_urls)) return '-';
    $html = '';
    $safe_urls = array_values(array_map('esc_url_raw', $image_urls));
    $urls_json = esc_attr(wp_json_encode($safe_urls));
    foreach ($safe_urls as $index => $url) {
        $u = esc_url($url);
        $img_tag = sprintf(
            '<img decoding="async" src="%1$s" style="max-width:80px;height:auto;border:1px solid #ddd;border-radius:4px;" data-eio="p" data-src="%1$s" class="lazyloaded scaled-image" width="1000" height="750" data-eio-rwidth="1000" data-eio-rheight="750" title="">',
            $u
        );
        $html .= '<a href="' . $u . '" class="ost-gallery-link" data-gallery="' . $urls_json . '" data-index="' . esc_attr($index) . '" style="margin:2px; display:inline-block;">' . $img_tag . '</a>';
    }
    $html .= '<br><button type="button" class="ost-dl-all-btn" data-urls="' . $urls_json . '" style="margin-top:4px;font-size:11px;padding:2px 7px;cursor:pointer;">&#8659; Tải tất cả ảnh</button>';
    return $html;
}

// Render danh sách Link ID đơn hàng
function ost_render_order_links($ids) {
    if (empty($ids)) return '0';
    $links = array_map(function($id) {
        return sprintf('<a href="%s" target="_blank">%s</a>', get_edit_post_link($id), $id);
    }, $ids);
    return implode(', ', $links);
}

function ost_is_self_shipper($shipper) {
    $value = strtolower(trim((string) $shipper));
    return in_array($value, ['self', 'thean'], true);
}

function ost_get_reconciliation_columns() {
    return [
        'missing_images' => ['label' => 'Thiếu ảnh', 'hint' => 'Bổ sung ảnh xuất kho trước'],
        'missing_confirmation' => ['label' => 'Chưa xác nhận', 'hint' => 'Xác nhận người kiểm soát'],
        'not_completed' => ['label' => 'Chưa hoàn thành', 'hint' => 'Kiểm tra trạng thái đơn'],
        'abnormal_data' => ['label' => 'Dữ liệu bất thường', 'hint' => 'Kiểm tra shipper, mã vận đơn, ngày'],
        'passed' => ['label' => 'Đạt', 'hint' => 'Không còn lỗi đối soát'],
    ];
}

function ost_get_reconciliation_issues($order, $shipper, $ship_code, $export_date, $images, $confirmed_user_id) {
    $issues = [];
    $shipper_value = trim((string) $shipper);
    $shipper_check = strtolower($shipper_value);
    $ship_code = trim((string) $ship_code);

    if (empty($images)) {
        $issues['missing_images'] = 'Thiếu ảnh xuất kho';
    }

    if (empty($confirmed_user_id)) {
        $issues['missing_confirmation'] = 'Chưa xác nhận xuất kho';
    }

    if (($shipper_check === '' || $shipper_check === 'ahamove' || ost_is_self_shipper($shipper_check)) && $order->get_status() !== 'completed') {
        $issues['not_completed'] = 'Đơn chưa hoàn thành';
    }

    if ($shipper_value === '') {
        $issues['abnormal_data'] = 'Thiếu shipper';
    } elseif (!ost_is_self_shipper($shipper_value) && $ship_code === '') {
        $issues['abnormal_data'] = 'Thiếu mã vận đơn';
    } elseif ($export_date === 'N/A' || !strtotime((string) $export_date)) {
        $issues['abnormal_data'] = 'Ngày xuất kho không hợp lệ';
    }

    return $issues;
}

function ost_get_reconciliation_bucket($issues) {
    foreach (['missing_images', 'missing_confirmation', 'not_completed', 'abnormal_data'] as $bucket) {
        if (isset($issues[$bucket])) return $bucket;
    }
    return 'passed';
}

function ost_init_reconciliation_stats() {
    $stats = [
        'total' => 0,
        'completion_rate' => 0,
        'by_status' => [],
        'by_shipper' => [],
    ];

    foreach (ost_get_reconciliation_columns() as $key => $column) {
        $stats['by_status'][$key] = 0;
    }

    return $stats;
}

function ost_render_issue_badges($issues) {
    if (empty($issues)) {
        return '<span class="ost-status-chip ost-status-passed">Đạt</span>';
    }

    $html = '';
    foreach ($issues as $key => $label) {
        $html .= '<span class="ost-status-chip ost-status-' . esc_attr($key) . '">' . esc_html($label) . '</span>';
    }
    return $html;
}

// Phân giải từ khóa tìm kiếm thành danh sách order IDs
function ost_resolve_search_to_order_ids($search) {
    $token = trim($search);
    if ($token === '') return [];

    // Email
    if (strpos($token, '@') !== false) {
        return wc_get_orders([
            'billing_email' => sanitize_email($token),
            'limit'  => 100,
            'return' => 'ids',
        ]) ?: [];
    }

    $digits = preg_replace('/\D/', '', $token);

    // Phone (9+ digits) — thử trước khi vào order ID
    if (strlen($digits) >= 9) {
        $found = wc_get_orders([
            'billing_phone' => $token,
            'limit'  => 100,
            'return' => 'ids',
        ]);
        if (!empty($found)) return $found;
    }

    // Order ID — normalize giống resolve_order_from_search_code() trong bank-transfer:
    // strip "#", prefix "P0"/"P1"; thử số gốc, bỏ 1 chữ (suffix 1 digit), bỏ 2 chữ (suffix 2 digits)
    $code = strtoupper($token);
    $code = str_replace('#', '', $code);
    if (strpos($code, 'P0') === 0 || strpos($code, 'P1') === 0) {
        $code = substr($code, 2);
    }
    $normalized = preg_replace('/\D+/', '', $code);

    if ($normalized === '') return [];

    // Thử: số gốc, bỏ 1 chữ số cuối (suffix 1 digit), bỏ 2 chữ số cuối (suffix 2 digits)
    $attempts = [$normalized];
    if (strlen($normalized) > 1) $attempts[] = substr($normalized, 0, -1);
    if (strlen($normalized) > 2) $attempts[] = substr($normalized, 0, -2);

    foreach ($attempts as $candidate) {
        if (!is_numeric($candidate) || (int) $candidate <= 0) continue;
        $order = wc_get_order((int) $candidate);
        if ($order) return [$order->get_id()];
    }

    return [];
}

/**
 * CORE LOGIC - HITHEAN VERSION
 * Mapping lại các Meta Key
 */

function ost_get_orders_data($from_date, $to_date, $filter_shipper, $search_ids = null) {
    global $wpdb;

    // --- MAPPING META KEYS (Cấu hình Hithean) ---
    $key_ship_date = 'order_ship_date';
    $key_shipper   = 'order_shipper';
    $key_ship_code = 'order_ship_code';
    $key_export_by = 'order_export_by';
    $key_paid_date = 'order_paid_date';
    $key_bank_acc  = 'order_bank_account_received';
    $key_handling  = 'order_handling_status';
    $key_images    = 'warehouse_export_images';
    $key_confirm   = 'export_confirmed_by';
    // ---------------------------------------------

    $dates_map = [];

    // 1. Query ID & Ngày giao hàng
    if ($search_ids !== null) {
        if (empty($search_ids)) return ['status' => 'empty'];
        $order_ids = array_map('intval', $search_ids);

        if ($from_date && $to_date) {
            $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
            $date_results = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value as ship_date FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value BETWEEN %s AND %s
                 AND post_id IN ($placeholders)",
                $key_ship_date, $from_date, $to_date, ...$order_ids
            ));
            $order_ids = [];
            foreach ($date_results as $row) {
                $order_ids[] = $row->post_id;
                $dates_map[$row->post_id] = $row->ship_date;
            }
        }

        if (empty($order_ids)) return ['status' => 'empty'];
    } else {
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value as ship_date
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value BETWEEN %s AND %s
             LIMIT 500",
            $key_ship_date, $from_date, $to_date
        ));

        if (empty($results)) {
            return ['status' => 'empty'];
        }

        $order_ids = [];
        foreach ($results as $row) {
            $order_ids[] = $row->post_id;
            $dates_map[$row->post_id] = $row->ship_date;
        }
    }

    // 2. Bulk Meta Query
    $meta_keys_to_fetch = [
        $key_shipper,
        $key_images,
        $key_confirm,
        $key_export_by,
        $key_ship_code,
        $key_paid_date,
        $key_bank_acc,
        $key_handling,
        $key_ship_date,
    ];
    // Chuẩn bị string cho SQL IN clause
    $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
    $keys_in_sql  = "'" . implode("','", array_map('esc_sql', $meta_keys_to_fetch)) . "'";

    $meta_results = $wpdb->get_results($wpdb->prepare("
        SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
        WHERE post_id IN ($placeholders)
        AND meta_key IN ($keys_in_sql)
    ", ...$order_ids));

    $meta_map = [];
    foreach ($meta_results as $meta) {
        $meta_map[$meta->post_id][$meta->meta_key][] = maybe_unserialize($meta->meta_value);
    }

    // 3. Loop & Process
    $summary_data = [];
    $reconciliation_stats = ost_init_reconciliation_stats();
    $kanban_cards = array_fill_keys(array_keys(ost_get_reconciliation_columns()), '');
    $rows_html = '';
    $days_vn = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

    foreach ($order_ids as $id) {
        $order = wc_get_order($id);
        if (!$order) continue;

        $meta = $meta_map[$id] ?? [];
        $shipper_raw = $meta[$key_shipper][0] ?? '';
        $shipper = is_array($shipper_raw) ? implode(', ', $shipper_raw) : $shipper_raw;

        // -- Filter Shipper --
        if ($filter_shipper === '__none' && $shipper !== '') continue;
        if ($filter_shipper && $filter_shipper !== '__none' && stripos($shipper, $filter_shipper) === false) continue;

        // -- Prepare Data --
        $ship_date = $dates_map[$id] ?? ($meta[$key_ship_date][0] ?? 'N/A');
        $report_key = $ship_date . '||' . $shipper;
        $images = ost_get_order_images($meta[$key_images][0] ?? '');
        $confirmed_user_id = $meta[$key_confirm][0] ?? '';
        $ship_code = $meta[$key_ship_code][0] ?? '';
        $issues = ost_get_reconciliation_issues($order, $shipper, $ship_code, $ship_date, $images, $confirmed_user_id);
        $bucket = ost_get_reconciliation_bucket($issues);
        
        // -- Calculate Report --
        if (!isset($summary_data[$report_key])) {
            $summary_data[$report_key] = [
                'date' => $ship_date,
                'shipper' => $shipper ?: 'Không có',
                'total' => 0,
                'dow' => ($ship_date !== 'N/A' && strtotime($ship_date)) ? $days_vn[date('w', strtotime($ship_date))] : '-',
                'no_img_list' => [],
                'no_confirm_list' => [],
                'not_completed_list' => [],
                'abnormal_list' => []
            ];
        }
        $summary_data[$report_key]['total']++;
        $reconciliation_stats['total']++;
        $reconciliation_stats['by_status'][$bucket]++;
        $shipper_label = $shipper ?: 'Không có';
        if (!isset($reconciliation_stats['by_shipper'][$shipper_label])) {
            $reconciliation_stats['by_shipper'][$shipper_label] = 0;
        }
        $reconciliation_stats['by_shipper'][$shipper_label]++;

        if (empty($images)) $summary_data[$report_key]['no_img_list'][] = $id;
        if (empty($confirmed_user_id)) $summary_data[$report_key]['no_confirm_list'][] = $id;
        if (isset($issues['not_completed'])) $summary_data[$report_key]['not_completed_list'][] = $id;
        if (isset($issues['abnormal_data'])) $summary_data[$report_key]['abnormal_list'][] = $id;

        // Dữ liệu cho row html
        $row_data = [
            'id' => $id,
            'order' => $order,
            'ship_date' => $ship_date,
            'shipper' => $shipper,
            'ship_code' => $ship_code,
            'export_by' => $meta[$key_export_by][0] ?? '',
            'paid_date' => $meta[$key_paid_date][0] ?? '',
            'bank_acc' => $meta[$key_bank_acc][0] ?? '',
            'handling' => $meta[$key_handling][0] ?? '',
            'images' => $images,
            'confirmed_user_id' => $confirmed_user_id,
            'issues' => $issues,
            'bucket' => $bucket
        ];

        // -- Render Row --
        ob_start();
        ost_render_single_row($row_data);
        $rows_html .= ob_get_clean();

        ob_start();
        ost_render_kanban_card($row_data);
        $kanban_cards[$bucket] .= ob_get_clean();
    }

    if ($reconciliation_stats['total'] > 0) {
        $reconciliation_stats['completion_rate'] = round(($reconciliation_stats['by_status']['passed'] / $reconciliation_stats['total']) * 100);
    }

    return [
        'status' => 'success',
        'summary' => $summary_data,
        'reconciliation_stats' => $reconciliation_stats,
        'kanban_cards' => $kanban_cards,
        'rows_html' => $rows_html
    ];
}

/**
 * VIEW RENDERING
 */

// Render 1 dòng chi tiết
function ost_render_single_row($data) {
    $order = $data['order'];
    $user_id = $order->get_user_id();
    $role = $user_id ? implode(', ', get_userdata($user_id)->roles) : 'Khách';
    $confirmed_user = $data['confirmed_user_id'] ? get_userdata($data['confirmed_user_id']) : null;
    
    // Xử lý hiển thị mảng (ví dụ export_by hoặc handling là mảng)
    $export_by_display = is_array($data['export_by']) ? implode(', ', $data['export_by']) : $data['export_by'];
    $handling_display = is_array($data['handling']) ? implode(', ', $data['handling']) : $data['handling'];

    ?>
    <tr class="ost-detail-row ost-row-<?php echo esc_attr($data['bucket']); ?>" data-bucket="<?php echo esc_attr($data['bucket']); ?>">
        <td><a href="<?php echo esc_url(get_edit_post_link($data['id'])); ?>" target="_blank">#<?php echo $data['id']; ?></a></td>
        <td class="ost-status-cell"><?php echo ost_render_issue_badges($data['issues']); ?></td>
        <td><?php echo esc_html($order->get_billing_phone()); ?></td>
        <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
        <td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
        <td><?php echo esc_html($handling_display); ?></td>
        <td><?php echo esc_html($order->get_date_created()->date('Y-m-d')); ?></td>
        <td><?php echo esc_html($data['shipper']); ?></td>
        <td><?php echo esc_html($data['ship_code']); ?></td>
        <td><?php echo esc_html($data['ship_date']); ?></td>
        <td><?php echo esc_html($export_by_display); ?></td>
        <td><?php echo esc_html($data['paid_date']); ?></td>
        <td><?php echo esc_html($data['bank_acc']); ?></td>
        <td><?php echo ost_get_payment_label($order->get_payment_method()); ?></td>
        <td><?php echo esc_html($role); ?></td>
        <td><?php echo ost_render_image_thumbs($data['images']); ?></td>
        <td><?php echo $confirmed_user ? esc_html($confirmed_user->display_name) : '-'; ?></td>
    </tr>
    <?php
}

function ost_render_kanban_card($data) {
    $order = $data['order'];
    $image_count = count($data['images']);
    $safe_urls = array_values(array_map('esc_url_raw', $data['images']));
    $urls_json = esc_attr(wp_json_encode($safe_urls));
    ?>
    <article class="ost-kanban-card ost-card-<?php echo esc_attr($data['bucket']); ?>">
        <div class="ost-card-head">
            <a href="<?php echo esc_url(get_edit_post_link($data['id'])); ?>" target="_blank" class="ost-card-order">#<?php echo esc_html($data['id']); ?></a>
            <span><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
        </div>
        <div class="ost-card-meta">
            <span><?php echo esc_html($data['shipper'] ?: 'Không có shipper'); ?></span>
            <span><?php echo esc_html($data['ship_code'] ?: 'Chưa có mã vận đơn'); ?></span>
            <span><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span>
            <span><?php echo esc_html(ost_get_payment_label($order->get_payment_method())); ?></span>
        </div>
        <div class="ost-card-issues"><?php echo ost_render_issue_badges($data['issues']); ?></div>
        <div class="ost-card-actions">
            <a href="<?php echo esc_url(get_edit_post_link($data['id'])); ?>" target="_blank">Mở đơn</a>
            <?php if ($image_count > 0): ?>
                <a href="<?php echo esc_url($safe_urls[0]); ?>" class="ost-gallery-link" data-gallery="<?php echo $urls_json; ?>" data-index="0"><?php echo esc_html($image_count); ?> ảnh</a>
            <?php else: ?>
                <span>0 ảnh</span>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

function ost_render_reconciliation_workspace($stats, $kanban_cards) {
    $columns = ost_get_reconciliation_columns();
    $remaining = max(0, (int) $stats['total'] - (int) $stats['by_status']['passed']);
    ob_start();
    ?>
    <section class="ost-workspace">
        <div class="ost-hero">
            <div>
                <h3>Kiểm soát xuất kho trong ngày</h3>
                <p><?php echo $remaining > 0 ? 'Xử lý lần lượt các nhóm lỗi từ trái sang phải.' : 'Ngày này đã hoàn tất đối soát xuất kho.'; ?></p>
            </div>
            <div class="ost-progress">
                <strong><?php echo esc_html($stats['completion_rate']); ?>%</strong>
                <span>Hoàn tất</span>
            </div>
        </div>

        <div class="ost-summary-grid">
            <div class="ost-summary-card ost-card-total"><span>Tổng đơn</span><strong><?php echo esc_html($stats['total']); ?></strong></div>
            <?php foreach ($columns as $key => $column): ?>
                <div class="ost-summary-card ost-card-<?php echo esc_attr($key); ?>">
                    <span><?php echo esc_html($column['label']); ?></span>
                    <strong><?php echo esc_html($stats['by_status'][$key] ?? 0); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="ost-flow">
            <div class="ost-flow-step"><b>1</b><span>Kiểm tra thiếu ảnh</span></div>
            <div class="ost-flow-step"><b>2</b><span>Xác nhận xuất kho</span></div>
            <div class="ost-flow-step"><b>3</b><span>Hoàn thành đơn cần hoàn thành</span></div>
            <div class="ost-flow-step"><b>4</b><span>Sửa dữ liệu bất thường</span></div>
            <div class="ost-flow-step"><b>5</b><span>Kiểm tra nhóm đạt</span></div>
        </div>

        <div class="ost-shipper-strip">
            <?php foreach ($stats['by_shipper'] as $shipper => $count): ?>
                <span><b><?php echo esc_html($shipper); ?></b> <?php echo esc_html($count); ?> đơn</span>
            <?php endforeach; ?>
        </div>

        <div class="ost-kanban">
            <?php foreach ($columns as $key => $column): ?>
                <section class="ost-kanban-column ost-column-<?php echo esc_attr($key); ?>">
                    <header>
                        <div>
                            <h4><?php echo esc_html($column['label']); ?></h4>
                            <p><?php echo esc_html($column['hint']); ?></p>
                        </div>
                        <strong><?php echo esc_html($stats['by_status'][$key] ?? 0); ?></strong>
                    </header>
                    <div class="ost-kanban-list">
                        <?php echo $kanban_cards[$key] ?: '<div class="ost-empty">Không có đơn</div>'; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

// Render Bảng Báo Cáo
function ost_render_report_table($summary_data) {
    if (empty($summary_data)) return '';
    $site_host = parse_url(home_url(), PHP_URL_HOST);
    ob_start();
    ?>
    <div class="report-box">
        <h3 style="margin-top:0;">Tổng hợp theo ngày / shipper (Hithean)</h3>
        <table class="nitro-table">
            <thead>
                <tr>
                    <th>Ngày xuất kho</th>
                    <th>Thứ</th>
                    <th>Shipper</th>
                    <th>Tổng số đơn</th>
                    <th>Website</th>
                    <th>DS đơn chưa có ảnh</th>
                    <th>DS đơn chưa xác nhận</th>
                    <th>DS đơn chưa hoàn thành</th>
                    <th>DS đơn dữ liệu bất thường</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary_data as $row): ?>
                <tr>
                    <td class="rp-date"><?php echo esc_html($row['date']); ?></td>
                    <td class="rp-dow"><?php echo esc_html($row['dow']); ?></td>
                    <td class="rp-shipper"><?php echo esc_html($row['shipper']); ?></td>
                    <td class="rp-total" style="font-weight:bold; color:blue;"><?php echo esc_html($row['total']); ?></td>
                    <td class="rp-web"><?php echo esc_html($site_host); ?></td>
                    <td class="rp-list-col rp-no-img"><?php echo ost_render_order_links($row['no_img_list']); ?></td>
                    <td class="rp-list-col rp-no-confirm"><?php echo ost_render_order_links($row['no_confirm_list']); ?></td>
                    <td class="rp-list-col rp-not-completed"><?php echo ost_render_order_links($row['not_completed_list']); ?></td>
                    <td class="rp-list-col rp-abnormal"><?php echo ost_render_order_links($row['abnormal_list'] ?? []); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * MAIN SHORTCODE & AJAX HANDLER
 */

add_shortcode('order_shipped_table', function () {
    if (!current_user_can('manage_woocommerce') && !current_user_can('administrator')) {
        return "";
    }
    ob_start();
    ?>
    <style>
        .nitro-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px; background: #fff; border-radius: 5px; }
        .nitro-table th, .nitro-table td { border: 1px solid #ddd; padding: 8px 10px; text-align: center; vertical-align: middle; }
        .nitro-table th { background-color: #f2f2f2; font-weight: bold; text-transform: uppercase; color: #333; }
        .nitro-table tbody tr:nth-child(even) { background-color: #f9f9f9; }
        .nitro-table tbody tr:hover { background-color: #e6f7ff; }
        .scroll-container { width: 100%; overflow-x: auto; border: 1px solid #ddd; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .report-box { border-radius: 5px; margin-bottom: 40px !important;}
        .ost-workspace { margin: 0 0 24px; color: #1f2937; }
        .ost-hero { display: flex; justify-content: space-between; gap: 16px; align-items: center; padding: 18px 20px; background: #fff; border: 1px solid #e5e7eb; border-left: 5px solid #1976d2; border-radius: 8px; box-shadow: 0 1px 4px rgba(15,23,42,0.08); }
        .ost-hero h3 { margin: 0 0 4px; font-size: 20px; color: #111827; }
        .ost-hero p { margin: 0; color: #6b7280; }
        .ost-progress { min-width: 96px; text-align: center; padding: 10px 12px; border-radius: 8px; background: #e3f2fd; color: #0d47a1; }
        .ost-progress strong { display: block; font-size: 24px; line-height: 1; }
        .ost-progress span { display: block; margin-top: 4px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .ost-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 12px 0; }
        .ost-summary-card { min-height: 78px; padding: 12px; border-radius: 8px; background: #fff; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(15,23,42,0.06); }
        .ost-summary-card span { display: block; color: #6b7280; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .ost-summary-card strong { display: block; margin-top: 8px; font-size: 28px; color: #111827; }
        .ost-card-missing_images { border-left: 4px solid #d32f2f; }
        .ost-card-missing_confirmation { border-left: 4px solid #f57c00; }
        .ost-card-not_completed { border-left: 4px solid #7b1fa2; }
        .ost-card-abnormal_data { border-left: 4px solid #455a64; }
        .ost-card-passed { border-left: 4px solid #2e7d32; }
        .ost-flow { display: grid; grid-template-columns: repeat(5, minmax(130px, 1fr)); gap: 8px; margin: 12px 0; }
        .ost-flow-step { display: flex; align-items: center; gap: 8px; padding: 10px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; font-weight: 600; }
        .ost-flow-step b { display: inline-flex; width: 24px; height: 24px; align-items: center; justify-content: center; border-radius: 50%; background: #1976d2; color: #fff; flex: 0 0 24px; }
        .ost-shipper-strip { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
        .ost-shipper-strip span { padding: 6px 10px; border-radius: 999px; background: #f3f4f6; border: 1px solid #e5e7eb; font-size: 12px; }
        .ost-kanban { display: grid; grid-template-columns: repeat(5, minmax(220px, 1fr)); gap: 12px; overflow-x: auto; padding-bottom: 8px; }
        .ost-kanban-column { min-width: 220px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; }
        .ost-kanban-column header { display: flex; justify-content: space-between; gap: 10px; padding: 12px; border-bottom: 1px solid #e5e7eb; background: #fff; border-radius: 8px 8px 0 0; }
        .ost-kanban-column h4 { margin: 0; font-size: 14px; color: #111827; }
        .ost-kanban-column p { margin: 3px 0 0; font-size: 12px; color: #6b7280; }
        .ost-kanban-column header strong { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 30px; border-radius: 50%; background: #e5e7eb; color: #111827; }
        .ost-kanban-list { display: grid; gap: 8px; padding: 10px; max-height: 520px; overflow-y: auto; }
        .ost-kanban-card { padding: 10px; border-radius: 8px; background: #fff; border: 1px solid #e5e7eb; box-shadow: 0 1px 2px rgba(15,23,42,0.06); }
        .ost-card-head, .ost-card-actions { display: flex; justify-content: space-between; gap: 8px; align-items: center; }
        .ost-card-order { font-weight: 800; text-decoration: none; color: #1976d2; }
        .ost-card-meta { display: grid; gap: 4px; margin: 8px 0; color: #4b5563; font-size: 12px; }
        .ost-card-issues { display: flex; flex-wrap: wrap; gap: 4px; }
        .ost-card-actions { margin-top: 8px; font-size: 12px; color: #6b7280; }
        .ost-empty { padding: 12px; border: 1px dashed #cbd5e1; border-radius: 8px; color: #64748b; text-align: center; background: #fff; }
        .ost-status-cell { min-width: 160px; }
        .ost-status-chip { display: inline-block; margin: 2px; padding: 3px 7px; border-radius: 999px; font-size: 11px; font-weight: 700; line-height: 1.3; white-space: nowrap; }
        .ost-status-missing_images { background: #ffebee; color: #b71c1c; }
        .ost-status-missing_confirmation { background: #fff3e0; color: #e65100; }
        .ost-status-not_completed { background: #f3e5f5; color: #4a148c; }
        .ost-status-abnormal_data { background: #eceff1; color: #263238; }
        .ost-status-passed { background: #e8f5e9; color: #1b5e20; }
        .ost-detail-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 10px; }
        .ost-detail-tab { border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 999px; padding: 7px 12px; cursor: pointer; font-size: 13px; font-weight: 700; }
        .ost-detail-tab.is-active { background: #1976d2; border-color: #1976d2; color: #fff; }
        @media (max-width: 900px) {
            .ost-hero { align-items: flex-start; flex-direction: column; }
            .ost-flow { grid-template-columns: 1fr; }
            .ost-kanban { grid-template-columns: repeat(5, 240px); }
        }
        .rp-list-col { max-width: 250px; word-break: break-word; font-size: 11px; line-height: 1.5; }
        .rp-list-col a { color: #c0392b; font-weight: 500; text-decoration: none; }
        .rp-list-col a:hover { text-decoration: underline; color: #e74c3c; }
        .ost-gallery-link { cursor: zoom-in; }
        .ost-gallery-modal { position: fixed; inset: 0; z-index: 999999; display: none; align-items: center; justify-content: center; background: rgba(17, 24, 39, 0.88); padding: 18px; }
        .ost-gallery-modal.is-open { display: flex; }
        .ost-gallery-dialog { position: relative; width: min(1100px, 96vw); height: min(760px, 90vh); display: grid; grid-template-rows: auto 1fr auto; gap: 10px; }
        .ost-gallery-header { display: flex; align-items: center; justify-content: space-between; color: #fff; font-size: 14px; font-weight: 600; }
        .ost-gallery-close, .ost-gallery-prev, .ost-gallery-next { border: 0; border-radius: 4px; background: rgba(255,255,255,0.92); color: #111827; cursor: pointer; font-size: 22px; line-height: 1; width: 42px; height: 42px; }
        .ost-gallery-close { font-size: 28px; }
        .ost-gallery-stage { position: relative; min-height: 0; display: flex; align-items: center; justify-content: center; }
        .ost-gallery-stage img { max-width: 100%; max-height: 100%; object-fit: contain; background: #fff; border-radius: 4px; box-shadow: 0 18px 50px rgba(0,0,0,0.35); }
        .ost-gallery-prev, .ost-gallery-next { position: absolute; top: 50%; transform: translateY(-50%); }
        .ost-gallery-prev { left: 10px; }
        .ost-gallery-next { right: 10px; }
        .ost-gallery-footer { text-align: center; }
        .ost-gallery-open-new { color: #fff !important; font-size: 13px; text-decoration: underline; }
    </style>

    <h2>Đơn Xuất Kho (Hithean)</h2>
    <form id="order-filter-form" style="margin-bottom: 20px;display: flex;flex-wrap: wrap;gap: 12px 20px;align-items: center;border: 1px solid #2ecc71;padding: 10px;border-radius: 5px; background: #fff;">
        <div style="width:100%;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <label><strong>Tìm theo ID / SĐT / Email:</strong></label>
            <input type="text" name="filter_search" placeholder="VD: 12345 hoặc 0912345678 hoặc email@..." style="flex:1;min-width:240px;" />
            <small style="color:#888;font-style:italic;">Nếu có từ khóa, không cần chọn ngày.</small>
        </div>
        <div style="width:100%;border-top:1px dashed #ccc;padding-top:10px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <label><strong>Ngày xuất kho:</strong></label>
            Từ <input type="date" name="filter_export_date_from" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>" />
            đến <input type="date" name="filter_export_date_to" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>" />

            <label><strong>Shipper:</strong></label>
            <select name="filter_shipper" style="width: auto;">
                <option value="">-- Tất cả --</option>
                <option value="__none">-- Không có --</option>
                <?php foreach (ost_get_shippers() as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button--green" style="cursor:pointer;">Lọc đơn hàng</button>
        </div>
    </form>

    <div id="order-results-container">
        <div id="report-summary-box"></div>
        <div id="order-results">
            <p style="padding:10px;border:1px dashed #ccc;border-radius:4px;">Vui lòng chọn khoảng ngày và nhấn "Lọc đơn hàng" để xem dữ liệu.</p>
        </div>
    </div>

    <div id="ost-gallery-modal" class="ost-gallery-modal" aria-hidden="true">
        <div class="ost-gallery-dialog" role="dialog" aria-modal="true" aria-label="Xem ảnh lấy hàng">
            <div class="ost-gallery-header">
                <span id="ost-gallery-counter"></span>
                <button type="button" class="ost-gallery-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="ost-gallery-stage">
                <button type="button" class="ost-gallery-prev" aria-label="Ảnh trước">&#8249;</button>
                <img id="ost-gallery-image" src="" alt="Ảnh lấy hàng">
                <button type="button" class="ost-gallery-next" aria-label="Ảnh kế tiếp">&#8250;</button>
            </div>
            <div class="ost-gallery-footer">
                <a id="ost-gallery-open-new" class="ost-gallery-open-new" href="#" target="_blank" rel="noopener">Mở ảnh gốc</a>
            </div>
        </div>
    </div>

    <script>
        var ostNonce = "<?php echo esc_js(wp_create_nonce('ajax_load_order_shipped_nonce')); ?>";
        jQuery(function($) {
            const form = $('#order-filter-form');
            const resultBox = $('#order-results');
            const reportBox = $('#report-summary-box');
            const galleryModal = $('#ost-gallery-modal');
            const galleryImage = $('#ost-gallery-image');
            const galleryCounter = $('#ost-gallery-counter');
            const galleryOpenNew = $('#ost-gallery-open-new');
            let galleryUrls = [];
            let galleryIndex = 0;
            let xhr;

            function showGalleryImage() {
                if (!galleryUrls.length) return;
                galleryIndex = (galleryIndex + galleryUrls.length) % galleryUrls.length;
                galleryImage.attr('src', galleryUrls[galleryIndex]);
                galleryOpenNew.attr('href', galleryUrls[galleryIndex]);
                galleryCounter.text((galleryIndex + 1) + ' / ' + galleryUrls.length);
            }

            function openGallery(urls, index) {
                galleryUrls = urls.filter(Boolean);
                galleryIndex = Number.isFinite(index) ? index : 0;
                showGalleryImage();
                galleryModal.addClass('is-open').attr('aria-hidden', 'false');
            }

            function closeGallery() {
                galleryModal.removeClass('is-open').attr('aria-hidden', 'true');
                galleryImage.attr('src', '');
            }

            $('#order-results-container').on('click', '.ost-gallery-link', function(e) {
                e.preventDefault();
                var urls = [];
                try { urls = JSON.parse($(this).attr('data-gallery') || '[]'); } catch(err) {}
                openGallery(urls.length ? urls : [$(this).attr('href')], parseInt($(this).attr('data-index'), 10) || 0);
            });

            galleryModal.on('click', function(e) {
                if (e.target === this) closeGallery();
            });
            galleryModal.on('click', '.ost-gallery-close', closeGallery);
            galleryModal.on('click', '.ost-gallery-prev', function() {
                galleryIndex--;
                showGalleryImage();
            });
            galleryModal.on('click', '.ost-gallery-next', function() {
                galleryIndex++;
                showGalleryImage();
            });
            $(document).on('keydown', function(e) {
                if (!galleryModal.hasClass('is-open')) return;
                if (e.key === 'Escape') closeGallery();
                if (e.key === 'ArrowLeft') {
                    galleryIndex--;
                    showGalleryImage();
                }
                if (e.key === 'ArrowRight') {
                    galleryIndex++;
                    showGalleryImage();
                }
            });

            $('#order-results-container').on('click', '.ost-dl-all-btn', function() {
                var urls = [];
                try { urls = JSON.parse($(this).attr('data-urls') || '[]'); } catch(e) {}
                urls.forEach(function(url, i) {
                    setTimeout(function() {
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = '';
                        a.target = '_blank';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    }, i * 400);
                });
            });

            $('#order-results-container').on('click', '.ost-detail-tab', function() {
                const bucket = $(this).data('bucket');
                $('.ost-detail-tab').removeClass('is-active');
                $(this).addClass('is-active');
                $('.ost-detail-row').each(function() {
                    const rowBucket = $(this).data('bucket');
                    const shouldShow = bucket === 'all' || rowBucket === bucket || (bucket === 'action' && rowBucket !== 'passed');
                    $(this).toggle(shouldShow);
                });
            });

            form.on('submit', function(e) {
                e.preventDefault();
                const params = {};
                form.serializeArray().forEach(d => params[d.name] = d.value);

                const hasSearch = (params.filter_search || '').trim() !== '';
                const hasDates = params.filter_export_date_from && params.filter_export_date_to;
                if (!hasSearch && !hasDates) {
                    resultBox.html('<p style="color:red;">Vui lòng nhập từ khóa tìm kiếm hoặc chọn khoảng ngày!</p>');
                    return;
                }

                if (xhr) xhr.abort();
                resultBox.html('<p>Đang tải dữ liệu...</p>');
                reportBox.html('');

                xhr = $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    method: 'POST',
                    data: { action: 'ajax_load_order_shipped', nonce: ostNonce, ...params },
                    success: function(res) {
                        try {
                            const response = JSON.parse(res);
                            reportBox.html(response.report_html);
                            resultBox.html(response.table_html);
                        } catch (e) {
                            resultBox.html(res);
                        }
                    },
                    error: function() {
                        resultBox.html('<p style="color:red;">Lỗi khi tải dữ liệu.</p>');
                    }
                });
            });

            form.trigger('submit');
        });
    </script>
    <?php
    return ob_get_clean();
});

// AJAX Handler
add_action('wp_ajax_ajax_load_order_shipped', 'ajax_load_order_shipped');
function ajax_load_order_shipped() {
    check_ajax_referer('ajax_load_order_shipped_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce') && !current_user_can('administrator')) wp_die();

    $from    = sanitize_text_field($_POST['filter_export_date_from'] ?? '');
    $to      = sanitize_text_field($_POST['filter_export_date_to'] ?? '');
    $shipper = sanitize_text_field($_POST['filter_shipper'] ?? '');
    $search  = sanitize_text_field($_POST['filter_search'] ?? '');

    $search_ids = null;
    if ($search !== '') {
        $search_ids = ost_resolve_search_to_order_ids($search);
        if (empty($search_ids)) {
            echo json_encode(['report_html' => '', 'table_html' => '<p>Không tìm thấy đơn hàng nào khớp với từ khóa tìm kiếm.</p>']);
            wp_die();
        }
    } elseif (!$from || !$to) {
        wp_die('Thiếu dữ liệu ngày.');
    }

    // Gọi hàm xử lý logic chính
    $data = ost_get_orders_data($from, $to, $shipper, $search_ids);

    if ($data['status'] === 'empty') {
        echo json_encode(['report_html' => '', 'table_html' => '<p>Không có đơn hàng nào.</p>']);
        wp_die();
    }

    // Render 2 bảng từ dữ liệu đã xử lý
    $report_html = ost_render_reconciliation_workspace($data['reconciliation_stats'], $data['kanban_cards']);
    $report_html .= ost_render_report_table($data['summary']);
    
    // Bọc bảng chi tiết trong container scroll
    $table_html = '<div class="ost-detail-tabs">
        <button type="button" class="ost-detail-tab is-active" data-bucket="all">Tất cả</button>
        <button type="button" class="ost-detail-tab" data-bucket="action">Cần xử lý</button>
        <button type="button" class="ost-detail-tab" data-bucket="missing_images">Thiếu ảnh</button>
        <button type="button" class="ost-detail-tab" data-bucket="missing_confirmation">Chưa xác nhận</button>
        <button type="button" class="ost-detail-tab" data-bucket="not_completed">Chưa hoàn thành</button>
        <button type="button" class="ost-detail-tab" data-bucket="abnormal_data">Dữ liệu bất thường</button>
        <button type="button" class="ost-detail-tab" data-bucket="passed">Đạt</button>
    </div><div class="scroll-container"><table class="nitro-table" border="1"><thead><tr>
        <th>Đơn hàng</th><th>Đối soát</th><th>SĐT</th><th>Tổng</th><th>Tình trạng</th><th>Nội bộ</th>
        <th>Ngày đặt</th><th>Shipper</th><th>Mã giao vận</th><th>Ngày xuất kho</th>
        <th>Xuất kho bởi</th><th>Ngày thanh toán</th><th>Tài khoản nhận</th><th>Thanh toán</th>
        <th>Vai trò</th><th>Ảnh xuất kho</th><th>Người xác nhận</th>
    </tr></thead><tbody>' . $data['rows_html'] . '</tbody></table></div>';

    echo json_encode([
        'report_html' => $report_html,
        'table_html' => $table_html
    ]);
    wp_die();
}
