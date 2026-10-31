<?php
if (!defined('ABSPATH')) exit;
/**
 * THEAN – Bảng điều phối đơn (trang sửa đơn trong WP Admin)
 *
 * Thay cho metabox "Thông tin bổ sung" + các info-box rời: hiển thị đơn theo
 * luồng xử lý thực tế, mỗi bước là 1 tab (chỉ hiện box của bước đang chọn):
 *   Thanh toán (CK) / Khách xác nhận (COD) → Lấy hàng (soạn hàng) → Xuất kho → Giao / Khách lấy → Hoàn hàng
 * Cột phụ: Thu tiền COD, Khách hàng, HDSD sản phẩm, Chứng từ (+ Hoàn hàng khi chưa có yêu cầu).
 *
 * Port từ theme theanmarket. Khác biệt hithean: meta đơn có tiền tố "order_" (map ở
 * thean_ow_key()), bộ trạng thái đơn của order-status.php (không có "Chờ khách lấy hàng"),
 * QR / TK nhận đọc từ checkout/bank-accounts.php.
 *
 * Thao tác TÁI SỬ DỤNG endpoint của các công cụ /tien-ich-admin (cùng logic, cùng audit):
 *   - confirm_order_payment  (checkout/shortcode-bank-transfer-confirmation.php)
 *   - ajax_upload_images / ajax_confirm_export (shortcodes/shortcode-order-export-confirm.php)
 *   - process_return_order / upload_return_images (shortcodes/shortcode-order-return-manager.php)
 * Riêng cập nhật nhanh field / trạng thái / xác nhận COD dùng action `thean_order_workspace` bên dưới.
 *
 * Không reload trang: sau mỗi thao tác JS gọi task `render` để lấy HTML bảng mới + giá trị
 * field Meta Box / trạng thái WC hiện tại, rồi gán lại vào form sửa đơn — nếu không, bấm
 * "Cập nhật" đơn sẽ ghi đè thay đổi vừa lưu bằng giá trị cũ còn trong form.
 *
 * HPOS-safe: đọc/ghi meta đơn qua WC_Order API.
 */

const THEAN_OW_NONCE = 'thean_order_workspace';

/**
 * Tên field trong bảng điều phối → meta key thật của hithean (order-metabox.php, cùng bảng
 * với filter eim_export_meta_map ở bootstrap.php). Key không có trong map giữ nguyên.
 */
function thean_ow_key(string $key): string
{
    $map = [
        'handling_status'       => 'order_handling_status',
        'shipper'               => 'order_shipper',
        'ship_code'             => 'order_ship_code',
        'export_date'           => 'order_ship_date',
        'export_by'             => 'order_export_by',
        'paid_date'             => 'order_paid_date',
        'bank_account_received' => 'order_bank_account_received',
    ];
    return $map[$key] ?? $key;
}

/** Meta đơn theo tên field bảng điều phối. */
function thean_ow_meta(WC_Order $order, string $key): string
{
    return (string) $order->get_meta(thean_ow_key($key));
}

/** Field select của metabox lưu nhiều dòng meta cùng key (Meta Box multiple) → gom thành mảng. */
function thean_ow_meta_list(WC_Order $order, string $key): array
{
    $out = [];
    foreach ($order->get_meta(thean_ow_key($key), false) as $meta) {
        $value = $meta instanceof WC_Meta_Data ? $meta->value : $meta;
        foreach ((array) $value as $v) {
            $v = trim((string) $v);
            if ($v !== '') $out[] = $v;
        }
    }
    return array_values(array_unique($out));
}

/** Định nghĩa field metabox "Thông tin bổ sung" (order-metabox.php), key theo id. */
function thean_ow_metabox_fields(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $boxes = function_exists('thean_register_order_meta_boxes') ? thean_register_order_meta_boxes([]) : [];
        foreach ($boxes as $box) {
            foreach ((array) ($box['fields'] ?? []) as $field) {
                if (!empty($field['id'])) $map[$field['id']] = $field;
            }
        }
    }
    return $map;
}

/** Options lấy thẳng từ định nghĩa metabox (order-metabox.php) để luôn đồng bộ. */
function thean_ow_field_options(string $field_id): array
{
    return (array) (thean_ow_metabox_fields()[thean_ow_key($field_id)]['options'] ?? []);
}

/**
 * Ghi field nhiều giá trị theo dạng Meta Box (mỗi giá trị 1 dòng meta) — gộp luôn dữ liệu cũ
 * dạng 1 dòng mảng (update_order_meta_field ở order-auto-tasks.php). Chưa save().
 */
function thean_ow_set_list(WC_Order $order, string $key, array $values): void
{
    $key = thean_ow_key($key);
    $order->delete_meta_data($key);
    foreach (array_values(array_unique($values)) as $v) $order->add_meta_data($key, $v, false);
}

/** Thêm / gỡ giá trị của field nhiều giá trị rồi save(). */
function thean_ow_update_list(WC_Order $order, string $key, array $add, array $remove): void
{
    $values = array_diff(array_merge(thean_ow_meta_list($order, $key), $add), $remove);
    thean_ow_set_list($order, $key, $values);
    $order->save();
}

/**
 * Giá trị hiện tại của các field metabox để JS gán lại vào form sửa đơn (không reload).
 * Bỏ qua field ảnh/file/clone: không sửa từ bảng điều phối, gán lại dễ sai cấu trúc.
 */
function thean_ow_metabox_values(WC_Order $order): array
{
    $types = ['text', 'textarea', 'date', 'number', 'select', 'select_advanced', 'user', 'checkbox', 'radio'];
    $out = [];
    foreach (thean_ow_metabox_fields() as $id => $field) {
        if (!in_array($field['type'] ?? '', $types, true) || !empty($field['clone'])) continue;
        $out[$id] = !empty($field['multiple']) ? thean_ow_meta_list($order, $id) : (string) $order->get_meta($id);
    }
    return $out;
}

/** id DOM của metabox "Thông tin bổ sung" (Meta Box không khai id → sanitize_title(title)). */
function thean_ow_metabox_id(): string
{
    $boxes = function_exists('thean_register_order_meta_boxes') ? thean_register_order_meta_boxes([]) : [];
    $box = $boxes[0] ?? [];
    return (string) ($box['id'] ?? sanitize_title((string) ($box['title'] ?? '')));
}

/** Field cho phép sửa nhanh từ bảng điều phối: key => multiple? */
function thean_ow_editable_fields(): array
{
    return [
        'shipper'         => true,
        'handling_status' => true,
        'ship_code'       => false,
        'export_date'     => false,
        'return_status'   => false,
        'return_code'     => false,
    ];
}

/** Trạng thái giao hàng được chuyển nhanh (cùng bộ trạng thái của order-statuses.php). */
function thean_ow_delivery_statuses(): array
{
    $statuses = [
        'packaging'      => 'Chuẩn bị giao VC',
        'shipping'       => 'Đang giao',
        'local-shipping' => 'Giao nhanh',
        'delivered'      => 'Đã giao',
    ];
    return array_filter($statuses, function ($slug) { return wc_is_order_status('wc-' . $slug); }, ARRAY_FILTER_USE_KEY);
}

/** Mô tả ngắn từng trạng thái trong modal "Chuyển trạng thái đơn". */
function thean_ow_delivery_status_notes(): array
{
    return [
        'packaging'      => 'Soạn, đóng gói, chờ bàn giao đơn vị vận chuyển',
        'shipping'       => 'Đã bàn giao ĐVVC — gỡ nhãn chờ in / đã in vận đơn',
        'local-shipping' => 'Giao nhanh nội thành (Ahamove / THEAN)',
        'delivered'      => 'Khách đã nhận hàng',
    ];
}

/** Nhóm nhãn "Trạng thái xử lý nội bộ" để chọn bằng chip; nhãn không thuộc nhóm nào vào "Khác / nhãn cũ". */
function thean_ow_handling_groups(): array
{
    return [
        'Kho & in'      => ['Đã in phiếu xuất hàng', 'Chờ in vận đơn', 'Đã in vận đơn, chờ xử lý', 'Cần chỉnh đơn'],
        'Vận chuyển'    => ['Giao nhanh 1 giờ', 'Giao nhanh 2 giờ', 'Giao nhanh 4 giờ', 'Đã nhập kho vận chuyển'],
        'Xác nhận & CK' => ['Cần gọi xác nhận đơn', 'Chờ khách phản hồi', 'Đã xác nhận đơn', 'Cần báo hủy', 'Chờ thanh toán'],
        'Sự cố'         => ['Thiếu SP'],
    ];
}

/**
 * Ghi chú "hệ thống sẽ tự cập nhật" dưới các trường giao hàng. Nguồn logic:
 * order-auto-tasks.php (đổi trạng thái), warehouse-export-workflow.php (mã vận đơn AI).
 * Đổi logic ở đó thì sửa lại ở đây.
 */
function thean_ow_field_hints(): array
{
    return [
        'status' => [
            'title' => 'Tự động khi chuyển trạng thái đơn',
            'short' => '“Đang giao”: gỡ nhãn chờ in / đã in vận đơn (lần đầu chuyển).',
            'more'  => [
                '<b>Chuẩn bị giao VC</b> — đơn COD: gỡ nhãn “Chờ khách phản hồi”. Đơn CK: tự đổi phương thức thanh toán sang COD (có ghi chú đơn).',
                '<b>Đang giao</b> — lần đầu chuyển: ghi ngày vào ghi chú đơn, gỡ “Chờ in vận đơn”, “Đã in vận đơn, chờ xử lý”.',
                '<b>Giao nhanh</b> — đơn Hà Nội có nhãn “Giao nhanh…” tự chuyển sang Giao nhanh khi vào Chuẩn bị giao VC / Đã nhận thanh toán.',
                'Mỗi lần chuyển từ bảng này đều ghi chú đơn “Đổi trạng thái từ bảng điều phối đơn”.',
            ],
        ],
        'shipper' => [
            'title' => 'Đơn vị giao',
            'short' => 'Plugin hóa đơn (eim) lấy đơn vị giao + mã vận đơn từ đây.',
            'more'  => [
                'Chọn được nhiều đơn vị (giao nhiều lần / đổi đơn vị).',
            ],
        ],
        'ship_code' => [
            'title' => 'Mã vận đơn tự động',
            'short' => 'AI tự thêm mã đọc từ ảnh xuất kho — chỉ thêm mã mới, không ghi đè mã đã nhập.',
            'more'  => [
                'Chạy khi AI kiểm tra ảnh xuất kho <b>đạt</b>, hoặc khi bấm <b>Xác nhận xuất kho</b>.',
                'Bỏ trùng theo phần mã sau dấu chấm (VD <code>S123.ABC456</code> = <code>ABC456</code>).',
                'Nhiều mã lưu cách nhau “, ”. Mỗi lần thêm đều ghi log + ghi chú đơn.',
            ],
        ],
        'export_date' => [
            'title' => 'Ngày giao hàng',
            'short' => 'Field “Ngày giao hàng” của form đơn — nhập tay, không tự điền.',
            'more'  => [],
        ],
        'handling_status' => [
            'title' => 'Nhãn tự gắn / tự gỡ',
            'short' => 'Một số nhãn tự gắn/gỡ theo trạng thái đơn và nút xác nhận COD.',
            'more'  => [
                'Đơn COD vào <b>Đang xử lý</b>: khách mới / vãng lai → gắn “Cần gọi xác nhận đơn”; khách cũ → tự chuyển Chuẩn bị giao VC.',
                'Đơn CK vào <b>Tạm giữ</b> chưa có ngày CK + TK nhận → gắn “Chờ thanh toán”.',
                'Đơn COD <b>Tạm giữ</b>: chờ khách xác nhận qua chat (bước Xác nhận).',
                'Nút xác nhận COD: ✅ gắn “Đã xác nhận đơn”, gỡ các nhãn chờ, chuyển Chuẩn bị giao VC · ⏳ gắn “Chờ khách phản hồi” · ✖️ gắn “Cần báo hủy”.',
                '<b>Chuẩn bị giao VC</b> (COD) gỡ “Chờ khách phản hồi”; <b>Đang giao</b> gỡ nhãn chờ in / đã in vận đơn.',
            ],
        ],
    ];
}

function thean_ow_payment_state(WC_Order $order): array
{
    $status = $order->get_status();
    $audit_raw = (string) $order->get_meta('_order_payment_confirmation_audit');
    $audit = $audit_raw !== '' ? json_decode($audit_raw, true) : [];
    $audit = is_array($audit) ? $audit : [];
    $legacy = thean_ow_meta($order, 'paid_date') !== '' && thean_ow_meta_list($order, 'bank_account_received');

    if ($status === 'partial-paid') {
        return ['key' => 'partial', 'label' => 'CK 1 phần', 'tone' => 'warning', 'done' => false, 'audit' => $audit];
    }
    if ($audit || $legacy || in_array($status, ['paid', 'received-payment', 'completed'], true)) {
        return ['key' => 'paid', 'label' => 'Đã thanh toán', 'tone' => 'success', 'done' => true, 'audit' => $audit];
    }
    if ($order->get_payment_method() === 'cod') {
        return ['key' => 'cod', 'label' => 'COD – thu khi giao', 'tone' => 'info', 'done' => false, 'audit' => $audit];
    }
    return ['key' => 'pending', 'label' => 'Chờ thanh toán', 'tone' => 'danger', 'done' => false, 'audit' => $audit];
}

/**
 * Đơn COD cần khách xác nhận khi:
 * - TẠM GIỮ: chờ khách xác nhận qua kênh chat (channel = chat).
 * - ĐANG XỬ LÝ và (khách tự đặt trên web hoặc được gắn nhãn thủ công "Cần gọi xác nhận đơn" /
 *   đang trong luồng gọi) → gọi khách (channel = call).
 * Còn lại (đơn tạo tay, khách cũ đã được order-auto-tasks.php chuyển "Chuẩn bị giao VC"…) → bước
 * xác nhận "Không cần" (skip).
 */
function thean_ow_cod_confirm_state(WC_Order $order, array $handling): array
{
    if (in_array('Đã xác nhận đơn', $handling, true)) {
        return ['label' => 'Khách đã xác nhận', 'tone' => 'success', 'done' => true, 'skip' => false, 'hint' => '', 'channel' => ''];
    }
    $status = $order->get_status();
    $self_placed = in_array($order->get_created_via(), ['checkout', 'store-api'], true);
    $tagged = array_intersect(['Cần gọi xác nhận đơn', 'Chờ khách phản hồi', 'Cần báo hủy'], $handling);
    $chat = $status === 'on-hold';
    if ($chat || ($status === 'processing' && ($self_placed || $tagged))) {
        $channel = $chat ? 'chat' : 'call';
        if (in_array('Cần báo hủy', $handling, true)) {
            return ['label' => 'Cần báo hủy', 'tone' => 'danger', 'done' => false, 'skip' => false, 'hint' => 'Khách không nhận — báo hủy đơn', 'channel' => $channel];
        }
        if (in_array('Chờ khách phản hồi', $handling, true)) {
            $hint = $chat ? 'Nhắn lại khách xác nhận đơn COD qua chat (đang chờ phản hồi)' : 'Gọi lại khách xác nhận đơn COD (đang chờ phản hồi)';
            return ['label' => 'Chờ khách phản hồi', 'tone' => 'warning', 'done' => false, 'skip' => false, 'hint' => $hint, 'channel' => $channel];
        }
        if ($chat) {
            return ['label' => 'Chờ khách xác nhận qua chat', 'tone' => 'warning', 'done' => false, 'skip' => false, 'hint' => 'Chờ khách xác nhận đơn COD qua chat', 'channel' => $channel];
        }
        return ['label' => 'Chờ khách xác nhận', 'tone' => 'danger', 'done' => false, 'skip' => false, 'hint' => 'Gọi khách xác nhận đơn COD', 'channel' => $channel];
    }
    return ['label' => 'Không cần xác nhận', 'tone' => 'neutral', 'done' => true, 'skip' => true, 'hint' => '', 'channel' => ''];
}

/** Trạng thái cho phép đổi đơn COD sang chờ CK (chưa xuất kho / giao). */
function thean_ow_to_bacs_statuses(): array
{
    return ['pending', 'on-hold', 'processing', 'packaging'];
}

/** Kết quả xác nhận đơn COD (gọi / chat): [thêm vào handling_status, gỡ khỏi handling_status, ghi chú]. */
function thean_ow_cod_confirm_results(): array
{
    return [
        'confirmed' => [['Đã xác nhận đơn'], ['Cần gọi xác nhận đơn', 'Chờ khách phản hồi', 'Cần báo hủy'], 'Khách đã xác nhận đơn COD.'],
        'waiting'   => [['Chờ khách phản hồi'], [], 'Đã liên hệ, chờ khách phản hồi.'],
        'cancel'    => [['Cần báo hủy'], ['Chờ khách phản hồi'], 'Khách không nhận đơn — cần báo hủy.'],
        'require'   => [['Cần gọi xác nhận đơn'], [], 'Gắn nhãn: cần gọi xác nhận đơn.'],
    ];
}

function thean_ow_export_images(WC_Order $order): array
{
    return array_values(array_filter(array_map('trim', explode("\n", (string) $order->get_meta('warehouse_export_images')))));
}

function thean_ow_ai_state(WC_Order $order): array
{
    $review = $order->get_meta('warehouse_export_ai_check');
    $queue  = $order->get_meta('warehouse_export_ai_review_status');
    if (is_array($queue) && ($queue['state'] ?? '') === 'queued') {
        return ['label' => 'AI đang kiểm tra', 'tone' => 'info'];
    }
    if (!is_array($review) || !$review) {
        return ['label' => '', 'tone' => 'neutral'];
    }
    $pass = ($review['overall'] ?? '') === 'pass' && ($review['order_match'] ?? '') === 'match' && ($review['items_match'] ?? '') === 'match';
    return [
        'label'  => $pass ? 'AI: khớp đơn' : 'AI: cần kiểm tra',
        'tone'   => $pass ? 'success' : 'warning',
        'reason' => (string) ($review['reason'] ?? ''),
    ];
}

/** Trạng thái đơn đã đóng — không tạm giữ / hủy / đổi tiếp. */
function thean_ow_closed_statuses(): array
{
    return ['cancelled', 'failed', 'refunded', 'trash'];
}

/** Tạm giữ / hủy / tiếp tục: [trạng thái đích, cần lý do?, ghi chú]. */
function thean_ow_order_flows(): array
{
    return [
        'hold'   => ['on-hold', true, '⏸️ Tạm giữ đơn để điều chỉnh'],
        'resume' => ['', false, '▶️ Tiếp tục xử lý đơn sau tạm giữ'], // đích theo thean_ow_resume_status()
        'cancel' => ['cancelled', true, '✖️ Hủy đơn (trả hàng về tồn kho)'],
    ];
}

/** Trạng thái giao / xong: tạm giữ từ đây thì tiếp tục sẽ trả về đúng trạng thái này. */
function thean_ow_hold_restore_statuses(): array
{
    return ['shipping', 'local-shipping', 'partial-shipping', 'delivered', 'partial-shipped', 'completed'];
}

/**
 * Trạng thái khi tiếp tục sau tạm giữ: đang giao / đã giao / đã xong → trạng thái trước khi tạm giữ;
 * còn lại COD → Chuẩn bị giao VC, CK đã xác nhận → Đã CK.
 * Rỗng = chưa tiếp tục được (CK chưa xác nhận thanh toán).
 */
function thean_ow_resume_status(WC_Order $order): string
{
    $from = (string) $order->get_meta('_thean_ow_hold_from');
    if (in_array($from, thean_ow_hold_restore_statuses(), true) && wc_is_order_status('wc-' . $from)) return $from;
    if ($order->get_payment_method() === 'cod') $target = 'packaging';
    elseif (thean_ow_payment_state($order)['done']) $target = 'paid';
    else return '';
    return wc_is_order_status('wc-' . $target) ? $target : 'processing';
}

function thean_ow_return_next_status(string $status): string
{
    if (function_exists('hithean_return_next_status')) return hithean_return_next_status($status);
    $map = ['Cần đổi trả' => 'Chờ hoàn (Đổi trả)', 'Cần thu hồi' => 'Chờ hoàn (Thu hồi)', 'Cần giao lại' => 'Chờ hoàn (Giao 1 phần)'];
    return $map[trim($status)] ?? '';
}

function thean_ow_short_codes(string $codes): string
{
    $out = [];
    foreach (preg_split('/[\s,;|]+/u', trim($codes)) ?: [] as $code) {
        if ($code === '') continue;
        $parts = explode('.', $code);
        $out[] = trim((string) end($parts)) ?: $code;
    }
    return implode(', ', array_unique($out));
}

function thean_ow_badge(string $label, string $tone = 'neutral'): string
{
    return '<span class="tow-badge tow-badge--' . esc_attr($tone) . '">' . esc_html($label) . '</span>';
}

function thean_ow_row(string $label, string $value_html): string
{
    if ($value_html === '') return '';
    return '<div class="tow-row"><span class="tow-row__k">' . esc_html($label) . '</span><span class="tow-row__v">' . $value_html . '</span></div>';
}

/** Ghi chú tự động dưới field: 1 dòng ngắn + "Xem thêm" mở modal (nội dung trong config JS). */
function thean_ow_hint(string $key): string
{
    $hint = thean_ow_field_hints()[$key] ?? null;
    if (!$hint) return '';
    $more = $hint['more'] ? ' <button type="button" class="tow-more" data-hint="' . esc_attr($key) . '">Xem thêm</button>' : '';
    return '<p class="tow-hint">ⓘ ' . esc_html($hint['short']) . $more . '</p>';
}

/** 1 chip chọn (checkbox) — nhiều chip cùng data-field gom thành mảng khi lưu. */
function thean_ow_pill(string $field, string $value, string $label, bool $checked): string
{
    $danger = thean_ow_pill_tone($label) === 'danger' ? ' tow-pill--danger' : '';
    return '<label class="tow-pill' . $danger . '"><input type="checkbox" data-field="' . esc_attr($field) . '" value="' . esc_attr($value) . '"' . checked($checked, true, false) . '><span>' . esc_html($label) . '</span></label>';
}

function thean_ow_pills(string $field, array $options, array $selected): string
{
    // Giá trị đang lưu nhưng không còn trong options (dữ liệu cũ) vẫn hiện để lưu lại không bị mất.
    foreach ($selected as $value) {
        if (!array_key_exists($value, $options)) $options[$value] = $value;
    }
    $html = '<div class="tow-pills">';
    foreach ($options as $value => $label) {
        $html .= thean_ow_pill($field, (string) $value, (string) $label, in_array((string) $value, $selected, true));
    }
    return $html . '</div>';
}

/** Chọn nhãn xử lý nội bộ: chip chia nhóm + ô lọc. */
function thean_ow_handling_picker(array $selected): string
{
    $options = thean_ow_field_options('handling_status');
    foreach ($selected as $value) {
        if (!array_key_exists($value, $options)) $options[$value] = $value;
    }
    $html = '<div class="tow-picker"><div class="tow-picker__bar"><input type="search" class="tow-input tow-filter" placeholder="Lọc nhãn…" aria-label="Lọc nhãn"><span class="tow-muted tow-picker__count"></span></div>';
    $used = [];
    foreach (thean_ow_handling_groups() as $group => $keys) {
        $pills = '';
        foreach ($keys as $key) {
            if (!array_key_exists($key, $options)) continue;
            $used[$key] = true;
            $pills .= thean_ow_pill('handling_status', $key, (string) $options[$key], in_array($key, $selected, true));
        }
        if ($pills) $html .= '<div class="tow-pgroup"><div class="tow-pgroup__label">' . esc_html($group) . '</div><div class="tow-pills">' . $pills . '</div></div>';
    }
    $rest = '';
    $rest_open = false;
    foreach ($options as $key => $label) {
        $key = (string) $key;
        if (isset($used[$key])) continue;
        $checked = in_array($key, $selected, true);
        $rest_open = $rest_open || $checked;
        $rest .= thean_ow_pill('handling_status', $key, (string) $label, $checked);
    }
    if ($rest) {
        $html .= '<details class="tow-pgroup tow-pgroup--more"' . ($rest_open ? ' open' : '') . '><summary class="tow-pgroup__label">Khác / nhãn cũ</summary><div class="tow-pills">' . $rest . '</div></details>';
    }
    return $html . '</div>';
}

function thean_ow_date_field(string $field, string $label, string $value): string
{
    return '<div class="tow-f tow-datefield"><span>' . esc_html($label) . '</span><div class="tow-datefield__row">'
        . '<input type="date" class="tow-input" data-field="' . esc_attr($field) . '" value="' . esc_attr($value) . '">'
        . '<button type="button" class="tow-mini" data-date-set="0">Hôm nay</button>'
        . '<button type="button" class="tow-mini" data-date-set="1">Mai</button>'
        . '<button type="button" class="tow-mini" data-date-set="" aria-label="Xóa ngày">✕</button>'
        . '</div></div>';
}

/**
 * Nhóm hơn 3 lựa chọn: nút tóm tắt giá trị đang chọn, bấm mở modal (JS chuyển panel vào modal rồi
 * trả về chỗ cũ khi đóng, nên input vẫn nằm trong form để lưu). $selected: [[label, tone]];
 * $tone: màu badge JS dùng khi vẽ lại tóm tắt (chip --danger luôn đỏ).
 */
function thean_ow_choice(string $title, array $selected, string $panel_html, string $empty = 'Chưa chọn', string $tone = 'neutral'): string
{
    $summary = '';
    foreach ($selected as [$label, $item_tone]) $summary .= thean_ow_badge($label, $item_tone);
    return '<div class="tow-choice" data-title="' . esc_attr($title) . '" data-empty="' . esc_attr($empty) . '" data-tone="' . esc_attr($tone) . '">'
        . '<button type="button" class="tow-choice__open" aria-haspopup="dialog">'
        . '<span class="tow-choice__value">' . ($summary !== '' ? $summary : '<span class="tow-muted">' . esc_html($empty) . '</span>') . '</span>'
        . '<span class="tow-choice__cta">Chọn ▸</span></button>'
        . '<div class="tow-choice__panel" hidden>' . $panel_html . '</div></div>';
}

/** Hơn 3 thao tác cùng loại: 1 nút mở modal liệt kê thao tác (nút .tow-act thật nằm trong panel). */
function thean_ow_action_menu(string $button_label, string $title, string $items_html, string $button_class = 'button'): string
{
    return '<div class="tow-menu" data-title="' . esc_attr($title) . '">'
        . '<button type="button" class="' . esc_attr($button_class) . ' tow-menu__open" aria-haspopup="dialog">' . esc_html($button_label) . '</button>'
        . '<div class="tow-menu__panel" hidden><div class="tow-opts">' . $items_html . '</div></div></div>';
}

/** 1 dòng thao tác trong modal: tiêu đề + mô tả ngắn. $attrs: data-* cho .tow-act. */
function thean_ow_action_opt(string $label, string $desc, array $attrs, bool $current = false): string
{
    if ($current) {
        return '<div class="tow-opt is-current" aria-current="true"><strong>✓ ' . esc_html($label) . '</strong><small>Trạng thái hiện tại</small></div>';
    }
    $html = '';
    foreach ($attrs as $k => $v) $html .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
    return '<button type="button" class="tow-opt tow-act"' . $html . '><strong>' . esc_html($label) . '</strong>' . ($desc !== '' ? '<small>' . esc_html($desc) . '</small>' : '') . '</button>';
}

function thean_ow_pill_tone(string $label): string
{
    return preg_match('/gấp|sự cố|thiếu|hủy/iu', $label) ? 'danger' : 'neutral';
}

function thean_ow_user_name($user_id): string
{
    $user = $user_id ? get_userdata((int) $user_id) : null;
    return $user ? ($user->display_name ?: $user->user_login) : '';
}

function thean_ow_gallery(array $urls): string
{
    if (!$urls) return '';
    $html = '<div class="tow-thumbs">';
    foreach ($urls as $url) {
        $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"><img src="' . esc_url($url) . '" alt="" loading="lazy"></a>';
    }
    return $html . '</div>';
}

/** Phiếu in khi soạn hàng: GHTK / Nhanh. */
function thean_ow_print_links(WC_Order $order): array
{
    return ['Phiếu GHTK / Nhanh' => add_query_arg(['action' => 'inhoadon_ghtk', 'order_id' => $order->get_id()], admin_url('admin-ajax.php'))];
}

/** Danh sách đơn tìm theo SĐT / email — HPOS (wc-orders) hoặc legacy (edit.php). */
function thean_ow_orders_search_url(string $search): string
{
    $hpos = class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    return $hpos
        ? add_query_arg(['page' => 'wc-orders', 's' => $search], admin_url('admin.php'))
        : add_query_arg(['s' => $search, 'post_type' => 'shop_order', 'post_status' => 'all'], admin_url('edit.php'));
}

// ================= Render =================

function thean_render_order_workspace($order_arg): void
{
    static $rendered = false;
    $order = $order_arg instanceof WC_Order ? $order_arg : wc_get_order($order_arg);
    if ($rendered || !$order instanceof WC_Order || !current_user_can('manage_woocommerce')) return;
    if ($order->get_status() === 'auto-draft' || !$order->get_date_created()) return;
    $rendered = true;

    $hints = [];
    foreach (thean_ow_field_hints() as $key => $hint) {
        if ($hint['more']) $hints[$key] = ['title' => $hint['title'], 'items' => $hint['more']];
    }
    $cfg = [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'orderId' => $order->get_id(),
        'metabox' => thean_ow_metabox_id(),
        'hints'   => $hints,
        'nonces'  => [
            'workspace'      => wp_create_nonce(THEAN_OW_NONCE),
            'payment'        => wp_create_nonce('confirm_order_payment_nonce'),
            'exportUpload'   => wp_create_nonce('ajax_upload_images_nonce'),
            'exportConfirm'  => wp_create_nonce('ajax_confirm_export_nonce'),
            'returnProcess'  => wp_create_nonce('process_return_order_nonce'),
            'returnUpload'   => wp_create_nonce('upload_return_images_nonce'),
        ],
    ];
    ?>
    <div id="thean-order-workspace" class="tow" data-config="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
        <?php thean_ow_render_inner($order); ?>
        <div class="tow-modal" hidden>
            <div class="tow-modal__box" role="dialog" aria-modal="true" aria-labelledby="tow-modal-title">
                <div class="tow-modal__head"><strong id="tow-modal-title" class="tow-modal__title"></strong><button type="button" class="tow-modal__x" data-modal-close aria-label="Đóng">✕</button></div>
                <div class="tow-modal__body"></div>
                <div class="tow-modal__foot"></div>
            </div>
        </div>
        <div class="tow-toast" role="status" aria-live="polite"></div>
    </div>
    <?php
}

/**
 * Trạng thái luồng xử lý của đơn (không render, không ghi): các bước, bước cần làm tiếp và gợi ý.
 * Dùng chung cho bảng điều phối (thean_ow_render_inner) và board "Cần xử lý" của ô tìm đơn.
 */
function thean_ow_flow(WC_Order $order): array
{
    $status = $order->get_status();

    $payment       = thean_ow_payment_state($order);
    $export_images = thean_ow_export_images($order);
    $confirmed_by  = (int) $order->get_meta('export_confirmed_by');
    $ai            = thean_ow_ai_state($order);
    $shippers      = thean_ow_meta_list($order, 'shipper');
    $handling      = thean_ow_meta_list($order, 'handling_status');
    $ship_code     = thean_ow_meta($order, 'ship_code');
    $export_date   = thean_ow_meta($order, 'export_date');
    $return_status = trim((string) $order->get_meta('return_status'));
    $return_code   = (string) $order->get_meta('return_code');
    $compensate    = (string) $order->get_meta('compensate_status');
    $refund        = (string) $order->get_meta('refund_status');
    $return_images = array_values(array_filter(array_map('trim', explode("\n", (string) $order->get_meta('issue_result_images')))));

    $is_cod      = $order->get_payment_method() === 'cod';
    $cod_confirm = $is_cod ? thean_ow_cod_confirm_state($order, $handling) : null;
    $is_pickup   = in_array('Chờ khách đến lấy hàng', $handling, true);
    $is_closed   = in_array($status, thean_ow_closed_statuses(), true);
    $is_shipping = in_array($status, ['shipping', 'local-shipping', 'partial-shipping'], true);
    $return_done = strpos($return_status, 'Đã nhận') === 0 || $return_status === 'Hủy yêu cầu';
    $has_return  = $return_status !== '';

    // Luồng: Thanh toán (CK) / Khách xác nhận (COD) → Lấy hàng → Xuất kho → Giao → Hoàn (nếu có).
    // Mỗi bước: [nhãn, xong?, bỏ qua?]
    $steps = $is_cod
        ? ['confirm' => ['Xác nhận', $cod_confirm['done'], $cod_confirm['skip']]]
        : ['payment' => ['Thanh toán', $payment['done'], false]];
    $steps += [
        'picking'  => ['Lấy hàng', (bool) $export_images, false],
        'export'   => ['Xuất kho', (bool) $confirmed_by, false],
        'delivery' => [$is_pickup ? 'Khách lấy' : 'Giao hàng', in_array($status, ['delivered', 'partial-shipped', 'completed'], true), false],
    ];
    if ($has_return) $steps['return'] = ['Hoàn hàng', $return_done, false];

    $next = '';
    foreach ($steps as $key => $step) {
        if (!$step[1]) { $next = $key; break; }
    }
    // Đang hoàn hàng thì ưu tiên xử lý hoàn (VD "Chờ hoàn (Không giao được)" khi chưa giao xong).
    if ($has_return && !$return_done) $next = 'return';
    $next_hint = [
        'confirm'  => $cod_confirm['hint'] ?? '',
        'payment'  => 'Xác nhận khách đã chuyển khoản',
        'picking'  => 'Soạn hàng, in phiếu & chụp ảnh xuất kho',
        'export'   => 'Kiểm tra ảnh & xác nhận xuất kho',
        'delivery' => $is_pickup ? 'Bàn giao khi khách đến lấy' : ($is_shipping ? 'Theo dõi giao hàng, chuyển "Đã giao" khi khách nhận' : 'Chọn đơn vị giao, nhập mã vận đơn'),
        'return'   => 'Hoàn hàng: ' . $return_status,
    ][$next] ?? '';
    $next_goto = $next;
    if ($next === '' && $is_cod && !$payment['done']) {
        $next_hint = 'Đối soát / xác nhận đã thu COD';
        $next_goto = 'payment';
    }
    if ($is_closed) $next_hint = '';
    // Tab mở sẵn: bước cần làm; xong hết thì bước cuối.
    $active = isset($steps[$next]) ? $next : ($has_return ? 'return' : 'delivery');

    return compact('payment', 'export_images', 'confirmed_by', 'ai', 'shippers', 'handling', 'ship_code', 'export_date', 'return_status', 'return_code', 'compensate', 'refund', 'return_images', 'is_cod', 'cod_confirm', 'is_pickup', 'is_closed', 'is_shipping', 'return_done', 'has_return', 'steps', 'next', 'next_hint', 'next_goto', 'active');
}

/** Phần bảng thay được qua AJAX (task `render`) — không gồm modal/toast/config. */
function thean_ow_render_inner(WC_Order $order): void
{
    $order_no  = function_exists('change_order_number') ? change_order_number($order->get_id()) : $order->get_order_number();
    $status    = $order->get_status();
    $locked    = function_exists('thean_order_status_is_locked') && thean_order_status_is_locked($order);

    // Đơn đã rời Tạm giữ (kể cả đổi ngoài bảng) → bỏ trạng thái gốc đã nhớ, tránh lần tạm giữ sau trả về nhầm.
    if ($status !== 'on-hold' && $order->get_meta('_thean_ow_hold_from') !== '') {
        $order->delete_meta_data('_thean_ow_hold_from');
        $order->save_meta_data();
    }

    [
        'payment' => $payment,
        'export_images' => $export_images,
        'confirmed_by' => $confirmed_by,
        'ai' => $ai,
        'shippers' => $shippers,
        'handling' => $handling,
        'ship_code' => $ship_code,
        'export_date' => $export_date,
        'return_status' => $return_status,
        'return_code' => $return_code,
        'compensate' => $compensate,
        'refund' => $refund,
        'return_images' => $return_images,
        'is_cod' => $is_cod,
        'cod_confirm' => $cod_confirm,
        'is_pickup' => $is_pickup,
        'is_closed' => $is_closed,
        'is_shipping' => $is_shipping,
        'return_done' => $return_done,
        'has_return' => $has_return,
        'steps' => $steps,
        'next' => $next,
        'next_hint' => $next_hint,
        'next_goto' => $next_goto,
        'active' => $active,
    ] = thean_ow_flow($order);
    ?>
    <div class="tow-inner" data-active="<?php echo esc_attr($active); ?>">
        <div class="tow-head">
            <div class="tow-head__main">
                <strong class="tow-head__no">#<?php echo esc_html($order_no); ?></strong>
                <?php echo thean_ow_badge(wc_get_order_status_name($status), 'status'); ?>
                <?php if ($cod_confirm && !$cod_confirm['done']) echo thean_ow_badge($cod_confirm['label'], $cod_confirm['tone']); ?>
                <?php echo thean_ow_badge($payment['label'], $payment['tone']); ?>
                <?php if ($has_return) echo thean_ow_badge('Hoàn: ' . $return_status, 'danger'); ?>
                <?php if ($locked) echo thean_ow_badge('🔒 Khóa trạng thái', 'neutral'); ?>
            </div>
            <div class="tow-head__total"><?php echo wp_kses_post(wc_price($order->get_total())); ?> · <?php echo esc_html($order->get_payment_method_title() ?: strtoupper($order->get_payment_method())); ?></div>
        </div>

        <div class="tow-steps" role="tablist" aria-label="Các bước xử lý đơn">
            <?php $i = 0; foreach ($steps as $key => $step) :
                $i++;
                $cls = $step[2] ? 'is-skip' : ($step[1] ? 'is-done' : ($key === $next ? 'is-next' : ''));
                $on = $key === $active; ?>
                <button type="button" role="tab" class="tow-tab <?php echo esc_attr($cls . ($on ? ' is-active' : '')); ?>" data-tab="<?php echo esc_attr($key); ?>" aria-selected="<?php echo $on ? 'true' : 'false'; ?>">
                    <span class="tow-tab__n"><?php echo $step[1] && !$step[2] ? '✓' : (int) $i; ?></span><?php echo esc_html($step[0]); ?><?php if ($step[2]) echo ' <small>· không cần</small>'; ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php if ($next_hint) : ?>
            <button type="button" class="tow-next" data-goto="<?php echo esc_attr($next_goto); ?>">👉 <strong>Việc tiếp theo:</strong> <?php echo esc_html($next_hint); ?></button>
        <?php endif; ?>

        <div class="tow-layout">
            <div class="tow-main">
                <?php
                $panel = function (string $key, callable $render) use ($active) {
                    echo '<div class="tow-panel" role="tabpanel" data-step="' . esc_attr($key) . '"' . ($key === $active ? '' : ' hidden') . '>';
                    $render();
                    echo '</div>';
                };
                if ($is_cod) {
                    $panel('confirm', function () use ($order, $order_no, $cod_confirm, $locked) { thean_ow_box_cod_confirm($order, $order_no, $cod_confirm, $locked); });
                } else {
                    $panel('payment', function () use ($order, $order_no, $payment, $locked) { thean_ow_box_payment($order, $order_no, $payment, $locked, true); });
                }
                $panel('picking', function () use ($order) { thean_ow_box_picking($order); });
                $panel('export', function () use ($order, $export_images, $confirmed_by, $ai) { thean_ow_box_export($order, $export_images, $confirmed_by, $ai); });
                $panel('delivery', function () use ($order, $shippers, $handling, $ship_code, $export_date, $is_pickup, $locked) {
                    thean_ow_box_delivery($order, $shippers, $handling, $ship_code, $export_date, $is_pickup, $locked);
                });
                if ($has_return) {
                    $panel('return', function () use ($order, $return_status, $return_code, $compensate, $refund, $return_images) {
                        thean_ow_box_return($order, $return_status, $return_code, $compensate, $refund, $return_images);
                    });
                }
                ?>
            </div>
            <aside class="tow-side">
                <?php
                // COD: thu tiền sau khi giao → mở sẵn khi đã giao mà chưa đối soát.
                if ($is_cod) thean_ow_box_payment($order, $order_no, $payment, $locked, !$payment['done'] && $steps['delivery'][1]);
                thean_ow_box_customer($order);
                thean_ow_box_hdsd($order);
                if (!$has_return) thean_ow_box_return($order, $return_status, $return_code, $compensate, $refund, $return_images);
                if (!$is_closed) thean_ow_box_hold_cancel($order, $locked);
                thean_ow_box_tools($order, $order_no);
                ?>
            </aside>
        </div>

        <div class="tow-foot">
            <button type="button" class="button tow-toggle-metabox" aria-expanded="false">⚙️ Form đầy đủ (VAT, sự cố, kế toán…)</button>
            <a class="button" href="<?php echo esc_url(home_url('/tien-ich-admin/')); ?>" target="_blank" rel="noopener">Tiện ích admin ↗</a>
        </div>
    </div>
    <?php
}

function thean_ow_box_open(string $id, string $title, string $badge_html = '', bool $open = true): void
{
    echo '<details class="tow-box" id="tow-' . esc_attr($id) . '"' . ($open ? ' open' : '') . '>';
    echo '<summary class="tow-box__head"><span class="tow-box__title">' . esc_html($title) . '</span>' . $badge_html . '</summary><div class="tow-box__body">';
}

function thean_ow_box_close(): void
{
    echo '</div></details>';
}

function thean_ow_box_cod_confirm(WC_Order $order, $order_no, array $state, bool $locked): void
{
    $chat = $state['channel'] === 'chat';
    thean_ow_box_open('confirm', ($chat ? '💬' : '📞') . ' Khách xác nhận đơn (COD)', thean_ow_badge($state['label'], $state['tone']));

    $phone = $order->get_billing_phone();
    if ($phone) {
        $zalo = '<a href="https://zalo.me/' . esc_attr(preg_replace('/\D/', '', $phone)) . '" target="_blank" rel="noopener">Zalo</a>';
        $tel  = '<a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
        echo $chat ? thean_ow_row('Nhắn khách', $zalo . ' · ' . $tel) : thean_ow_row('Gọi khách', $tel . ' · ' . $zalo);
    }
    if ($chat && !$state['done']) {
        echo '<p class="tow-muted">Đơn Tạm giữ: chờ khách xác nhận qua kênh chat (Zalo / Messenger…). Khách xác nhận → bấm ✅ để chuyển Chuẩn bị giao VC.</p>';
    }
    echo thean_ow_row('Giá trị thu hộ', wp_kses_post(wc_price($order->get_total())));
    if ($order->get_customer_note()) echo thean_ow_row('Ghi chú khách', esc_html($order->get_customer_note()));
    // Khách muốn đổi COD sang chuyển khoản: gửi QR, chuyển đơn sang chờ CK, hoặc xác nhận luôn nếu khách đã CK.
    thean_ow_transfer_qr($order, $order_no, 'Khách muốn chuyển khoản? Gửi mã QR');
    if (!$locked && in_array($order->get_status(), thean_ow_to_bacs_statuses(), true)) {
        echo '<details class="tow-sub"><summary>💳 Khách chuyển khoản (COD → CK)</summary>';
        echo '<p class="tow-muted">Khách hẹn CK: bấm 🔄 — đơn đổi sang Chuyển khoản, về <b>Tạm giữ</b> + nhãn “Chờ thanh toán”. Khách đã CK: xác nhận bên dưới (tự đổi sang Chuyển khoản).</p>';
        echo '<div class="tow-btns"><button type="button" class="button tow-act" data-action="to_bacs">🔄 Chuyển sang chờ CK (COD → BACS)</button></div>';
        thean_ow_payment_form($order, true, 'customer');
        echo '</details>';
    }

    if ($state['skip']) {
        echo '<p class="tow-muted">Không cần xác nhận — chỉ cần khi đơn <b>Tạm giữ</b> (chờ khách xác nhận qua chat) hoặc <b>Đang xử lý</b> do khách tự đặt trên web / được gắn nhãn “Cần gọi xác nhận đơn”.</p>';
        if ($order->get_status() === 'processing' && !$locked) {
            echo '<div class="tow-btns"><button type="button" class="button tow-act" data-action="cod_confirm" data-result="require">📞 Gắn: cần gọi xác nhận</button></div>';
        }
    } elseif (!$state['done'] && !$locked) {
        echo '<div class="tow-btns">';
        echo '<button type="button" class="button button-primary tow-act" data-action="cod_confirm" data-result="confirmed">✅ Khách đã xác nhận</button>';
        echo '<button type="button" class="button tow-act" data-action="cod_confirm" data-result="waiting">⏳ Chờ khách phản hồi</button>';
        echo '<button type="button" class="button tow-btn-danger tow-act" data-action="cod_confirm" data-result="cancel" data-danger="1">✖️ Khách không nhận – cần báo hủy</button>';
        echo '</div>';
    }
    thean_ow_box_close();
}

/** Mã QR chuyển khoản (SePay) + nút sao chép link QR / tin nhắn gửi khách. */
function thean_ow_transfer_qr(WC_Order $order, $order_no, string $title = 'Mã QR chuyển khoản'): void
{
    if (!function_exists('hithean_payment_qr_url')) return;
    $des = hithean_payment_transfer_content($order->get_id());
    $qr  = hithean_payment_qr_url(hithean_primary_bank_account(), (float) $order->get_total(), $des);
    if ($qr === '') return;
    $msg = "The An gui ban ma QR chuyen khoan don $order_no: $qr — So tien: " . wc_format_decimal($order->get_total(), 0) . "d, noi dung CK: $des. Cam on ban!";
    echo '<details class="tow-sub"><summary>' . esc_html($title) . ' · nội dung <code>' . esc_html($des) . '</code></summary>';
    echo '<a href="' . esc_url($qr) . '" target="_blank" rel="noopener"><img class="tow-qr" src="' . esc_url($qr) . '" alt="QR" loading="lazy"></a>';
    echo '<div class="tow-chips">';
    echo '<button type="button" class="tow-chip" data-copy="' . esc_attr($msg) . '">📋 Tin nhắn kèm QR</button>';
    echo '<button type="button" class="tow-chip" data-copy="' . esc_attr($qr) . '">🔗 Link QR</button>';
    echo '<button type="button" class="tow-chip" data-copy="' . esc_attr($des) . '">📋 Nội dung CK</button>';
    echo '</div></details>';
}

/**
 * Form xác nhận thanh toán (AJAX confirm_order_payment). $only_payer = 'customer': chỉ khách CK —
 * dùng ở tab Xác nhận của đơn COD (radio đổi tên để không chung nhóm với form ở box Thanh toán).
 */
function thean_ow_payment_form(WC_Order $order, bool $is_cod, string $only_payer = ''): void
{
    // eim bật: 1 luồng duy nhất — chọn người thanh toán rồi mở widget hub [eim_payment_hub]
    // (tra sẵn đơn, chọn sẵn người thanh toán, khớp giao dịch hàng đợi Sepay).
    $hub = function_exists('child_theme_hub_pay_enabled') && child_theme_hub_pay_enabled();
    ?>
    <div class="tow-form" data-task="payment"<?php if ($only_payer) echo ' data-payer="' . esc_attr($only_payer) . '"'; ?>>
        <?php if (!$only_payer) : ?>
        <div class="tow-seg" role="radiogroup" aria-label="Người thanh toán">
            <label><input type="radio" name="tow_payer" value="customer"<?php checked(!$is_cod); ?>> Khách CK</label>
            <label><input type="radio" name="tow_payer" value="shipper"<?php checked($is_cod); ?>> Shipper COD</label>
            <label><input type="radio" name="tow_payer" value="self"> NV thu COD</label>
        </div>
        <?php endif; ?>
        <?php if ($hub) : ?>
        <button type="button" class="button button-primary tow-hub-pay">💳 Xác nhận thanh toán</button>
        <p class="tow-muted">Mở công cụ Xác nhận thanh toán của hub: đơn và người thanh toán điền sẵn; khách CK chọn giao dịch trong hàng đợi Sepay (mỗi giao dịch dùng 1 lần), đơn COD tự đổi sang Chuyển khoản.</p>
        <?php else :
            $accounts = thean_ow_field_options('bank_account_received');
            $sfx = $only_payer ? '_' . $only_payer : '';
            ?>
        <div class="tow-fields">
            <div class="tow-f" data-for="customer"><span>TK nhận</span>
                <?php
                if (count($accounts) > 3) {
                    $first = (string) array_key_first($accounts);
                    $radios = '<div class="tow-pills">';
                    foreach ($accounts as $value => $label) {
                        $radios .= '<label class="tow-pill"><input type="radio" name="tow_bank_account' . $sfx . '" data-p="bank_account" value="' . esc_attr($value) . '"' . checked((string) $value === $first, true, false) . '><span>' . esc_html($label) . '</span></label>';
                    }
                    echo thean_ow_choice('Chọn tài khoản nhận', [[(string) $accounts[$first], 'info']], $radios . '</div>', 'Chưa chọn TK', 'info');
                } else {
                    echo '<select class="tow-input" data-p="bank_account">';
                    foreach ($accounts as $value => $label) echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
                    echo '</select>';
                }
                ?>
            </div>
            <label class="tow-f" data-for="customer"><span>Ngày nhận</span><input type="date" class="tow-input" data-p="paid_date" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>"></label>
            <label class="tow-f"><span>Số tiền (trống = đủ)</span><input type="number" min="0" step="1000" inputmode="numeric" class="tow-input" data-p="amount_received" placeholder="<?php echo esc_attr(wc_format_decimal($order->get_total(), 0)); ?>"></label>
            <?php if (!$only_payer) : ?>
            <label class="tow-f tow-f--wide" data-for="cod"><span>Ghi chú COD</span><input type="text" class="tow-input" data-p="cod_note"></label>
            <?php endif; ?>
        </div>
        <button type="button" class="button button-primary tow-act" data-action="payment"><?php echo $is_cod && !$only_payer ? '✅ Xác nhận đã thu COD' : '✅ Xác nhận thanh toán'; ?></button>
        <?php endif; ?>
    </div>
    <?php
}

function thean_ow_box_payment(WC_Order $order, $order_no, array $payment, bool $locked, bool $open): void
{
    $audit  = $payment['audit'];
    $is_cod = $payment['key'] === 'cod';
    thean_ow_box_open('payment', $is_cod ? '💵 Thu tiền COD' : '💳 Thanh toán', thean_ow_badge($payment['label'], $payment['tone']), $open);

    echo thean_ow_row('Phương thức', esc_html($order->get_payment_method_title() ?: $order->get_payment_method()));
    echo thean_ow_row('Ngày CK', esc_html(thean_ow_meta($order, 'paid_date')));
    echo thean_ow_row('TK nhận', esc_html(implode(', ', thean_ow_meta_list($order, 'bank_account_received'))));
    if ($audit) {
        echo thean_ow_row('Xác nhận', esc_html(($audit['confirmed_name'] ?? '') . ' · ' . ($audit['confirmed_at'] ?? '')) . ' · ' . wp_kses_post(wc_price((float) ($audit['amount'] ?? 0))));
    }
    // Huy hiệu tin cậy (plugin eim): khớp giao dịch ngân hàng / cần xem / không có giao dịch.
    if ($audit && function_exists('eim_order_payment_trust')) {
        echo thean_ow_row('Tin cậy', eim_payment_trust_badge_html(eim_order_payment_trust($order))); // phpcs:ignore -- HTML đã escape trong plugin
    }

    if (!$payment['done'] && !$locked) {
        thean_ow_payment_form($order, $is_cod);
    } elseif ($locked && !$payment['done']) {
        echo '<p class="tow-muted">Đơn tạo hơn ' . (defined('THEAN_ORDER_STATUS_LOCK_DAYS') ? (int) THEAN_ORDER_STATUS_LOCK_DAYS : 365) . ' ngày — chỉ admin được xác nhận.</p>';
    }

    if ($order->get_payment_method() === 'bacs') thean_ow_transfer_qr($order, $order_no);

    thean_ow_box_close();
}

function thean_ow_box_picking(WC_Order $order): void
{
    $items = $order->get_items();
    $qty = 0;
    foreach ($items as $item) $qty += (float) $item->get_quantity();
    thean_ow_box_open('picking', '🧺 Lấy hàng (soạn hàng)', thean_ow_badge(count($items) . ' SP · ' . wc_stock_amount($qty) . ' món', 'neutral'));

    echo '<div class="tow-btns tow-print">';
    foreach (thean_ow_print_links($order) as $label => $url) {
        echo '<a class="button button-primary" href="' . esc_url($url) . '" target="_blank" rel="noopener">🖨️ In ' . esc_html($label) . '</a>';
    }
    echo '</div>';

    echo '<ul class="tow-pick">';
    foreach ($items as $item) {
        $product = $item->get_product();
        $sku  = $product ? $product->get_sku() : '';
        $seal = $product && function_exists('hithean_order_item_has_the_an_organics_brand') && hithean_order_item_has_the_an_organics_brand($product);
        $exp  = '';
        if ($product) {
            $exp = $item->get_variation_id()
                ? (string) get_post_meta($item->get_variation_id(), 'product_expiry_date_variation', true)
                : (string) get_post_meta($product->get_id(), 'product_expiry_date', true);
        }
        $exp_soon = $exp !== '' && strtotime($exp) && strtotime($exp) <= strtotime('+6 months');
        echo '<li><label><input type="checkbox" class="tow-pick__chk"><span class="tow-pick__qty">×' . esc_html(wc_stock_amount($item->get_quantity())) . '</span>';
        echo '<span class="tow-pick__name">' . esc_html($item->get_name());
        if ($sku) echo ' <small>· ' . esc_html($sku) . '</small>';
        if ($exp) echo ' <small class="' . ($exp_soon ? 'tow-warn' : '') . '">· HSD ' . esc_html($exp) . '</small>';
        if ($seal) echo ' ' . thean_ow_badge('Tem niêm phong', 'warning');
        echo '</span></label></li>';
    }
    echo '</ul><p class="tow-muted tow-pick__done" hidden>✅ Đã soạn đủ — chụp ảnh ở bước <button type="button" class="tow-link" data-goto="export">Xuất kho</button>.</p>';
    thean_ow_box_close();
}

function thean_ow_box_export(WC_Order $order, array $images, int $confirmed_by, array $ai): void
{
    $badge = $confirmed_by ? thean_ow_badge('Đã xuất kho', 'success') : ($images ? thean_ow_badge('Chờ xác nhận', 'warning') : thean_ow_badge('Chưa có ảnh', 'danger'));
    thean_ow_box_open('export', '📦 Xuất kho', $badge);

    if ($confirmed_by) echo thean_ow_row('Xác nhận bởi', esc_html(thean_ow_user_name($confirmed_by)));
    echo thean_ow_row('Xuất kho bởi', esc_html(implode(', ', thean_ow_meta_list($order, 'export_by'))));
    if ($ai['label']) {
        echo thean_ow_row('AI', thean_ow_badge($ai['label'], $ai['tone']) . (!empty($ai['reason']) ? ' <small class="tow-muted">' . esc_html($ai['reason']) . '</small>' : ''));
    }
    echo thean_ow_gallery($images);

    if (!$confirmed_by) {
        $checklist = function_exists('hithean_get_export_upload_checklist')
            ? hithean_get_export_upload_checklist()
            : array_filter(array_map('trim', explode("\n", (string) get_option('thean_export_upload_checklist', "Ảnh chụp đầy đủ sản phẩm\nSố lượng khớp với đơn\nHàng không bị hư hỏng, rò rỉ, móp méo\nẢnh chụp rõ mã đơn hoặc tên khách hàng\nNhãn hàng The An Organics đã dán TEM NIÊM PHONG"))));
        if ($checklist) {
            echo '<ul class="tow-checklist">';
            foreach ($checklist as $line) echo '<li>' . esc_html($line) . '</li>';
            echo '</ul>';
        }
        ?>
        <div class="tow-form" data-task="export">
            <label class="tow-file">
                <input type="file" class="tow-file__input" accept="image/jpeg,image/png" capture="environment" multiple data-max="5">
                <span>📷 Chọn/chụp ảnh (tối đa 5)</span>
            </label>
            <label class="tow-check"><input type="checkbox" data-p="ai" checked> AI kiểm tra ảnh</label>
            <div class="tow-btns">
                <button type="button" class="button tow-act" data-action="export_upload">⬆️ Upload ảnh</button>
                <?php if ($images) : ?>
                    <button type="button" class="button button-primary tow-act" data-action="export_confirm">✅ Xác nhận xuất kho</button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    thean_ow_box_close();
}

function thean_ow_box_delivery(WC_Order $order, array $shippers, array $handling, string $ship_code, string $export_date, bool $is_pickup, bool $locked): void
{
    $status = $order->get_status();
    $shipper_labels = thean_ow_field_options('shipper');
    $handling_labels = thean_ow_field_options('handling_status');

    // 1. Chuyển trạng thái đơn
    thean_ow_box_open('delivery', '🔄 Chuyển trạng thái đơn');
    echo thean_ow_row('Hiện tại', thean_ow_badge(wc_get_order_status_name($status), 'status'));
    if ($locked) {
        echo '<p class="tow-muted">Đơn đã khóa đổi trạng thái — chỉ admin được chuyển.</p>';
    } else {
        $notes = thean_ow_delivery_status_notes();
        $items = '';
        foreach (thean_ow_delivery_statuses() as $slug => $label) {
            $items .= thean_ow_action_opt($label, $notes[$slug] ?? '', ['data-action' => 'set_status', 'data-status' => $slug, 'data-noconfirm' => '1'], $slug === $status);
        }
        echo '<div class="tow-btns">' . thean_ow_action_menu('🔄 Chuyển sang trạng thái khác…', 'Chuyển trạng thái đơn hàng', $items, 'button button-primary') . '</div>';
        echo thean_ow_hint('status');
    }
    thean_ow_box_close();

    // 2. Nhãn xử lý nội bộ
    $selected = array_map(function ($h) use ($handling_labels) {
        $label = (string) ($handling_labels[$h] ?? $h);
        return [$label, thean_ow_pill_tone($label)];
    }, $handling);
    thean_ow_box_open('handling', '🏷️ Nhãn xử lý nội bộ', $handling ? thean_ow_badge(count($handling) . ' nhãn', 'neutral') : '');
    echo '<div class="tow-form" data-task="handling">';
    echo thean_ow_choice('Chọn nhãn xử lý nội bộ', $selected, thean_ow_handling_picker($handling), 'Chưa gắn nhãn');
    echo thean_ow_hint('handling_status');
    echo '<button type="button" class="button button-primary tow-act" data-action="set_meta">💾 Lưu nhãn</button>';
    echo '</div>';
    thean_ow_box_close();

    // 3. Vận chuyển
    $names = array_map(function ($s) use ($shipper_labels) { return (string) ($shipper_labels[$s] ?? $s); }, $shippers);
    $badge = $is_pickup ? thean_ow_badge('Khách đến lấy', 'info') : ($names ? thean_ow_badge(implode(', ', $names), 'info') : thean_ow_badge('Chưa chọn ĐVVC', 'neutral'));
    thean_ow_box_open('shipping', $is_pickup ? '🏪 Vận chuyển / Khách lấy' : '🚚 Vận chuyển', $badge);
    ?>
    <div class="tow-form" data-task="shipping">
        <div class="tow-f">
            <span class="tow-label">Đơn vị giao</span>
            <?php echo thean_ow_choice('Chọn đơn vị giao', array_map(function ($n) { return [$n, 'info']; }, $names), thean_ow_pills('shipper', $shipper_labels, $shippers), 'Chưa chọn đơn vị giao', 'info'); ?>
            <?php echo thean_ow_hint('shipper'); ?>
        </div>
        <div class="tow-f">
            <span class="tow-label">Mã vận đơn</span>
            <div class="tow-tags">
                <input type="hidden" data-field="ship_code" value="<?php echo esc_attr($ship_code); ?>">
                <input type="text" class="tow-tags__input" placeholder="Nhập / quét mã rồi Enter" aria-label="Thêm mã vận đơn">
            </div>
            <?php echo thean_ow_hint('ship_code'); ?>
        </div>
        <button type="button" class="button button-primary tow-act" data-action="set_meta">💾 Lưu vận chuyển</button>
    </div>
    <?php
    thean_ow_box_close();

    // 4. Ngày giao hàng
    thean_ow_box_open('schedule', '📅 Ngày giao hàng', $export_date !== '' ? thean_ow_badge('Giao ' . $export_date, 'neutral') : '');
    ?>
    <div class="tow-form" data-task="schedule">
        <div class="tow-f">
            <?php echo thean_ow_date_field('export_date', 'Ngày giao hàng', $export_date); ?>
            <?php echo thean_ow_hint('export_date'); ?>
        </div>
        <button type="button" class="button button-primary tow-act" data-action="set_meta">💾 Lưu ngày giao</button>
    </div>
    <?php
    thean_ow_box_close();
}

function thean_ow_box_return(WC_Order $order, string $return_status, string $return_code, string $compensate, string $refund, array $images): void
{
    $has_return = $return_status !== '' || $compensate !== '' || $refund !== '';
    $tone = strpos($return_status, 'Có sự cố') !== false ? 'danger' : (strpos($return_status, 'Đã nhận') === 0 || $return_status === 'Hủy yêu cầu' ? 'success' : 'warning');
    thean_ow_box_open('return', '↩️ Hoàn hàng / Sự cố', $return_status !== '' ? thean_ow_badge($return_status, $tone) : thean_ow_badge('Không có', 'neutral'), $has_return);

    echo thean_ow_row('Mã hoàn', $return_code !== '' ? '<code>' . esc_html(thean_ow_short_codes($return_code)) . '</code>' : '');
    echo thean_ow_row('Bồi hoàn', esc_html($compensate));
    echo thean_ow_row('Hoàn tiền', esc_html($refund));
    echo thean_ow_gallery($images);
    $issue_images = array_values(array_filter(array_map('trim', explode("\n", (string) $order->get_meta('issue_report_images')))));
    echo thean_ow_row('Ảnh sự cố', thean_ow_gallery($issue_images));
    // Ô mã vận đơn hoàn + ảnh báo sự cố, dùng chung cho form xử lý / chờ hoàn.
    $report_fields = static function (bool $with_code = true): void {
        ?>
        <?php if ($with_code) : ?>
            <label class="tow-f"><span>Mã vận đơn hoàn</span><input type="text" class="tow-input" data-p="return_code" placeholder="Mã vận đơn ĐVVC đang hoàn về"></label>
        <?php endif; ?>
        <label class="tow-file">
            <input type="file" class="tow-file__input" data-files="issue_images" accept="image/jpeg,image/png" capture="environment" multiple data-max="6">
            <span>⚠️ Ảnh báo sự cố (nếu có, tối đa 6)</span>
        </label>
        <?php
    };

    $next = thean_ow_return_next_status($return_status);
    if ($return_status === '' || $return_status === 'Hủy yêu cầu') {
        $requests = [
            'Cần đổi trả'                => 'Khách đổi / trả sản phẩm',
            'Cần thu hồi'                => 'Thu hồi hàng đã giao',
            'Cần giao lại'               => 'Giao lại đơn cho khách',
            'Chờ hoàn (Không giao được)' => 'ĐVVC không giao được, chờ hàng hoàn về',
            'Chờ hoàn (Hủy)'             => 'Khách hủy, chờ hàng hoàn về',
        ];
        $items = '';
        foreach ($requests as $s => $desc) {
            $items .= thean_ow_action_opt($s, $desc, ['data-action' => 'return_request', 'data-status' => $s, 'data-danger' => '1']);
        }
        echo '<div class="tow-btns">' . thean_ow_action_menu('↩️ Tạo yêu cầu hoàn / đổi…', 'Tạo yêu cầu hoàn hàng', $items) . '</div>';
    } elseif ($next !== '') {
        ?>
        <div class="tow-form" data-task="return_process">
            <div class="tow-fields">
                <label class="tow-f"><span>Mã vận đơn hoàn</span><input type="text" class="tow-input" data-p="return_code"></label>
                <label class="tow-f"><span>Ghi chú</span><input type="text" class="tow-input" data-p="return_note"></label>
            </div>
            <?php $report_fields(false); ?>
            <div class="tow-btns">
                <button type="button" class="button button-primary tow-act" data-action="return_process">Đã xử lý → <?php echo esc_html($next); ?></button>
                <button type="button" class="button tow-act" data-action="return_report">💾 Chỉ lưu mã / ảnh sự cố</button>
            </div>
        </div>
        <?php
    } elseif (strpos($return_status, 'Chờ hoàn') === 0) {
        ?>
        <div class="tow-form" data-task="return_receive">
            <?php $report_fields(); ?>
            <div class="tow-btns">
                <button type="button" class="button tow-act" data-action="return_report">💾 Lưu mã / ảnh sự cố (hàng đang hoàn)</button>
            </div>
            <label class="tow-file">
                <input type="file" class="tow-file__input" data-files="images" accept="image/jpeg,image/png" capture="environment" multiple data-max="6">
                <span>📷 Ảnh hàng hoàn về (tối đa 6)</span>
            </label>
            <label class="tow-check"><input type="checkbox" data-p="has_issue"<?php checked($issue_images || $order->get_meta('return_has_issue')); ?>> Hàng hoàn có sự cố</label>
            <?php if (!in_array($order->get_status(), thean_ow_closed_statuses(), true)) : ?>
                <label class="tow-check"><input type="checkbox" data-p="close_failed"> Hủy đơn: trả hàng về tồn kho rồi chuyển <strong>Thất bại</strong></label>
            <?php endif; ?>
            <button type="button" class="button button-primary tow-act" data-action="return_receive">📥 Đã nhận hàng hoàn</button>
        </div>
        <?php
    }
    thean_ow_box_close();
}

function thean_ow_box_hdsd(WC_Order $order): void
{
    $rows = [];
    foreach ($order->get_items() as $item) {
        $product_id = (int) $item->get_product_id();
        if (!$product_id || isset($rows[$product_id])) continue;
        $hdsd = trim((string) get_post_meta($product_id, 'product_info_hdsd', true));
        if ($hdsd !== '') $rows[$product_id] = ['name' => get_the_title($product_id), 'html' => wp_kses_post($hdsd)];
    }
    thean_ow_box_open('hdsd', '📖 HDSD sản phẩm', thean_ow_badge(count($rows) . ' SP', $rows ? 'info' : 'neutral'), false);
    if (!$rows) {
        echo '<p class="tow-muted">Sản phẩm trong đơn chưa có HDSD (field <code>product_info_hdsd</code>).</p>';
    } else {
        echo '<button type="button" class="button tow-copy-all">📋 Copy toàn bộ HDSD gửi khách</button>';
        foreach ($rows as $row) {
            echo '<details class="tow-sub tow-hdsd"><summary>' . esc_html($row['name']) . '</summary><div class="tow-hdsd__body" data-title="' . esc_attr($row['name']) . '">' . $row['html'] . '</div>';
            echo '<button type="button" class="button button-small tow-copy">📋 Copy</button></details>';
        }
    }
    thean_ow_box_close();
}

function thean_ow_box_customer(WC_Order $order): void
{
    $admin_url   = admin_url();
    $customer_id = $order->get_customer_id();
    $phone       = $order->get_billing_phone();
    $email       = $order->get_billing_email();
    $name        = trim($order->get_formatted_billing_full_name());
    thean_ow_box_open('customer', '👤 Khách hàng', $customer_id ? thean_ow_badge('ID ' . $customer_id, 'neutral') : thean_ow_badge('Khách vãng lai', 'neutral'), false);

    echo thean_ow_row('Tên', esc_html($name));
    if ($phone) {
        $zalo = preg_replace('/\D/', '', $phone);
        echo thean_ow_row('SĐT', '<a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a> · <a href="https://zalo.me/' . esc_attr($zalo) . '" target="_blank" rel="noopener">Zalo</a>');
    }
    echo thean_ow_row('Email', esc_html($email));

    foreach ($order->get_coupon_codes() as $code) {
        $coupon = new WC_Coupon($code);
        $amount = $coupon->get_discount_type() === 'percent' ? $coupon->get_amount() . '%' : wp_strip_all_tags(wc_price($coupon->get_amount()));
        echo thean_ow_row('Mã ưu đãi', '<code>' . esc_html($code) . '</code> ' . esc_html($amount) . ($coupon->get_description() ? ' <small class="tow-muted">' . esc_html($coupon->get_description()) . '</small>' : ''));
    }

    $links = [];
    if ($phone) $links['Đơn theo SĐT'] = thean_ow_orders_search_url($phone);
    if ($email) $links['Đơn theo email'] = thean_ow_orders_search_url($email);
    if ($customer_id) $links['Hồ sơ KH'] = $admin_url . 'user-edit.php?user_id=' . $customer_id;
    if ($email) $links['Mã ưu đãi của KH'] = add_query_arg(['post_type' => 'shop_coupon', 'customer_email' => $email], $admin_url . 'edit.php');
    $links['Tạo mã ưu đãi'] = $admin_url . 'post-new.php?post_type=shop_coupon';
    if ($chat = $order->get_meta('chat_link')) $links['Chat'] = $chat;
    if ($fb = $order->get_meta('facebook_link')) $links['Facebook'] = $fb;

    echo '<div class="tow-chips">';
    foreach ($links as $label => $url) echo '<a class="tow-chip" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
    echo '</div>';
    thean_ow_box_close();
}

function thean_ow_box_hold_cancel(WC_Order $order, bool $locked): void
{
    $on_hold = $order->get_status() === 'on-hold';
    thean_ow_box_open('hold', '⏸️ Tạm giữ / Hủy đơn', $on_hold ? thean_ow_badge('Đang tạm giữ', 'warning') : '', $on_hold);
    if ($locked) {
        echo '<p class="tow-hint">🔒 Đơn đã bị khóa đổi trạng thái (chỉ admin).</p>';
        thean_ow_box_close();
        return;
    }
    if ($on_hold) {
        echo '<p class="tow-hint">ⓘ Đơn đang tạm giữ: sửa sản phẩm / phí / địa chỉ ở form đơn bên dưới, bấm "Cập nhật", xong thì tiếp tục xử lý'
            . (thean_ow_resume_status($order) !== '' ? '' : ' (đơn CK chưa xác nhận thanh toán: xác nhận ở bước Thanh toán)') . '.</p>';
    } else {
        echo '<p class="tow-hint">ⓘ Tạm giữ để sửa sản phẩm / phí / địa chỉ (trang tải lại để mở sửa). Hủy đơn sẽ trả hàng về tồn kho.</p>';
    }
    ?>
    <div class="tow-form" data-task="order_flow">
        <label class="tow-f"><span>Lý do</span><input type="text" class="tow-input" data-p="reason" placeholder="VD: khách đổi sản phẩm, sai địa chỉ…"></label>
        <div class="tow-btns">
            <?php if ($on_hold && ($resume = thean_ow_resume_status($order)) !== '') : ?>
                <button type="button" class="button button-primary tow-act" data-action="order_flow" data-flow="resume">▶️ Tiếp tục → <?php echo esc_html(wc_get_order_status_name($resume)); ?></button>
            <?php elseif (!$on_hold) : ?>
                <button type="button" class="button tow-act" data-action="order_flow" data-flow="hold">⏸️ Tạm giữ để điều chỉnh</button>
            <?php endif; ?>
            <button type="button" class="button tow-btn-danger tow-act" data-action="order_flow" data-flow="cancel" data-danger="1">✖️ Hủy đơn</button>
        </div>
    </div>
    <?php
    thean_ow_box_close();
}

function thean_ow_box_tools(WC_Order $order, $order_no): void
{
    thean_ow_box_open('tools', '🖨️ Chứng từ & tiện ích');

    // Phiếu GHTK / Nhanh, PXK IVAR nằm ở bước Lấy hàng (thean_ow_box_picking).
    $links = [];
    if (current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce')) {
        $route = defined('ORDER_CREATOR_ROUTE') ? ORDER_CREATOR_ROUTE : 'tao-don';
        $links['✏️ Chỉnh đơn (Tạo đơn)'] = home_url('/' . $route . '/?order_id=' . $order->get_id());
    }
    echo '<div class="tow-chips">';
    foreach ($links as $label => $url) echo '<a class="tow-chip tow-chip--solid" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
    echo '<button type="button" class="tow-chip" data-copy="' . esc_attr((string) $order_no) . '">📋 Mã đơn</button>';
    echo '<button type="button" class="tow-chip" data-goto="picking">🖨️ In phiếu GHTK/Nhanh → Lấy hàng</button>';
    echo '</div>';
    thean_ow_box_close();
}

// ================= AJAX: sửa nhanh field / trạng thái / render lại bảng =================

add_action('wp_ajax_thean_order_workspace', 'thean_ow_ajax');
function thean_ow_ajax(): void
{
    check_ajax_referer(THEAN_OW_NONCE, 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Không có quyền.'], 403);
    }
    $order = wc_get_order(absint($_POST['order_id'] ?? 0));
    if (!$order instanceof WC_Order) {
        wp_send_json_error(['message' => 'Không tìm thấy đơn hàng.'], 404);
    }

    $task = sanitize_key($_POST['task'] ?? '');
    switch ($task) {
        case 'render':
            // Helper tem niêm phong nằm ở edit-order-page.php (chỉ load ở màn sửa đơn).
            if (!function_exists('hithean_order_item_has_the_an_organics_brand')) require_once __DIR__ . '/edit-order-page.php';
            ob_start();
            thean_ow_render_inner($order);
            wp_send_json_success([
                'html' => ob_get_clean(),
                'sync' => ['status' => $order->get_status(), 'fields' => thean_ow_metabox_values($order)],
            ]);
            break;

        case 'set_meta':
            $fields = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : [];
            $changed = thean_ow_save_fields($order, $fields);
            if (!$changed) wp_send_json_success(['message' => 'Không có thay đổi.']);
            $order->add_order_note('📝 Cập nhật từ bảng điều phối: ' . implode('; ', $changed));
            $order->save();
            wp_send_json_success(['message' => 'Đã lưu: ' . implode('; ', $changed)]);
            break;

        case 'set_status':
            $target = sanitize_key($_POST['status'] ?? '');
            if (!isset(thean_ow_delivery_statuses()[$target])) wp_send_json_error(['message' => 'Trạng thái không hợp lệ.']);
            if (function_exists('thean_order_status_is_locked') && thean_order_status_is_locked($order)) {
                wp_send_json_error(['message' => 'Đơn đã bị khóa đổi trạng thái (chỉ admin).']);
            }
            $order->update_status($target, 'Đổi trạng thái từ bảng điều phối đơn.', true);
            wp_send_json_success(['message' => 'Đã chuyển sang: ' . wc_get_order_status_name($target)]);
            break;

        case 'return_request':
            $target = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
            if (!array_key_exists($target, thean_ow_field_options('return_status'))) wp_send_json_error(['message' => 'Trạng thái hoàn không hợp lệ.']);
            $current = trim((string) $order->get_meta('return_status'));
            if ($current !== '' && $current !== 'Hủy yêu cầu') wp_send_json_error(['message' => 'Đơn đang có yêu cầu hoàn: ' . $current]);
            if (!function_exists('hithean_return_touch_meta')) require_once __DIR__ . '/../../shortcodes/shortcode-order-return-manager.php';
            $order->update_meta_data('return_status', $target);
            hithean_return_touch_meta($order, 'created');
            $order->add_order_note('HOÀN HÀNG tạo yêu cầu: ' . $target, false, true);
            $order->save();
            hithean_return_clear_cache();
            wp_send_json_success(['message' => 'Đã tạo yêu cầu: ' . $target]);
            break;

        case 'return_report':
            // Ghi mã vận đơn hoàn / ảnh báo sự cố khi hàng đang hoàn — không đổi trạng thái hoàn.
            $return_status = trim((string) $order->get_meta('return_status'));
            if ($return_status === '' || $return_status === 'Hủy yêu cầu') wp_send_json_error(['message' => 'Đơn chưa có yêu cầu hoàn.']);
            if (!function_exists('hithean_return_upload_files')) require_once __DIR__ . '/../../shortcodes/shortcode-order-return-manager.php';
            $code = sanitize_text_field(wp_unslash($_POST['return_code'] ?? ''));
            $urls = hithean_return_upload_files('issue_images', $order->get_id(), 'order-return-issue');
            if (is_wp_error($urls)) wp_send_json_error(['message' => $urls->get_error_message()]);
            $code_added = hithean_return_append_code($order, $code);
            if (!$code_added && !$urls) wp_send_json_error(['message' => $code !== '' ? 'Mã vận đơn hoàn đã có.' : 'Nhập mã vận đơn hoàn hoặc chọn ảnh sự cố.']);
            hithean_return_append_images($order, 'issue_report_images', $urls);
            if ($urls) $order->update_meta_data('return_has_issue', '1');
            hithean_return_touch_meta($order, 'code');
            $done = array_filter([$code_added ? 'mã vận đơn hoàn: ' . $code : '', $urls ? count($urls) . ' ảnh sự cố' : '']);
            $order->add_order_note('HOÀN HÀNG ghi nhận ' . implode(', ', $done), false, true);
            $order->save();
            hithean_return_clear_cache();
            wp_send_json_success(['message' => 'Đã lưu ' . implode(', ', $done) . '.']);
            break;

        case 'return_close':
            // Sau khi nhận hàng hoàn: trả tồn kho rồi chuyển Thất bại.
            if (strpos(trim((string) $order->get_meta('return_status')), 'Đã nhận hàng hoàn') !== 0) {
                wp_send_json_error(['message' => 'Đơn chưa ghi nhận đã nhận hàng hoàn.']);
            }
            if (in_array($order->get_status(), thean_ow_closed_statuses(), true)) {
                wp_send_json_error(['message' => 'Đơn đã ' . wc_get_order_status_name($order->get_status()) . '.']);
            }
            if (function_exists('thean_order_status_is_locked') && thean_order_status_is_locked($order)) {
                wp_send_json_error(['message' => 'Đơn đã bị khóa đổi trạng thái (chỉ admin).']);
            }
            // Trả tồn kho qua hook woocommerce_order_status_failed (order-auto-tasks.php).
            $order->update_status('failed', '↩️ Đã nhận hàng hoàn: hủy đơn, trả hàng về tồn kho, chuyển Thất bại.', true);
            wp_send_json_success(['message' => 'Đã trả tồn kho và chuyển đơn sang Thất bại.']);
            break;

        case 'order_flow':
            $flows = thean_ow_order_flows();
            $flow  = sanitize_key($_POST['flow'] ?? '');
            if (!isset($flows[$flow])) wp_send_json_error(['message' => 'Thao tác không hợp lệ.']);
            [$target, $need_reason, $label] = $flows[$flow];
            $status = $order->get_status();
            $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
            if (in_array($status, thean_ow_closed_statuses(), true)) wp_send_json_error(['message' => 'Đơn đã ' . wc_get_order_status_name($status) . '.']);
            if (function_exists('thean_order_status_is_locked') && thean_order_status_is_locked($order)) {
                wp_send_json_error(['message' => 'Đơn đã bị khóa đổi trạng thái (chỉ admin).']);
            }
            if ($need_reason && $reason === '') wp_send_json_error(['message' => 'Nhập lý do trước.']);
            if ($flow === 'resume' && $status !== 'on-hold') wp_send_json_error(['message' => 'Đơn không ở trạng thái Tạm giữ.']);
            if ($flow === 'resume') {
                $target = thean_ow_resume_status($order);
                if ($target === '') wp_send_json_error(['message' => 'Đơn CK chưa xác nhận thanh toán — xác nhận thanh toán trước.']);
            }
            if ($status === $target) wp_send_json_error(['message' => 'Đơn đã ở trạng thái ' . wc_get_order_status_name($target) . '.']);
            // Nhớ trạng thái trước khi tạm giữ để tiếp tục trả về đúng chỗ (đơn đang giao / đã giao / đã xong).
            if ($flow === 'hold') $order->update_meta_data('_thean_ow_hold_from', $status);
            else $order->delete_meta_data('_thean_ow_hold_from');
            // WooCommerce tự trả tồn kho khi chuyển Đã hủy (wc_maybe_increase_stock_levels).
            $order->update_status($target, $label . ($reason !== '' ? ': ' . $reason : '') . '.', true);
            wp_send_json_success(['message' => 'Đã chuyển sang: ' . wc_get_order_status_name($target)]);
            break;

        case 'cod_confirm':
            $results = thean_ow_cod_confirm_results();
            $result  = sanitize_key($_POST['result'] ?? '');
            if ($order->get_payment_method() !== 'cod' || !isset($results[$result])) wp_send_json_error(['message' => 'Thao tác không hợp lệ.']);
            $locked = function_exists('thean_order_status_is_locked') && thean_order_status_is_locked($order);
            if ($locked) wp_send_json_error(['message' => 'Đơn đã bị khóa đổi trạng thái (chỉ admin).']);
            [$add, $remove, $note] = $results[$result];
            thean_ow_update_list($order, 'handling_status', $add, $remove);
            // Như khách cũ ở order-auto-tasks.php: xác nhận xong chuyển "Chuẩn bị giao VC" để soạn hàng.
            if ($result === 'confirmed' && in_array($order->get_status(), ['pending', 'on-hold', 'processing'], true) && wc_is_order_status('wc-packaging')) {
                $order->update_status('packaging', '📞 ' . $note, true);
            } else {
                $order->add_order_note('📞 ' . $note, false, true);
            }
            wp_send_json_success(['message' => $note]);
            break;

        case 'to_bacs':
            // COD → chờ CK: đổi phương thức, gỡ nhãn chờ xác nhận COD, về Tạm giữ + "Chờ thanh toán".
            if ($order->get_payment_method() !== 'cod') wp_send_json_error(['message' => 'Đơn không phải COD.']);
            if (!in_array($order->get_status(), thean_ow_to_bacs_statuses(), true)) {
                wp_send_json_error(['message' => 'Đơn đã ' . wc_get_order_status_name($order->get_status()) . ' — không đổi sang chờ CK được.']);
            }
            if (function_exists('thean_order_status_is_locked') && thean_order_status_is_locked($order)) {
                wp_send_json_error(['message' => 'Đơn đã bị khóa đổi trạng thái (chỉ admin).']);
            }
            $order->set_payment_method('bacs');
            $order->set_payment_method_title('Chuyển khoản ngân hàng');
            thean_ow_update_list($order, 'handling_status', ['Chờ thanh toán'], ['Cần gọi xác nhận đơn', 'Chờ khách phản hồi', 'Cần báo hủy']);
            $note = '🔄 Khách chọn chuyển khoản: đổi COD → Chuyển khoản ngân hàng, chờ khách CK.';
            if ($order->get_status() !== 'on-hold') {
                $order->update_status('on-hold', $note, true);
            } else {
                $order->add_order_note($note, false, true);
                $order->save();
            }
            wp_send_json_success(['message' => 'Đã chuyển đơn sang chờ CK.']);
            break;
    }
    wp_send_json_error(['message' => 'Thao tác không hợp lệ.']);
}

/** Ghi field whitelist; multi field lưu nhiều dòng meta như Meta Box. Trả về mô tả thay đổi. */
function thean_ow_save_fields(WC_Order $order, array $fields): array
{
    $changed = [];
    foreach (thean_ow_editable_fields() as $key => $multiple) {
        if (!array_key_exists($key, $fields)) continue;
        $options = thean_ow_field_options($key);
        $current = thean_ow_meta_list($order, $key);
        $values  = array_values(array_filter(array_map(function ($v) { return trim(sanitize_text_field((string) $v)); }, (array) $fields[$key]), 'strlen'));
        // Chỉ nhận giá trị có trong options hoặc đang lưu sẵn (dữ liệu cũ ngoài options).
        if ($options) $values = array_values(array_filter($values, function ($v) use ($options, $current) { return array_key_exists($v, $options) || in_array($v, $current, true); }));
        if ($key === 'export_date') {
            $values = array_values(array_filter($values, function ($v) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v); }));
        }

        if ($multiple) {
            $values = array_values(array_unique($values));
            $a = $values; $b = $current; sort($a); sort($b);
            if ($a === $b) continue;
            thean_ow_set_list($order, $key, $values);
            $changed[] = thean_ow_key($key) . ' = ' . ($values ? implode(', ', $values) : '(trống)');
        } else {
            $value = $values[0] ?? '';
            if ([$value] === $current || ($value === '' && !$current)) continue;
            if ($value === '') $order->delete_meta_data(thean_ow_key($key)); else $order->update_meta_data(thean_ow_key($key), $value);
            $changed[] = thean_ow_key($key) . ' = ' . ($value !== '' ? $value : '(trống)');
        }
    }
    return $changed;
}
