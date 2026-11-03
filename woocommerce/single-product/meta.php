<?php
/**
 * Single Product Meta
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/meta.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see         https://woocommerce.com/document/template-structure/
 * @package     WooCommerce\Templates
 * @version     3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $product;

// Danh mục & Tag dạng chip (CSS: product-purchase.css → .hithean-product-meta).
$hithean_meta_rows = [
	'cats' => ['label' => __('Danh mục', 'hithean.com'), 'terms' => get_the_terms($product->get_id(), 'product_cat')],
	'tags' => ['label' => __('Tag', 'hithean.com'), 'terms' => get_the_terms($product->get_id(), 'product_tag')],
];
?>
<div class="product_meta hithean-product-meta">

	<?php do_action( 'woocommerce_product_meta_start' ); ?>

	<?php foreach ( $hithean_meta_rows as $hithean_key => $hithean_row ) :
		if ( empty( $hithean_row['terms'] ) || is_wp_error( $hithean_row['terms'] ) ) {
			continue;
		}
		?>
		<div class="hithean-product-meta__row hithean-product-meta__row--<?php echo esc_attr( $hithean_key ); ?> <?php echo 'cats' === $hithean_key ? 'posted_in' : 'tagged_as'; ?>">
			<span class="hithean-product-meta__label"><?php echo esc_html( $hithean_row['label'] ); ?></span>
			<ul class="hithean-product-meta__chips">
				<?php foreach ( $hithean_row['terms'] as $hithean_term ) :
					$hithean_link = get_term_link( $hithean_term );
					if ( is_wp_error( $hithean_link ) ) {
						continue;
					}
					?>
					<li><a href="<?php echo esc_url( $hithean_link ); ?>" rel="tag"><?php echo esc_html( $hithean_term->name ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>

	<?php do_action( 'woocommerce_product_meta_end' ); ?>

</div>
