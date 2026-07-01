<?php
if (!defined('ABSPATH')) exit;

/**
 * Chặn upload file chứa mã PHP / có extension thực thi trên server.
 */
function hithean_upload_guard_check_content( $file ) {
    if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
        return $file;
    }

    // Chặn các extension thực thi được trên server, bất kể MIME khai báo.
    $blocked_ext = [ 'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phps', 'pht', 'phar' ];
    $ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
    if ( in_array( $ext, $blocked_ext, true ) ) {
        $file['error'] = 'File bị từ chối — định dạng không cho phép.';
        return $file;
    }

    // Quét đầu file tìm thẻ PHP rõ ràng, giới hạn 1MB để tránh đọc nguyên file lớn vào RAM.
    $handle = fopen( $file['tmp_name'], 'rb' );
    if ( $handle ) {
        $head = fread( $handle, 1024 * 1024 );
        fclose( $handle );
        if ( $head !== false && strpos( $head, "\0" ) === false && preg_match( '/<\?(?:php|=)/i', $head ) ) {
            $file['error'] = 'File bị từ chối — chứa mã PHP.';
        }
    }

    return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'hithean_upload_guard_check_content' );
