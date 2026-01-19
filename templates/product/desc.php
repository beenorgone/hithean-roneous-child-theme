<?php
/**
 * templates/product/desc.php
 * Layout 2 cột: (Media | Desc). Chỉ bọc su_expand khi nội dung dài.
 */

if (!defined('ABSPATH')) exit;

$term = get_queried_object();
if (!($term instanceof WP_Term)) return;

// ----- Data -----
$raw_desc  = !empty($term->description) ? $term->description : '';
$term_desc = $raw_desc ? wp_kses_post(wpautop($raw_desc)) : '';

$thumb_id  = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
$image_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'full') : '';

// Chỉ dùng su_expand nếu nội dung dài (giảm tải JS/CSS của plugin)
$plain_text_len = (int) mb_strlen(wp_strip_all_tags($term_desc));
$use_expand     = $plain_text_len > 1000;

// ----- Cache (tránh do_shortcode nhiều lần) -----
// Cache phụ thuộc nội dung mô tả + thumbnail id + cờ use_expand
$cache_key = 'term_desc_block_' . $term->term_id . '_' . md5($raw_desc . '|' . $thumb_id . '|' . (int) $use_expand);
$desc_block = wp_cache_get($cache_key, 'terms');

if (false === $desc_block) {
    if ($term_desc) {
        if ($use_expand) {
            // Chỉ bọc su_expand khi dài
            $desc_inner = $term_desc;
            // Lưu ý: do_shortcode tốn chi phí => đã bọc trong cache
            $desc_block = '<h2 style="margin-top:0; text-align:center;">' . esc_html($term->name) . '</h2>'
                . '[su_expand more_text="ĐỌC THÊM" less_text="THU GỌN" height="500" hide_less="yes" link_color="#0047ba" link_style="dashed" link_align="center" more_icon="icon: arrow-circle-down" less_icon="icon: arrow-up"]'
                . $desc_inner
                . '[/su_expand]';
        } else {
            // Ngắn: không dùng su_expand để khỏi tải tài nguyên không cần
            $desc_block = '<h2 style="margin-top:0; text-align:center;">' . esc_html($term->name) . '</h2>' . $term_desc;
        }

        $desc_block .= '<div id="section-product-ctas" class="b-single-product-ctas" style="text-align:center;">'
            . '<a target="_blank" class="button--light-blue" href="https://m.me/61558663706094"><i class="icon-facebook ti-facebook"></i>Chat với The An</a>'
            . '</div>';
    } else {
        // Không có mô tả
        $desc_block  = '<h2 style="margin-top:0; text-align:center;">' . esc_html($term->name) . '</h2>';
        $desc_block .= '<p></p><div id="section-product-ctas" class="b-single-product-ctas" style="text-align:center;">'
            . '<a style="margin-right: 10px;" class="button--dark-blue-reverse" href="#main-content" ><i class="icon-products ti-angle-double-down"></i>Xem sản phẩm</a><a target="_blank" class="button--light-blue" href="https://m.me/61558663706094"><i class="icon-facebook ti-facebook"></i>Chat với The An</a>'
            . '</div>';
    }
    wp_cache_set($cache_key, $desc_block, 'terms', HOUR_IN_SECONDS * 12); // TTL 12h
}

// Chuẩn bị ảnh responsive (WP mặc định lazy + decoding async từ 6.x, nhưng ta set rõ)
$img_html = '';
if ($thumb_id && $image_url) {
    $sizes = '(max-width: 768px) 100vw, 50vw';
    $img_html = wp_get_attachment_image(
        $thumb_id,
        'large',
        false,
        [
            'class'          => 'term-media-img',
            'loading'        => 'lazy',
            'decoding'       => 'async',
            'fetchpriority'  => 'low',
            'sizes'          => $sizes,
            // width/height sẽ tự thêm từ metadata nếu có, giúp tránh CLS
        ]
    );
}

// Với su_expand: chỉ chạy do_shortcode đúng 1 lần (đã cache)
if ($use_expand && $term_desc) {
    $desc_block_rendered = do_shortcode($desc_block);
} else {
    $desc_block_rendered = $desc_block;
}
?>
<section class="block-product-desc" aria-labelledby="term-desc-title">
    <style>
        .block-product-desc{padding:0;margin:0}
        .block-product-desc .desc-grid{
            display:flex;flex-wrap:wrap;align-items:stretch
        }
        .block-product-desc .desc-box,.block-product-desc .media-box{
            flex:1 1 50%;min-width:0
        }
        @media(max-width:768px){
            .block-product-desc .desc-box,.block-product-desc .media-box{flex:1 1 100%}
            .block-product-desc .media-box{max-height:none}
        }
        .block-product-desc .desc-box{
            padding:50px 40px;line-height:1.7;background:#fafafa;border:1px solid #eee;border-radius:0;
            content-visibility:auto;contain-intrinsic-size:1px 800px; /* tăng tốc paint, dự phòng kích thước */
        }
        .block-product-desc .media-box{
            position:relative;overflow:hidden;min-height:260px;max-height:910px;background:#e5e7eb;border-radius:0;
            display:flex;align-items:center;justify-content:center;
            content-visibility:auto;contain-intrinsic-size:800px 600px;
        }
        .block-product-desc .media-box .term-media-img{
            display:block;width:100%;height:100%;object-fit:cover;object-position:top;aspect-ratio: 4/3;
        }
        .block-product-desc .fallback{
            display:flex;align-items:center;justify-content:center;text-align:center;padding:32px;min-height:260px;background:#e5e7eb;width:100%
        }
        .block-product-desc .fallback h2{margin:0;text-align:center;font-size:clamp(22px,3.2vw,32px);font-weight:700;color:#2a2f36}
        /* Ẩn heading phụ cho screen reader */
        .screen-reader-text{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden}
    </style>

    <div class="desc-grid">
        <?php if ($image_url || $term_desc): ?>
            <div class="media-box" aria-hidden="<?php echo $image_url ? 'false' : 'true'; ?>">
                <?php if ($img_html): ?>
                    <?php echo $img_html; ?>
                <?php elseif ($term_desc): ?>
                    <div class="fallback" role="img" aria-label="<?php echo esc_attr($term->name); ?>">
                        <h2><?php echo esc_html($term->name); ?></h2>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="desc-box">
            <h2 id="term-desc-title" class="screen-reader-text"><?php echo esc_html($term->name); ?></h2>
            <?php echo $desc_block_rendered; ?>
        </div>
    </div>
</section>