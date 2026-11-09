<?php
/**
 * Đồng bộ tài liệu với code — phần nào máy sinh được thì máy sinh, phần nào
 * người viết thì máy canh cho khỏi lệch.
 *
 *   php tools/docs-sync.php                 ghi lại mọi file sinh tự động
 *   php tools/docs-sync.php --check         không ghi; exit 1 nếu file sinh đã cũ hoặc audit lỗi
 *   php tools/docs-sync.php --index         đọc code từ git index (pre-commit hook dùng)
 *   php tools/docs-sync.php --changed a b   in tài liệu viết tay có `applies_to` khớp các file a, b
 *   php tools/docs-sync.php --quiet         chỉ in lỗi
 *
 * KHÔNG cần WordPress. Chỉ đọc mã nguồn bằng tokenizer (coding-practices §8:
 * phân tích mã nguồn thì dùng tokenizer, đừng dùng regex).
 *
 * Engine DÙNG CHUNG theanmarket + hithean (giống hệt nhau — sửa thì chép sang repo kia). Phần riêng
 * của từng site (chia khu, mô tả khu, route, audit bật/tắt) ở tools/docs-sync.config.php.
 * Gốc: tools/docs-sync.php của ivarvietnam.
 *
 * Sinh ra (đừng sửa tay — sửa docblock trong code rồi chạy lại):
 *   - docs/code-map/README.md + docs/code-map/<khu>.md   bản đồ hàm, điểm vào, hook
 *   - khối <!-- AUTO:xxx --> trong docs/architecture.md
 *   - AGENTS.md                                          bản chép CLAUDE.md cho agent khác
 *
 * Kiểm (audit):
 *   - [lỗi]      mọi route trong code (config 'routes' + define('…ROUTE…')) phải có dòng trong bảng
 *                route của docs/architecture.md
 *   - [lỗi]      (config 'guide_web_check') mọi guide*.md trong custom-functions/ phải mở được trên web
 *   - [lỗi]      không có hai đường dẫn chỉ khác chữ hoa/thường
 *   - [cảnh báo] tài liệu viết tay có `applies_to` mà code liên quan có commit mới hơn `reviewed`
 *   - [cảnh báo] file khớp config 'domain_glob' chưa thuộc `applies_to` của tài liệu domain nào
 *
 * Giới hạn: tên hook ghép động (`'wp_ajax_' . $x`) chỉ in nguyên biểu thức; hook
 * đăng ký qua vòng lặp mảng thì không thấy. Bản đồ là để TÌM code, không thay đọc code.
 *
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ROOT = dirname(__DIR__);
chdir($ROOT);

/** Cấu hình riêng của site — xem tools/docs-sync.config.php. */
$GLOBALS['DS_CFG'] = array_merge([
    'area_rules'      => [],   // tiền tố (tương đối custom-functions/) => số cấp thư mục làm 1 khu; mặc định 1
    'layer_rules'     => [],   // như area_rules, cho bảng tầng AUTO:layers
    'area_desc'       => [],   // khu => mô tả (khu không có file "chính")
    'module_tree'     => [],   // tiền tố khu liệt kê ở AUTO:modules
    'routes'          => null, // callable(array $sources): string[] — slug route (không gạch chéo đầu/cuối)
    'guide_web_check' => false,
    'domain_glob'     => '',   // vd. custom-functions/erp/domain/*.php
    'manual_globs'    => [],   // glob tài liệu viết tay ngoài docs/ có applies_to
    'skip_dirs'       => [],   // thêm vào DS_SKIP_DIRS, dạng '/draft/'
], is_file(__DIR__ . '/docs-sync.config.php') ? (array) require __DIR__ . '/docs-sync.config.php' : []);

$args    = array_slice($argv, 1);
$CHECK   = in_array('--check', $args, true);
$QUIET   = in_array('--quiet', $args, true);
$INDEX   = in_array('--index', $args, true);
$changed = [];
if (($p = array_search('--changed', $args, true)) !== false) {
    $changed = array_values(array_filter(array_slice($args, $p + 1), static fn($a) => strpos($a, '--') !== 0));
}

const DS_SKIP_DIRS = ['/vendor/', '/node_modules/', '/_archive/'];
const DS_AUTO_NOTE = '<!-- Sinh tự động bởi tools/docs-sync.php — KHÔNG sửa tay. Sửa docblock trong code rồi chạy `php tools/docs-sync.php`. -->';

// ─── Nguồn mã: cây làm việc hoặc git index ───────────────────────────────────

/** @return array<string,string> đường dẫn tương đối → nội dung, mọi file .php trong custom-functions/ */
function ds_read_sources(bool $from_index): array
{
    $out = [];
    if (!$from_index) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('custom-functions', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', $f->getPathname());
            if (substr($rel, -4) === '.php' && !ds_skipped($rel)) {
                $out[$rel] = (string) file_get_contents($rel);
            }
        }
        ksort($out);
        return $out;
    }

    // Index: đúng thứ sắp commit, không lẫn thay đổi chưa stage của người khác.
    $list = [];
    exec('git ls-files -s -- custom-functions', $lines);
    foreach ($lines as $line) {
        if (!preg_match('/^\d+ ([0-9a-f]+) \d\t(.+)$/', $line, $m)) {
            continue;
        }
        if (substr($m[2], -4) === '.php' && !ds_skipped($m[2])) {
            $list[$m[2]] = $m[1];
        }
    }
    $proc = proc_open(['git', 'cat-file', '--batch'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], implode("\n", $list) . "\n");
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);

    $pos = 0;
    foreach ($list as $path => $sha) {
        $nl     = strpos($raw, "\n", $pos);
        $header = explode(' ', substr($raw, $pos, $nl - $pos));
        $size   = (int) ($header[2] ?? 0);
        $out[$path] = substr($raw, $nl + 1, $size);
        $pos = $nl + 1 + $size + 1;
    }
    ksort($out);
    return $out;
}

function ds_skipped(string $rel): bool
{
    foreach (array_merge(DS_SKIP_DIRS, (array) ($GLOBALS['DS_CFG']['skip_dirs'] ?? [])) as $d) {
        if (strpos('/' . $rel, $d) !== false) {
            return true;
        }
    }
    return false;
}

// ─── Phân tích một file ──────────────────────────────────────────────────────

/** Dòng mô tả đầu tiên của docblock (bỏ @tag), tối đa ~180 ký tự. */
function ds_doc_summary(string $doc): string
{
    $doc   = preg_replace('#^/\*+|\*/$#', '', trim($doc));
    $lines = preg_split('/\r\n|\n|\r/', (string) $doc) ?: [];
    $buf   = [];
    foreach ($lines as $l) {
        $l = trim(preg_replace('/^\s*\*\s?/', '', $l));
        if ($l === '') {
            if ($buf) {
                break;
            }
            continue;
        }
        if ($l[0] === '@' || preg_match('/^[─━=\-]{3,}/u', $l)) {
            if ($buf) {
                break;
            }
            continue;
        }
        $buf[] = $l;
    }
    $s = trim(implode(' ', $buf));
    if (mb_strlen($s) > 180) {
        $s = rtrim(mb_substr($s, 0, 177)) . '…';
    }
    return str_replace('|', '\|', $s);
}

function ds_compact(string $s, int $max): string
{
    $s = trim((string) preg_replace('/\s+/', ' ', $s));
    if (mb_strlen($s) > $max) {
        $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
    }
    return $s;
}

/**
 * @return array{summary:string, functions:array, classes:array, entries:array, listens:array, fires:array, lines:int}
 */
function ds_parse(string $src): array
{
    $r = ['summary' => '', 'functions' => [], 'classes' => [], 'entries' => [], 'listens' => [], 'fires' => [], 'lines' => substr_count($src, "\n") + 1];

    try {
        $tokens = token_get_all($src);
    } catch (Throwable $e) {
        return $r;
    }
    $n = count($tokens);

    $is_ws  = static fn($t) => is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT], true);
    $next   = static function (int $i) use ($tokens, $n, $is_ws): int {
        for ($j = $i + 1; $j < $n; $j++) {
            if (!$is_ws($tokens[$j])) {
                return $j;
            }
        }
        return $n;
    };
    $prev   = static function (int $i) use ($tokens, $is_ws): int {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$is_ws($tokens[$j])) {
                return $j;
            }
        }
        return -1;
    };
    $text   = static fn($t) => is_array($t) ? $t[1] : $t;
    // Gom đối số trong (...) bắt đầu ở $open; trả mảng chuỗi nguồn từng đối số cấp 1.
    $args   = static function (int $open) use ($tokens, $n, $text): array {
        $depth = 0;
        $cur   = '';
        $out   = [];
        $toks  = [];
        for ($j = $open; $j < $n; $j++) {
            $t = $tokens[$j];
            $s = $text($t);
            if ($s === '(' || $s === '[' || $s === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($s === ')' || $s === ']' || $s === '}') {
                $depth--;
                if ($depth === 0) {
                    $out[] = [$cur, $toks];
                    break;
                }
            } elseif ($s === ',' && $depth === 1) {
                $out[] = [$cur, $toks];
                $cur   = '';
                $toks  = [];
                continue;
            }
            $cur .= $s;
            if (!(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT], true))) {
                $toks[] = $t;
            }
        }
        return $out;
    };
    // Tên hook từ một đối số: chuỗi hằng → giá trị; còn lại → biểu thức rút gọn.
    // Chuỗi hằng, hằng đã define, và phép nối `.` giữa chúng được giải ra giá trị.
    $hook_name = static function (array $arg): string {
        [$src, $toks] = $arg;
        $consts = $GLOBALS['DS_CONSTS'] ?? [];
        $val    = '';
        $expect_part = true;
        foreach ($toks as $t) {
            if ($expect_part && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $val .= stripcslashes(substr($t[1], 1, -1));
            } elseif ($expect_part && is_array($t) && $t[0] === T_STRING && isset($consts[$t[1]])) {
                $val .= $consts[$t[1]];
            } elseif (!$expect_part && $t === '.') {
                // nối tiếp
            } else {
                return '⟨' . ds_compact($src, 60) . '⟩';
            }
            $expect_part = !$expect_part;
        }
        return ($toks && !$expect_part) ? $val : '⟨' . ds_compact($src, 60) . '⟩';
    };

    // Docblock đầu file: docblock đầu tiên mà không gắn ngay vào function/class.
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_OPEN_TAG, T_WHITESPACE], true)) {
            continue;
        }
        if (is_array($t) && ($t[0] === T_DOC_COMMENT || ($t[0] === T_COMMENT && strpos($t[1], '/*') === 0))) {
            $nx = $next($i);
            $nt = $tokens[$nx] ?? null;
            if (!(is_array($nt) && in_array($nt[0], [T_FUNCTION, T_CLASS, T_FINAL, T_ABSTRACT], true))) {
                $r['summary'] = ds_doc_summary($t[1]);
            }
        }
        break;
    }

    $depth       = 0;
    $class_stack = []; // [depth mở class]
    $pending_cls = null;

    $listen_fns = ['add_action', 'add_filter'];
    $fire_fns   = ['do_action', 'apply_filters', 'do_action_ref_array', 'apply_filters_ref_array'];

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        $s = $text($t);

        if ($s === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            if ($pending_cls !== null) {
                $class_stack[] = $depth;
                $pending_cls   = null;
            }
            continue;
        }
        if ($s === '}') {
            if ($class_stack && end($class_stack) === $depth) {
                array_pop($class_stack);
            }
            $depth--;
            continue;
        }
        if (!is_array($t)) {
            continue;
        }

        // class / trait / interface (không tính `X::class`, `new class`)
        if (in_array($t[0], [T_CLASS, T_TRAIT, T_INTERFACE], true)) {
            $pv = $prev($i);
            if ($pv >= 0 && is_array($tokens[$pv]) && in_array($tokens[$pv][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            $nx = $next($i);
            if (isset($tokens[$nx]) && is_array($tokens[$nx]) && $tokens[$nx][0] === T_STRING) {
                $r['classes'][] = ['name' => $tokens[$nx][1], 'summary' => ds_lookback_doc($tokens, $i, $prev)];
                $pending_cls    = true;
            }
            continue;
        }

        if ($t[0] === T_FUNCTION) {
            if ($class_stack) {
                continue; // method — chỉ liệt kê tên class
            }
            $nx = $next($i);
            if (isset($tokens[$nx]) && $text($tokens[$nx]) === '&') {
                $nx = $next($nx);
            }
            if (!isset($tokens[$nx]) || !is_array($tokens[$nx]) || $tokens[$nx][0] !== T_STRING) {
                continue; // closure
            }
            $name = $tokens[$nx][1];
            $op   = $next($nx);
            $params = array_map(static fn($a) => ds_compact($a[0], 200), $args($op));
            $params = array_values(array_filter($params, static fn($p) => $p !== ''));
            $r['functions'][] = [
                'name'    => $name,
                'params'  => ds_compact(implode(', ', $params), 90),
                'summary' => ds_doc_summary(ds_lookback_doc($tokens, $i, $prev, true)),
            ];
            continue;
        }

        $fn = null;
        if ($t[0] === T_STRING) {
            $fn = $t[1];
        } elseif (defined('T_NAME_FULLY_QUALIFIED') && $t[0] === T_NAME_FULLY_QUALIFIED) {
            $fn = ltrim($t[1], '\\');
        }
        if ($fn === null) {
            continue;
        }
        $pv = $prev($i);
        if ($pv >= 0 && is_array($tokens[$pv]) && in_array($tokens[$pv][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
            continue;
        }
        $op = $next($i);
        if (($tokens[$op] ?? null) !== '(') {
            continue;
        }

        if (in_array($fn, $listen_fns, true)) {
            $a = $args($op);
            if (!$a) {
                continue;
            }
            $h = $hook_name($a[0]);
            if (preg_match('/^wp_ajax_nopriv_(.+)$/', $h, $m)) {
                $r['entries'][] = ['AJAX công khai', $m[1]];
            } elseif (preg_match('/^wp_ajax_(.+)$/', $h, $m)) {
                $r['entries'][] = ['AJAX', $m[1]];
            } elseif (preg_match('/^admin_post_nopriv_(.+)$/', $h, $m)) {
                $r['entries'][] = ['admin-post công khai', $m[1]];
            } elseif (preg_match('/^admin_post_(.+)$/', $h, $m)) {
                $r['entries'][] = ['admin-post', $m[1]];
            } else {
                $r['listens'][] = $h;
            }
        } elseif (in_array($fn, $fire_fns, true)) {
            $a = $args($op);
            if ($a) {
                $r['fires'][] = $hook_name($a[0]);
            }
        } elseif ($fn === 'add_shortcode') {
            $a = $args($op);
            if ($a) {
                $r['entries'][] = ['shortcode', '[' . trim($hook_name($a[0]), '⟨⟩') . ']'];
            }
        } elseif ($fn === 'register_rest_route') {
            $a = $args($op);
            if (count($a) >= 2) {
                $r['entries'][] = ['REST', trim($hook_name($a[0]), '⟨⟩') . trim($hook_name($a[1]), '⟨⟩')];
            }
        } elseif ($fn === 'register_post_type' || $fn === 'register_taxonomy') {
            $a = $args($op);
            if ($a) {
                $r['entries'][] = [$fn === 'register_post_type' ? 'CPT' : 'taxonomy', $hook_name($a[0])];
            }
        } elseif ($fn === 'wp_schedule_event' || $fn === 'wp_schedule_single_event') {
            $a = $args($op);
            $h = $fn === 'wp_schedule_event' ? ($a[2] ?? null) : ($a[1] ?? null);
            if ($h) {
                $r['entries'][] = ['cron', $hook_name($h)];
            }
        } elseif ($fn === 'add_rewrite_rule') {
            $a = $args($op);
            if ($a) {
                $r['entries'][] = ['rewrite', $hook_name($a[0])];
            }
        }
    }

    return $r;
}

/** Docblock nằm ngay trước khai báo (bỏ qua khoảng trắng và modifier). */
function ds_lookback_doc(array $tokens, int $i, callable $prev, bool $raw = false): string
{
    $j = $i - 1;
    while ($j >= 0) {
        $t = $tokens[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_FINAL, T_ABSTRACT, T_STATIC, T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
            $j--;
            continue;
        }
        if (defined('T_READONLY') && is_array($t) && $t[0] === T_READONLY) {
            $j--;
            continue;
        }
        if (is_array($t) && $t[0] === T_DOC_COMMENT) {
            return $raw ? $t[1] : ds_doc_summary($t[1]);
        }
        break;
    }
    return '';
}

// ─── Chia khu ────────────────────────────────────────────────────────────────

/** Số cấp thư mục của một đường dẫn theo luật tiền tố dài nhất (mặc định 1). */
function ds_depth(string $p, array $rules): int
{
    $best = '';
    $depth = 1;
    foreach ($rules as $prefix => $d) {
        if (strpos($p, $prefix) === 0 && strlen($prefix) > strlen($best)) {
            $best  = $prefix;
            $depth = (int) $d;
        }
    }
    return $depth;
}

/** Khu = đơn vị một trang code-map. File gốc custom-functions/ = 'bootstrap'. */
function ds_area(string $rel): string
{
    $p    = substr($rel, strlen('custom-functions/'));
    $dirs = array_slice(explode('/', $p), 0, -1);
    if (!$dirs) {
        return 'bootstrap';
    }
    $d = ds_depth($p, $GLOBALS['DS_CFG']['area_rules']);
    return count($dirs) >= $d ? implode('/', array_slice($dirs, 0, $d)) : implode('/', $dirs) . ' (file lẻ)';
}

function ds_area_slug(string $area): string
{
    $s = strtolower(str_replace([' (file lẻ)', '/'], ['-loose', '-'], $area));
    return preg_replace('/[^a-z0-9-]+/', '-', $s);
}

/** Tầng: đơn vị của bảng tổng quan trong architecture.md. */
function ds_layer(string $rel): string
{
    $p    = substr($rel, strlen('custom-functions/'));
    $dirs = array_slice(explode('/', $p), 0, -1);
    if (!$dirs) {
        return '(file gốc)';
    }
    $d = ds_depth($p, $GLOBALS['DS_CFG']['layer_rules']);
    return count($dirs) >= $d ? implode('/', array_slice($dirs, 0, $d)) . '/' : implode('/', $dirs) . '/ (file lẻ)';
}

/** Chuỗi hằng trong thân `function $fn()` của $src (tiện cho config 'routes'). */
function ds_function_strings(string $src, string $fn): array
{
    $out = [];
    if (preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n\}/s', $src, $m)) {
        foreach (token_get_all('<?php ' . $m[1]) as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $out[] = stripcslashes(substr($t[1], 1, -1));
            }
        }
    }
    return $out;
}

// ─── Dựng nội dung ───────────────────────────────────────────────────────────

$sources = ds_read_sources($INDEX);

// Hằng chuỗi toàn repo (`define('X', 'v')`, `const X = 'v'`) — để tên CPT/hook viết bằng hằng vẫn ra giá trị.
$GLOBALS['DS_CONSTS'] = [];
foreach ($sources as $src) {
    try {
        $tk = array_values(array_filter(token_get_all($src), static fn($t) => !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
    } catch (Throwable $e) {
        continue;
    }
    for ($i = 0, $c = count($tk); $i < $c - 5; $i++) {
        $is = static fn($t, $id, $v = null) => is_array($t) && $t[0] === $id && ($v === null || strtolower($t[1]) === $v);
        if ($is($tk[$i], T_STRING, 'define') && $tk[$i + 1] === '(' && $is($tk[$i + 2], T_CONSTANT_ENCAPSED_STRING)
            && $tk[$i + 3] === ',' && $is($tk[$i + 4], T_CONSTANT_ENCAPSED_STRING) && $tk[$i + 5] === ')') {
            $GLOBALS['DS_CONSTS'][substr($tk[$i + 2][1], 1, -1)] = stripcslashes(substr($tk[$i + 4][1], 1, -1));
        } elseif ($is($tk[$i], T_CONST) && $is($tk[$i + 1], T_STRING) && $tk[$i + 2] === '=' && $is($tk[$i + 3], T_CONSTANT_ENCAPSED_STRING) && $tk[$i + 4] === ';') {
            $GLOBALS['DS_CONSTS'][$tk[$i + 1][1]] = stripcslashes(substr($tk[$i + 3][1], 1, -1));
        }
    }
}

$parsed  = [];
foreach ($sources as $rel => $src) {
    $parsed[$rel] = ds_parse($src);
}

$areas = [];
foreach ($parsed as $rel => $info) {
    $areas[ds_area($rel)][$rel] = $info;
}
ksort($areas);

$round = static fn(int $x) => $x < 100 ? (string) $x : '~' . number_format((int) round($x / 100) * 100, 0, ',', '.');

/**
 * Mô tả các khu KHÔNG có file "chính" (tầng dùng chung, thư mục gom file lẻ).
 * Khu module/report lấy mô tả từ docblock đầu file chính của nó — viết ở code, không ở đây.
 */
$DS_AREA_DESC = (array) $GLOBALS['DS_CFG']['area_desc'];

/** Mô tả một khu: DS_AREA_DESC, else docblock của file "chính" (cùng tên thư mục / registration / bootstrap). */
$area_summary = static function (string $area, array $files) use ($DS_AREA_DESC): string {
    if (isset($DS_AREA_DESC[$area])) {
        return $DS_AREA_DESC[$area];
    }
    $base = basename(str_replace(' (file lẻ)', '', $area));
    $prefer = ["/$base.php", '/registration.php', '/bootstrap.php', "/$base-manager.php"];
    foreach ($prefer as $suffix) {
        foreach ($files as $rel => $info) {
            if (substr($rel, -strlen($suffix)) === $suffix && $info['summary'] !== '') {
                return $info['summary'];
            }
        }
    }
    foreach ($files as $info) {
        if ($info['summary'] !== '') {
            return $info['summary'];
        }
    }
    return '';
};

$generated = []; // path => content

$index_rows = [];
foreach ($areas as $area => $files) {
    $slug   = ds_area_slug($area);
    $lines  = 0;
    $fn_all = 0;
    $fn_doc = 0;
    $entries = [];
    $listens = [];
    $fires   = [];
    foreach ($files as $rel => $info) {
        $lines += $info['lines'];
        foreach ($info['functions'] as $f) {
            $fn_all++;
            $fn_doc += $f['summary'] !== '' ? 1 : 0;
        }
        foreach ($info['entries'] as $e) {
            $entries[$e[0] . "\0" . $e[1]][] = basename($rel);
        }
        foreach ($info['listens'] as $h) {
            $listens[$h] = ($listens[$h] ?? 0) + 1;
        }
        foreach ($info['fires'] as $h) {
            $fires[$h][] = basename($rel);
        }
    }
    $cov     = $fn_all ? (int) round($fn_doc * 100 / $fn_all) : 100;
    $summary = $area_summary($area, $files);

    $md  = DS_AUTO_NOTE . "\n\n";
    $md .= "# `$area`\n\n";
    if ($summary !== '') {
        $md .= "> $summary\n\n";
    }
    $md .= sprintf("%d file · %s dòng · %d hàm (%d%% có docblock)\n\n", count($files), $round($lines), $fn_all, $cov);
    $md .= "[← Mục lục bản đồ code](README.md)\n\n";

    if ($entries) {
        ksort($entries);
        $md .= "## Điểm vào\n\n| Loại | Tên | File |\n|---|---|---|\n";
        foreach ($entries as $k => $fs) {
            [$type, $name] = explode("\0", $k);
            $md .= sprintf("| %s | `%s` | %s |\n", $type, str_replace('|', '\|', $name), implode(', ', array_unique($fs)));
        }
        $md .= "\n";
    }
    if ($fires) {
        ksort($fires);
        $md .= "## Hook do khu này phát (điểm mở rộng)\n\nModule khác móc vào đây để thêm hành vi — đổi tên là gãy im lặng.\n\n| Hook | File |\n|---|---|\n";
        foreach ($fires as $h => $fs) {
            $md .= sprintf("| `%s` | %s |\n", str_replace('|', '\|', $h), implode(', ', array_unique($fs)));
        }
        $md .= "\n";
    }
    if ($listens) {
        ksort($listens);
        $md .= "## Hook khu này lắng nghe\n\n";
        $md .= implode(' · ', array_map(static fn($h, $c) => '`' . str_replace('|', '\|', $h) . '`' . ($c > 1 ? " ×$c" : ''), array_keys($listens), $listens)) . "\n\n";
    }

    $md .= "## File\n\n";
    foreach ($files as $rel => $info) {
        $md .= "### `" . substr($rel, strlen('custom-functions/')) . "`\n\n";
        if ($info['summary'] !== '') {
            $md .= $info['summary'] . "\n\n";
        }
        if ($info['classes']) {
            $md .= 'Class: ' . implode(', ', array_map(static fn($c) => '`' . $c['name'] . '`' . ($c['summary'] !== '' ? ' — ' . $c['summary'] : ''), $info['classes'])) . "\n\n";
        }
        if ($info['functions']) {
            $md .= "| Hàm | Mô tả |\n|---|---|\n";
            foreach ($info['functions'] as $f) {
                $sig = $f['name'] . '(' . str_replace('|', '\|', $f['params']) . ')';
                $md .= sprintf("| `%s` | %s |\n", $sig, $f['summary'] !== '' ? $f['summary'] : '—');
            }
            $md .= "\n";
        }
    }

    $generated["docs/code-map/$slug.md"] = $md;
    $index_rows[] = sprintf(
        "| [`%s`](%s.md) | %d | %s | %d | %d%% | %s |",
        $area,
        $slug,
        count($files),
        $round($lines),
        $fn_all,
        $cov,
        $summary !== '' ? ds_compact($summary, 120) : '—'
    );
}

$readme  = DS_AUTO_NOTE . "\n\n# Bản đồ code\n\n";
$readme .= "Mỗi khu một trang: điểm vào (AJAX, admin-post, REST, shortcode, CPT, cron), hook phát ra và lắng nghe, ";
$readme .= "danh sách hàm kèm dòng đầu docblock. Dùng để **tìm chỗ sửa** trước khi đọc code — không thay được việc đọc code.\n\n";
$readme .= "Cột *docblock* thấp = khu khó đọc với người mới và với AI: viết docblock cho hàm public là cách rẻ nhất để trang này có ích hơn.\n\n";
$readme .= "| Khu | File | Dòng | Hàm | Docblock | Mô tả |\n|---|---:|---:|---:|---:|---|\n";
$readme .= implode("\n", $index_rows) . "\n";
$generated['docs/code-map/README.md'] = $readme;

// ─── Khối AUTO trong architecture.md ─────────────────────────────────────────

$layers = [];
foreach ($parsed as $rel => $info) {
    $l = ds_layer($rel);
    $layers[$l]['files'] = ($layers[$l]['files'] ?? 0) + 1;
    $layers[$l]['lines'] = ($layers[$l]['lines'] ?? 0) + $info['lines'];
}
ksort($layers);
$auto['layers'] = "| Thư mục | File PHP | Dòng |\n|---|---:|---:|\n" . implode("\n", array_map(
    static fn($k, $v) => sprintf('| `%s` | %d | %s |', $k, $v['files'], $round($v['lines'])),
    array_keys($layers),
    $layers
));

$tree = [];
foreach ($areas as $area => $files) {
    $in_tree = false;
    foreach ((array) $GLOBALS['DS_CFG']['module_tree'] as $prefix) {
        $in_tree = $in_tree || strpos($area, $prefix) === 0;
    }
    if ($in_tree) {
        $tree[] = sprintf('- [`%s/`](code-map/%s.md) — %s', $area, ds_area_slug($area), ds_compact($area_summary($area, $files), 140) ?: '_(chưa có docblock đầu file)_');
    }
}
$auto['modules'] = implode("\n", $tree);

$arch_path = 'docs/architecture.md';
$arch      = is_file($arch_path) ? (string) file_get_contents($arch_path) : '';
if ($arch !== '') {
    $new_arch = $arch;
    foreach ($auto as $key => $body) {
        $new_arch = preg_replace_callback(
            '/(<!-- AUTO:' . $key . ' -->)(.*?)(<!-- \/AUTO:' . $key . ' -->)/s',
            static fn($m) => $m[1] . "\n" . $body . "\n" . $m[3],
            $new_arch
        );
    }
    $generated[$arch_path] = $new_arch;
}

// ─── AGENTS.md = CLAUDE.md ──────────────────────────────────────────────────

if (is_file('CLAUDE.md')) {
    $generated['AGENTS.md'] = "<!-- Bản chép của CLAUDE.md, sinh bởi tools/docs-sync.php — sửa CLAUDE.md, đừng sửa file này. -->\n\n"
        . (string) file_get_contents('CLAUDE.md');
}

// ─── Ghi / so ────────────────────────────────────────────────────────────────

$errors   = [];
$warnings = [];
$written  = [];

// File code-map cũ không còn khu tương ứng → xoá.
$stale_maps = array_diff(glob('docs/code-map/*.md') ?: [], array_keys($generated));

// --changed chỉ để nhắc: không ghi gì, không chặn.
$REPORT_ONLY = (bool) $changed;
if ($REPORT_ONLY) {
    $generated  = [];
    $stale_maps = [];
}

foreach ($generated as $path => $content) {
    $old = is_file($path) ? (string) file_get_contents($path) : null;
    if ($old === $content) {
        continue;
    }
    if ($CHECK) {
        $errors[] = "$path đã cũ so với code — chạy: php tools/docs-sync.php";
        continue;
    }
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, $content);
    $written[] = $path;
}
foreach ($stale_maps as $path) {
    if ($CHECK) {
        $errors[] = "$path không còn khu code tương ứng — chạy: php tools/docs-sync.php";
    } else {
        unlink($path);
        $written[] = "$path (xoá)";
    }
}

// ─── Audit ───────────────────────────────────────────────────────────────────

// 1. Route ↔ bảng route trong architecture.md
$routes = [];
if (is_callable($GLOBALS['DS_CFG']['routes'])) {
    foreach ((array) call_user_func($GLOBALS['DS_CFG']['routes'], $sources) as $seg) {
        $seg = trim((string) $seg, '/');
        if ($seg !== '') {
            $routes[$seg] = true;
        }
    }
}
foreach ($sources as $rel => $src) {
    // Hằng route của module: define('IVAR_X_ROUTE', 'slug') / define('IVAR_X_ROUTE_ALIASES', [...])
    if (preg_match_all("/define\(\s*'[A-Z0-9_]*ROUTE[A-Z0-9_]*'\s*,\s*('([a-z0-9-]+)'|\[[^\]]*\])/", $src, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $x) {
            if (($x[2] ?? '') !== '') {
                $routes[$x[2]] = true;
            } else {
                preg_match_all("/'([a-z0-9-]+)'/", $x[1], $ss);
                foreach ($ss[1] as $s) {
                    $routes[$s] = true;
                }
            }
        }
    }
}
$arch_now = $new_arch ?? $arch;
if (preg_match('/<!-- ROUTES -->(.*?)<!-- \/ROUTES -->/s', $arch_now, $rm)) {
    foreach (array_keys($routes) as $seg) {
        if (strpos($rm[1], "/$seg/") === false) {
            $errors[] = "route /$seg/ có trong code nhưng chưa có dòng trong bảng route (docs/architecture.md, giữa <!-- ROUTES -->)";
        }
    }
} else {
    $errors[] = 'docs/architecture.md thiếu bảng route bọc trong <!-- ROUTES --> … <!-- /ROUTES -->';
}

// 2. guide*.md phải mở được trên web
$php_all = implode("\n", $sources);
$it = $GLOBALS['DS_CFG']['guide_web_check']
    ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator('custom-functions', FilesystemIterator::SKIP_DOTS))
    : [];
foreach ($it as $f) {
    $rel = str_replace('\\', '/', $f->getPathname());
    if (ds_skipped($rel) || !preg_match('#/guide[^/]*\.md$#', $rel)) {
        continue;
    }
    $fm  = ds_front_matter((string) file_get_contents($rel));
    $dir = dirname($rel) . '/';
    // Được nhắc bằng đường dẫn đầy đủ ở bất kỳ đâu, hoặc bằng tên file trong PHP của chính module đó.
    $mentioned = strpos($php_all, $rel) !== false;
    foreach ($sources as $src_rel => $src) {
        if ($mentioned) {
            break;
        }
        $mentioned = strpos($src_rel, $dir) === 0 && strpos($src, basename($rel)) !== false;
    }
    if (!$mentioned && empty($fm['web_label'])) {
        $errors[] = "$rel không mở được trên web — thêm front-matter `web_label` (tự đăng ký vào /huong-dan-erp/) hoặc đăng ký trong erp_docs_list";
    }
}

// 3. Đường dẫn chỉ khác hoa/thường
exec('git ls-files', $all_files);
$all_files = array_merge($all_files, array_keys($generated));
$seen = [];
foreach (array_unique($all_files) as $p) {
    $k = mb_strtolower($p);
    if (isset($seen[$k]) && $seen[$k] !== $p) {
        $errors[] = "hai đường dẫn chỉ khác hoa/thường: {$seen[$k]} ↔ $p (đè nhau trên macOS/Windows/Google Drive)";
    }
    $seen[$k] = $p;
}

// 4–5. Tài liệu viết tay: applies_to / reviewed
$manual = [];
foreach (array_merge(glob('docs/*.md') ?: [], glob('docs/*/*.md') ?: []) as $doc) {
    if (strpos($doc, 'docs/code-map/') === 0) {
        continue;
    }
    $fm = ds_front_matter((string) file_get_contents($doc));
    if (!empty($fm['applies_to'])) {
        $manual[$doc] = $fm;
    }
}
foreach ((array) $GLOBALS['DS_CFG']['manual_globs'] as $g) foreach (glob($g) ?: [] as $doc) {
    $fm = ds_front_matter((string) file_get_contents($doc));
    if (!empty($fm['applies_to'])) {
        $manual[$doc] = $fm;
    }
}

$covered_domain = [];
$domain_files   = $GLOBALS['DS_CFG']['domain_glob'] !== '' ? (glob($GLOBALS['DS_CFG']['domain_glob']) ?: []) : [];
foreach ($manual as $doc => $fm) {
    $paths = (array) $fm['applies_to'];
    foreach ($paths as $p) {
        foreach ($domain_files ?: [] as $d) {
            if (ds_path_matches($d, $p)) {
                $covered_domain[$d] = true;
            }
        }
    }
    if (!$CHECK || empty($fm['reviewed'])) {
        continue;
    }
    $cmd = 'git log -1 --format=%cs -- ' . implode(' ', array_map('escapeshellarg', array_map(static fn($p) => rtrim($p, '*'), $paths)));
    $last = trim((string) shell_exec($cmd));
    if ($last !== '' && $last > (string) $fm['reviewed']) {
        $warnings[] = "$doc: code liên quan có commit $last, tài liệu reviewed {$fm['reviewed']} — đọc lại và cập nhật `reviewed`";
    }
}
foreach ($domain_files ?: [] as $d) {
    if (!isset($covered_domain[$d])) {
        $warnings[] = 'chưa có tài liệu domain cho ' . $d . ' (thêm vào applies_to của một file docs/domain/*.md)';
    }
}

// --changed: nhắc tài liệu viết tay liên quan tới các file vừa đổi
if ($changed) {
    $hits = [];
    foreach ($manual as $doc => $fm) {
        if (in_array($doc, $changed, true)) {
            continue; // tài liệu đã được sửa cùng lần này
        }
        foreach ((array) $fm['applies_to'] as $p) {
            foreach ($changed as $c) {
                if (ds_path_matches($c, $p)) {
                    $hits[$doc][] = $c;
                }
            }
        }
    }
    if ($hits) {
        echo "\n📝 Tài liệu viết tay liên quan tới code vừa đổi — kiểm xem còn đúng không:\n";
        foreach ($hits as $doc => $cs) {
            printf("   %s  ← %s\n", $doc, implode(', ', array_slice(array_unique($cs), 0, 4)));
        }
        echo "   (sửa xong thì cập nhật `reviewed:` trong front-matter)\n\n";
    }
}

// ─── Báo cáo ─────────────────────────────────────────────────────────────────

if ($REPORT_ONLY) {
    exit(0);
}
if (!$QUIET) {
    foreach ($written as $w) {
        echo "  ghi  $w\n";
    }
    if (!$written && !$CHECK && !$changed) {
        echo "  tài liệu sinh tự động đã khớp code\n";
    }
    foreach ($warnings as $w) {
        echo "  \033[33m⚠\033[0m $w\n";
    }
}
foreach ($errors as $e) {
    fwrite(STDERR, "  \033[31m✗\033[0m $e\n");
}
exit($errors ? 1 : 0);

// ─── Tiện ích ────────────────────────────────────────────────────────────────

/**
 * Front-matter YAML tối giản ở đầu file: `key: value` và `key: [a, b]`.
 * Đủ cho tài liệu của repo; không phải parser YAML.
 */
function ds_front_matter(string $md): array
{
    if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $md, $m)) {
        return [];
    }
    $out = [];
    foreach (preg_split('/\r?\n/', $m[1]) ?: [] as $line) {
        if (!preg_match('/^([a-z_]+):\s*(.*)$/', trim($line), $kv)) {
            continue;
        }
        $v = trim($kv[2]);
        if ($v !== '' && $v[0] === '[') {
            $v = array_values(array_filter(array_map(static fn($x) => trim($x, " \t'\""), explode(',', trim($v, '[]')))));
        } else {
            $v = trim($v, "'\"");
        }
        $out[$kv[1]] = $v;
    }
    return $out;
}

/** `$path` thuộc mẫu `$pattern`? Mẫu là file, thư mục (kết thúc `/`) hoặc glob. */
function ds_path_matches(string $path, string $pattern): bool
{
    if (substr($pattern, -1) === '/') {
        return strpos($path, $pattern) === 0;
    }
    return $path === $pattern || fnmatch($pattern, $path);
}
