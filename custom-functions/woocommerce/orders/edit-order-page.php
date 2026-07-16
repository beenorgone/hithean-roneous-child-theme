<?php
if (!defined('ABSPATH')) {
    exit;
}


if (!function_exists("hithean_order_edit_current_post_id")) {
    function hithean_order_edit_current_post_id(): int
    {
        if (isset($_GET["post"])) {
            return absint(wp_unslash($_GET["post"]));
        }
        if (isset($_POST["post_ID"])) {
            return absint(wp_unslash($_POST["post_ID"]));
        }
        return 0;
    }
}

if (!function_exists("hithean_is_legacy_shop_order_edit_request")) {
    function hithean_is_legacy_shop_order_edit_request(): bool
    {
        if (!is_admin()) {
            return false;
        }

        $admin_page = isset($_SERVER["PHP_SELF"]) ? basename((string) wp_unslash($_SERVER["PHP_SELF"])) : "";
        if ($admin_page !== "post.php") {
            return false;
        }

        $post_id = hithean_order_edit_current_post_id();
        return $post_id > 0 && get_post_type($post_id) === "shop_order";
    }
}

if (!function_exists("hithean_keep_order_edit_redirect")) {
    function hithean_keep_order_edit_redirect(string $location, int $post_id): string
    {
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

        $args = [
            "post"   => $post_id,
            "action" => "edit",
        ];
        if (!empty($_POST["publish"]) || !empty($_POST["save"])) {
            $args["message"] = 1;
        }

        return add_query_arg($args, admin_url("post.php"));
    }
    add_filter("redirect_post_location", "hithean_keep_order_edit_redirect", 20, 2);
}

if (!function_exists("hithean_order_edit_form_action_guard")) {
    function hithean_order_edit_form_action_guard(): void
    {
        if (!hithean_is_legacy_shop_order_edit_request()) {
            return;
        }

        $post_id = hithean_order_edit_current_post_id();
        $action_url = add_query_arg([
            "post"   => $post_id,
            "action" => "edit",
        ], admin_url("post.php"));
        ?>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                var form = document.getElementById("post");
                if (form) {
                    form.setAttribute("action", <?php echo wp_json_encode($action_url); ?>);
                }
            });
        </script>
        <?php
    }
    add_action("admin_footer-post.php", "hithean_order_edit_form_action_guard", 20);
}

if (!function_exists('hithean_order_item_has_the_an_organics_brand')) {
    function hithean_order_item_has_the_an_organics_brand($product)
    {
        if (!$product instanceof WC_Product) {
            return false;
        }

        $product_ids = array_filter(array_unique([
            (int) $product->get_id(),
            (int) $product->get_parent_id(),
        ]));

        foreach ($product_ids as $product_id) {
            $terms = wp_get_post_terms($product_id, 'thuong-hieu');
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (
                    strtolower((string) $term->slug) === 'the-an-organics'
                    || strtolower((string) $term->name) === 'the an organics'
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}

add_action('woocommerce_before_order_itemmeta', 'hithean_display_order_item_seal_checkbox', 20, 3);
function hithean_display_order_item_seal_checkbox($item_id, $item, $product)
{
    if (!hithean_order_item_has_the_an_organics_brand($product)) {
        return;
    }

    echo '<label style="display:inline-flex;align-items:center;gap:4px;margin:4px 0 0;font-weight:700;"><input type="checkbox">TEM NIÊM PHONG</label>';
}

if (!function_exists('wr_order_item_edit_lock_user_is_restricted')) {
    function wr_order_item_edit_lock_user_is_restricted()
    {
        return is_admin() && current_user_can('manage_woocommerce');
    }
}

if (!function_exists('wr_order_item_edit_lock_render_ui')) {
    function wr_order_item_edit_lock_render_ui()
    {
        if (!wr_order_item_edit_lock_user_is_restricted()) {
            return;
        }
        ?>
        <style>
            #woocommerce-order-items #order_line_items .wc-order-edit-line-item,
            #woocommerce-order-items #order_line_items .wc-order-edit-product {
                display: none !important;
            }
        </style>
        <script>
            jQuery(function ($) {
                const lockControls = function () {
                    $('#woocommerce-order-items #order_line_items .wc-order-edit-line-item, #woocommerce-order-items #order_line_items .wc-order-edit-product').remove();
                    $('#woocommerce-order-items #order_line_items .quantity input, #woocommerce-order-items #order_line_items .line_cost input, #woocommerce-order-items #order_line_items .line_tax input').prop('readonly', true).prop('disabled', true);
                };

                lockControls();
                $(document.body).on('wc_order_items_reloaded updated', lockControls);
            });
        </script>
        <?php
    }
}
add_action('admin_print_footer_scripts', 'wr_order_item_edit_lock_render_ui');

// Nút "Chỉnh đơn" -> trang Tạo đơn (/tao-don/?order_id=)
if (!function_exists('hithean_render_order_creator_edit_button')) {
    function hithean_render_order_creator_edit_button($order_arg)
    {
        $order = $order_arg instanceof WC_Order ? $order_arg : wc_get_order($order_arg);
        if (!$order instanceof WC_Order) {
            return;
        }
        if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
            return;
        }

        $route = defined('ORDER_CREATOR_ROUTE') ? ORDER_CREATOR_ROUTE : 'tao-don';
        $url = home_url('/' . $route . '/?order_id=' . $order->get_id());
        ?>
        <p class="form-field form-field-wide" style="margin-top:10px;">
            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"
               class="button button-primary"
               style="background:#08e097;border-color:#007a52;color:#002b1c;">
                ✏️ Chỉnh đơn (trang Tạo đơn)
            </a>
        </p>
        <?php
    }
    add_action('woocommerce_admin_order_data_after_order_details', 'hithean_render_order_creator_edit_button', 70, 1);
}
