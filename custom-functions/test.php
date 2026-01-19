<?php
function remove_footer_bg_color_customizer( $wp_customize ) {
    $prefix = 'roneous_'; // Thêm prefix của bạn nếu có
    // Xóa setting
    $wp_customize->remove_setting( $prefix . 'color_footer_bg' );
    // Xóa control
    $wp_customize->remove_control( $prefix . 'color_footer_bg' );
}
add_action( 'customize_register', 'remove_footer_bg_color_customizer', 20 );
