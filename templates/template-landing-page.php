<?php
/**
 * Template Name: Landing Page
 *
 * Blank landing page — không dùng .main-container, không có nav, không có footer.
 * CSS + JS được enqueue riêng theo page slug trong functions.php.
 * Nội dung (các section) được quản lý trong post content.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <?php wp_head(); ?>
</head>
<body <?php body_class( 'anc-page' ); ?>>
<?php wp_body_open(); ?>

<?php
while ( have_posts() ) {
    the_post();
    the_content();
}
?>

<?php wp_footer(); ?>
</body>
</html>
