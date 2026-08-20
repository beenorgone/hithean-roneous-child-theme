<?php
if (!defined('ABSPATH')) exit;
// Product Additional Info
function hithean_product_additional_info_metabox($meta_boxes)
{
    $prefix = 'product_info_';

    $meta_boxes[] = [
        'title' => "Thông tin cơ bản",
        'id' => 'product_addtional_info_metabox',
        'post_types' => array('product'),
        'context' => 'advanced',
        'priority' => 'default',
        'autosave' => true,
        'fields' => [
            [
                'id' => $prefix . 'subheading',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Nhấn mạnh sản phẩm: Tagline, Subheading, USP ...', 'hithean-product-metabox'),
                'desc' => esc_html__('Hiển thị phía dưới tên sản phẩm để nêu bật điểm mạnh, điểm riêng biệt, USP', 'hithean-product-metabox'),
            ],
            // custom info use for tabs
            [
                'id' => $prefix . 'hdsd',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Hướng dẫn sử dụng', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Hướng Dẫn Sử Dụng', 'hithean-product-metabox'),
            ],

            [
                'id' => $prefix . 'thanh_phan',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Thành phần và bảng giá trị dinh dưỡng', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Thành Phần', 'hithean-product-metabox'),
            ],
            [
                'id'                => 'product_nutrition_label_csv',
                'type'              => 'textarea',
                'name'              => esc_html__('Bảng thành phần / dinh dưỡng (CSV)', 'hithean-product-metabox'),
                'desc'              => wp_kses_post(__('Cách dùng: bấm <strong>Tải ảnh lên</strong> bên dưới, rồi bấm <strong>Thêm vào CSV</strong>. Mỗi dòng có định dạng: Tiêu đề ảnh, URL ảnh. Dùng dấu ngoặc kép nếu tiêu đề có dấu phẩy.', 'hithean-product-metabox')),
                'sanitize_callback' => 'sanitize_textarea_field',
                'attributes'        => [
                    'placeholder' => "Supplement Facts,https://example.com/supplement-facts.jpg\nNutrition Facts,https://example.com/nutrition-facts.jpg",
                ],
                'rows'              => 6,
            ],

            [
                'id' => $prefix . 'nhan_phu',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Nhãn phụ', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Nhãn Phụ', 'hithean-product-metabox'),
            ],

            [
                'id' => $prefix . 'ho_so_phap_ly',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Hồ sơ pháp lý sản phẩm', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Hồ Sơ Sản Phẩm', 'hithean-product-metabox'),
            ],

            [
                'id' => $prefix . 'faq',
                'type' => 'wysiwyg',
                'raw' => true,
                'options' => [
                    'textarea_rows' => 4,
                ],
                'name' => esc_html__('Câu hỏi thường gặp', 'hithean-product-metabox'),
                'desc' => esc_html__('Thông tin nhập tại đây sẽ hiển thị ở Tab Câu Hỏi Thường Gặp', 'hithean-product-metabox'),
            ],
        ],
    ];

    return $meta_boxes;
}

add_filter('rwmb_meta_boxes', 'hithean_product_additional_info_metabox');

// Nội dung mặc định hiện dưới tiêu đề "Chọn kênh mua hàng" khi admin để trống field mô tả.
function hithean_ecom_modal_default_desc(): string
{
    return __('Đơn hàng đặt tại Shopee/TikTok không áp dụng chung chính sách ưu đãi, tích điểm như tại website', 'hithean-product-metabox');
}

// Kênh bán TMĐT (Shopee / TikTok) — dùng cho nút "Mua tại Shopee" trên trang sản phẩm.
// Lưu dạng CSV (giống product_nutrition_label_csv / addon-manager.php): mỗi dòng
// "Tên store,Mô tả ngắn,platform,Link" — field textarea là core Meta Box, không cần extension.
function hithean_product_ecom_channels_metabox($meta_boxes)
{
    $meta_boxes[] = [
        'title'      => 'Kênh bán TMĐT (Shopee / TikTok)',
        'id'         => 'product_ecom_channels_metabox',
        'post_types' => ['product'],
        'context'    => 'normal',
        'priority'   => 'default',
        'autosave'   => true,
        'fields'     => [
            [
                'id'          => 'product_ecom_modal_desc',
                'name'        => esc_html__('Mô tả dưới tiêu đề "Chọn kênh mua hàng"', 'hithean-product-metabox'),
                'type'        => 'textarea',
                'rows'        => 2,
                'placeholder' => hithean_ecom_modal_default_desc(),
                'desc'        => esc_html__('Để trống sẽ dùng nội dung mặc định (xem placeholder phía trên).', 'hithean-product-metabox'),
            ],
            [
                'id'          => 'product_ecom_channels_csv',
                'name'        => esc_html__('Danh sách kênh bán (CSV)', 'hithean-product-metabox'),
                'type'        => 'textarea',
                'rows'        => 4,
                'placeholder' => "Shopee Mall Hithean,Chính hãng - giao nhanh,shopee,https://shopee.vn/...\nHithean Official,Kênh TikTok chính thức,tiktok,https://tiktok.com/@...",
                'desc'        => wp_kses_post(__('Mỗi dòng: <code>Tên store, Mô tả ngắn, shopee/tiktok, Link</code>. Dùng form bên dưới để nhập cho dễ, không cần gõ tay.', 'hithean-product-metabox')),
            ],
            [
                'type' => 'custom_html',
                'std'  => hithean_render_ecom_channel_builder_tool(),
            ],
        ],
    ];

    return $meta_boxes;
}

add_filter('rwmb_meta_boxes', 'hithean_product_ecom_channels_metabox');

function hithean_render_ecom_channel_builder_tool()
{
    ob_start();
    ?>
    <style>
    .ecom-channel-builder {
        margin-top: 12px;
        padding: 14px;
        border: 1px solid #dcdcde;
        background: #f6f7f7;
        border-radius: 4px;
    }
    .ecom-channel-builder h4 { margin: 0 0 10px; }
    .ecom-channel-builder-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px;
        align-items: end;
    }
    .ecom-channel-field label {
        display: block;
        margin-bottom: 4px;
        font-weight: 600;
        font-size: 12px;
    }
    .ecom-channel-field input,
    .ecom-channel-field select {
        width: 100%;
        min-height: 34px;
        padding: 0 8px;
        border: 1px solid #8c8f94;
        border-radius: 4px;
        box-sizing: border-box;
    }
    .ecom-channel-builder-actions {
        margin-top: 12px;
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .ecom-channel-builder-note {
        color: #50575e;
        font-size: 12px;
    }
    .ecom-channel-preview {
        margin-top: 16px;
    }
    .ecom-channel-preview h4 {
        margin: 0 0 10px;
    }
    .ecom-channel-preview-mock {
        max-width: 420px;
        padding: 20px;
        background: #fff;
        border: 1px solid #dcdcde;
        border-radius: 8px;
    }
    .ecom-channel-preview-mock__title {
        margin: 0 0 4px;
        font-weight: 600;
    }
    .ecom-channel-preview-mock__desc {
        margin: 0 0 12px;
        font-size: 13px;
        color: #666;
    }
    .ecom-channel-preview-empty {
        margin: 0;
        color: #757575;
        font-size: 13px;
    }
    .ecom-buy-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 0;
        border-top: 1px solid #eee;
    }
    .ecom-buy-card:first-child { border-top: none; }
    .ecom-buy-card__store {
        margin: 0;
        font-weight: 600;
    }
    .ecom-buy-card__desc {
        margin: 4px 0 0;
        font-size: 13px;
        color: #666;
    }
    .ecom-buy-card__cta {
        flex-shrink: 0;
        display: inline-block;
        padding: 8px 16px;
        border-radius: 4px;
        color: #fff;
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
    }
    .ecom-buy-card__cta--shopee {
        background: #ee4d2d;
    }
    .ecom-buy-card__cta--tiktok {
        background: #000;
        box-shadow: inset 3px 0 0 #fe2c55, inset -3px 0 0 #25f4ee;
    }
    </style>

    <div class="ecom-channel-builder">
        <h4><?php esc_html_e('Thêm nhanh 1 kênh bán', 'hithean-product-metabox'); ?></h4>
        <div class="ecom-channel-builder-grid">
            <div class="ecom-channel-field">
                <label><?php esc_html_e('Tên store', 'hithean-product-metabox'); ?></label>
                <input type="text" data-field="store_name">
            </div>
            <div class="ecom-channel-field">
                <label><?php esc_html_e('Mô tả ngắn', 'hithean-product-metabox'); ?></label>
                <input type="text" data-field="short_desc">
            </div>
            <div class="ecom-channel-field">
                <label><?php esc_html_e('Phân loại', 'hithean-product-metabox'); ?></label>
                <select data-field="platform">
                    <option value="shopee">Shopee</option>
                    <option value="tiktok">TikTok</option>
                </select>
            </div>
            <div class="ecom-channel-field">
                <label><?php esc_html_e('Link (store hoặc sản phẩm)', 'hithean-product-metabox'); ?></label>
                <input type="url" data-field="link" placeholder="https://...">
            </div>
        </div>
        <div class="ecom-channel-builder-actions">
            <button type="button" class="button button-primary" data-add-ecom-channel><?php esc_html_e('Thêm vào danh sách', 'hithean-product-metabox'); ?></button>
            <span class="ecom-channel-builder-note"><?php esc_html_e('Tool này tự chèn đúng format vào ô CSV bên trên. Nhớ bấm Cập nhật sản phẩm để lưu.', 'hithean-product-metabox'); ?></span>
        </div>
    </div>

    <div class="ecom-channel-preview">
        <h4><?php esc_html_e('Xem trước popup', 'hithean-product-metabox'); ?></h4>
        <div class="ecom-channel-preview-mock">
            <p class="ecom-channel-preview-mock__title"><?php esc_html_e('Chọn kênh mua hàng', 'hithean-product-metabox'); ?></p>
            <p class="ecom-channel-preview-mock__desc" data-ecom-preview-desc></p>
            <div class="ecom-channel-preview-list" data-ecom-preview-list></div>
        </div>
    </div>

    <script>
    (function () {
        if (window.ecomChannelBuilderInit) return;
        window.ecomChannelBuilderInit = true;

        function csvEscape(value) {
            return '"' + String(value || '').replace(/"/g, '""') + '"';
        }

        // Parser CSV đơn giản, hỗ trợ field có dấu phẩy/ngoặc kép (khớp hành vi str_getcsv phía PHP)
        function parseCsvLine(line) {
            var result = [];
            var cur = '';
            var inQuotes = false;

            for (var i = 0; i < line.length; i++) {
                var ch = line[i];
                if (inQuotes) {
                    if (ch === '"') {
                        if (line[i + 1] === '"') { cur += '"'; i++; }
                        else { inQuotes = false; }
                    } else {
                        cur += ch;
                    }
                } else if (ch === '"') {
                    inQuotes = true;
                } else if (ch === ',') {
                    result.push(cur);
                    cur = '';
                } else {
                    cur += ch;
                }
            }
            result.push(cur);

            return result;
        }

        function renderEcomPreview(builder) {
            var descEl = builder.querySelector('[data-ecom-preview-desc]');
            if (descEl) {
                var descField = document.getElementById('product_ecom_modal_desc');
                var descValue = descField ? descField.value.trim() : '';
                descEl.textContent = descValue || '<?php echo esc_js(hithean_ecom_modal_default_desc()); ?>';
            }

            var textarea = document.getElementById('product_ecom_channels_csv');
            var list = builder.querySelector('[data-ecom-preview-list]');
            if (!textarea || !list) return;

            var channels = textarea.value.split(/\r\n|\r|\n/)
                .map(function (line) { return line.trim(); })
                .filter(Boolean)
                .map(parseCsvLine)
                .filter(function (cols) { return cols.length >= 4 && cols[0].trim() && cols[3].trim(); });

            list.innerHTML = '';

            if (!channels.length) {
                var empty = document.createElement('p');
                empty.className = 'ecom-channel-preview-empty';
                empty.textContent = '<?php echo esc_js(__('Chưa có kênh bán nào — thêm ở form trên hoặc gõ trực tiếp vào ô CSV.', 'hithean-product-metabox')); ?>';
                list.appendChild(empty);
                return;
            }

            channels.forEach(function (cols) {
                var platform = (cols[2] || '').trim().toLowerCase();
                platform = (platform === 'shopee' || platform === 'tiktok') ? platform : 'shopee';

                var card = document.createElement('div');
                card.className = 'ecom-buy-card';

                var info = document.createElement('div');
                var storeEl = document.createElement('p');
                storeEl.className = 'ecom-buy-card__store';
                storeEl.textContent = cols[0].trim();
                info.appendChild(storeEl);

                var shortDesc = (cols[1] || '').trim();
                if (shortDesc) {
                    var descEl = document.createElement('p');
                    descEl.className = 'ecom-buy-card__desc';
                    descEl.textContent = shortDesc;
                    info.appendChild(descEl);
                }

                var cta = document.createElement('span');
                cta.className = 'ecom-buy-card__cta ecom-buy-card__cta--' + platform;
                cta.textContent = '<?php echo esc_js(__('Mua ngay', 'hithean-product-metabox')); ?>';

                card.appendChild(info);
                card.appendChild(cta);
                list.appendChild(card);
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.ecom-channel-builder').forEach(function (builder) {
                var textarea = document.getElementById('product_ecom_channels_csv');
                if (textarea) {
                    textarea.addEventListener('input', function () { renderEcomPreview(builder); });
                    textarea.addEventListener('change', function () { renderEcomPreview(builder); });
                }

                var descField = document.getElementById('product_ecom_modal_desc');
                if (descField) {
                    descField.addEventListener('input', function () { renderEcomPreview(builder); });
                    descField.addEventListener('change', function () { renderEcomPreview(builder); });
                }

                renderEcomPreview(builder);

                var addButton = builder.querySelector('[data-add-ecom-channel]');
                if (!addButton) return;

                addButton.addEventListener('click', function () {
                    if (!textarea) {
                        alert('<?php echo esc_js(__('Không tìm thấy ô CSV kênh bán.', 'hithean-product-metabox')); ?>');
                        return;
                    }

                    var fields = ['store_name', 'short_desc', 'platform', 'link'];
                    var values = fields.map(function (field) {
                        var el = builder.querySelector('[data-field="' + field + '"]');
                        return el ? el.value.trim() : '';
                    });

                    if (!values[0] || !values[3]) {
                        alert('<?php echo esc_js(__('Cần nhập Tên store và Link trước khi thêm.', 'hithean-product-metabox')); ?>');
                        return;
                    }

                    var line = values.map(csvEscape).join(',');
                    textarea.value = textarea.value.trim() ? textarea.value.trim() + "\n" + line : line;
                    textarea.dispatchEvent(new Event('change'));

                    builder.querySelectorAll('[data-field]').forEach(function (el) {
                        if (el.tagName === 'SELECT') {
                            el.selectedIndex = 0;
                        } else {
                            el.value = '';
                        }
                    });
                });
            });
        });
    })();
    </script>
    <?php

    return ob_get_clean();
}
