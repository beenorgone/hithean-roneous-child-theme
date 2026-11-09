<?php
/**
 * Cấu hình docs-sync riêng của hithean (engine dùng chung: tools/docs-sync.php, giống hệt bản theanmarket).
 * Đường dẫn tương đối thư mục gốc repo; tiền tố khu tương đối custom-functions/.
 */

return [
    // Khu code-map: mặc định 1 cấp thư mục. Khu lớn chia sâu hơn.
    'area_rules' => [
        'woocommerce/'                => 2, // woocommerce/orders, /products, /checkout
        'woocommerce/orders/order-creator/' => 3,
        'marketing/offers/'           => 2,
        'marketing/lucky-wheel/'      => 2,
    ],
    'layer_rules' => [
        'woocommerce/' => 2,
    ],

    'area_desc' => [
        'bootstrap'            => 'Điểm vào: bootstrap.php chỉ require core/* rồi core/module-loader.php; shortcode-social-display.php (social fan/marquee/collage, nạp theo trang).',
        'core'                 => 'Hạ tầng theme: module loader (what-loads-when), nạp theo màn admin, template router, enqueue, guard upload/file/CSP, AI settings/providers, địa chỉ VN, thông tin công ty, admin chrome.',
        'admin'                => 'wp-admin: hướng dẫn shortcode, metabox bài viết, trang plugins, editor tools.',
        'auth'                 => 'Đăng nhập OTP email / SMS Firebase, email lỗi, email tác vụ.',
        'integrations'         => 'Adapter ngoài theme: MCP liên site (mcp-site-link), hóa đơn eim (shipper / mã vận đơn / meta xuất kho), GHTK.',
        'marketing'            => 'Marketing: popup, thanh menu đáy + "Ưu đãi của tôi", vòng quay may mắn, CRO landing agent (chạy theo lịch).',
        'marketing/offers'     => 'Trang ưu đãi /uu-dai/: lưu trữ, bóc tách AI, thẻ ưu đãi, structured data, worker nền.',
        'marketing/lucky-wheel' => 'Vòng quay may mắn: cài đặt, widget, dọn mã.',
        'shortcodes'           => 'Shortcode: xuất kho / hoàn hàng / đơn đã giao, so sánh SP (+AI), protein calculator, landing ANC, thông tin công ty, nhúng Google Docs/Sheets…',
        'woocommerce/orders'   => 'Đơn hàng: trạng thái, số đơn, khóa đơn cũ, bước vận hành + board "Cần xử lý", bảng điều phối, tìm đơn, /quan-ly-don, HPOS, VAT, xuất kho + Lark.',
        'woocommerce/orders/order-creator' => 'Trang Tạo đơn hộ khách /tao-don/.',
        'woocommerce/products' => 'Sản phẩm: trang SP, mua hàng, lượt bán, tab, taxonomy, nhãn dinh dưỡng, popup giỏ hàng, nhúng hồ sơ IVAR.',
        'woocommerce/checkout' => 'Checkout: TK nhận tiền (BACS + eim), trang thanh toán, trang cảm ơn, xác nhận chuyển khoản / chờ thanh toán.',
    ],

    'module_tree' => ['marketing/offers', 'marketing/lucky-wheel', 'woocommerce/orders/order-creator'],

    // Route frontend phải có dòng trong bảng <!-- ROUTES --> của docs/architecture.md.
    // define('…ROUTE…', 'slug') ở bất kỳ file nào được engine tự quét thêm.
    'routes' => static function (array $sources): array {
        $routes = [];
        $ml     = $sources['custom-functions/core/module-loader.php'] ?? '';
        if (preg_match_all("/'paths'\\s*=>\\s*\\[([^\\]]*)\\]/", $ml, $m)) {
            foreach ($m[1] as $list) {
                preg_match_all("/'\\/([a-z0-9\\/-]+)'/", $list, $p);
                $routes = array_merge($routes, $p[1]);
            }
        }
        foreach (ds_function_strings($ml, 'tpc_loader_path_groups') as $s) {
            if ($s !== '' && $s[0] === '/') {
                $routes[] = $s;
            }
        }
        return $routes;
    },

    'guide_web_check' => false,
    'domain_glob'     => '', // hithean không có ERP
    'manual_globs'    => [],
    'skip_dirs'       => [],
];
