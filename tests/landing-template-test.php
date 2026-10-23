<?php
/** Exercise the real file-based landing template, including HTML fragments. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__);
function get_post_field(...$args) { return 'uu-dai'; }
function get_queried_object_id() { return 1; }
function get_stylesheet_directory() { return dirname(__DIR__); }
function do_shortcode($content) { return str_replace('[hithean_offers]', '<section class="ho-page">Rendered offers</section>', $content); }
function language_attributes() {} function bloginfo(...$args) {} function wp_head() {}
function body_class(...$args) {} function wp_body_open() {} function wp_footer() {}
$count = 0;
function check_landing($condition, $message) {
    global $count; $count++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
ob_start();
require dirname(__DIR__) . '/templates/template-landing-page.php';
$rendered = ob_get_clean();
check_landing(str_contains($rendered, '<section class="ho-page">'), 'Actual uu-dai file executes its shortcode through the template');
check_landing(!str_contains($rendered, '[hithean_offers]'), 'Rendered landing never exposes the raw offers shortcode');
$path = tempnam(sys_get_temp_dir(), 'hithean-landing-');
try {
    file_put_contents($path, ' <div>[hithean_offers]</div><script>previewOnly()</script> ');
    $fragment = child_theme_landing_page_content($path);
    check_landing($fragment === '<div><section class="ho-page">Rendered offers</section></div>', 'HTML fragment strips preview scripts and renders shortcodes');
    file_put_contents($path, '<html><head><title>Preview</title></head><body class="demo"><p>[hithean_offers]</p><script>previewOnly()</script></body></html>');
    check_landing(child_theme_landing_page_content($path) === '<p><section class="ho-page">Rendered offers</section></p>', 'Complete HTML document still extracts only the body and renders shortcodes');
    file_put_contents($path, '<HTML><BODY>[hithean_offers]</BODY></HTML>');
    check_landing(child_theme_landing_page_content($path) === '<section class="ho-page">Rendered offers</section>', 'Uppercase body tags are handled');
    file_put_contents($path, ' <div>Plain fragment</div> ');
    check_landing(child_theme_landing_page_content($path) === '<div>Plain fragment</div>', 'Plain fragment content is preserved');
} finally { unlink($path); }
check_landing(child_theme_landing_page_content($path) === '', 'Missing landing source produces empty content');
echo "PASS: $count landing-template assertions\n";
