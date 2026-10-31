<?php
/**
 * Cột "Thao tác" ở danh sách đơn admin: [nút bước tiếp theo] [✏ Sửa] [⋯ Thêm ▾].
 *
 * Mọi action vẫn lấy từ filter woocommerce_admin_order_actions (add_custom_actions, print_pxk...).
 * Khi WC đang in cột (giữa woocommerce_admin_order_actions_start/_end) ta giữ lại danh sách, trả về
 * rỗng để WC không in nút, rồi tự in ở _end. Nút/mục giữ class .wc-action-button-{slug} + href để
 * modal thao tác nhanh ở orders-admin.php vẫn bắt được. Ô tìm đơn (order-universal-search.php)
 * gọi filter ngoài cột nên vẫn nhận đủ action, dùng chung nhãn/nhóm ở hithean_order_action_meta().
 */
if (!defined('ABSPATH')) exit;

/** Nhóm hiển thị trong menu "Thêm" (cũng là nhóm màu ở ô tìm đơn). */
function hithean_order_action_groups(): array
{
    return ['pay' => 'Thanh toán', 'kho' => 'Kho / vận chuyển', 'hoan' => 'Hoàn / hoàn tiền', 'other' => 'Khách & khác'];
}

/** Nhãn đủ, nhãn ngắn (ô tìm đơn), biểu tượng và nhóm của từng action. */
function hithean_order_action_meta(string $slug): array
{
    $map = [
        'chinh-don-tao-don'                => ['other', '✏', 'Sửa đơn trên trang Tạo đơn', 'Chỉnh'],
        'hithean-order-payment'            => ['pay', '💳', 'Xác nhận thanh toán', 'Xác nhận TT'],
        'hithean-order-customer-confirmed' => ['pay', '✓', 'Khách đã xác nhận COD', 'Khách XN COD'],
        'qr-code'                          => ['pay', '▦', 'QR thanh toán', 'QR thanh toán'],
        'hithean-order-export-images'      => ['kho', '📦', 'Up ảnh xuất kho', 'Ảnh xuất kho'],
        'hithean-order-export-confirm'     => ['kho', '✓', 'Xác nhận xuất kho', 'XN xuất kho'],
        'in-van-don-ghtk'                  => ['kho', '🖨', 'In phiếu GHTK', 'In phiếu GHTK'],
        'print_pxk'                        => ['kho', '🖨', 'In PXK IVAR', 'PXK IVAR'],
        'hithean-order-return-record'      => ['hoan', '↩', 'Ghi nhận đơn hoàn hàng', 'Ghi nhận hoàn'],
        'hithean-order-return-images'      => ['hoan', '📷', 'Up ảnh hoàn hàng', 'Ảnh hoàn'],
        'don-hoan-tien'                    => ['hoan', '↩', 'Yêu cầu hoàn tiền cho khách', 'YC hoàn tiền'],
        'tra-vd-ghtk'                      => ['kho', '🔎', '', ''],
        'tra-vd-vtp'                       => ['kho', '🔎', '', ''],
        'goi-sdt-dat-hang'                 => ['other', '📞', '', 'Gọi người đặt'],
        'goi-sdt-nhan-hang'                => ['other', '📞', '', 'Gọi người nhận'],
    ];
    [$group, $icon, $label, $short] = $map[$slug] ?? ['other', '•', '', ''];
    return ['group' => $group, 'icon' => $icon, 'label' => $label, 'short' => $short];
}

/** Meta nhiều giá trị (handling_status...) → mảng chuỗi. $map: tên field → meta key riêng của hithean (như thean_ow_key). */
function hithean_oac_meta_list(WC_Order $order, string $key): array
{
    $map = [
        'handling_status'       => 'order_handling_status',
        'paid_date'             => 'order_paid_date',
        'bank_account_received' => 'order_bank_account_received',
        'shipper'               => 'order_shipper',
        'ship_code'             => 'order_ship_code',
    ];
    $out = [];
    foreach ($order->get_meta($map[$key] ?? $key, false) as $meta) {
        foreach ((array) ($meta instanceof WC_Meta_Data ? $meta->value : $meta) as $v) {
            $v = trim((string) $v);
            if ($v !== '') $out[] = $v;
        }
    }
    return $out;
}

/** Trạng thái còn ở khâu kho (chưa giao) — mới gợi ý bước up ảnh / xác nhận xuất kho. */
function hithean_oac_export_statuses(): array
{
    return ['processing', 'packaging', 'paid', 'received-payment', 'partial-paid', 'chua-ghi-nhan', 'local-pickup'];
}

/**
 * Action của bước tiếp theo (rút gọn từ luồng ở order-workspace.php):
 * COD cần khách xác nhận → CK chưa thanh toán → chưa có ảnh xuất kho → chưa xác nhận xuất kho.
 * Trả '' khi không còn bước nào cần làm ở danh sách (đang giao, đã xong, đã hủy...).
 */
function hithean_oac_primary_slug(WC_Order $order, array $actions): string
{
    $status = $order->get_status();
    if (in_array($status, ['cancelled', 'failed', 'refunded', 'trash', 'completed', 'checkout-draft'], true)) {
        return '';
    }

    $handling = hithean_oac_meta_list($order, 'handling_status');

    if ($order->get_payment_method() === 'cod') {
        $self_placed = in_array($order->get_created_via(), ['checkout', 'store-api'], true);
        $tagged      = array_intersect(['Cần gọi xác nhận đơn', 'Chờ khách phản hồi', 'Cần báo hủy'], $handling);
        $needs       = $status === 'on-hold' || ($status === 'processing' && ($self_placed || $tagged));
        if ($needs && !in_array('Đã xác nhận đơn', $handling, true) && isset($actions['hithean-order-customer-confirmed'])) {
            return 'hithean-order-customer-confirmed';
        }
    } else {
        $paid = in_array($status, ['paid', 'received-payment', 'completed'], true)
            || (string) $order->get_meta('_order_payment_confirmation_audit') !== ''
            || (hithean_oac_meta_list($order, 'paid_date') && hithean_oac_meta_list($order, 'bank_account_received'));
        if (!$paid && isset($actions['hithean-order-payment'])) {
            return 'hithean-order-payment';
        }
    }

    if (!in_array($status, hithean_oac_export_statuses(), true)) {
        return '';
    }
    if (trim((string) $order->get_meta('warehouse_export_images')) === '' && isset($actions['hithean-order-export-images'])) {
        return 'hithean-order-export-images';
    }
    if (!$order->get_meta('export_confirmed_by') && isset($actions['hithean-order-export-confirm'])) {
        return 'hithean-order-export-confirm';
    }
    return '';
}

/**
 * "Tra vận đơn" theo mã của đơn (link cho khách): GHTK mở thẳng i.ghtk.vn/<mã>; Viettel Post không nhận mã
 * qua URL nên mở trang tra cứu + chép sẵn mã (data-hithean-oa-copy). Link tra cho kho nằm trên thanh công cụ.
 */
add_filter('woocommerce_admin_order_actions', 'hithean_oac_tracking_actions', 150, 2);
function hithean_oac_tracking_actions($actions, $order)
{
    if (!$order instanceof WC_Order) {
        return $actions;
    }
    $shippers = hithean_oac_meta_list($order, 'shipper');
    $carriers = array_intersect(['ghtk', 'viettel'], $shippers);
    if (!$carriers) {
        return $actions;
    }
    $codes = preg_split('/[\s,;|]+/u', implode(' ', hithean_oac_meta_list($order, 'ship_code'))) ?: [];
    $codes = array_slice(array_unique(array_filter($codes, fn($c) => strlen($c) >= 6 && preg_match('/\d/', $c))), 0, 3);
    foreach ($codes as $i => $code) {
        if (in_array('ghtk', $carriers, true)) {
            $actions['tra-vd-ghtk-' . $i] = [
                'url'    => 'https://i.ghtk.vn/' . rawurlencode($code),
                'name'   => 'Tra GHTK · ' . $code,
                'action' => 'tra-vd-ghtk',
                'target' => '_blank',
            ];
        }
        if (in_array('viettel', $carriers, true)) {
            $actions['tra-vd-vtp-' . $i] = [
                'url'    => 'https://viettelpost.com.vn/tra-cuu-hanh-trinh-don/',
                'name'   => 'Tra Viettel · ' . $code,
                'action' => 'tra-vd-vtp',
                'target' => '_blank',
                'copy'   => $code,
            ];
        }
    }
    return $actions;
}

add_action('woocommerce_admin_order_actions_start', function () {
    $GLOBALS['hithean_oac_capturing'] = true;
});

add_filter('woocommerce_admin_order_actions', 'hithean_oac_capture', PHP_INT_MAX, 2);
function hithean_oac_capture($actions, $order)
{
    if (empty($GLOBALS['hithean_oac_capturing'])) {
        return $actions;
    }
    $GLOBALS['hithean_oac_actions'] = (array) $actions;
    return [];
}

add_action('woocommerce_admin_order_actions_end', 'hithean_oac_render');
function hithean_oac_render($order): void
{
    $actions = $GLOBALS['hithean_oac_actions'] ?? [];
    unset($GLOBALS['hithean_oac_capturing'], $GLOBALS['hithean_oac_actions']);
    if (!$order instanceof WC_Order || !$actions) {
        return;
    }

    $primary = hithean_oac_primary_slug($order, $actions);
    $head    = '';
    $groups  = array_fill_keys(array_keys(hithean_order_action_groups()), '');

    foreach ($actions as $key => $action) {
        if (empty($action['url'])) {
            continue;
        }
        $slug  = sanitize_html_class($action['action'] ?? $key);
        $meta  = hithean_order_action_meta($slug);
        $label = $meta['label'] !== '' ? $meta['label'] : wp_strip_all_tags((string) ($action['name'] ?? $key));
        $attrs = ' href="' . esc_url($action['url']) . '" title="' . esc_attr($label) . '"'
            . (!empty($action['target']) ? ' target="_blank" rel="noopener"' : '')
            . (!empty($action['copy']) ? ' data-hithean-oa-copy="' . esc_attr($action['copy']) . '"' : '');
        $inner = '<span aria-hidden="true">' . esc_html($meta['icon']) . '</span>';

        if ($slug === 'chinh-don-tao-don') {
            // Có chữ "Sửa" (không chỉ biểu tượng) để dễ nhận ra; title giữ nhãn đầy đủ.
            $head .= '<a class="hithean-oa__btn wc-action-button-' . esc_attr($slug) . '"' . $attrs . '>' . $inner
                . '<span class="hithean-oa__txt">Sửa</span></a>';
        } elseif ($key === $primary) {
            $head = '<a class="hithean-oa__btn hithean-oa__btn--primary wc-action-button-' . esc_attr($slug) . '"' . $attrs . '>' . $inner
                . '<span class="hithean-oa__txt">' . esc_html($label) . '</span></a>' . $head;
        } else {
            $tone = $meta['group'] === 'hoan' ? ' hithean-oa__item--warn' : '';
            $groups[$meta['group']] .= '<a class="hithean-oa__item' . $tone . ' wc-action-button-' . esc_attr($slug) . '"' . $attrs . '>' . $inner
                . '<span class="hithean-oa__txt">' . esc_html($label) . '</span></a>';
        }
    }

    $menu = '';
    foreach (hithean_order_action_groups() as $group => $title) {
        if ($groups[$group] !== '') {
            $menu .= '<span class="hithean-oa__group">' . esc_html($title) . '</span>' . $groups[$group];
        }
    }
    if ($menu !== '') {
        $head .= '<button type="button" class="hithean-oa__btn" data-hithean-oa-toggle aria-expanded="false">⋯ Thêm</button>'
            . '<span class="hithean-oa__menu" role="menu" hidden>' . $menu . '</span>';
    }

    // Chỉ dùng thẻ inline: WC bọc cột trong <p>.
    echo '<span class="hithean-oa no-link">' . $head . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- đã escape từng phần
}

/** Link dùng chung (không theo từng đơn) — đặt 1 lần trên thanh công cụ thay vì lặp ở mỗi dòng. */
function hithean_oac_toolbar_links(): array
{
    return [
        'https://khachhang.giaohangtietkiem.vn/web/don-hang'                         => '🔎 Tra đơn GHTK',
        'https://viettelpost.vn/quan-ly-van-don'                                     => '🔎 Tra đơn Viettel',
        'https://ivarvietnam.sg.larksuite.com/share/base/view/shrlgfNPS0U52nGaZClUNvARIpb' => 'Theo dõi hoàn tiền KH',
        'https://ivarvietnam.sg.larksuite.com/share/base/view/shrlgvghQ7MdT8Dn24B500a5pBg' => 'Theo dõi bồi hoàn',
    ];
}

function hithean_oac_render_toolbar(string $which): void
{
    if ($which !== 'top' || !current_user_can('manage_woocommerce')) {
        return;
    }
    echo '<div class="alignleft actions hithean-oa-toolbar">';
    foreach (hithean_oac_toolbar_links() as $url => $label) {
        echo '<a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
    }
    echo '</div>';
}

add_action('manage_posts_extra_tablenav', function ($which) {
    global $typenow;
    if ($typenow === 'shop_order') {
        hithean_oac_render_toolbar((string) $which);
    }
});
add_action('woocommerce_order_list_table_extra_tablenav', function ($order_type, $which) {
    if ($order_type === 'shop_order') {
        hithean_oac_render_toolbar((string) $which);
    }
}, 10, 2);

add_action('admin_head', 'hithean_oac_assets');
function hithean_oac_assets(): void
{
    if (!function_exists('hithean_order_admin_is_list_screen') || !hithean_order_admin_is_list_screen()) {
        return;
    }
    ?>
    <style>
        .column-wc_actions .hithean-oa { position:relative; display:inline-flex; flex-wrap:wrap; align-items:center; gap:6px; }
        .hithean-oa__btn { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#1e293b; font-size:13px; font-weight:600; line-height:1.6; text-decoration:none; white-space:nowrap; cursor:pointer; }
        .hithean-oa__btn:hover, .hithean-oa__btn:focus { border-color:#0047ba; color:#0047ba; }
        .hithean-oa__btn--primary { border-color:#0047ba; background:#0047ba; color:#fff; }
        .hithean-oa__btn--primary:hover, .hithean-oa__btn--primary:focus { background:#003a99; color:#fff; }
        .hithean-oa__menu { position:absolute; top:calc(100% + 4px); right:0; z-index:100; display:grid; min-width:230px; padding:6px; border:1px solid #e2e8f0; border-radius:8px; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.18); }
        .hithean-oa__menu[hidden] { display:none; }
        .hithean-oa__group { padding:6px 8px 2px; color:#64748b; font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
        .hithean-oa__item { display:flex; align-items:center; gap:8px; padding:6px 8px; border-radius:5px; color:#1e293b; font-size:13px; line-height:1.4; text-decoration:none; white-space:nowrap; }
        .hithean-oa__item:hover, .hithean-oa__item:focus { background:#eef4ff; color:#0047ba; }
        .hithean-oa__item--warn { color:#b45309; }
        .hithean-oa .is-hithean-complete { opacity:.6; pointer-events:none; }
        .hithean-oa .is-hithean-complete .hithean-oa__txt { display:none; }
        .hithean-oa .is-hithean-complete::after { content:attr(data-hithean-complete-label); }
        .hithean-oa-toolbar { display:flex; flex-wrap:wrap; gap:4px; }
    </style>
    <script>
    (function () {
        function closeAll(except) {
            document.querySelectorAll('.hithean-oa__menu:not([hidden])').forEach(function (menu) {
                if (menu === except) return;
                menu.hidden = true;
                menu.previousElementSibling.setAttribute('aria-expanded', 'false');
            });
        }
        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-hithean-oa-toggle]');
            if (toggle) {
                event.preventDefault();
                var menu = toggle.nextElementSibling;
                closeAll(menu);
                menu.hidden = !menu.hidden;
                toggle.setAttribute('aria-expanded', String(!menu.hidden));
                return;
            }
            var copy = event.target.closest('[data-hithean-oa-copy]');
            if (copy && navigator.clipboard) navigator.clipboard.writeText(copy.getAttribute('data-hithean-oa-copy')).catch(function () {});
            if (event.target.closest('.hithean-oa__menu') && !event.target.closest('a')) return;
            closeAll(null);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeAll(null);
        });
    }());
    </script>
    <?php
}
