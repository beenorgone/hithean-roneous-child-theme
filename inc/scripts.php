<?php 
/**
 * Theme Script
 *
 * @package TLG Theme
 *
 */


if( !function_exists('roneous_fonts_url') ) {
	function roneous_fonts_url() {
	    $fonts_url 		= '';
	    $font_families 	= array();

	    $body_font 		= roneous_parsing_fonts( get_option('roneous_font'), 'Hind', 400 );
		$heading_font 	= roneous_parsing_fonts( get_option('roneous_header_font'), 'Montserrat', 400 );
		$menu_font 		= roneous_parsing_fonts( get_option('roneous_menu_font'), 'Roboto', 400 );
	    
	    /*
	    Translators: If there are characters in your language that are not supported
	    by chosen font(s), translate this to 'off'. Do not translate into your own language.
	     */
	    if ( 'off' !== _x( 'on', 'Body font: on or off', 'roneous' ) ) {
	    	$font_families[] = $body_font['family'];
	    }
	    if ( 'off' !== _x( 'on', 'Heading font: on or off', 'roneous' ) ) {
	    	$font_families[] = $heading_font['family'];
	    }
	    if ( 'off' !== _x( 'on', 'Menu font: on or off', 'roneous' ) ) {
	    	$font_families[] = $menu_font['family'];
	    }
	    if ( 'off' !== _x( 'on', 'Open Sans font: on or off', 'roneous' ) ) {
	    	$font_families[] = 'Open Sans:300,400';
	    }

	    $query_args = array(
			'family' => urlencode( implode( '|', $font_families ) ),
			'subset' => urlencode( 'latin,latin-ext' ),
		);
		$fonts_url = add_query_arg( $query_args, 'https://fonts.googleapis.com/css' );

	    return esc_url_raw( $fonts_url );
	}
}


if( !function_exists('roneous_load_scripts') ) {
	function roneous_load_scripts() {
		# FONT - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
		wp_enqueue_style( 'roneous-google-fonts', roneous_fonts_url() );
		# CSS - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
		wp_enqueue_style( 'roneous-libs', TLG_THEME_DIRECTORY . 'assets/css/libs.css' );
		if( class_exists('bbPress') ) {
			wp_enqueue_style( 'roneous-bbpress', TLG_THEME_DIRECTORY . 'assets/css/bbpress.css' );
		}
		if (function_exists('tlg_framework_setup')) {
			wp_enqueue_style( 'roneous-theme-styles', TLG_THEME_DIRECTORY . 'assets/css/theme.less' );
		} else {
			wp_enqueue_style( 'roneous-theme-styles', TLG_THEME_DIRECTORY . 'assets/css/theme.min.css' );
		}
		wp_enqueue_style( 'roneous-style', get_stylesheet_uri() );
		# CUSTOM CSS - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
		$custom_css = '';
		if( 'no' == get_option( 'roneous_header_sticky', 'yes' ) ) {
		    $custom_css .= '.nav-container nav.fixed{position:absolute;}';
		}
		if( 'yes' == get_option( 'roneous_header_sticky_mobile', 'no' ) ) {
		    $custom_css .= '@media (max-width: 990px) {nav.absolute, nav.fixed, nav { position: fixed !important; background: #fff!important; z-index: 99999;} .nav-container nav .module.widget-wrap i, .nav-container nav.transparent .nav-utility { color: #28262b !important; } nav.absolute .logo-light, nav.fixed .logo-light, nav .logo-light{ display: none!important; } nav.absolute .logo-dark, nav.fixed .logo-dark, nav .logo-dark{ display: inline!important; } .site-scrolled.admin-bar nav.fixed, .site-scrolled.admin-bar nav.absolute, .site-scrolled.admin-bar nav { top: 0; }}';
		}
		$menu_bgcolor = get_option('roneous_color_menu_bg', '');
		$menu_color = get_option('roneous_color_menu', '');
		if( $menu_bgcolor || $menu_color ) {
			$custom_css .= 'nav .menu > li ul,.module-group .widget_shopping_cart .product_list_widget li:hover,.widget-wrap .widget-inner { background: '.$menu_bgcolor.'!important;}.module-group .widget_shopping_cart .buttons{border-top-color:'.$menu_bgcolor.'!important;}.module-group .widget_shopping_cart .product_list_widget li{border-bottom-color:'.$menu_bgcolor.'!important;}.mega-menu > li{border-right-color:'.$menu_bgcolor.'!important;}nav .menu > li > ul li a, .mega-menu .has-dropdown > a, nav .has-dropdown:after, nav .menu > li ul > .has-dropdown:hover:after, nav .menu > li > ul > li a i, .nav-container nav.transparent.nav-show .menu li:not(.menu-item-btn) a, .nav-container nav.transparent.nav-show .widget-wrap.module i, .nav-container nav:not(.transparent) h1.logo, .nav-container nav.transparent.nav-show h1.logo {opacity: 1!important; color: '.$menu_color.'!important;}@media (max-width: 990px) {.nav-container nav .module-group .menu > li > a, .nav-container nav .module-group .menu > li > span.no-link, .nav-container nav .module-group .widget-wrap a, .nav-container nav .module-group .widget-wrap .search {background-color: '.$menu_bgcolor.'!important; border: none;}.nav-container nav .module-group .menu > li > a, .nav-container nav .module-group .module.widget-wrap i, .nav-container nav .module-group .widget-wrap a,.nav-container nav .module-group .has-dropdown:after,.widget-wrap .search-form input{color: '.$menu_color.'!important;}}.mega-menu .has-dropdown > a{border-bottom:none;}';
		}
		$footer_bgcolor = get_option('roneous_color_footer_bg', '');
		$footer_color = get_option('roneous_color_footer', '');
		$footer_linkcolor = get_option('roneous_color_footer_link', '');
		if( $footer_bgcolor || $footer_color || $footer_linkcolor ) {
			$custom_css .= 'footer h1, footer h2, footer h3, footer h4, footer h5, footer h6{color:'.$footer_color.';} .sub-footer .menu a:after{background:'.$footer_linkcolor.'!important;} .footer-widget.bg-white .widget .tlg-posts-widget .tlg-posts-item .tlg-posts-content .tlg-posts-title:hover, .footer-widget.bg-white .widget .tlg-posts-widget .tlg-posts-item .tlg-posts-content .tlg-posts-title:focus,footer .sub-footer .social-list a,footer .sub-footer .menu a,.footer-widget .widget .twitter-feed .timePosted a, .footer-widget .widget .twitter-feed .timePosted a,.footer-widget .widget .twitter-feed .tweet a,footer a, footer a:hover, footer a:focus, footer h3 a, footer .widget_nav_menu li a, footer .widget_layered_nav li a, footer .widget_product_categories li a, footer .widget_categories .widget-archive li a, footer .widget_categories .post-categories li a, footer .widget_categories li a, footer .widget_archive .widget-archive li a, footer .widget_archive .post-categories li a, footer .widget_archive li a, footer .widget_meta li a, footer .widget_recent_entries li a, footer .widget_pages li a,footer .textwidget a{color:'.$footer_linkcolor.'!important;} footer, footer .widget .title, footer .widget .widgettitle,.footer-widget .widget .twitter-feed .tweet,.footer-widget .widget .tlg-posts-widget .tlg-posts-item .tlg-posts-content .tlg-posts-date,footer .sub {color:'.$footer_color.'!important;}} footer .sub-footer, footer .sub-footer{background:'.$footer_bgcolor.'!important;border-top-color:'.$footer_bgcolor.'!important;}.bg-dark .widget .tlg-posts-widget .tlg-posts-item, .bg-graydark .widget .tlg-posts-widget .tlg-posts-item{border-bottom-color:'.$footer_bgcolor.'!important;}';
		}
		wp_add_inline_style( 'roneous-style', get_option( 'roneous_custom_css', '' ).$custom_css );
		# JS - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
		wp_enqueue_script( 'bootstrap', TLG_THEME_DIRECTORY . 'assets/js/bootstrap.js', array('jquery'), false, true );
		wp_enqueue_script( 'masonry' );
		wp_enqueue_script( 'equalheights', TLG_THEME_DIRECTORY . 'assets/js/lib/jquery.equalheights.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'smoothscroll', TLG_THEME_DIRECTORY . 'assets/js/lib/jquery.smooth-scroll.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'owlcarousel', TLG_THEME_DIRECTORY . 'assets/js/lib/owl.carousel.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'flexslider', TLG_THEME_DIRECTORY . 'assets/js/lib/jquery.flexslider-min.js', array('jquery'), false, true );
		wp_enqueue_script( 'social-share-counter', TLG_THEME_DIRECTORY . 'assets/js/lib/jquery.social-share-counter.js', array('jquery'), false, true );
		wp_enqueue_script( 'flickr-photo-stream', TLG_THEME_DIRECTORY . 'assets/js/lib/flickrPhotoStream.js', array('jquery'), false, true );
		wp_enqueue_script( 'jsparallax', TLG_THEME_DIRECTORY . 'assets/js/lib/jquery.parallax.js', array('jquery'), false, true );
		wp_enqueue_script( 'waypoint', TLG_THEME_DIRECTORY . 'assets/js/lib/waypoint.js', array('jquery'), false, true );
		wp_enqueue_script( 'counterup', TLG_THEME_DIRECTORY . 'assets/js/lib/jquery.counterup.js', array('jquery'), false, true );
		wp_enqueue_script( 'jslightbox', TLG_THEME_DIRECTORY . 'assets/js/lib/lightbox.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'mb-ytplayer', TLG_THEME_DIRECTORY . 'assets/js/lib/jquery.mb.YTPlayer.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'countdown', TLG_THEME_DIRECTORY . 'assets/js/lib/jquery.countdown.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'fluidvids', TLG_THEME_DIRECTORY . 'assets/js/lib/fluidvids.js', array('jquery'), false, true );
		wp_enqueue_script( 'gmap3', TLG_THEME_DIRECTORY . 'assets/js/lib/gmap3.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'modernizr', TLG_THEME_DIRECTORY . 'assets/js/lib/modernizr.js', array('jquery'), false, true );
		wp_enqueue_script( 'jsthrottle', TLG_THEME_DIRECTORY . 'assets/js/lib/jquery.throttle.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'jsshuffle', TLG_THEME_DIRECTORY . 'assets/js/lib/jQuery.shuffle.min.js', array('jquery'), false, true );
		wp_enqueue_script( 'roneous-scripts', TLG_THEME_DIRECTORY . 'assets/js/scripts.js', array('jquery'), false, true );
		if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
			wp_enqueue_script( 'comment-reply' );
		}
		wp_localize_script( 'roneous-scripts', 'wp_data', array(
			'roneous_ajax_url' 		=> admin_url( 'admin-ajax.php' ),
			'roneous_menu_height' 	=> get_option( 'roneous_menu_height', '64' ),
			'roneous_menu_open' 	=> get_option( 'roneous_menu_open', 'yes' ),
			'roneous_permalink' 	=> get_permalink(),
		));
	}
	add_action( 'wp_enqueue_scripts', 'roneous_load_scripts', 110 );
}


if( !function_exists('roneous_admin_load_scripts') ) {
	function roneous_admin_load_scripts() {
		# FONT - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -	
		wp_enqueue_style( 'roneous-google-fonts', roneous_fonts_url() );	
		wp_enqueue_style( 'roneous-fonts', TLG_THEME_DIRECTORY . 'assets/css/fonts.css' );
		# CSS - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -		
		wp_enqueue_style( 'roneous-admin-css', TLG_THEME_DIRECTORY . 'assets/css/admin.css' );
		$custom_css = '';
		if( 'no' == get_option( 'roneous_enable_portfolio', 'yes' ) ) {
			$custom_css .= '#menu-posts-portfolio,[data-element="tlg_portfolio"]{display:none!important;}';
		}
		if( 'no' == get_option( 'roneous_enable_team', 'yes' ) ) {
			$custom_css .= '#menu-posts-team,[data-element="tlg_team"]{display:none!important;}';
		}
		if( 'no' == get_option( 'roneous_enable_client', 'yes' ) ) {
			$custom_css .= '#menu-posts-client,[data-element="tlg_clients"]{display:none!important;}';
		}
		if( 'no' == get_option( 'roneous_enable_testimonial', 'yes' ) ) {
			$custom_css .= '#menu-posts-testimonial,[data-element="tlg_testimonial"]{display:none!important;}';
		}
		if( $custom_css ) {
			wp_add_inline_style( 'roneous-admin-css', $custom_css );
		}
		# JS - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -		
		wp_enqueue_script( 'roneous-admin-js', TLG_THEME_DIRECTORY . 'assets/js/admin.js', array('jquery'), false, true );
	}
	add_action( 'admin_enqueue_scripts', 'roneous_admin_load_scripts', 200 );
}


if( !function_exists('roneous_less_vars') ) {
	function roneous_less_vars( $vars, $handle = 'roneous-theme-styles' ) {
		$body_font 		= roneous_parsing_fonts( get_option('roneous_font'), 'Hind', 400 );
		$heading_font 	= roneous_parsing_fonts( get_option('roneous_header_font'), 'Montserrat', 400 );
		$menu_font 		= roneous_parsing_fonts( get_option('roneous_menu_font'), 'Roboto', 400 );
		$vars['body-font']       	 = $body_font['name'];
		$vars['body-font-weight']    = $body_font['weight'];
		$vars['body-font-style']   	 = $body_font['style'];
		$vars['heading-font']    	 = $heading_font['name'];
		$vars['heading-font-weight'] = $heading_font['weight'];
		$vars['heading-font-style']  = $heading_font['style'];
		$vars['menu-font']    	 	 = $menu_font['name'];
		$vars['menu-font-weight'] 	 = $menu_font['weight'];
		$vars['text-color']    	 	 = get_option('roneous_color_text', '#565656');
		$vars['primary-color']   	 = get_option('roneous_color_primary', '#10B8D2');
		$vars['dark-color']      	 = get_option('roneous_color_dark', '#28262b');
		$vars['bg-dark-color']       = get_option('roneous_color_bg_dark', '#1c1d1f');
		$vars['bg-graydark-color']   = get_option('roneous_color_bg_graydark', '#393939');
		$vars['secondary-color'] 	 = get_option('roneous_color_secondary', '#f7f7f7');
		$vars['menu-badge-color'] 	 = get_option('roneous_color_menu_badge', '#8fae1b');
		$vars['menu-height']   		 = (int) get_option('roneous_menu_height', '64').'px';
		$vars['menu-column-width']   = (int) get_option('roneous_menu_column_width', '230').'px';
		$vars['menu-vertical-width'] = (int) get_option('roneous_menu_vertical_width', '280').'px';
		$vars['menu-rmargin']   	 = (int) get_option('roneous_menu_right_space', '32').'px';
		$vars['body-font-size']    	 = (int) get_option('roneous_body_font_size', '14').'px';
		$vars['menu-font-size']   	 = (int) get_option('roneous_menu_font_size', '11').'px';
		$vars['submenu-font-size']   = (int) get_option('roneous_submenu_font_size', '13').'px';
	    return $vars;
	}
	if (function_exists('tlg_framework_setup')) {
		add_filter( 'less_vars', 'roneous_less_vars', 10, 2 );
	}
}
