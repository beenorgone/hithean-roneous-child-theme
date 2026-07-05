<?php
/**
 * Trang admin: Shortcodes Guide
 * Hiển thị tài liệu + hướng dẫn dùng các shortcode của theme.
 *
 * Thêm guide cho shortcode khác qua filter `ivar_shortcodes_guide`:
 *   add_filter('ivar_shortcodes_guide', function ($guides) {
 *       $guides['my_shortcode'] = [ 'name' => '[my_shortcode]', ... ];
 *       return $guides;
 *   });
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
    return;
}

if (!function_exists('ivar_shortcodes_guide_data')) {
    /**
     * Cấu trúc mỗi guide:
     *   name        string   tên hiển thị, vd "[certifications]"
     *   summary     string   mô tả ngắn (HTML cho phép)
     *   args        array    [ [name, desc, default], ... ]  (bảng arguments)
     *   sections    array    [ [title, args[]], ... ]         (nhóm arguments; dùng thay `args` nếu muốn tách nhóm)
     *   examples    array    [ [label, code], ... ]           (khối code ví dụ)
     *   notes       string[] ghi chú (HTML cho phép)
     */
    function ivar_shortcodes_guide_data(): array
    {
        $guides = [
            'certifications' => [
                'name'    => '[certifications]',
                'summary' => 'Khối "Hệ thống sản xuất &amp; chứng nhận": 3 card (Hữu cơ / Eurofins / ISO·HACCP·GMP) mở modal chi tiết + modal gộp. Nội dung card cấu hình qua filter <code>ivar_certifications_config</code>; giao diện chỉnh qua các argument dưới đây (map sang CSS variables, áp cho cả section lẫn modal).',
                'sections' => [
                    [
                        'title' => 'Màu sắc',
                        'args'  => [
                            ['bg', 'Màu nền section.', '#614132'],
                            ['primary', 'Màu chính: tiêu đề modal, số thứ tự bước, link, tiêu đề card, chữ "Xem chi tiết".', '#0047ba'],
                            ['accent', 'Màu nhấn: viền trên mỗi card, viền trái khối "Kiểm tra chéo", nút "Tìm hiểu thêm".', '#fbb917'],
                            ['on_bg', 'Màu chữ tiêu đề / mô tả nằm trên nền section (thường để sáng khi nền tối).', 'trắng'],
                            ['card_bg', 'Màu nền của card và của modal.', '#fff'],
                            ['text', 'Màu chữ nội dung (mô tả, danh sách, các bước).', '#555'],
                        ],
                    ],
                    [
                        'title' => 'Bố cục',
                        'args'  => [
                            ['padding', 'Padding của section. Nhận cú pháp CSS, vd <code>56px 20px</code>.', '72px 24px'],
                            ['max_width', 'Bề rộng tối đa vùng nội dung, vd <code>1100px</code>.', '960px'],
                            ['class', 'Thêm class vào thẻ <code>&lt;section&gt;</code> để tự viết CSS nhắm riêng.', '—'],
                            ['id', 'Gán id cho <code>&lt;section&gt;</code> (dùng cho anchor / cuộn tới, vd <code>anc-qc</code>).', '—'],
                        ],
                    ],
                    [
                        'title' => 'Tiêu đề &amp; nội dung',
                        'args'  => [
                            ['title', 'Tiêu đề — render sẵn với class <code>.certs-title</code> (Oswald, in hoa, căn giữa).', 'rỗng'],
                            ['desc', 'Mô tả — render với class <code>.certs-desc</code>.', 'rỗng'],
                            ['subheading', 'Dòng phụ trên lưới card — class <code>.certs-subheading</code>.', 'rỗng'],
                            ['more_label', 'Chữ nút mở modal gộp.', 'Tìm hiểu thêm →'],
                        ],
                    ],
                ],
                'examples' => [
                    ['Mặc định (không tiêu đề)', '[certifications]'],
                    ['Đổi màu &amp; bố cục', '[certifications bg="#0d1b2a" primary="#16a34a" accent="#16a34a" on_bg="#ffffff" padding="80px 24px" max_width="1100px"]'],
                    [
                        'Tiêu đề có sẵn style (qua atts)',
                        "[certifications title=\"HỆ THỐNG SẢN XUẤT & CHỨNG NHẬN\"\n                desc=\"Mọi sản phẩm được xây dựng trên nền tảng khoa học và minh bạch.\"\n                subheading=\"Bấm vào từng mục để xem chi tiết\"]",
                    ],
                    [
                        'Tiêu đề tuỳ ý (markup của bạn — ghi đè atts title/desc/subheading)',
                        "[certifications bg=\"#614132\"]\n  <h2 style=\"text-align:center;color:#fff;\">HỆ THỐNG SẢN XUẤT & CHỨNG NHẬN</h2>\n  <p style=\"text-align:center;color:rgba(255,255,255,.85);\">Mô tả tuỳ ý…</p>\n[/certifications]",
                    ],
                    [
                        'Toàn quyền CSS qua class',
                        "[certifications class=\"cert-hithean\"]\n\n/* trong file CSS của theme */\n.cert-hithean .certs-title { letter-spacing: 4px; }\n.cert-hithean .cert-item  { border-radius: 14px; }",
                    ],
                ],
                'notes' => [
                    'Nội dung 3 card (logo, mã số, quy trình, link kiểm tra chéo, nhà máy) đến từ <code>ivar_certifications_config()</code> — sửa qua <code>add_filter(\'ivar_certifications_config\', …)</code> trong theme của từng website.',
                    'Để trống một argument = dùng giá trị mặc định (fallback trong CSS).',
                    'Nếu có nội dung bao <code>[certifications]…[/certifications]</code>, phần bao đó thay cho <code>title/desc/subheading</code> và render y nguyên markup bạn viết.',
                    'Với trang WordPress thường (editor), nên đặt shortcode trong block <strong>"HTML tuỳ chỉnh"</strong> để tránh trình soạn thảo bóp méo markup bên trong.',
                ],
            ],
        ];

        return apply_filters('ivar_shortcodes_guide', $guides);
    }
}

if (!function_exists('ivar_shortcodes_guide_menu')) {
    function ivar_shortcodes_guide_menu(): void
    {
        add_menu_page(
            'Shortcodes Guide',
            'Shortcodes',
            'manage_options',
            'ivar-shortcodes-guide',
            'ivar_shortcodes_guide_render',
            'dashicons-editor-code',
            58
        );
    }
    add_action('admin_menu', 'ivar_shortcodes_guide_menu');
}

if (!function_exists('ivar_shortcodes_guide_render')) {
    function ivar_shortcodes_guide_render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $guides = ivar_shortcodes_guide_data();
        ?>
        <div class="wrap ivar-sg">
            <h1>Shortcodes Guide</h1>
            <p class="ivar-sg__intro">Danh sách shortcode của theme và cách dùng. Chọn shortcode ở thanh bên để xem chi tiết.</p>

            <?php if (count($guides) > 1) : ?>
                <p class="ivar-sg__toc">
                    <?php foreach ($guides as $key => $g) : ?>
                        <a href="#sg-<?php echo esc_attr($key); ?>"><?php echo esc_html($g['name']); ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <?php foreach ($guides as $key => $g) : ?>
                <div class="ivar-sg__card" id="sg-<?php echo esc_attr($key); ?>">
                    <h2><code><?php echo esc_html($g['name']); ?></code></h2>
                    <?php if (!empty($g['summary'])) : ?>
                        <p class="ivar-sg__summary"><?php echo wp_kses_post($g['summary']); ?></p>
                    <?php endif; ?>

                    <?php
                    // Chuẩn hoá: gộp `args` phẳng thành 1 section không tiêu đề.
                    $sections = $g['sections'] ?? [];
                    if (empty($sections) && !empty($g['args'])) {
                        $sections = [['title' => '', 'args' => $g['args']]];
                    }
                    foreach ($sections as $sec) :
                        ?>
                        <?php if (!empty($sec['title'])) : ?>
                            <h3 class="ivar-sg__subhead"><?php echo wp_kses_post($sec['title']); ?></h3>
                        <?php endif; ?>
                        <table class="widefat striped ivar-sg__table">
                            <thead>
                                <tr><th>Argument</th><th>Mô tả</th><th>Mặc định</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sec['args'] as $arg) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html($arg[0]); ?></code></td>
                                        <td><?php echo wp_kses_post($arg[1]); ?></td>
                                        <td><?php echo wp_kses_post($arg[2]); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endforeach; ?>

                    <?php if (!empty($g['examples'])) : ?>
                        <h3 class="ivar-sg__subhead">Ví dụ</h3>
                        <?php foreach ($g['examples'] as $ex) : ?>
                            <p class="ivar-sg__ex-label"><?php echo wp_kses_post($ex[0]); ?></p>
                            <pre class="ivar-sg__code"><code><?php echo esc_html($ex[1]); ?></code></pre>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($g['notes'])) : ?>
                        <h3 class="ivar-sg__subhead">Ghi chú</h3>
                        <ul class="ivar-sg__notes">
                            <?php foreach ($g['notes'] as $note) : ?>
                                <li><?php echo wp_kses_post($note); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <style>
            .ivar-sg__intro{max-width:820px;font-size:14px}
            .ivar-sg__toc{display:flex;flex-wrap:wrap;gap:8px 16px;margin:12px 0}
            .ivar-sg__card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;margin:18px 0;max-width:900px}
            .ivar-sg__card h2{margin-top:0}
            .ivar-sg__summary{max-width:820px;font-size:14px;line-height:1.6;color:#3c434a}
            .ivar-sg__subhead{margin:22px 0 8px;font-size:14px;text-transform:uppercase;letter-spacing:.5px;color:#1d2327}
            .ivar-sg__table{max-width:860px;margin-bottom:6px}
            .ivar-sg__table th{font-weight:600}
            .ivar-sg__table td:first-child{white-space:nowrap}
            .ivar-sg__ex-label{margin:14px 0 4px;font-weight:600}
            .ivar-sg__code{background:#1d2327;color:#e6edf3;padding:12px 14px;border-radius:6px;overflow-x:auto;font-size:12.5px;line-height:1.55;max-width:860px}
            .ivar-sg__code code{background:none;color:inherit;padding:0}
            .ivar-sg__notes{max-width:820px;font-size:13.5px;line-height:1.6}
            .ivar-sg__notes li{margin-bottom:6px}
        </style>
        <?php
    }
}
