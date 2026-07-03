<?php
if (!defined('ABSPATH')) exit;

function hithean_product_nutrition_label_items(int $product_id): array
{
    $csv = trim((string) get_post_meta($product_id, 'product_nutrition_label_csv', true));
    if ($csv === '') return [];

    $items = [];
    foreach (preg_split('/\R/', $csv) as $line) {
        $row = str_getcsv(trim($line));
        if (count($row) < 2) continue;

        $url    = esc_url_raw(trim((string) $row[1]));
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || !in_array($scheme, ['http', 'https'], true)) continue;

        $items[] = [
            'title' => sanitize_text_field(trim((string) $row[0])) ?: __('Bảng dinh dưỡng', 'hithean-product-metabox'),
            'url'   => $url,
        ];
    }

    return $items;
}

function hithean_render_product_nutrition_label($product_id = 0): void
{
    static $render_count = 0;

    $product_id = absint($product_id);

    if (!$product_id) {
        if (!is_product()) return;
        $product_id = (int) get_queried_object_id();
    }

    if ($product_id <= 0 || get_post_type($product_id) !== 'product') return;

    $items = hithean_product_nutrition_label_items($product_id);
    if (!$items) return;

    $render_count++;
    $modal_id = 'nutrition-label-modal-' . $product_id . '-' . $render_count;
    ?>
    <section class="product-nutrition-label" aria-label="Nutrition label">
        <button class="product-nutrition-label__trigger button--" type="button" aria-haspopup="dialog" aria-controls="<?php echo esc_attr($modal_id); ?>" aria-expanded="false"><i class="ti-clipboard" aria-hidden="true"></i><?php esc_html_e('Xem bảng dinh dưỡng', 'hithean-product-metabox'); ?></button>
    </section>
    <div id="<?php echo esc_attr($modal_id); ?>" class="product-nutrition-label__modal" hidden aria-hidden="true">
        <div class="product-nutrition-label__backdrop" data-nutrition-label-close></div>
        <section class="product-nutrition-label__dialog" role="dialog" aria-modal="true" aria-label="Nutrition label images" tabindex="-1">
            <button class="product-nutrition-label__close" type="button" aria-label="Close nutrition label" data-nutrition-label-close>&times;</button>
            <div class="product-nutrition-label__stage">
                <?php if (count($items) > 1) : ?>
                    <button class="product-nutrition-label__nav product-nutrition-label__nav--prev" type="button" aria-label="Previous nutrition label image" data-nutrition-label-step="-1">&lsaquo;</button>
                    <button class="product-nutrition-label__nav product-nutrition-label__nav--next" type="button" aria-label="Next nutrition label image" data-nutrition-label-step="1">&rsaquo;</button>
                <?php endif; ?>
                <?php foreach ($items as $index => $item) : ?>
                    <figure class="product-nutrition-label__image" data-nutrition-label-image="<?php echo esc_attr((string) $index); ?>"<?php echo $index ? ' hidden' : ''; ?>><img src="<?php echo esc_url($item['url']); ?>" alt="<?php echo esc_attr($item['title']); ?>" loading="<?php echo $index ? 'lazy' : 'eager'; ?>" decoding="async"><figcaption><?php echo esc_html($item['title']); ?></figcaption></figure>
                <?php endforeach; ?>
            </div>
            <?php if (count($items) > 1) : ?><div class="product-nutrition-label__thumbs" role="tablist" aria-label="Nutrition label images">
                <?php foreach ($items as $index => $item) : ?><button type="button" class="product-nutrition-label__thumb<?php echo $index ? '' : ' is-active'; ?>" role="tab" aria-selected="<?php echo $index ? 'false' : 'true'; ?>" aria-label="<?php echo esc_attr($item['title']); ?>" data-nutrition-label-thumb="<?php echo esc_attr((string) $index); ?>"><img src="<?php echo esc_url($item['url']); ?>" alt="" loading="lazy" decoding="async"></button><?php endforeach; ?>
            </div><?php endif; ?>
        </section>
    </div>
    <?php
}
add_action('hithean_before_product_chat_ctas', 'hithean_render_product_nutrition_label', 10, 0);

function hithean_product_nutrition_label_shortcode(array $atts = []): string
{
    $atts = shortcode_atts(
        [
            'product_id' => 0,
        ],
        $atts,
        'product_nutrition_label'
    );

    $product_id = absint($atts['product_id']);
    if (!$product_id) {
        $product_id = (int) get_the_ID();
    }

    // Đảm bảo CSS/JS có mặt khi shortcode dùng ngoài trang sản phẩm (vd: landing page).
    if (hithean_product_nutrition_label_items($product_id)) {
        hithean_product_nutrition_label_enqueue_front_assets();
    }

    ob_start();
    hithean_render_product_nutrition_label($product_id);
    return (string) ob_get_clean();
}
add_shortcode('product_nutrition_label', 'hithean_product_nutrition_label_shortcode');

function hithean_product_nutrition_label_register_guide_page(): void
{
    add_submenu_page(
        'edit.php?post_type=product',
        __('Hướng dẫn Nutrition Label', 'hithean-product-metabox'),
        __('Nutrition Label Guide', 'hithean-product-metabox'),
        'edit_products',
        'hithean-product-nutrition-label-guide',
        'hithean_product_nutrition_label_render_guide_page'
    );
}
add_action('admin_menu', 'hithean_product_nutrition_label_register_guide_page', 30);

function hithean_product_nutrition_label_render_guide_page(): void
{
    if (!current_user_can('edit_products')) wp_die(esc_html__('Không có quyền truy cập.', 'hithean-product-metabox'));

    $shortcode_current = '[product_nutrition_label]';
    $shortcode_product = '[product_nutrition_label product_id="123"]';
    $remove_auto_hook  = "add_action('wp', function () {\n    remove_action('hithean_before_product_chat_ctas', 'hithean_render_product_nutrition_label', 10);\n});";
    $template_snippet  = "<?php echo do_shortcode('[product_nutrition_label]'); ?>";
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Hướng dẫn setup nút Xem bảng dinh dưỡng', 'hithean-product-metabox'); ?></h1>
        <p><?php esc_html_e('Mặc định Hithean hiển thị nút ngay phía trên cụm Chat với The An. Nếu cần đặt ở vị trí khác, dùng shortcode hoặc snippet code bên dưới.', 'hithean-product-metabox'); ?></p>

        <h2><?php esc_html_e('1. Nhập dữ liệu ảnh', 'hithean-product-metabox'); ?></h2>
        <p><?php esc_html_e('Trong trang sửa sản phẩm, điền field Bảng thành phần / dinh dưỡng (CSV). Mỗi dòng gồm: Tiêu đề ảnh, URL ảnh.', 'hithean-product-metabox'); ?></p>

        <h2><?php esc_html_e('2. Đặt thủ công bằng shortcode', 'hithean-product-metabox'); ?></h2>
        <pre><code><?php echo esc_html($shortcode_current); ?></code></pre>
        <pre><code><?php echo esc_html($shortcode_product); ?></code></pre>

        <h2><?php esc_html_e('3. Dời vị trí bằng code', 'hithean-product-metabox'); ?></h2>
        <p><?php esc_html_e('Nếu đặt shortcode trực tiếp vào template, nên bỏ auto hook mặc định để tránh hiện 2 nút.', 'hithean-product-metabox'); ?></p>
        <pre><code><?php echo esc_html($remove_auto_hook); ?></code></pre>
        <p><?php esc_html_e('Sau đó chèn shortcode vào vị trí mong muốn trong template sản phẩm:', 'hithean-product-metabox'); ?></p>
        <pre><code><?php echo esc_html($template_snippet); ?></code></pre>
    </div>
    <?php
}

function hithean_product_nutrition_label_assets(): void
{
    if (!is_product()) return;
    if (!hithean_product_nutrition_label_items((int) get_queried_object_id())) return;
    hithean_product_nutrition_label_enqueue_front_assets();
}
add_action('wp_enqueue_scripts', 'hithean_product_nutrition_label_assets', 30);

function hithean_product_nutrition_label_enqueue_front_assets(): void
{
    if (wp_style_is('hithean-product-nutrition-label', 'enqueued')) return;
    $css = '.product-nutrition-label__stage{position:relative;padding:20px 58px;touch-action:pan-y}.product-nutrition-label{margin:18px 0!important;text-align:left}.product-nutrition-label__trigger{width:235px;max-width:100%;cursor:pointer}.product-nutrition-label__trigger i{margin-right:8px}.product-nutrition-label__modal[hidden]{display:none}.product-nutrition-label__modal{position:fixed;z-index:999999;inset:0;display:grid;place-items:center;padding:20px}.product-nutrition-label__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.68)}.product-nutrition-label__dialog{position:relative;display:grid;gap:14px;width:min(100%,900px);max-height:calc(100vh - 40px);padding:44px 20px 20px;overflow:auto;border-radius:12px;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.32)}.product-nutrition-label__close{position:absolute;top:8px;right:10px;width:34px;height:34px;border:0;border-radius:50%;background:#f1f1f1;color:#111;font-size:28px;line-height:1;cursor:pointer}.product-nutrition-label__nav{position:absolute;top:50%;z-index:2;display:grid;place-items:center;width:42px;height:42px;border:0;border-radius:50%;background:rgba(0,0,0,.58);color:#fff;font-size:36px;line-height:1;transform:translateY(-50%);cursor:pointer}.product-nutrition-label__nav--prev{left:8px}.product-nutrition-label__nav--next{right:8px}.product-nutrition-label__image{margin:0;text-align:center}.product-nutrition-label__image img{display:block;width:auto;max-width:100%;max-height:70vh;margin:auto}.product-nutrition-label__image figcaption{margin-top:8px;display:inline-block;padding:4px 10px;background:rgba(0,0,0,.55);color:#fff;font-weight:600;text-shadow:0 1px 3px rgba(0,0,0,.65)}.product-nutrition-label__thumbs{display:flex;justify-content:center;gap:8px;overflow-x:auto;padding:2px}.product-nutrition-label__thumb{flex:0 0 64px;padding:2px;border:2px solid transparent;border-radius:6px;background:transparent;cursor:pointer}.product-nutrition-label__thumb.is-active{border-color:#111}.product-nutrition-label__thumb img{display:block;width:56px;height:56px;object-fit:cover}@media(max-width:600px){.product-nutrition-label{text-align:center}.product-nutrition-label__modal{padding:10px}.product-nutrition-label__dialog{max-height:calc(100vh - 20px);padding:42px 12px 12px}.product-nutrition-label__stage{padding:20px 44px}.product-nutrition-label__nav{width:36px;height:36px;font-size:30px}.product-nutrition-label__nav--prev{left:0}.product-nutrition-label__nav--next{right:0}}';
    wp_register_style('hithean-product-nutrition-label', false, [], '1.0.0');
    wp_enqueue_style('hithean-product-nutrition-label');
    wp_add_inline_style('hithean-product-nutrition-label', $css);
    $script = <<<'JS'
document.addEventListener('click',function(e){
    var t=e.target.closest('.product-nutrition-label__trigger');
    if(t){var m=document.getElementById(t.getAttribute('aria-controls'));if(!m)return;m.hidden=false;m.setAttribute('aria-hidden','false');t.setAttribute('aria-expanded','true');m.querySelector('.product-nutrition-label__close').focus();return}
    var m=e.target.closest('.product-nutrition-label__modal');if(!m)return;
    if(e.target.closest('[data-nutrition-label-close]')){m.hidden=true;m.setAttribute('aria-hidden','true');var o=document.querySelector('.product-nutrition-label__trigger[aria-controls="'+m.id+'"]');if(o){o.setAttribute('aria-expanded','false');o.focus()}return}
    var step=e.target.closest('[data-nutrition-label-step]');if(step){stepNutritionLabelImage(m,parseInt(step.getAttribute('data-nutrition-label-step'),10)||0);return}
    var b=e.target.closest('[data-nutrition-label-thumb]');if(!b)return;setNutritionLabelImage(m,b.getAttribute('data-nutrition-label-thumb'));
});
function setNutritionLabelImage(m,i){m.querySelectorAll('[data-nutrition-label-image]').forEach(function(x){x.hidden=x.getAttribute('data-nutrition-label-image')!==String(i)});m.querySelectorAll('[data-nutrition-label-thumb]').forEach(function(x){var a=x.getAttribute('data-nutrition-label-thumb')===String(i);x.setAttribute('aria-selected',a?'true':'false');x.classList.toggle('is-active',a)})}
function stepNutritionLabelImage(m,dir){var imgs=[].slice.call(m.querySelectorAll('[data-nutrition-label-image]'));if(imgs.length<2)return;var current=imgs.findIndex(function(x){return !x.hidden});var next=(current+dir+imgs.length)%imgs.length;setNutritionLabelImage(m,imgs[next].getAttribute('data-nutrition-label-image'))}
document.addEventListener('touchstart',function(e){var s=e.target.closest('.product-nutrition-label__stage');if(!s)return;var t=e.changedTouches[0];s.dataset.swipeX=t.clientX;s.dataset.swipeY=t.clientY},{passive:true});
document.addEventListener('touchend',function(e){var s=e.target.closest('.product-nutrition-label__stage');if(!s||!s.dataset.swipeX)return;var t=e.changedTouches[0],dx=t.clientX-parseFloat(s.dataset.swipeX),dy=t.clientY-parseFloat(s.dataset.swipeY);delete s.dataset.swipeX;delete s.dataset.swipeY;if(Math.abs(dx)>45&&Math.abs(dx)>Math.abs(dy)*1.25){var m=s.closest('.product-nutrition-label__modal');if(m)stepNutritionLabelImage(m,dx<0?1:-1)}},{passive:true});
document.addEventListener('keydown',function(e){var m=document.querySelector('.product-nutrition-label__modal:not([hidden])');if(!m)return;if(e.key==='Escape')m.querySelector('[data-nutrition-label-close]').click();if(e.key==='ArrowLeft')stepNutritionLabelImage(m,-1);if(e.key==='ArrowRight')stepNutritionLabelImage(m,1)});
JS;
    wp_register_script('hithean-product-nutrition-label', false, [], '1.0.0', true);
    wp_enqueue_script('hithean-product-nutrition-label');
    wp_add_inline_script('hithean-product-nutrition-label', $script);
}

function hithean_product_nutrition_label_admin_assets(string $hook): void
{
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'product') return;

    wp_enqueue_media();
    wp_register_style('hithean-product-nutrition-label-admin', false, [], '1.1.0');
    wp_enqueue_style('hithean-product-nutrition-label-admin');
    wp_add_inline_style('hithean-product-nutrition-label-admin', '.nutrition-label-csv-tools{margin-top:10px}.nutrition-label-csv-tools__guide{margin:8px 0}.nutrition-label-csv-tools__list{margin:10px 0 0}.nutrition-label-csv-tools__item{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:6px 0}.nutrition-label-csv-tools__url{flex:1 1 320px;min-width:0}');
    wp_register_script('hithean-product-nutrition-label-admin', false, ['jquery'], '1.1.0', true);
    wp_enqueue_script('hithean-product-nutrition-label-admin');
    wp_add_inline_script('hithean-product-nutrition-label-admin', <<<'JS'
jQuery(function($) {
    var $field = $('#product_nutrition_label_csv');
    if (!$field.length || !window.wp || !wp.media) return;

    var $tools = $('<div class="nutrition-label-csv-tools"><button type="button" class="button button-secondary">Tải ảnh lên</button><p class="description nutrition-label-csv-tools__guide">Upload ảnh, sau đó Copy URL hoặc bấm “Thêm vào CSV” để chèn tiêu đề và link vào field phía trên. Cuối cùng bấm Cập nhật sản phẩm.</p><ul class="nutrition-label-csv-tools__list"></ul></div>');
    $field.after($tools);

    function csvCell(value) {
        value = String(value || '');
        return /[",\r\n]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value;
    }

    function copyUrl(url, $button) {
        function done() { $button.text('Đã copy'); window.setTimeout(function() { $button.text('Copy'); }, 1500); }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done);
            return;
        }
        var $temp = $('<textarea>').val(url).appendTo('body').select();
        document.execCommand('copy');
        $temp.remove();
        done();
    }

    function addItem(attachment) {
        var title = attachment.get('title') || 'Bảng dinh dưỡng';
        var url = attachment.get('url');
        if (!url) return;

        var $item = $('<li class="nutrition-label-csv-tools__item">');
        var $url = $('<input class="nutrition-label-csv-tools__url" type="url" readonly>');
        $url.val(url);
        var $copy = $('<button type="button" class="button">Copy</button>');
        var $append = $('<button type="button" class="button button-secondary">Thêm vào CSV</button>');
        $copy.on('click', function() { copyUrl(url, $copy); });
        $append.on('click', function() {
            var line = csvCell(title) + ',' + csvCell(url);
            var current = $field.val().replace(/\s+$/, '');
            $field.val(current ? current + '\n' + line : line).trigger('change');
            $append.text('Đã thêm');
        });
        $item.append($('<strong>').text(title), $url, $copy, $append);
        $tools.find('.nutrition-label-csv-tools__list').append($item);
    }

    $tools.find('button').first().on('click', function() {
        var frame = wp.media({ title: 'Tải ảnh bảng dinh dưỡng', button: { text: 'Dùng ảnh đã chọn' }, multiple: true, library: { type: 'image' } });
        frame.on('select', function() { frame.state().get('selection').each(addItem); });
        frame.open();
    });
});
JS
    );
}
add_action('admin_enqueue_scripts', 'hithean_product_nutrition_label_admin_assets');
