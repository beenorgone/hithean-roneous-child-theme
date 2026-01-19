<?php
get_header();
$page_title_args = array(
	'title'   	=> get_option( 'roneous_blog_title', esc_html__( 'Our Blog', 'roneous' ) ),
	'subtitle'  => get_option( 'roneous_blog_subtitle', '' ),
	'layout' 	=> get_option( 'roneous_blog_header_layout', 'center' ),
	'image'    	=> get_option( 'roneous_blog_header_image' ) ? '<img src="'. get_option( 'roneous_blog_header_image' ) .'" alt="'.esc_attr( 'page-header' ).'" class="background-image" />' : false
);
echo roneous_get_the_page_title( $page_title_args );

include('blog-title.php');

get_template_part( 'templates/post/layout', get_option( 'roneous_blog_layout', 'sidebar-right' ) );
get_footer();
