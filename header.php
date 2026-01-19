<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="pingback" href="<?php bloginfo( 'pingback_url' ); ?>" />
	<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam:wght@400;500&family=IBM+Plex+Sans:wght@400;500&family=Oswald:wght@400;500&display=swap" rel="stylesheet">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php if ( 'yes' == get_option('roneous_enable_preloader', 'no') ) : ?>
		<div id="tlg_preloader"><span class="spinner"></span></div>
	<?php endif; ?>
	<?php if( 'border-layout' == roneous_get_body_layout() ) : ?>
		<span class="tlg_border border--top"></span>
		<span class="tlg_border border--bottom"></span>
		<span class="tlg_border border--right"></span>
		<span class="tlg_border border--left"></span>
	<?php endif; ?>
	<?php get_template_part( 'templates/header/layout', roneous_get_header_layout() ); ?>
	<div class="main-container">
