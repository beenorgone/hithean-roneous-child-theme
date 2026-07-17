<?php
defined('ABSPATH') || exit;

/**
 * Cầu nối dữ liệu địa chỉ hành chính VN — định dạng mới 2025 (2 cấp):
 * _state = Tỉnh/Thành (34 đơn vị, lưu MÃ, VD "HANOI"),
 * _city  = Phường/Xã (lưu TÊN, VD "Phường Ba Đình"). Không còn cấp quận/huyện.
 *
 * Nguồn dữ liệu: plugin devvn-woo-ghtk (vietnam-checkout/cities):
 *  - tinh_thanhpho.php     → $tinh_thanhpho: [matp => tên tỉnh/thành]
 *  - quan_huyen.php        → $quan_huyen: [maqh => [maqh, name, matp, ...]] (phường/xã)
 *  - devvn-vn-address.json → {matp: {maqh: {maqh, name}}} — gọn nhất, ưu tiên đọc file này
 *
 * File chỉ được require khi cần (render popup khách hàng / AJAX địa chỉ / AI bóc tách).
 */

/**
 * Thư mục cities chứa data ĐỊNH DẠNG MỚI (2 cấp, 34 tỉnh).
 * Trong plugin gốc, cities/ gốc là bộ CŨ (63 tỉnh, 3 cấp — nhận diện bằng
 * file xa_phuong_thitran.php), bộ mới nằm ở cities/v2.1. Vì vậy: ưu tiên
 * v2.1 của plugin, và LOẠI mọi thư mục còn file xa_phuong_thitran.php;
 * fallback cuối là bản copy trong theme (đã là bộ mới).
 */
function theme_vn_address_cities_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $candidates = (array) apply_filters('theme_vn_address_cities_dirs', [
        WP_PLUGIN_DIR . '/devvn-woo-ghtk/vietnam-checkout/cities/v2.1',
        WP_PLUGIN_DIR . '/devvn-woo-ghtk/vietnam-checkout/cities',
        get_stylesheet_directory() . '/custom-plugins/devvn-woo-ghtk/vietnam-checkout/cities',
    ]);

    foreach ($candidates as $candidate) {
        if (!is_readable($candidate . '/tinh_thanhpho.php')) {
            continue;
        }
        if (file_exists($candidate . '/xa_phuong_thitran.php')) {
            continue; // bộ data cũ 3 cấp → bỏ qua
        }
        $dir = (string) $candidate;
        return $dir;
    }

    $dir = '';
    return $dir;
}

/**
 * @return array<string,string> [matp => tên tỉnh/thành], VD ['HANOI' => 'Thành phố Hà Nội']
 */
function theme_vn_address_provinces(): array
{
    static $provinces = null;
    if ($provinces !== null) {
        return $provinces;
    }

    $provinces = [];
    $dir = theme_vn_address_cities_dir();
    if ($dir === '') {
        return $provinces;
    }

    $tinh_thanhpho = [];
    include $dir . '/tinh_thanhpho.php'; // định nghĩa $tinh_thanhpho

    foreach ((array) $tinh_thanhpho as $code => $name) {
        $code = strtoupper(trim((string) $code));
        $name = trim((string) $name);
        if ($code !== '' && $name !== '') {
            $provinces[$code] = $name;
        }
    }

    return $provinces;
}

/**
 * Toàn bộ phường/xã theo tỉnh. Đọc JSON (nhẹ hơn parse PHP array 500KB),
 * fallback quan_huyen.php nếu JSON thiếu.
 *
 * @return array<string,array<string,string>> [matp => [maqh => tên phường/xã]]
 */
function theme_vn_address_all_wards(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    $dir = theme_vn_address_cities_dir();
    if ($dir === '') {
        return $map;
    }

    $json_file = $dir . '/devvn-vn-address.json';
    if (is_readable($json_file)) {
        $data = json_decode((string) file_get_contents($json_file), true);
        if (is_array($data) && $data) {
            foreach ($data as $matp => $wards) {
                foreach ((array) $wards as $maqh => $ward) {
                    $name = trim((string) ($ward['name'] ?? ''));
                    if ($name !== '') {
                        $map[strtoupper((string) $matp)][(string) $maqh] = $name;
                    }
                }
            }
            return $map;
        }
    }

    $quan_huyen = [];
    if (is_readable($dir . '/quan_huyen.php')) {
        include $dir . '/quan_huyen.php'; // định nghĩa $quan_huyen
    }
    foreach ((array) $quan_huyen as $maqh => $ward) {
        $matp = strtoupper(trim((string) ($ward['matp'] ?? '')));
        $name = trim((string) ($ward['name'] ?? ''));
        if ($matp !== '' && $name !== '') {
            $map[$matp][(string) $maqh] = $name;
        }
    }

    return $map;
}

/**
 * @return array<string,string> [maqh => tên phường/xã] của một tỉnh
 */
function theme_vn_address_wards(string $matp): array
{
    $matp = strtoupper(trim($matp));
    $all  = theme_vn_address_all_wards();

    return $all[$matp] ?? [];
}

/**
 * Chuẩn hóa chuỗi địa danh để so khớp: bỏ dấu, thường hóa, bỏ tiền tố
 * đơn vị hành chính ở đầu chuỗi (Thành phố/Tỉnh/Phường/Xã/TP./P./X./TT.),
 * bỏ ký tự không phải chữ-số.
 */
function theme_vn_address_normalize(string $text): string
{
    $text = strtolower(remove_accents(trim($text)));
    $text = preg_replace('/^(thanh pho|thanhpho|tinh|phuong|thi tran|thitran|dac khu|dackhu|xa|tp|p|x|tt)[\s\.\-]+/', '', $text);
    $text = preg_replace('/[^a-z0-9]+/', '', $text);

    return (string) $text;
}

/**
 * So khớp text tự do (AI/người gõ) với danh mục tỉnh/thành.
 *
 * @return array{code:string,name:string}|array{} [] nếu không khớp
 */
function theme_vn_address_match_province(string $text)
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $provinces = theme_vn_address_provinces();

    // Khớp mã trực tiếp (VD "HANOI", "hungyen").
    $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $text));
    if ($code !== '' && isset($provinces[$code])) {
        return ['code' => $code, 'name' => $provinces[$code]];
    }

    $needle = theme_vn_address_normalize($text);
    if ($needle === '') {
        return [];
    }

    // Viết tắt phổ biến.
    $aliases = (array) apply_filters('theme_vn_address_province_aliases', [
        'hcm'    => 'HOCHIMINH',
        'tphcm'  => 'HOCHIMINH',
        'saigon' => 'HOCHIMINH',
        'sg'     => 'HOCHIMINH',
        'hn'     => 'HANOI',
    ]);
    if (isset($aliases[$needle], $provinces[$aliases[$needle]])) {
        return ['code' => $aliases[$needle], 'name' => $provinces[$aliases[$needle]]];
    }

    foreach ($provinces as $matp => $name) {
        if (theme_vn_address_normalize($name) === $needle) {
            return ['code' => $matp, 'name' => $name];
        }
    }

    return [];
}

/**
 * So khớp text tự do với danh mục phường/xã của một tỉnh.
 * So cả bản đầy đủ ("Phường Ba Đình") lẫn bản bỏ tiền tố ("Ba Đình").
 *
 * @return array{code:string,name:string}|array{} [] nếu không khớp
 */
function theme_vn_address_match_ward(string $matp, string $text)
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $wards = theme_vn_address_wards($matp);
    if (!$wards) {
        return [];
    }

    // Bản chuẩn hóa GIỮ tiền tố — phân biệt "Phường X" và "Xã X" trùng tên.
    $full_needle = preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents($text)));
    $needle      = theme_vn_address_normalize($text);

    $fallback = [];
    foreach ($wards as $maqh => $name) {
        $full_candidate = preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents($name)));
        if ($full_needle !== '' && $full_candidate === $full_needle) {
            return ['code' => (string) $maqh, 'name' => $name];
        }
        if (!$fallback && $needle !== '' && theme_vn_address_normalize($name) === $needle) {
            $fallback = ['code' => (string) $maqh, 'name' => $name];
        }
    }

    return $fallback;
}
