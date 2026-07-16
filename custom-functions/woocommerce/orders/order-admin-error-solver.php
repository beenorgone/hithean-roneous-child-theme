<?php
if (!defined("ABSPATH")) exit;

/**
 * Error solver for legacy WooCommerce order admin saves.
 *
 * Kept separate from the order UI/metabox modules so it can run on the POST
 * request that saves an order, where screen-specific admin files are not loaded.
 */

if (!function_exists("hithean_order_admin_current_post_id")) {
    function hithean_order_admin_current_post_id()
    {
        if (isset($_POST["post_ID"])) {
            return absint(wp_unslash($_POST["post_ID"]));
        }
        if (isset($_GET["post"])) {
            return absint(wp_unslash($_GET["post"]));
        }
        return 0;
    }
}

if (!function_exists("hithean_keep_order_edit_redirect")) {
    function hithean_keep_order_edit_redirect($location, $post_id = 0)
    {
        $location = is_scalar($location) ? (string) $location : admin_url("post.php");
        $post_id = is_object($post_id) && isset($post_id->ID) ? $post_id->ID : $post_id;
        $post_id = absint($post_id);

        if ($post_id <= 0 || get_post_type($post_id) !== "shop_order") {
            return $location;
        }

        $path = (string) wp_parse_url($location, PHP_URL_PATH);
        if (basename($path) !== "post.php") {
            return $location;
        }

        $query = [];
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
        if (!empty($query["post"]) || !empty($query["action"])) {
            return $location;
        }

        return add_query_arg([
            "post" => $post_id,
            "action" => "edit",
            "message" => 1,
        ], admin_url("post.php"));
    }
    add_filter("redirect_post_location", "hithean_keep_order_edit_redirect", 20, 2);
}

if (!function_exists("hithean_order_save_error_solver")) {
    function hithean_order_save_error_solver()
    {
        if (!is_admin()) {
            return;
        }

        $admin_page = isset($_SERVER["PHP_SELF"]) ? basename((string) wp_unslash($_SERVER["PHP_SELF"])) : "";
        $method = isset($_SERVER["REQUEST_METHOD"]) ? strtoupper((string) $_SERVER["REQUEST_METHOD"]) : "";
        if ($admin_page !== "post.php" || $method !== "POST") {
            return;
        }

        $trace_post_id = hithean_order_admin_current_post_id();
        if ($trace_post_id > 0) {
            file_put_contents(
                WP_CONTENT_DIR . "/hithean-order-save-trace.log",
                sprintf(
                    "[%s] begin post_id=%d type=%s uri=%s keys=%s\n",
                    gmdate("c"),
                    $trace_post_id,
                    (string) get_post_type($trace_post_id),
                    isset($_SERVER["REQUEST_URI"]) ? (string) $_SERVER["REQUEST_URI"] : "",
                    implode(",", array_slice(array_keys($_POST), 0, 40))
                ),
                FILE_APPEND | LOCK_EX
            );
        }

        register_shutdown_function(function () {
            $error = error_get_last();
            if (!is_array($error)) {
                return;
            }

            $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array((int) ($error["type"] ?? 0), $fatal_types, true)) {
                return;
            }

            $post_id = hithean_order_admin_current_post_id();
            if ($post_id <= 0 || get_post_type($post_id) !== "shop_order") {
                return;
            }

            $line = sprintf(
                "[%s] order_id=%d uri=%s message=%s file=%s line=%s\n",
                gmdate("c"),
                $post_id,
                isset($_SERVER["REQUEST_URI"]) ? (string) $_SERVER["REQUEST_URI"] : "",
                isset($error["message"]) ? (string) $error["message"] : "",
                isset($error["file"]) ? (string) $error["file"] : "",
                isset($error["line"]) ? (string) $error["line"] : ""
            );

            file_put_contents(WP_CONTENT_DIR . "/hithean-order-save-fatal.log", $line, FILE_APPEND | LOCK_EX);
        });
    }
    hithean_order_save_error_solver();
}
