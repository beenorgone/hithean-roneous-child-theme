<?php
/**
 * Template Name: Landing Page
 *
 * Blank landing page — không dùng .main-container, không có nav, không có footer.
 * CSS + JS được enqueue riêng theo page slug trong functions.php.
 *
 * Nội dung lấy trực tiếp từ pages/{page-slug}/{page-slug}.html trong theme.
 * Chỉnh sửa file HTML đó, git push, VPS git pull → trang tự update.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Đọc body content từ file HTML của trang.
 * Strip <script> tags (chỉ cần cho local preview, WP dùng wp_footer).
 */
function child_theme_landing_page_content( string $html_path ): string {
    if ( ! file_exists( $html_path ) ) {
        return '';
    }

    $raw = file_get_contents( $html_path );

    $body_open = strpos( $raw, '<body' );
    if ( $body_open === false ) {
        return $raw;
    }

    $body_start = strpos( $raw, '>', $body_open ) + 1;
    $body_end   = strrpos( $raw, '</body>' );
    $content    = ( $body_end !== false )
        ? substr( $raw, $body_start, $body_end - $body_start )
        : substr( $raw, $body_start );

    // Bỏ <script> local-preview (JS được enqueue qua wp_footer)
    $content = preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $content );

    return trim( $content );
}

$page_slug    = get_post_field( 'post_name', get_queried_object_id() );
$html_path    = get_stylesheet_directory() . '/pages/' . $page_slug . '/' . $page_slug . '.html';
$page_content = child_theme_landing_page_content( $html_path );

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <?php wp_head(); ?>
</head>
<body <?php body_class( 'anc-page' ); ?>>
<?php wp_body_open(); ?>

<?php echo $page_content; ?>

<?php wp_footer(); ?>
</body>
</html>
