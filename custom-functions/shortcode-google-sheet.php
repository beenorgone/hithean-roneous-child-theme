<?php
/**
 * Shortcode hiển thị Google Sheet (Đã sửa lỗi hiển thị trắng)
 * Cách dùng: [google_sheet_editable id="YOUR_ID"]
 */
function custom_embed_editable_google_sheet($atts) {
    // 1. Lấy tham số
    $atts = shortcode_atts(
        array(
            'id'      => '',
            'width'   => '100%',
            'height'  => '900', // Tăng chiều cao lên 900px
            'private' => 'yes',
        ),
        $atts,
        'google_sheet_editable'
    );

    // 2. Kiểm tra ID
    if (empty($atts['id'])) {
        return '<p style="color:red; font-weight:bold;">Lỗi: Thiếu ID Google Sheet.</p>';
    }

    // 3. Kiểm tra đăng nhập (nếu cần bảo mật)
    if ($atts['private'] === 'yes' && !is_user_logged_in()) {
        return '<p style="text-align:center; padding:20px;">Vui lòng đăng nhập quản trị viên để xem bảng tính này.</p>';
    }

    // 4. Tạo URL
    // Lưu ý: Dùng rm=minimal để giao diện gọn hơn
    $sheet_url = "https://docs.google.com/spreadsheets/d/" . esc_attr($atts['id']) . "/edit?usp=sharing&rm=demo";

    // 5. HTML Output (Đã sửa lỗi nối chuỗi)
    ob_start(); 
    ?>
    <style>
        .sheet-container {
            position: relative;
            width: 100%;
            border: 1px solid #ccc;
            background-color: #f0f0f0; /* Màu nền xám để biết khung đang load */
        }
        .sheet-iframe {
            display: block;
            width: 100%;
            border: 0;
        }
        .sheet-fallback {
            text-align: center;
            padding: 8px;
            background: #fff8e1;
            font-size: 13px;
            border-bottom: 1px solid #eee;
        }
    </style>

    <div class="sheet-container">
        <div class="sheet-fallback">
            ⚠️ Nếu bảng tính không hiện ra (màn hình trắng), <a href="<?php echo esc_url($sheet_url); ?>" target="_blank" style="text-decoration: underline; font-weight: bold;">bấm vào đây để mở trong tab mới</a>.
        </div>

        <iframe class="sheet-iframe"
                src="<?php echo esc_url($sheet_url); ?>" 
                style="height: <?php echo esc_attr($atts['height']); ?>px;"
                frameborder="0" 
                loading="lazy" 
                allow="autoplay; encrypted-media" 
                allowfullscreen>
        </iframe>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('google_sheet_editable', 'custom_embed_editable_google_sheet');