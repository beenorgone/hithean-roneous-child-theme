<?php

/**
 * CORE HELPER FUNCTION
 * Handles the logic for both Sheets and Docs to avoid code duplication.
 */
function render_google_embed_helper($atts, $type = 'sheet')
{
    // 1. Setup Defaults
    $default_atts = array(
        'id'          => '',
        'width'       => '100%',
        'height'      => '900',
        'private'     => 'yes',
        'load_button' => 'no', // New parameter: yes or no
        'btn_text'    => ($type === 'sheet') ? 'Tải Bảng Tính (Google Sheet)' : 'Tải Tài Liệu (Google Doc)',
    );

    $atts = shortcode_atts($default_atts, $atts);

    // 2. Validation
    if (empty($atts['id'])) {
        return '<p style="color:red; font-weight:bold; border:1px solid red; padding:10px;">Error: Missing ID for Google ' . ucfirst($type) . '.</p>';
    }

    // 3. Security Check
    if ($atts['private'] === 'yes' && !is_user_logged_in()) {
        return '<div style="text-align:center; padding:30px; background:#f9f9f9; border:1px solid #ddd; border-radius:4px;">
            <strong>🔒 Nội dung giới hạn</strong><br>Vui lòng đăng nhập quản trị viên để xem tài liệu này.
        </div>';
    }

    // 4. Construct URL
    $base_url = ($type === 'sheet')
        ? "https://docs.google.com/spreadsheets/d/"
        : "https://docs.google.com/document/d/";

    // For sheets: rm=demo allows editing toolbar. For docs: /edit is standard.
    $url_suffix = ($type === 'sheet') ? "/edit?usp=sharing&rm=demo" : "/edit?usp=sharing";

    $embed_url = $base_url . esc_attr($atts['id']) . $url_suffix;
    $unique_uid = uniqid('g_embed_'); // Unique ID for JS targeting

    // 5. Output CSS & JS (Only once per page load)
    static $assets_loaded = false;
    ob_start();
    if (!$assets_loaded) {
?>
        <style>
            .g-embed-wrapper {
                position: relative;
                width: 100%;
                border: 1px solid #dfe1e5;
                background: #fff;
                overflow: hidden;
                border-radius: 4px;
            }

            .g-embed-iframe {
                display: block;
                width: 100%;
                border: 0;
            }

            .g-embed-fallback {
                text-align: center;
                padding: 8px;
                background: #e8f0fe;
                font-size: 13px;
                color: #1a73e8;
                border-bottom: 1px solid #dfe1e5;
            }

            /* Load Button Styles */
            .g-embed-placeholder {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                background-color: #f8f9fa;
                cursor: pointer;
                transition: background 0.2s;
                background-image: linear-gradient(45deg, #f8f9fa 25%, #fff 25%, #fff 50%, #f8f9fa 50%, #f8f9fa 75%, #fff 75%, #fff 100%);
                background-size: 20px 20px;
            }

            .g-embed-placeholder:hover {
                background-image: none;
                background-color: #eef1f5;
            }

            .g-embed-btn {
                background: #1a73e8;
                color: #fff;
                border: none;
                padding: 12px 24px;
                border-radius: 4px;
                font-weight: 600;
                font-size: 16px;
                cursor: pointer;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
                transition: transform 0.1s;
            }

            .g-embed-btn:hover {
                background: #1669bb;
            }

            .g-embed-btn:active {
                transform: translateY(1px);
            }

            .g-embed-note {
                margin-top: 10px;
                font-size: 12px;
                color: #666;
            }
        </style>
        <script>
            function loadGoogleEmbed(containerId, url, height) {
                var container = document.getElementById(containerId);
                if (!container) return;

                container.innerHTML = '<iframe src="' + url + '" ' +
                    'style="width:100%; height:' + height + 'px; border:0;" ' +
                    'frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>';
            }
        </script>
    <?php
        $assets_loaded = true;
    }

    // 6. Render Layout
    ?>
    <div class="g-embed-wrapper" style="min-height: 50px;">

        <?php if ($atts['load_button'] === 'yes'): ?>
            <div id="<?php echo esc_attr($unique_uid); ?>"
                class="g-embed-placeholder"
                style="height: <?php echo esc_attr($atts['height']); ?>px;"
                onclick="loadGoogleEmbed('<?php echo esc_js($unique_uid); ?>', '<?php echo esc_url($embed_url); ?>', '<?php echo esc_js($atts['height']); ?>')">

                <button class="g-embed-btn">
                    <span style="margin-right:5px;">📥</span> <?php echo esc_html($atts['btn_text']); ?>
                </button>
                <span class="g-embed-note">Nhấn vào nút để tải dữ liệu (Giúp web load nhanh hơn)</span>
            </div>

        <?php else: ?>
            <div class="g-embed-fallback">
                ℹ️ Nếu không thấy nội dung, <a href="<?php echo esc_url($embed_url); ?>" target="_blank" style="font-weight:bold;">mở trong tab mới tại đây</a>.
            </div>
            <iframe class="g-embed-iframe"
                src="<?php echo esc_url($embed_url); ?>"
                style="height: <?php echo esc_attr($atts['height']); ?>px;"
                loading="lazy"
                allow="autoplay; encrypted-media"
                allowfullscreen>
            </iframe>
        <?php endif; ?>

    </div>
<?php

    return ob_get_clean();
}

/**
 * Shortcode: [google_sheet_editable]
 */
function custom_embed_editable_google_sheet($atts)
{
    return render_google_embed_helper($atts, 'sheet');
}
add_shortcode('google_sheet_editable', 'custom_embed_editable_google_sheet');

/**
 * Shortcode: [google_doc_editable]
 */
function custom_embed_editable_google_doc($atts)
{
    return render_google_embed_helper($atts, 'doc');
}
add_shortcode('google_doc_editable', 'custom_embed_editable_google_doc');
