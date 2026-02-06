<?php
/**
 * Shortcode to embed a PDF file securely and responsively.
 * Usage: [hithean_pdf url="https://example.com/file.pdf" height="600px"]
 */
function display_pdf_shortcode( $atts ) {
    // Define default attributes and merge with user inputs
    $atts = shortcode_atts(
        array(
            'url'    => '',
            'height' => '800px', // Default height
        ),
        $atts,
        'pdf_view'
    );

    // 1. Security: Sanitize the URL to prevent XSS or invalid inputs
    $pdf_url = esc_url( $atts['url'] );

    if ( empty( $pdf_url ) ) {
        return '<p style="color:red;">Error: Please provide a valid PDF URL.</p>';
    }

    // 2. Performance: Use a simple iframe to leverage browser-native PDF rendering
    // This avoids loading heavy external JS libraries.
    ob_start();
    ?>
    <style>
        /* Responsive PDF container */
        .pdf-container {
            position: relative;
            overflow: hidden;
            max-width: 100%;
        }

        @media (max-width: 768px) {
            .pdf-container iframe {
                height: 500px !important; /* Slightly shorter height for mobile */
            }
        }
    </style>
    <div class="pdf-container" style="width: 100%; margin-bottom: 20px;">
        <iframe 
            src="<?php echo $pdf_url; ?>" 
            width="100%" 
            height="<?php echo esc_attr( $atts['height'] ); ?>" 
            style="border: none; display: block;"
            loading="lazy">
            <p>Your browser does not support embedding PDF files. 
            </p>
        </iframe>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'pdf_view', 'display_pdf_shortcode' );