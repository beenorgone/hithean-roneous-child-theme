<?php 
global $post; 
$logos = roneous_get_logo();
?>
<div class="b-menu-top-bar">
<?php
if ( has_nav_menu( 'secondary-menu' ) ) {
    wp_nav_menu( array(
        'theme_location' => 'secondary-menu',
        'container'      => false,
        'container_class'=> false,
        'menu_class'     => 'secondary-menu',
    ) );
}
?>
</div>
<div class="nav-container">
    <nav>
    	<?php get_template_part( 'templates/header/inc', 'top-center' ); ?>
        <div class="nav-bar">
		<div class="module visible-sm visible-xs inline-block">
                <a href="<?php echo esc_url(home_url('/')); ?>">
                    <?php if( $logos['logo_text'] && 'text' == $logos['site_logo'] ) : ?>
                        <h1 class="logo"><?php echo esc_attr($logos['logo_text']); ?></h1>
                    <?php else: ?>
                    <img class="logo logo-light" alt="<?php echo esc_attr(get_bloginfo('title')); ?>" src="<?php echo esc_url($logos['logo_light']); ?>" />
                    <img class="logo logo-dark" alt="<?php echo esc_attr(get_bloginfo('title')); ?>" src="<?php echo esc_url($logos['logo']); ?>" />
                    <?php endif; ?>
                </a>
            </div>
            <div class="module widget-wrap mobile-toggle left visible-sm visible-xs">
                <i class="ti-menu"></i>
            </div>
            <div class="row">
                <div class="text-left col-lg-1 module-group">
                    <?php
                    if( (!isset($post->ID) || (isset($post->ID) && !get_post_meta( $post->ID, '_tlg_menu_hide_cart', 1 ))) && 
                        'yes' == get_option( 'roneous_header_cart', 'yes' ) && class_exists( 'Woocommerce' ) ) {
                        get_template_part( 'templates/header/inc', 'cart' );
                    }
                    ?>
                </div>
                <div class="text-center col-lg-10 module-group">
                    <div class="module text-left">
                        <?php
                        wp_nav_menu( 
                            array(
                                'theme_location'    => 'primary',
                                'depth'             => 5,
                                'container'         => false,
                                'container_class'   => false,
                                'menu_class'        => 'menu',
                                'fallback_cb'       => 'Roneous_Nav_Walker::fallback',
                                'walker'            => new Roneous_Nav_Walker()
                            )
                        );
                        ?>
                    </div>
                </div>
                <div class="text-center col-lg-1 module-group center">
                    <?php
                    if( (!isset($post->ID) || (isset($post->ID) && !get_post_meta( $post->ID, '_tlg_menu_hide_language', 1 ))) && 
                        'yes' == get_option( 'roneous_header_language', 'yes' ) && function_exists( 'icl_get_languages' ) ) {
                        get_template_part( 'templates/header/inc', 'language' );
                    }
                    if( (!isset($post->ID) || (isset($post->ID) && !get_post_meta( $post->ID, '_tlg_menu_hide_search', 1 ))) && 
                        'yes' == get_option( 'roneous_header_search', 'yes' ) ) {
                        get_template_part( 'templates/header/inc', 'search' );
                    }
                    ?>
                </div>
            </div>
        </div>
    </nav>
</div>
