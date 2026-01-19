<?php
// SECURITY: Prevent direct file access
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Fetch Greenspark Widget via Secure AJAX
 */
function get_greenspark_banner() {
    // SECURITY: Define API key securely (DO NOT expose in JavaScript)
    $api_key = 'Ad7GRBUTBUiaZ1E9KMDl7SjxOdpx%2FanOd8yIavl3BrL7bTybzBCZDQtk7kL9D5Q6qan%2BdqVOvo9Y'; // Replace with actual API key

    // Greenspark API URL
    $api_url = 'https://api.getgreenspark.com/v2/widgets/full-width-banner?lng=en';

    // API request body
    $body = json_encode([
        //'options' => ['trees', 'monthsEarthPositive', 'plastic', 'plastic', 'carbon', 'kelp', 'straws', 'miles', 'footballPitches']
        'options' => ['trees', 'monthsEarthPositive', 'carbon', 'footballPitches']
    ]);

    // Send request to Greenspark API
    $response = wp_remote_post($api_url, [
        'method'    => 'POST',
        'headers'   => [
            'accept'        => 'application/json',
            'content-type'  => 'application/json',
            'x-api-key'     => $api_key
        ],
        'body'    => $body,
        'timeout' => 15,
    ]);

    // Handle errors
    if (is_wp_error($response)) {
        wp_die('Error connecting to Greenspark API');
    }

    // Retrieve API response body
    $data = wp_remote_retrieve_body($response);

    // SECURITY: Force correct content type
    header('Content-Type: text/html');
    
    // Return raw HTML (no JSON encoding needed)
    echo $data;
    exit;
}

// Register AJAX actions (supports logged-in & guest users)
add_action('wp_ajax_get_greenspark_banner', 'get_greenspark_banner');
add_action('wp_ajax_nopriv_get_greenspark_banner', 'get_greenspark_banner');

/**
 * Register Shortcode for Greenspark Banner
 */
function greenspark_banner_shortcode() {
    ob_start();
    ?>
    <div id="greenspark-banner"></div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                action: "get_greenspark_banner"
            })
        })
        .then(response => response.text())  // Expecting raw HTML
        .then(html => {
            console.log("Received Greenspark HTML:", html); // Debugging
            document.getElementById("greenspark-banner").innerHTML = html; // Insert HTML into div
        })
        .catch(error => console.error("Error loading Greenspark widget:", error));
    });
    </script>
    <?php
    return ob_get_clean();
}

// Register the shortcode
add_shortcode('greenspark_banner', 'greenspark_banner_shortcode');
?>

