<?php
/**
 * Plugin Name: The An Affiliate Sample Form
 * Description: Form đăng ký sample (Fix lỗi Invalid Nonce + Honeypot + Redirect Thank You Page with Arg).
 * Version: 2.4
 * Author: The An Organics
 */

if (!defined('ABSPATH')) exit;

class TAO_Affiliate_Form {
    private $csv_path;
    private $gsheet_url;

    public function __construct() {
        $upload_dir      = wp_upload_dir();
        $this->csv_path  = trailingslashit($upload_dir['basedir']) . 'affiliate-samples.csv';

        // TODO: ĐIỀN URL GOOGLE APPS SCRIPT CỦA BẠN VÀO DƯỚI ĐÂY
        $this->gsheet_url = 'https://script.google.com/macros/s/XXXXX/exec';

        add_shortcode('tao_affiliate_form', [$this, 'render_form']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_nopriv_submit_affiliate_sample', [$this, 'handle_form']);
        add_action('admin_post_submit_affiliate_sample',        [$this, 'handle_form']);
        add_action('wp_head', [$this, 'inline_styles_frontend']);
        add_action('admin_head', [$this, 'inline_styles_backend']);
    }

    /** ========== 1. FRONTEND FORM ========== */
    public function render_form() {
        ob_start();

        $action_url = esc_url(admin_url('admin-post.php'));
        ?>
        <form class="tao-aff-form" action="<?php echo $action_url; ?>" method="post">
            <input type="hidden" name="action" value="submit_affiliate_sample">
            
            <div style="display:none; visibility:hidden;">
                <label>Đừng điền vào ô này nếu bạn là người:</label>
                <input type="text" name="tao_honey_check" value="">
            </div>

            <div class="tao-aff-field">
                <label for="aff_name">* Tên bạn</label>
                <input type="text" id="aff_name" name="aff_name" required>
            </div>

            <div class="tao-aff-field">
                <label for="aff_email">* Email nhận thông báo</label>
                <input type="email" id="aff_email" name="aff_email" required>
            </div>

            <div class="tao-aff-field">
                <label for="aff_phone">* Số điện thoại / Zalo</label>
                <input type="text" id="aff_phone" name="aff_phone" required>
            </div>

            <div class="tao-aff-field">
                <label for="aff_channel_link">* Link kênh TikTok / Facebook</label>
                <input type="url" id="aff_channel_link" name="aff_channel_link" required>
            </div>

            <div class="tao-aff-field">
                <label>Đặc điểm của kênh</label>
                <div class="tao-aff-radio-group">
                    <label><input type="radio" name="aff_niche" value="Mẹ & bé"> Mẹ & bé</label>
                    <label><input type="radio" name="aff_niche" value="Lối sống lành mạnh"> Lối sống lành mạnh</label>
                    <label><input type="radio" name="aff_niche" value="Thể thao"> Thể thao</label>
                    <label><input type="radio" name="aff_niche" value="Khác"> Khác</label>
                </div>
            </div>

            <div class="tao-aff-field">
                <label for="aff_gmv">GMV 30 ngày gần nhất (ước tính)</label>
                <input type="text" id="aff_gmv" name="aff_gmv" placeholder="Ví dụ: 20,000,000">
            </div>

            <div class="tao-aff-field">
                <label for="aff_address">* Địa chỉ nhận sản phẩm mẫu</label>
                <textarea id="aff_address" name="aff_address" rows="3" required></textarea>
            </div>

            <div class="tao-aff-field">
                <label>Bạn đồng ý review sản phẩm bằng video trong vòng 1 tuần từ khi nhận sample?</label>
                <div class="tao-aff-radio-group">
                    <label><input type="radio" name="aff_agree_review" value="Đồng ý"> Đồng ý</label>
                    <label><input type="radio" name="aff_agree_review" value="Không đồng ý"> Không đồng ý</label>
                </div>
            </div>

            <div class="tao-aff-field">
                <label>Video review có thể được The An sử dụng trong các chiến dịch truyền thông?</label>
                <div class="tao-aff-radio-group">
                    <label><input type="radio" name="aff_agree_use_video" value="Đồng ý"> Đồng ý</label>
                    <label><input type="radio" name="aff_agree_use_video" value="Không đồng ý"> Không đồng ý</label>
                </div>
            </div>

            <div class="tao-aff-field">
                <button type="submit" class="tao-aff-submit">Gửi đăng ký</button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /** ========== 2. ADMIN DASHBOARD MENU ========== */
    public function add_admin_menu() {
        add_options_page(
            'Danh sách Affiliate Sample',
            'Affiliate Samples',
            'manage_options',
            'tao-affiliate-samples',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {
        echo '<div class="wrap">';
        echo '<h1>Danh sách đăng ký Sample Affiliate (Backup CSV)</h1>';
        
        if (!file_exists($this->csv_path)) {
            echo '<div class="notice notice-warning"><p>Chưa có file CSV hoặc chưa có đăng ký nào.</p></div>';
            echo '</div>';
            return;
        }

        $rows = [];
        if (($handle = fopen($this->csv_path, 'r')) !== false) {
            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                $rows[] = $data;
            }
            fclose($handle);
        }

        if (empty($rows)) {
            echo '<p>File CSV trống.</p></div>';
            return;
        }

        $header = array_shift($rows);
        $rows = array_reverse($rows);

        echo '<div class="card" style="max-width:100%; margin-top:20px; padding:0;">';
        echo '<div class="tao-aff-table-wrap"><table class="widefat fixed striped">';
        echo '<thead><tr>';
        foreach ($header as $col) { echo '<th>' . esc_html($col) . '</th>'; }
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) { echo '<td>' . esc_html($cell) . '</td>'; }
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
        echo '<p class="description">File CSV: <code>' . $this->csv_path . '</code></p>';
        echo '</div>';
    }

    /** ========== 3. XỬ LÝ DATA & REDIRECT ========== */
    public function handle_form() {
        // Check Honeypot
        if (!empty($_POST['tao_honey_check'])) {
            wp_die('Spam detected.');
        }

        $data = [
            'time'          => current_time('mysql'),
            'name'          => sanitize_text_field($_POST['aff_name'] ?? ''),
            'email'         => sanitize_email($_POST['aff_email'] ?? ''),
            'phone'         => sanitize_text_field($_POST['aff_phone'] ?? ''),
            'channel_link'  => esc_url_raw($_POST['aff_channel_link'] ?? ''),
            'niche'         => sanitize_text_field($_POST['aff_niche'] ?? ''),
            'gmv_30_days'   => sanitize_text_field($_POST['aff_gmv'] ?? ''),
            'address'       => sanitize_textarea_field($_POST['aff_address'] ?? ''),
            'agree_review'  => sanitize_text_field($_POST['aff_agree_review'] ?? ''),
            'agree_use_video' => sanitize_text_field($_POST['aff_agree_use_video'] ?? ''),
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        $this->send_to_google_sheets($data);
        $this->append_to_csv($data);
        $this->send_email_notification($data);

        // --- THAY ĐỔI Ở ĐÂY: Redirect + Arg ---
        // Kết quả sẽ là: domain.com/affiliate-thank-you/?aff_submitted=1
        $redirect_url = add_query_arg('aff_submitted', '1', home_url('/affiliate-thank-you'));
        
        wp_safe_redirect($redirect_url);
        exit;
    }

    private function send_to_google_sheets($data) {
        if (empty($this->gsheet_url)) return;
        wp_remote_post($this->gsheet_url, [
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'    => wp_json_encode($data),
            'timeout' => 5,
            'blocking'=> false
        ]);
    }

    private function append_to_csv($data) {
        $file_exists = file_exists($this->csv_path);
        $fh = fopen($this->csv_path, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if (!$file_exists) fputcsv($fh, array_keys($data));
            fputcsv($fh, array_values($data));
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function send_email_notification($data) {
        $to      = 'ecom@ivarvietnam.com';
        $subject = '[The An] Đăng ký sample affiliate mới';
        $message = "Chi tiết đăng ký:\n\n";
        foreach ($data as $key => $value) {
            $message .= ucfirst($key) . ': ' . $value . "\n";
        }
        wp_mail($to, $subject, $message);
    }

    public function inline_styles_frontend() {
        ?>
        <style>
        .tao-aff-form { max-width: 680px; margin: 0 auto 40px; background:#fff; padding:24px 20px; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,.04); }
        .tao-aff-field { margin-bottom:16px; text-align:left; }
        .tao-aff-field label { display:block; margin-bottom:6px; font-weight:600; color:#184c35; }
        .tao-aff-field input[type="text"], .tao-aff-field input[type="email"], .tao-aff-field input[type="url"], .tao-aff-field textarea { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #d4e2d8; font-size:14px; box-sizing: border-box; }
        .tao-aff-submit { background:#1b7f4c; color:#fff; border:none; padding:10px 24px; border-radius:999px; cursor:pointer; font-weight:600; width: 100%; }
        .tao-aff-submit:hover { background:#13603a; }
        </style>
        <?php
    }

    public function inline_styles_backend() {
        ?>
        <style>.tao-aff-table-wrap { overflow-x:auto; }</style>
        <?php
    }
}

new TAO_Affiliate_Form();