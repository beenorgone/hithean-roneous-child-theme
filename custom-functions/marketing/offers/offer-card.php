<?php
/**
 * Thẻ ưu đãi dùng chung (.hithean-offer-card, CSS: marketing/assets/offer-card.css).
 * Cùng cấu trúc với thẻ popup "Ưu đãi đang áp dụng" ở trang sản phẩm (product-purchase.js → renderDialog):
 *   head [main: top (chip + giá) + tiêu đề] [ảnh / icon] → bậc giá → extra → ghi chú → nút.
 * Trang /uu-dai/ (chế độ "Theo sản phẩm") dựng thẻ bằng hithean_offer_cards_from_rows() để nhãn chip,
 * ảnh, ghi chú, thứ tự thẻ (add-on → bậc giá → vận chuyển / giá → vòng quay) khớp popup trang sản phẩm.
 */
defined('ABSPATH') || exit;

function hithean_offer_card_icon(string $icon): string {
    $icons = [
        'ship' => '<path d="M3 6h11v9H3z"/><path d="M14 9h4l3 3v3h-7"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
        'wheel' => '<g fill="currentColor" fill-opacity=".28" stroke="none"><path d="M12 13V5a8 8 0 0 1 6.93 4z"/><path d="M12 13l6.93 4A8 8 0 0 1 12 21z"/><path d="M12 13v8a8 8 0 0 1-6.93-4z"/></g>'
            . '<circle cx="12" cy="13" r="8"/><path d="M12 5v16M5.07 9l13.86 8M18.93 9 5.07 17"/>'
            . '<circle cx="12" cy="13" r="1.8" fill="currentColor"/><path d="M10.3 1.6h3.4L12 4.6z" fill="currentColor"/>',
        'coupon' => '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4z"/><path d="M9 9.5h.01M15 14.5h.01M15.5 9 8.5 15"/>',
    ];
    return isset($icons[$icon]) ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $icons[$icon] . '</svg>' : '';
}

/**
 * $card: badge, title, price_html?, media ['src'|'icon'], tiers [[label, range, price_html]], extra (text),
 * notes [string], until (timestamp hết hiệu lực — JS trang /uu-dai/ ẩn thẻ khi quá hạn), kind, tone (màu; mặc định group → kind), actions_html, active.
 */
function hithean_offer_card_html(array $card): string {
    $media = (array) ($card['media'] ?? []);
    $media_html = '';
    if (!empty($media['icon']) && ($svg = hithean_offer_card_icon((string) $media['icon'])) !== '') {
        $media_html = '<span class="hithean-offer-card__media hithean-offer-card__media--icon is-' . esc_attr($media['icon']) . '" aria-hidden="true">' . $svg . '</span>';
    } elseif (!empty($media['src'])) {
        $media_html = '<span class="hithean-offer-card__media" aria-hidden="true"><img src="' . esc_url($media['src']) . '" alt="" width="72" height="72" loading="lazy" decoding="async"></span>';
    }
    $attrs = ' data-ho-rule data-ho-kind="' . esc_attr($card['kind'] ?? 'other') . '"';
    if (!empty($card['until'])) { $attrs .= ' data-ho-until="' . (int) $card['until'] . '"'; }
    // Màu theo loại ưu đãi (offer-card.css .is-tone-*): quà kèm / bậc giá / vận chuyển / giá / vòng quay / mã riêng.
    $tone = sanitize_html_class((string) ($card['tone'] ?? $card['group'] ?? $card['kind'] ?? 'other'));
    $html = '<li class="hithean-offer-card is-tone-' . $tone . (!empty($card['active']) ? ' is-active' : '') . '"' . $attrs . '>';
    $html .= '<div class="hithean-offer-card__head' . ($media_html ? ' has-media' : '') . '"><div class="hithean-offer-card__main"><div class="hithean-offer-card__top">';
    $html .= '<span class="hithean-offer-card__badge">' . esc_html((string) ($card['badge'] ?? 'Ưu đãi')) . '</span>';
    if (!empty($card['price_html'])) { $html .= '<span class="hithean-offer-card__price">' . wp_kses_post($card['price_html']) . '</span>'; }
    $html .= '</div><p class="hithean-offer-card__title">' . esc_html((string) ($card['title'] ?? '')) . '</p></div>' . $media_html . '</div>';
    if (!empty($card['tiers'])) {
        $html .= '<ul class="hithean-offer-tiers">';
        foreach ($card['tiers'] as $tier) {
            $html .= '<li><span class="hithean-offer-tiers__label">' . esc_html($tier['label']) . '<small>Mua ' . esc_html($tier['range']) . ' sản phẩm</small></span>';
            if (!empty($tier['price'])) { $html .= '<span class="hithean-offer-card__price">' . wp_kses_post($tier['price']) . '</span>'; }
            $html .= '</li>';
        }
        $html .= '</ul>';
    }
    if (!empty($card['extra'])) { $html .= '<div class="hithean-offer-card__extra">' . esc_html($card['extra']) . '</div>'; }
    foreach ((array) ($card['notes'] ?? []) as $note) {
        if ($note !== '') { $html .= '<p class="hithean-offer-card__note">' . esc_html($note) . '</p>'; }
    }
    $html .= (string) ($card['actions_html'] ?? '');
    return $html . '</li>';
}

/** Bậc giá Discount Rules (bulk) → danh sách bậc như bảng của plugin trên trang sản phẩm. */
function hithean_offer_card_wdr_tiers(array $config): array {
    $bulk = $config['bulk_adjustments'] ?? [];
    $bulk = is_array($bulk) ? $bulk : (json_decode((string) $bulk, true) ?: []);
    $tiers = [];
    foreach ((array) ($bulk['ranges'] ?? []) as $range) {
        $from = (int) ($range['from'] ?? 0); $to = (int) ($range['to'] ?? 0); $value = (float) ($range['value'] ?? 0); $type = (string) ($range['type'] ?? '');
        if ($from < 1 || $value <= 0 || !in_array($type, ['percentage', 'flat', 'fixed_price'], true)) { continue; }
        $label = $type === 'percentage' ? 'Giảm ' . rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',') . '%'
            : ($type === 'fixed_price' ? 'Giá ' . hithean_offers_money($value) . '/sp' : 'Giảm ' . hithean_offers_money($value) . '/sp');
        $tiers[] = ['min' => $from, 'label' => $label, 'range' => $to === $from ? (string) $from : ($to ? $from . '–' . $to : 'từ ' . $from), 'price' => ''];
    }
    usort($tiers, static fn($a, $b) => $a['min'] <=> $b['min']);
    return $tiers;
}

/**
 * Các ưu đãi (dòng snapshot) của MỘT sản phẩm → thẻ theo đúng kiểu popup trang sản phẩm.
 * $rows: các dòng snapshot đã gộp theo sản phẩm (hithean_offers_shortcode → $catalog).
 */
function hithean_offer_cards_from_rows(array $rows): array {
    $product_image = (string) ($rows[0]['image'] ?? '');
    $order = ['addon' => 0, 'tiers' => 1, 'shipping' => 2, 'discount' => 3, 'other' => 4, 'wheel' => 5];
    $cards = [];
    foreach ($rows as $row) {
        $until = min(!empty($row['end']) ? (int) $row['end'] + 1 : PHP_INT_MAX, (int) ($row['verified_at'] ?? 0) + HITHEAN_OFFERS_FRESHNESS);
        $family = (string) ($row['family'] ?? '');
        $config = (array) ($row['source_config'] ?? []);
        $date = !empty($row['end']) ? '⏳ ' . hithean_offers_date_label($row) : '';
        $card = ['kind' => (string) ($row['kind'] ?? 'other'), 'until' => $until, 'media' => $product_image ? ['src' => $product_image] : [], 'notes' => []];
        if ($family === 'wheel') {
            $card += ['group' => 'wheel', 'badge' => 'Vòng quay may mắn', 'title' => 'Quay vòng quay may mắn — nhận mã giảm giá / quà tặng'];
            $card['media'] = ['icon' => 'wheel'];
            $card['notes'][] = 'Mỗi lượt quay có thể trúng mã giảm giá hoặc quà tặng';
            $card['actions_html'] = '<a class="hithean-offer-card__action" href="' . esc_url(home_url('/#thean-lw-root')) . '" data-ho-wheel-open>Quay ngay</a>';
        } elseif ($family === 'addon') {
            $gifts = $row['gift_strip']['gifts'] ?? [];
            $minimum = 1;
            foreach ((array) ($config['ranges'] ?? []) as $range) { $minimum = max(1, (int) ($range['min'] ?? 1)); break; }
            $card += ['group' => 'addon', 'badge' => 'Ưu đãi kèm', 'title' => trim(wp_strip_all_tags((string) ($config['name'] ?? ''))) ?: (string) $row['title']];
            if (!empty($gifts[0]['image'])) { $card['media'] = ['src' => $gifts[0]['image']]; }
            $card['notes'][] = ($config['mode'] ?? '') === 'auto' ? 'Tự động áp dụng khi mua từ ' . $minimum . ' sản phẩm' : 'Chọn ưu đãi này tại trang sản phẩm';
            $message = trim(wp_strip_all_tags((string) ($config['sale_message'] ?? '')));
            if ($message !== '') { $card['extra'] = $message; }
        } elseif ($family === 'wdr' && ($row['kind'] ?? '') === 'quantity' && ($tiers = hithean_offer_card_wdr_tiers($config))) {
            $card += ['group' => 'tiers', 'badge' => 'Mua nhiều giảm giá', 'title' => 'Mua nhiều giảm giá', 'tiers' => $tiers];
            $bulk = $config['bulk_adjustments'] ?? [];
            $bulk = is_array($bulk) ? $bulk : (json_decode((string) $bulk, true) ?: []);
            if (in_array($bulk['operator'] ?? '', ['product_cumulative', 'cart', 'category_cumulative'], true)) { $card['notes'][] = 'Tính cộng dồn số lượng các sản phẩm đủ điều kiện'; }
        } elseif (($row['kind'] ?? '') === 'shipping') {
            $card += ['group' => 'shipping', 'badge' => 'Ưu đãi vận chuyển', 'title' => (string) $row['title']];
            $card['media'] = ['icon' => 'ship'];
        } elseif ($family === 'sale') {
            $card += ['group' => 'discount', 'badge' => 'Giá ưu đãi', 'title' => (string) $row['title']];
        } else {
            $card += ['group' => ($row['kind'] ?? '') === 'discount' ? 'discount' : 'other', 'badge' => ($row['kind'] ?? '') === 'quantity' ? 'Mua nhiều giảm giá' : 'Ưu đãi', 'title' => (string) $row['title']];
        }
        if (!empty($row['condition_note'])) { $card['notes'][] = (string) $row['condition_note']; }
        if ($date !== '') { $card['notes'][] = $date; }
        $cards[] = $card;
    }
    usort($cards, static fn($a, $b) => ($order[$a['group']] ?? 9) <=> ($order[$b['group']] ?? 9));
    return $cards;
}
