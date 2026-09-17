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
?></div>
