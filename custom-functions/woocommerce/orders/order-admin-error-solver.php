<?php
if (!defined("ABSPATH")) exit;

if (!defined("HITHEAN_ADMIN_ERROR_SOLVER_LOG_ENABLED")) {
    define("HITHEAN_ADMIN_ERROR_SOLVER_LOG_ENABLED", false);
}

if (!defined("HITHEAN_ADMIN_ERROR_SOLVER_LOG_PREFIX")) {
    define("HITHEAN_ADMIN_ERROR_SOLVER_LOG_PREFIX", "hithean-admin-error-solver");
}

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

if (!function_exists("hithean_admin_error_solver_write_log")) {
    function hithean_admin_error_solver_write_log($name, $line)
    {
        if (!HITHEAN_ADMIN_ERROR_SOLVER_LOG_ENABLED) {
            return;
        }

        $name = sanitize_key((string) $name);
        if ($name === "") {
            return;
        }

        file_put_contents(
            WP_CONTENT_DIR . "/" . HITHEAN_ADMIN_ERROR_SOLVER_LOG_PREFIX . "-" . $name . ".log",
            (string) $line,
            FILE_APPEND | LOCK_EX
        );
    }
}

if (!function_exists("hithean_order_save_solver_mark")) {
    function hithean_order_save_solver_mark($event, $post_id = 0)
    {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            $post_id = hithean_order_admin_current_post_id();
        }
        if ($post_id <= 0 || get_post_type($post_id) !== "shop_order") {
            return;
        }

        $line = sprintf(
            "[%s] event=%s order_id=%d status=%d uri=%s\n",
            gmdate("c"),
            sanitize_key((string) $event),
            $post_id,
            function_exists("http_response_code") ? (int) http_response_code() : 0,
            isset($_SERVER["REQUEST_URI"]) ? (string) $_SERVER["REQUEST_URI"] : ""
        );
        hithean_admin_error_solver_write_log("progress", $line);
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

        hithean_order_save_solver_mark("redirect_post_location", $post_id);

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
            hithean_admin_error_solver_write_log(
                "trace",
                sprintf(
                    "[%s] begin post_id=%d type=%s uri=%s keys=%s\n",
                    gmdate("c"),
                    $trace_post_id,
                    (string) get_post_type($trace_post_id),
                    isset($_SERVER["REQUEST_URI"]) ? (string) $_SERVER["REQUEST_URI"] : "",
                    implode(",", array_slice(array_keys($_POST), 0, 40))
                )
            );
        }

        hithean_order_save_solver_mark("php_shutdown_registered", $trace_post_id);

        register_shutdown_function(function () {
            $post_id = hithean_order_admin_current_post_id();
            if ($post_id <= 0 || get_post_type($post_id) !== "shop_order") {
                return;
            }

            $error = error_get_last();
            $status = function_exists("http_response_code") ? (int) http_response_code() : 0;
            $error_type = is_array($error) ? (int) ($error["type"] ?? 0) : 0;
            $error_message = is_array($error) ? (string) ($error["message"] ?? "") : "";
            $error_file = is_array($error) ? (string) ($error["file"] ?? "") : "";
            $error_line = is_array($error) ? (string) ($error["line"] ?? "") : "";

            $diagnostic_line = sprintf(
                "[%s] end order_id=%d status=%d uri=%s error_type=%d message=%s file=%s line=%s\n",
                gmdate("c"),
                $post_id,
                $status,
                isset($_SERVER["REQUEST_URI"]) ? (string) $_SERVER["REQUEST_URI"] : "",
                $error_type,
                $error_message,
                $error_file,
                $error_line
            );
            hithean_admin_error_solver_write_log("diagnostic", $diagnostic_line);

            $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($error_type, $fatal_types, true)) {
                return;
            }

            hithean_admin_error_solver_write_log("fatal", $diagnostic_line);
        });
    }
    add_action("save_post_shop_order", function ($post_id) {
        hithean_order_save_solver_mark("save_post_shop_order", $post_id);
    }, 1);

    add_action("woocommerce_process_shop_order_meta", function ($post_id) {
        hithean_order_save_solver_mark("woocommerce_process_shop_order_meta", $post_id);
    }, 1);

    add_action("shutdown", function () {
        hithean_order_save_solver_mark("wp_shutdown", hithean_order_admin_current_post_id());
    }, PHP_INT_MAX);

    hithean_order_save_error_solver();
}
