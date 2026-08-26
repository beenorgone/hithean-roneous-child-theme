<?php
if (!defined('ABSPATH')) exit;

/**
 * [company_info field="hotline"] — in ra 1 trường thông tin doanh nghiệp
 * (khai báo ở Cài đặt ERP > Thông tin doanh nghiệp), dùng trong nội dung bài viết.
 *
 * Attributes:
 *   field (bắt buộc) — key trong hithean_company_info_fields(): company_name,
 *                       hotline, hotline_2, email_sales, email_accounting,
 *                       email_support, address, working_hours, tax_code, zalo, fanpage.
 *   link  ('yes'|'no', mặc định 'no') — bọc giá trị trong thẻ <a> (tel: cho hotline,
 *                       mailto: cho email, href thường cho zalo/fanpage).
 *   text  (tuỳ chọn) — chữ hiển thị khi link="yes" (mặc định hiện luôn giá trị).
 *   class (tuỳ chọn) — thêm class vào thẻ bọc ngoài.
 */
function hithean_company_info_shortcode($atts): string
{
    $atts = shortcode_atts([
        'field' => '',
        'link'  => 'no',
        'text'  => '',
        'class' => '',
    ], $atts, 'company_info');

    $field = sanitize_key($atts['field']);
    if ($field === '' || !array_key_exists($field, hithean_company_info_fields())) {
        return '';
    }

    $value = hithean_company_info_get($field);
    if ($value === '') {
        return '';
    }

    $wants_link = strtolower($atts['link']) === 'yes';
    $class_attr = $atts['class'] !== '' ? ' class="' . esc_attr($atts['class']) . '"' : '';

    if (!$wants_link) {
        return '<span' . $class_attr . '>' . esc_html($value) . '</span>';
    }

    $label = $atts['text'] !== '' ? $atts['text'] : $value;
    $href  = '';

    if (in_array($field, ['hotline', 'hotline_2'], true)) {
        $href = 'tel:' . preg_replace('/[^0-9+]/', '', $value);
    } elseif (in_array($field, ['email_sales', 'email_accounting', 'email_support'], true)) {
        $href = 'mailto:' . $value;
    } elseif (in_array($field, ['zalo', 'fanpage'], true)) {
        $href = $value;
    }

    if ($href === '') {
        return '<span' . $class_attr . '>' . esc_html($value) . '</span>';
    }

    $target = in_array($field, ['zalo', 'fanpage'], true) ? ' target="_blank" rel="noopener"' : '';

    return '<a href="' . esc_url($href) . '"' . $class_attr . $target . '>' . esc_html($label) . '</a>';
}
add_shortcode('company_info', 'hithean_company_info_shortcode');
