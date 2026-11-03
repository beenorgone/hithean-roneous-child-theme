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

// Danh mục rồi Tag: 1 dãy chip ngang, tự xuống dòng, không nhãn (CSS: product-purchase.css → .hithean-product-meta).
$hithean_meta_chips = [];
foreach ( [ 'product_cat' => 'cat', 'product_tag' => 'tag' ] as $hithean_tax => $hithean_kind ) {
	$hithean_terms = get_the_terms( $product->get_id(), $hithean_tax );
	if ( empty( $hithean_terms ) || is_wp_error( $hithean_terms ) ) {
		continue;
	}
	foreach ( $hithean_terms as $hithean_term ) {
		$hithean_link = get_term_link( $hithean_term );
		if ( ! is_wp_error( $hithean_link ) ) {
			$hithean_meta_chips[] = [ 'kind' => $hithean_kind, 'name' => $hithean_term->name, 'url' => $hithean_link ];
		}
	}
}
?>
<div class="product_meta hithean-product-meta">

	<?php do_action( 'woocommerce_product_meta_start' ); ?>

	<?php if ( $hithean_meta_chips ) : ?>
		<ul class="hithean-product-meta__chips" aria-label="<?php esc_attr_e( 'Danh mục và tag sản phẩm', 'hithean.com' ); ?>">
			<?php foreach ( $hithean_meta_chips as $hithean_chip ) : ?>
				<li class="hithean-product-meta__chip--<?php echo esc_attr( $hithean_chip['kind'] ); ?>"><a href="<?php echo esc_url( $hithean_chip['url'] ); ?>" rel="tag"><?php echo esc_html( $hithean_chip['name'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php do_action( 'woocommerce_product_meta_end' ); ?>

</div>
