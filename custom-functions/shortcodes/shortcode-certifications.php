<?php
/**
 * Shortcode: [certifications]
 * Khối "Hệ thống sản xuất & chứng nhận" (port từ section #anc-qc của landing anc).
 * Self-contained: CSS + JS inline, prefix `cert-`, dùng lại được cho website khác.
 *
 * Nội dung cấu hình qua filter `ivar_certifications_config` (mỗi site override trong theme):
 *   add_filter('ivar_certifications_config', function ($cfg) {
 *       $cfg['cards']['cert']['modal']['steps'] = [...];
 *       return $cfg;
 *   });
 *
 * Atts: title, desc, subheading, more_label, bg (màu nền section), primary, accent.
 *
 * Mỗi card: logos[] (src, alt) · title · subline · subtext · modal{...}
 * modal: badge{src,alt} · heading · lead(html) · points[] · steps_title · steps[]
 *        · crosscheck(html) · factory{label,address,map_embed,map_link}
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ivar_certifications_config')) {
    function ivar_certifications_config(): array
    {
        $default = [
            'cards' => [
                'cert' => [
                    'logos'   => [
                        ['src' => 'https://hithean.com/wp-content/uploads/2025/11/organic-logos-600x332.png.webp', 'alt' => 'USDA Organic · EU Organic'],
                    ],
                    'title'   => 'Chứng nhận hữu cơ',
                    'subline' => 'USDA &amp; EU Organic · Control Union · CU 916118',
                    'subtext' => '',
                    'modal'   => [
                        'badge'       => ['src' => 'https://hithean.com/wp-content/uploads/2025/11/organic-logos-600x332.png.webp', 'alt' => 'USDA Organic · EU Organic'],
                        'heading'     => 'Chứng nhận hữu cơ quốc tế USDA NOP và EU Organic',
                        'lead'        => 'Nhà máy sản xuất của IVAR chính thức tiếp nhận chứng nhận hữu cơ <strong>USDA Organic và EU Organic </strong>(mã hồ sơ CU 916118) — xác nhận toàn bộ quy trình sản xuất đạt tiêu chuẩn hữu cơ quốc tế, từ nguyên liệu đầu vào đến thành phẩm. Đạt chuẩn xuất khẩu sản phẩm hữu cơ (organic) vào Hoa Kỳ &amp; Liên minh châu Âu',
                        'points'      => [],
                        'steps_title' => 'Quy trình cấp chứng nhận hữu cơ',
                        'steps'       => [
                            'Đăng ký &amp; nộp hồ sơ vùng nguyên liệu, nhà cung cấp, và quy trình sản xuất chi tiết cho Control Union',
                            'Thẩm định độc lập hồ sơ và truy xuất nguồn gốc từng nguyên liệu đầu vào',
                            'Đánh giá hiện trường (audit) tại nhà máy và vùng trồng (nếu có)',
                            'Lấy mẫu ngẫu nhiên tại nhà máy / vùng trồng kiểm nghiệm dư lượng — xác nhận không hoá chất/chất cấm',
                            'Cấp chứng nhận đạt chuẩn USDA Organic &amp; EU Organic nếu đạt',
                            'Kiểm tra không báo trước hàng năm',
                            'Tái đánh giá giám sát định kỳ hàng năm',
                        ],
                        'crosscheck'  => 'Tra theo mã <strong>916118</strong> hoặc tên công ty <strong>IVAR VIET NAM</strong> trên cơ sở dữ liệu công khai của Control Union và USDA Organic Integrity Database — đối chiếu tên nhà máy &amp; phạm vi chứng nhận với thông tin công bố. Hoặc truy cập trực tiếp <a href="https://organic.ams.usda.gov/integrity/CP/OPP?cid=21&amp;nopid=1780915468&amp;ret=Search&amp;retName=Search" target="_blank" rel="noopener">tại đây</a>.',
                    ],
                ],
                'eurofins' => [
                    'logos'   => [
                        ['src' => 'https://hithean.com/wp-content/uploads/2025/11/eurofins-logo-1200x663-1-600x332.png.webp', 'alt' => 'Eurofins'],
                    ],
                    'title'   => 'Kiểm nghiệm độc lập',
                    'subline' => 'Eurofins Scientific — bên thứ ba',
                    'subtext' => '',
                    'modal'   => [
                        'badge'       => ['src' => 'https://hithean.com/wp-content/uploads/2025/11/eurofins-logo-1200x663-1-600x332.png.webp', 'alt' => 'Eurofins'],
                        'heading'     => 'Đối tác kiểm nghiệm độc lập — Eurofins',
                        'lead'        => 'Eurofins Scientific — 900+ phòng lab · 50+ quốc gia. Tập đoàn khoa học hàng đầu thế giới trong lĩnh vực thử nghiệm thực phẩm, môi trường, dược phẩm và mỹ phẩm và trong các dịch vụ CRO về khoa học nông nghiệp.<br>Tất cả sản phẩm của The An Organics đều được kiểm nghiệm bởi Eurofin Sắc Ký Hải Đăng ít nhất 2 lần (nguyên liệu đầu vào và thanh phẩm).',
                        'points'      => [],
                        'steps_title' => 'Quy trình kiểm nghiệm độc lập tại Eurofins',
                        'steps'       => [
                            'Lấy mẫu kiểm nghiệm',
                            'Gửi mẫu đến phòng lab Eurofins — bên thứ ba, không xung đột lợi ích',
                            'Phân tích theo phương pháp chuẩn <strong>ISO/IEC</strong> và <strong>QCVN</strong>',
                            'Eurofins phát hành phiếu kết quả (CoA) có mã số riêng, phiếu kết quả không thể sửa đổi, tách rời, thay thế.',
                            'Doanh nghiệp sử dụng hồ sơ kiểm nghiệm để xây dựng bảng dinh dưỡng trên bao bì sản phẩm. Tất cả các chỉ tiêu trên bao bì sản phẩm đều được xây dựng từ kết quả kiểm tra độc lập của bên thứ ba',
                        ],
                        'crosscheck'  => 'Liên hệ đại diện của Eurofins tại Việt Nam <a href="https://www.eurofins.vn/vn/th%C3%B4ng-tin-li%C3%AAn-h%E1%BB%87/" target="_blank" rel="noopener">qua website</a>.',
                    ],
                ],
                'iso' => [
                    'logos'   => [
                        ['src' => 'https://hithean.com/wp-content/uploads/2025/11/chung-nhan-nha-xuong-logos-haccp-iso2200-gmp-1200x663-1-600x332.png.webp', 'alt' => 'ISO 22000 · HACCP · GMP'],
                    ],
                    'title'   => 'Tiêu chuẩn sản xuất',
                    'subline' => 'ISO 22000 · HACCP · GMP',
                    'subtext' => 'Nhà máy vận hành theo hệ thống quản lý an toàn thực phẩm, kiểm soát mối nguy và thực hành sản xuất tốt.',
                    'modal'   => [
                        'badge'       => ['src' => 'https://hithean.com/wp-content/uploads/2025/11/chung-nhan-nha-xuong-logos-haccp-iso2200-gmp-1200x663-1-600x332.png.webp', 'alt' => 'ISO 22000 · HACCP · GMP'],
                        'heading'     => 'Tiêu chuẩn sản xuất quốc tế',
                        'lead'        => 'Chúng tôi sở hữu cơ sở sản xuất đạt chứng nhận quốc tế: ISO22000, HACCP, GMP tại Cụm sản xuất dịch vụ, thôn Văn Khê, xã Kiều Phú, Hà Nội, Việt Nam. Cấp bởi TQC CGLOBAL, Mã: TQC.03.6137',
                        'points'      => [
                            'ISO 22000 — Quản lý an toàn thực phẩm',
                            'HACCP — Phân tích mối nguy &amp; kiểm soát tới hạn',
                            'GMP — Quy phạm thực hành sản xuất tốt',
                        ],
                        'steps_title' => 'Quy trình cấp chứng nhận ISO 22000 · HACCP · GMP',
                        'steps'       => [
                            'Xây dựng &amp; vận hành hệ thống ISO 22000 / HACCP / GMP tại nhà máy',
                            'Đào tạo nhân sự, lưu hồ sơ kiểm soát mối nguy theo từng công đoạn',
                            'Tổ chức chứng nhận TQC CGLOBAL đánh giá hồ sơ + hiện trường',
                            'Khắc phục điểm chưa phù hợp (nếu có) và đánh giá lại',
                            'Cấp chứng nhận (mã <strong>TQC.03.6137</strong>)',
                            'Giám sát &amp; tái chứng nhận định kỳ',
                        ],
                        'crosscheck'  => 'Tra mã <strong>TQC.03.6137</strong> trên <a href="https://verify.tqc.vn/" target="_blank" rel="noopener">website TQC CGLOBAL</a>.',
                        'factory'     => [
                            'label'     => 'Nhà máy',
                            'address'   => 'Cụm sản xuất dịch vụ, thôn Văn Khê, xã Nghĩa Hương, Hà Nội, Việt Nam',
                            'map_embed' => 'https://maps.google.com/maps?q=Van+Khe,+Nghia+Huong,+Quoc+Oai,+Ha+Noi,+Vietnam&output=embed&hl=vi&z=15',
                            'map_link'  => 'https://maps.app.goo.gl/FWNMTCZnqEPZofj86',
                        ],
                    ],
                ],
            ],
        ];

        return apply_filters('ivar_certifications_config', $default);
    }
}

if (!function_exists('ivar_cert_modal_body')) {
    /** Dựng phần thân modal (dùng chung cho modal đơn lẫn modal gộp). */
    function ivar_cert_modal_body(array $m): string
    {
        $out = '';

        if (!empty($m['badge']['src'])) {
            $out .= sprintf(
                '<img class="cert-badge-img" src="%s" alt="%s" />',
                esc_url($m['badge']['src']),
                esc_attr($m['badge']['alt'] ?? '')
            );
        }
        if (!empty($m['heading'])) {
            $out .= '<h3>' . wp_kses_post($m['heading']) . '</h3>';
        }
        if (!empty($m['lead'])) {
            $out .= '<p>' . wp_kses_post($m['lead']) . '</p>';
        }
        if (!empty($m['points'])) {
            $out .= '<ul class="cert-list">';
            foreach ($m['points'] as $p) {
                $out .= '<li>' . wp_kses_post($p) . '</li>';
            }
            $out .= '</ul>';
        }
        if (!empty($m['steps'])) {
            if (!empty($m['steps_title'])) {
                $out .= '<h4 class="cert-subhead">' . wp_kses_post($m['steps_title']) . '</h4>';
            }
            $out .= '<ol class="cert-steps">';
            foreach ($m['steps'] as $s) {
                $out .= '<li>' . wp_kses_post($s) . '</li>';
            }
            $out .= '</ol>';
        }
        if (!empty($m['crosscheck'])) {
            $out .= '<div class="cert-crosscheck"><strong>Kiểm tra chéo thông tin</strong><p>'
                . wp_kses_post($m['crosscheck']) . '</p></div>';
        }
        if (!empty($m['factory']['address'])) {
            $f = $m['factory'];
            $out .= '<div class="cert-factory">'
                . '<span class="cert-factory-icon" aria-hidden="true">🏭</span>'
                . '<div class="cert-factory-label">' . esc_html($f['label'] ?? 'Nhà máy') . '</div>'
                . '<div class="cert-factory-value">' . esc_html($f['address']) . '</div>';
            if (!empty($f['map_embed'])) {
                $out .= '<div class="cert-factory-map"><iframe class="cert-factory-iframe" src="'
                    . esc_url($f['map_embed']) . '" height="200" frameborder="0" allowfullscreen loading="lazy" title="'
                    . esc_attr($f['label'] ?? 'Nhà máy') . '"></iframe>';
                if (!empty($f['map_link'])) {
                    $out .= '<a href="' . esc_url($f['map_link']) . '" class="cert-factory-link" target="_blank" rel="noopener">Mở Google Maps →</a>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';
        }

        return $out;
    }
}

if (!function_exists('ivar_certifications_shortcode')) {
    function ivar_certifications_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'id'         => '',
            'title'      => 'HỆ THỐNG SẢN XUẤT &amp; CHỨNG NHẬN',
            'desc'       => 'Mọi sản phẩm được xây dựng trên nền tảng khoa học và minh bạch — từ nguyên liệu đầu vào đến thành phẩm trên kệ.',
            'subheading' => 'Chứng nhận &amp; kiểm nghiệm độc lập — bấm vào từng mục để xem chi tiết',
            'more_label' => 'Tìm hiểu thêm →',
            'bg'         => '#614132',
            'primary'    => '#0047ba',
            'accent'     => '#fbb917',
        ], $atts, 'certifications');

        $cfg   = ivar_certifications_config();
        $cards = $cfg['cards'] ?? [];
        if (empty($cards)) {
            return '';
        }

        $style_vars = sprintf(
            '--cert-bg:%s;--cert-primary:%s;--cert-accent:%s',
            esc_attr($atts['bg']),
            esc_attr($atts['primary']),
            esc_attr($atts['accent'])
        );

        // Grid các card + modal đơn
        $grid    = '';
        $modals  = '';
        $all_body = '';
        $i = 0;
        foreach ($cards as $key => $card) {
            $modal_id = 'cert-modal-' . sanitize_html_class((string) $key);

            $logos = '';
            foreach (($card['logos'] ?? []) as $lg) {
                if (empty($lg['src'])) {
                    continue;
                }
                $logos .= sprintf(
                    '<img class="cert-logo" src="%s" alt="%s" loading="lazy" decoding="async" />',
                    esc_url($lg['src']),
                    esc_attr($lg['alt'] ?? '')
                );
            }

            $grid .= sprintf(
                '<button type="button" class="cert-item" data-cert-open="%s">'
                    . '<span class="cert-logos">%s</span>'
                    . '<span class="cert-text"><strong>%s</strong><span>%s</span>%s</span>'
                    . '<span class="cert-more" aria-hidden="true">Xem chi tiết →</span>'
                    . '</button>',
                esc_attr($modal_id),
                $logos,
                esc_html($card['title'] ?? ''),
                wp_kses_post($card['subline'] ?? ''),
                !empty($card['subtext']) ? '<span class="cert-subtext">' . wp_kses_post($card['subtext']) . '</span>' : ''
            );

            $body = ivar_cert_modal_body($card['modal'] ?? []);
            $modals .= sprintf(
                '<div class="cert-modal" id="%s" aria-hidden="true"><div class="cert-modal-backdrop" data-cert-close></div>'
                    . '<div class="cert-modal-dialog"><button type="button" class="cert-modal-close" data-cert-close aria-label="Đóng">&times;</button>'
                    . '<div class="cert-modal-body">%s</div></div></div>',
                esc_attr($modal_id),
                $body
            );

            $all_body .= ($i > 0 ? '<hr>' : '') . $body;
            $i++;
        }

        // Modal gộp
        $modals .= '<div class="cert-modal" id="cert-modal-all" aria-hidden="true"><div class="cert-modal-backdrop" data-cert-close></div>'
            . '<div class="cert-modal-dialog"><button type="button" class="cert-modal-close" data-cert-close aria-label="Đóng">&times;</button>'
            . '<div class="cert-modal-body">' . $all_body . '</div></div></div>';

        $id_attr = $atts['id'] !== '' ? ' id="' . esc_attr($atts['id']) . '"' : '';

        $section = sprintf(
            '<section class="certs"' . $id_attr . ' style="%s">'
                . '<div class="certs-inner">'
                . '<h2 class="certs-title">%s</h2>'
                . '<p class="certs-desc">%s</p>'
                . '<p class="certs-subheading">%s</p>'
                . '<div class="certs-grid">%s</div>'
                . '<button type="button" class="cert-more-btn" data-cert-open="cert-modal-all">%s</button>'
                . '</div></section>',
            esc_attr($style_vars),
            wp_kses_post($atts['title']),
            wp_kses_post($atts['desc']),
            wp_kses_post($atts['subheading']),
            $grid,
            esc_html($atts['more_label'])
        );

        return ivar_cert_assets() . $section . '<div class="cert-modals">' . $modals . '</div>';
    }
    add_shortcode('certifications', 'ivar_certifications_shortcode');
}

if (!function_exists('ivar_cert_assets')) {
    /** CSS + JS inline, in một lần cho cả trang. */
    function ivar_cert_assets(): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;

        $css = <<<'CSS'
<style id="cert-styles">
.certs{background:var(--cert-bg,#614132);padding:72px 24px;text-align:center}
.certs *{box-sizing:border-box}
.certs-inner{max-width:960px;margin:0 auto}
.certs-title{font-family:'Oswald',sans-serif;text-transform:uppercase;letter-spacing:2px;text-align:center;margin:0 0 10px;color:#fff;font-size:clamp(1.6rem,4vw,2.4rem);line-height:1.15}
.certs-desc{max-width:600px;margin:0 auto 40px;color:rgba(255,255,255,.85);font-size:15px;line-height:1.7}
.certs-subheading{color:rgba(255,255,255,.8);font-size:14px;margin:0 0 36px}
.certs-grid{display:grid;grid-template-columns:1fr;gap:18px;margin-bottom:36px;text-align:left}
@media(min-width:700px){.certs-grid{grid-template-columns:repeat(3,1fr)}}
.cert-item{display:flex;flex-direction:column;align-items:flex-start;gap:12px;width:100%;background:#fff;border:none;border-top:4px solid var(--cert-accent,#fbb917);padding:20px 18px;margin:0;text-align:left;font-family:inherit;cursor:pointer;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);transition:transform .15s ease,box-shadow .15s ease}
.cert-item:hover{transform:translateY(-3px);box-shadow:0 8px 22px rgba(0,0,0,.18)}
.cert-logos{display:flex;align-items:center;flex-wrap:wrap;gap:10px;min-height:44px}
.cert-logo{height:120px;width:auto;max-width:100%;object-fit:contain;display:block}
.cert-text{display:flex;flex-direction:column;gap:3px;line-height:1.3}
.cert-text strong{font-family:'Oswald',sans-serif;font-size:13px;letter-spacing:.5px;color:var(--cert-primary,#0047ba);text-transform:uppercase}
.cert-text>span{font-size:12px;color:#555}
.cert-subtext{font-size:12px;font-style:italic;color:#666}
.cert-subtext:empty{display:none}
.cert-more{margin-top:auto;font-family:'Oswald',sans-serif;font-size:12px;letter-spacing:.5px;color:var(--cert-primary,#0047ba);opacity:.85}
.cert-more-btn{display:inline-block;font-family:'Oswald',sans-serif;font-size:14px;text-transform:uppercase;letter-spacing:1px;padding:0 22px;height:45px;line-height:45px;border:2px solid #b0dded;border-radius:4px;background:#b0dded;color:var(--cert-primary,#0047ba);cursor:pointer}
.cert-more-btn:hover{background:var(--cert-primary,#0047ba);color:#fff;border-color:var(--cert-primary,#0047ba)}
/* Modal */
.cert-modal{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;padding:20px}
.cert-modal.is-open{display:flex}
.cert-modal-backdrop{position:absolute;inset:0;background:rgba(10,40,35,.72)}
.cert-modal-dialog{position:relative;background:#fff;border-radius:10px;max-width:680px;width:100%;max-height:86vh;overflow-y:auto;padding:40px 28px 28px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
@media(min-width:760px){.cert-modal-dialog{max-width:760px;padding:44px 40px 32px}}
.cert-modal-close{position:absolute;top:10px;right:12px;background:none;border:none;font-size:26px;line-height:1;color:#555;cursor:pointer;padding:4px 10px}
.cert-modal-body h3{font-family:'Oswald',sans-serif;text-transform:uppercase;letter-spacing:1px;color:var(--cert-primary,#0047ba);margin:0 0 14px;font-size:17px}
.cert-modal-body p{margin:0 0 12px;font-size:14px;color:#555;line-height:1.6}
.cert-badge-img{display:block;height:180px;margin:0 auto 16px;object-fit:contain;max-width:100%}
.cert-list{list-style:none;padding:0;margin:0;font-size:14px;color:#555;line-height:1.6}
.cert-list li{padding:2px 0}
.cert-list li::before{content:'— ';color:var(--cert-primary,#0047ba);font-weight:600}
.cert-subhead{font-family:'Oswald',sans-serif;text-transform:uppercase;letter-spacing:.6px;font-size:13px;color:var(--cert-primary,#0047ba);margin:22px 0 10px}
.cert-steps{margin:0 0 6px;padding-left:20px;counter-reset:cert-step;list-style:none}
.cert-steps li{position:relative;margin:0 0 9px;padding-left:14px;font-size:14px;line-height:1.55;color:#555}
.cert-steps li::before{counter-increment:cert-step;content:counter(cert-step);position:absolute;left:-20px;top:0;width:22px;height:22px;border-radius:50%;background:var(--cert-primary,#0047ba);color:#fff;font-family:'Oswald',sans-serif;font-size:12px;line-height:22px;text-align:center}
.cert-crosscheck{margin-top:16px;padding:14px 16px;border-left:3px solid var(--cert-accent,#fbb917);background:#f7f6f2;border-radius:0 6px 6px 0}
.cert-crosscheck strong{display:block;font-size:13px;color:#3b2222;margin-bottom:5px}
.cert-crosscheck p{margin:0;font-size:13px;line-height:1.55}
.cert-factory{background:#fff;border-radius:6px;padding:20px 18px;box-shadow:0 1px 6px rgba(0,0,0,.06);max-width:460px;margin:16px auto 0;text-align:left}
.cert-factory-icon{font-size:20px;display:block;margin-bottom:8px;line-height:1}
.cert-factory-label{font-family:'Oswald',sans-serif;font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:var(--cert-primary,#0047ba);margin-bottom:5px}
.cert-factory-value{font-size:13px;font-weight:600;color:#3b2222;line-height:1.45}
.cert-factory-map{margin-top:10px}
.cert-factory-iframe{width:100%;height:200px;border:0;border-radius:6px;display:block}
.cert-factory-link{display:inline-block;margin-top:8px;font-family:'Oswald',sans-serif;font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:var(--cert-primary,#0047ba);text-decoration:none}
.cert-modal-body hr{border:none;border-top:1px solid #e5e2da;margin:26px 0}
body.cert-modal-locked{overflow:hidden}
</style>
CSS;

        $js = <<<'JS'
<script>
(function(){
  function init(){
    var wrap=document.querySelector('.cert-modals');
    if(wrap && wrap.parentNode!==document.body){document.body.appendChild(wrap);}
    function open(id){var m=document.getElementById(id);if(!m)return;m.classList.add('is-open');m.setAttribute('aria-hidden','false');document.body.classList.add('cert-modal-locked');}
    function close(m){m.classList.remove('is-open');m.setAttribute('aria-hidden','true');document.body.classList.remove('cert-modal-locked');}
    document.addEventListener('click',function(e){
      var t=e.target.closest('[data-cert-open]');
      if(t){open(t.getAttribute('data-cert-open'));return;}
      if(e.target.closest('[data-cert-close]')){var m=e.target.closest('.cert-modal');if(m)close(m);}
    });
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){var o=document.querySelector('.cert-modal.is-open');if(o)close(o);}});
  }
  if(document.readyState!=='loading')init();else document.addEventListener('DOMContentLoaded',init);
})();
</script>
JS;

        return $css . $js;
    }
}
