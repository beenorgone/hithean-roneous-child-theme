<?php
/**
 * Hien thi tinh trang hang hoa cho trang chi tiet san pham
 * Display product stock status in single product page
 *
 * @package WooCommerce\Templates
 * @version 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Ngat neu truy cap truc tiep / Exit if accessed directly
}

global $product;

if ( ! $product instanceof WC_Product ) {
	return; // Ngat neu khong phai doi tuong san pham / Exit if not a product object
}

// Lay so luong ton kho / Get stock quantity
$stock_quantity = $product->get_stock_quantity();

// Xac dinh trang thai hang hoa / Determine stock status
if ( $product->is_in_stock() ) {
	$availability_text = 'Còn hàng'; // In stock
	/* if ( $stock_quantity !== null ) {
		$availability_text .= ' (' . $stock_quantity . ' sản phẩm)'; // Append quantity if available
	} */
	$class = 'in-stock';
} else {
	$availability_text = 'Hết hàng'; // Out of stock
	$class = 'out-of-stock';
}

?>
<p class="stock <?php echo esc_attr( $class ); ?>">
	<?php echo esc_html( $availability_text ); ?>
</p>
