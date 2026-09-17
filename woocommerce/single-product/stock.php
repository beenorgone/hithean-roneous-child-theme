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

// _stock_status co the bi lech so voi so luong thuc te (vd: am do dat hang dong thoi)
// _stock_status can drift from the real quantity (e.g. negative from concurrent orders)
$is_out_of_stock = ! $product->is_in_stock() || ( $product->managing_stock() && null !== $stock_quantity && $stock_quantity <= 0 );

// Xac dinh trang thai hang hoa / Determine stock status
if ( ! $is_out_of_stock ) {
	$availability_text = 'Còn hàng'; // In stock
	/* if ( $stock_quantity !== null ) {
		$availability_text .= ' (' . $stock_quantity . ' sản phẩm)'; // Append quantity if available
	} */
	$class = 'in-stock';
} elseif ( 'notify' === $product->get_backorders() ) {
	// Het hang nhung cho phep dat truoc va thong bao khach hang / Out of stock but backorders allowed with customer notification
	$availability_text = 'Tạm hết. Sắp có thêm';
	$class = 'available-on-backorder';
} else {
	$availability_text = 'Hết hàng'; // Out of stock
	$class = 'out-of-stock';
}

?>
<p class="stock <?php echo esc_attr( $class ); ?>">
	<?php echo esc_html( $availability_text ); ?>
</p>
