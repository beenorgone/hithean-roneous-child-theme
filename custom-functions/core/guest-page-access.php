<?php
if (!defined('ABSPATH')) exit;

/**
 * Guest page access — cho phép khách (chưa đăng nhập) xem một số page
 * chưa Publish (Private/Draft/Pending) qua URL trực tiếp.
 *
 * Dùng cho landing page soft-launch (vd: /an-new-chapter): page vẫn giữ
 * trạng thái Private trong wp-admin (không vào sitemap, search, menu),
 * nhưng ai có link đều xem được.
 *
 * Cách hoạt động: WP_Query chặn page chưa publish ở bước kiểm tra sau khi
 * fetch (yêu cầu đăng nhập + quyền read_private_pages). Hook 'posts_results'
 * chạy TRƯỚC bước kiểm tra đó, nên đổi post_status sang 'publish' trên object
 * trong bộ nhớ (không đụng DB) là đủ để render bình thường — cùng kỹ thuật
 * với plugin Public Post Preview.
 *
 * Thêm/bớt page (theo slug) qua filter:
 *   add_filter('hithean_guest_visible_page_slugs', fn($slugs) => [...$slugs, 'anc-huu-co']);
 */

function hithean_guest_visible_page_slugs(): array
{
    return (array) apply_filters('hithean_guest_visible_page_slugs', [
        'an-new-chapter',
    ]);
}

function hithean_guest_page_access_open(array $posts, WP_Query $query): array
{
    if (is_admin() || !$query->is_main_query() || count($posts) !== 1) {
        return $posts;
    }

    $post = $posts[0];
    if (!$post instanceof WP_Post || $post->post_type !== 'page') {
        return $posts;
    }

    if (!in_array($post->post_status, ['private', 'draft', 'pending'], true)) {
        return $posts;
    }

    if (!in_array($post->post_name, hithean_guest_visible_page_slugs(), true)) {
        return $posts;
    }

    $post->post_status = 'publish';

    return $posts;
}
add_filter('posts_results', 'hithean_guest_page_access_open', 10, 2);
