<?php
/**
 * templates/product/desc.php
 * Layout 2 cột: (Media | Desc).
 */

if (!defined('ABSPATH')) exit;

$term = get_queried_object();
if (!($term instanceof WP_Term)) return;

// ----- Data -----
$raw_desc  = !empty($term->description) ? $term->description : '';
// Giống the_content: autop rồi chạy shortcode trong mô tả
$term_desc = $raw_desc ? do_shortcode(shortcode_unautop(wpautop(wp_kses_post($raw_desc)))) : '';

$thumb_id  = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
$image_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'full') : '';

$desc_block_rendered = '<h2 style="margin-top:0; text-align:center;">' . esc_html($term->name) . '</h2>'
    . $term_desc;

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
