<?php
if (!defined('ABSPATH')) exit;

/**
 * Ghi chú khi thao tác trên đơn + sự cố giao hàng. Nạp từ order-ops-step.php (mọi request — bước board cần đọc cờ sự cố).
 *
 * 1. Mẫu ghi chú gợi ý (bấm để chèn) cho popup thao tác luồng đơn (assets/order-flow-popup.js): option
 *    `thean_flow_note_templates` = [kind => string[]], sửa ở Cài đặt ERP → Xử lý đơn → "Mẫu ghi chú thao tác".
 *    Option chưa có → mẫu mặc định; đã lưu (kể cả để trống 1 loại) → dùng đúng giá trị đã lưu.
 * 2. Sự cố giao hàng: cờ `_thean_delivery_issue` = '1' (+ _note / _at / _by). Đơn đang ở bước Chờ giao VC / Đang giao mà có cờ
 *    → bước 'issue' (tab "Sự cố giao hàng", thean_ops_step_compute). Gắn cờ BẮT BUỘC ghi chú; gỡ cờ khi xử lý xong
 *    (ghi chú không bắt buộc) hoặc tự gỡ khi đơn chuyển Đã giao / Hoàn thành / Hủy / Thất bại / Hoàn tiền.
 * 3. Tạo ghi chú đơn (nội bộ / gửi khách) từ ô tìm đơn, board ở danh sách đơn và /quan-ly-don (nút 📝 Ghi chú).
 * AJAX: task `delivery_issue` + `add_note` của thean_order_workspace (order-workspace.php).
 */

const THEAN_DELIVERY_ISSUE_META = '_thean_delivery_issue';

/** Loại thao tác có ô ghi chú (khóa = kind của order-flow-popup.js) => nhãn ở trang cài đặt. */
function thean_flow_note_kinds(): array
{
    return [
        'note'          => '📝 Tạo ghi chú đơn',
        'issue'         => '⚠️ Báo sự cố giao hàng',
        'issue_resolve' => '✅ Xử lý xong sự cố giao hàng',
        'hold'          => '⏸️ Tạm giữ đơn',
        'resume'        => '▶️ Tiếp tục xử lý',
        'cancel'        => '✖️ Hủy đơn',
        'preorder'      => '📅 Chuyển sang đặt trước',
        'confirm'       => '📞 Xác nhận giao đơn đặt trước',
        'skipproof'     => '⏭️ Bỏ qua thiếu ảnh xuất kho',
    ];
}

function thean_flow_note_templates_defaults(): array
{
    return [
        'note'          => ['Đã gọi khách, chờ phản hồi.', 'Đã nhắn Zalo cho khách.', 'Cần theo dõi lại trong ca sau.', 'Khách yêu cầu giao giờ hành chính.'],
        'issue'         => ['Không liên lạc được khách.', 'Khách hẹn giao lại.', 'Sai / thiếu địa chỉ giao.', 'Khách từ chối nhận hàng.', 'Hàng hư hỏng / móp khi giao.', 'ĐVVC giao chậm / thất lạc hàng.'],
        'issue_resolve' => ['Đã liên lạc được khách, giao lại.', 'Đã cập nhật địa chỉ, giao lại.', 'Khách đã nhận hàng.', 'Chuyển sang hoàn hàng.'],
        'hold'          => ['Khách đổi sản phẩm.', 'Sai địa chỉ, chờ khách xác nhận.', 'Chờ hàng về.'],
        'resume'        => ['Khách đã xác nhận.', 'Hàng đã về.'],
        'cancel'        => ['Khách hủy đơn.', 'Trùng đơn.', 'Không liên lạc được khách.', 'Hết hàng.'],
        'preorder'      => ['Chờ hàng về.', 'Khách hẹn giao sau.'],
        'confirm'       => ['Đã gọi, khách nghe máy.', 'Đã nhắn Zalo, chờ khách trả lời.'],
        'skipproof'     => ['Đơn giao thẳng từ NCC.', 'Ảnh lưu ở nơi khác.'],
    ];
}

/** Tách mẫu: 1 mẫu / dòng, bỏ trùng + dòng trống, ≤ 20 mẫu, ≤ 200 ký tự. Hàm thuần. */
function thean_flow_note_parse_lines(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line));
        if ($line !== '') {
            $out[] = function_exists('mb_substr') ? mb_substr($line, 0, 200) : substr($line, 0, 200);
        }
    }
    return array_slice(array_values(array_unique($out)), 0, 20);
}

/** @return array<string, string[]> mẫu theo kind (mọi kind của thean_flow_note_kinds đều có khóa). */
function thean_flow_note_templates(): array
{
    $saved    = get_option('thean_flow_note_templates', null);
    $defaults = thean_flow_note_templates_defaults();
    $out      = [];
    foreach (array_keys(thean_flow_note_kinds()) as $kind) {
        $list = is_array($saved) && array_key_exists($kind, $saved) ? (array) $saved[$kind] : ($defaults[$kind] ?? []);
        $out[$kind] = array_values(array_filter(array_map('strval', $list), 'strlen'));
    }
    return $out;
}

/** Mục "Mẫu ghi chú thao tác" ở Cài đặt ERP → Xử lý đơn (gọi từ thean_order_ops_settings_section). */
function thean_flow_note_templates_settings_section(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $saved = false;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['thean_flow_notes_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['thean_flow_notes_nonce'])), 'thean_flow_notes_save')) {
        $raw = isset($_POST['thean_flow_note_templates']) && is_array($_POST['thean_flow_note_templates']) ? wp_unslash($_POST['thean_flow_note_templates']) : [];
        $value = [];
        foreach (array_keys(thean_flow_note_kinds()) as $kind) {
            $value[$kind] = thean_flow_note_parse_lines(sanitize_textarea_field((string) ($raw[$kind] ?? '')));
        }
        if (!empty($_POST['thean_flow_notes_reset'])) {
            delete_option('thean_flow_note_templates');
        } else {
            update_option('thean_flow_note_templates', $value, false);
        }
        $saved = true;
    }
    $templates = thean_flow_note_templates();
    ?>
    <?php if ($saved) : ?><div class="notice notice-success is-dismissible"><p>✅ Đã lưu mẫu ghi chú thao tác.</p></div><?php endif; ?>
    <form method="post">
        <?php wp_nonce_field('thean_flow_notes_save', 'thean_flow_notes_nonce'); ?>
        <h2>Mẫu ghi chú thao tác</h2>
        <p class="description">Nội dung gợi ý hiện dưới ô ghi chú của popup thao tác đơn (ô tìm đơn, board, /quan-ly-don, trang sửa đơn) — bấm để chèn,
            sửa thêm được trước khi lưu. <strong>Mỗi dòng 1 mẫu</strong> (tối đa 20). Để trống = không gợi ý cho thao tác đó.</p>
        <table class="form-table" role="presentation"><tbody>
            <?php foreach (thean_flow_note_kinds() as $kind => $label) : ?>
                <tr>
                    <th scope="row"><label for="thean-flow-note-<?php echo esc_attr($kind); ?>"><?php echo esc_html($label); ?></label></th>
                    <td><textarea class="large-text" rows="4" style="max-width:600px" id="thean-flow-note-<?php echo esc_attr($kind); ?>" name="thean_flow_note_templates[<?php echo esc_attr($kind); ?>]"><?php echo esc_textarea(implode("\n", $templates[$kind] ?? [])); ?></textarea></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table>
        <p><label><input type="checkbox" name="thean_flow_notes_reset" value="1"> Khôi phục mẫu mặc định (bỏ qua nội dung đang sửa)</label></p>
        <?php submit_button('Lưu mẫu ghi chú'); ?>
    </form>
    <?php
}

/* ---------- Sự cố giao hàng ---------- */

/** ['on' => bool, 'note' => string, 'at' => int, 'by' => string] */
function thean_delivery_issue(WC_Order $order): array
{
    $by = (int) $order->get_meta(THEAN_DELIVERY_ISSUE_META . '_by');
    $user = $by ? get_userdata($by) : null;
    return [
        'on'   => (string) $order->get_meta(THEAN_DELIVERY_ISSUE_META) === '1',
        'note' => (string) $order->get_meta(THEAN_DELIVERY_ISSUE_META . '_note'),
        'at'   => (int) $order->get_meta(THEAN_DELIVERY_ISSUE_META . '_at'),
        'by'   => $user ? $user->display_name : '',
    ];
}

/** Bước board được tách sang "Sự cố giao hàng" khi có cờ (luồng giao hàng). */
function thean_delivery_issue_steps(): array
{
    return ['delivery', 'shipping'];
}

/** Trạng thái tự gỡ cờ sự cố (giao xong / đóng đơn). */
function thean_delivery_issue_clear_statuses(): array
{
    return ['delivered', 'completed', 'cancelled', 'failed', 'refunded'];
}

/**
 * Gắn / cập nhật (op flag) hoặc gỡ (op resolve) cờ sự cố. Gắn cờ bắt buộc ghi chú; chỉ đơn đang ở luồng giao hàng
 * (Chờ giao VC / Đang giao / đã có sự cố). Trả [ok, message].
 */
function thean_delivery_issue_apply(WC_Order $order, string $op, string $note): array
{
    $note  = trim($note);
    $issue = thean_delivery_issue($order);
    if ($op === 'flag') {
        if ($note === '') return [false, 'Nhập ghi chú sự cố trước.'];
        if (!$issue['on']) {
            $step = function_exists('thean_ops_step_compute') ? (string) thean_ops_step_compute($order, false)['step'] : '';
            if (!in_array($step, thean_delivery_issue_steps(), true)) {
                return [false, 'Chỉ báo sự cố cho đơn đang ở bước Chờ giao VC / Đang giao.'];
            }
        }
        $order->update_meta_data(THEAN_DELIVERY_ISSUE_META, '1');
        $order->update_meta_data(THEAN_DELIVERY_ISSUE_META . '_note', $note);
        $order->update_meta_data(THEAN_DELIVERY_ISSUE_META . '_at', (string) time());
        $order->update_meta_data(THEAN_DELIVERY_ISSUE_META . '_by', (string) get_current_user_id());
        $order->add_order_note(($issue['on'] ? '⚠️ Cập nhật sự cố giao hàng: ' : '⚠️ Sự cố giao hàng: ') . $note, false, true);
        $order->save();
        return [true, $issue['on'] ? 'Đã cập nhật sự cố giao hàng.' : 'Đã chuyển đơn sang Sự cố giao hàng.'];
    }
    if ($op === 'resolve') {
        if (!$issue['on']) return [false, 'Đơn không có sự cố giao hàng.'];
        thean_delivery_issue_clear($order);
        $order->add_order_note('✅ Đã xử lý sự cố giao hàng' . ($note !== '' ? ': ' . $note : '.'), false, true);
        $order->save();
        return [true, 'Đã gỡ sự cố — đơn quay lại luồng giao hàng.'];
    }
    return [false, 'Thao tác không hợp lệ.'];
}

function thean_delivery_issue_clear(WC_Order $order): void
{
    foreach (['', '_note', '_at', '_by'] as $suffix) {
        $order->delete_meta_data(THEAN_DELIVERY_ISSUE_META . $suffix);
    }
}

add_action('woocommerce_order_status_changed', function ($order_id, $from, $to, $order = null) {
    if (!in_array($to, thean_delivery_issue_clear_statuses(), true)) return;
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    if (!$order instanceof WC_Order || (string) $order->get_meta(THEAN_DELIVERY_ISSUE_META) !== '1') return;
    thean_delivery_issue_clear($order);
    $order->add_order_note('✅ Tự gỡ cờ sự cố giao hàng (đơn chuyển ' . wc_get_order_status_name($to) . ').', false, false);
    $order->save();
}, 20, 4);

/* ---------- Tạo ghi chú đơn ---------- */

/** Thêm ghi chú đơn: $type private (nội bộ) | customer (gửi email cho khách). Trả [ok, message]. */
function thean_order_add_flow_note(WC_Order $order, string $note, string $type): array
{
    $note = trim($note);
    if ($note === '') return [false, 'Nhập nội dung ghi chú.'];
    $customer = $type === 'customer';
    $order->add_order_note($note, $customer, true);
    // add_order_note không lưu đơn → chạm đơn để board / xem nhanh đọc lại (đổi version cache orders).
    $order->save();
    return [true, $customer ? 'Đã gửi ghi chú cho khách.' : 'Đã thêm ghi chú nội bộ.'];
}

/** Nút 📝 Ghi chú (popup kind note). */
function thean_order_note_button(WC_Order $order): string
{
    if (!current_user_can('manage_woocommerce')) return '';
    return '<button type="button" class="thean-ous-btn thean-ous-btn--flow" data-thean-flow="note" data-order-id="' . esc_attr($order->get_id()) . '">📝 Ghi chú</button>';
}

/** Nút Báo sự cố / Xử lý xong sự cố theo bước board. */
function thean_delivery_issue_buttons(WC_Order $order, string $step): array
{
    if (!current_user_can('manage_woocommerce')) return [];
    $id = ' data-order-id="' . esc_attr($order->get_id()) . '"';
    if ($step === 'issue') {
        return [
            '<button type="button" class="thean-ous-btn thean-ous-btn--primary" data-thean-flow="issue_resolve"' . $id . '>✅ Xử lý xong sự cố</button>',
            '<button type="button" class="thean-ous-btn thean-ous-btn--flow" data-thean-flow="issue"' . $id . '>⚠️ Cập nhật sự cố</button>',
        ];
    }
    if (in_array($step, thean_delivery_issue_steps(), true)) {
        return ['<button type="button" class="thean-ous-btn thean-ous-btn--danger" data-thean-flow="issue"' . $id . '>⚠️ Báo sự cố</button>'];
    }
    return [];
}
