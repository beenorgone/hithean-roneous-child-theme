<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wr_shortcode_pdf_embed')) {
    function wr_shortcode_pdf_embed($atts = [])
    {
        $atts = shortcode_atts([
            'wrapper_styles' => 'position: relative; overflow: hidden; max-width: 650px; margin: 0 auto;',
            'frame_styles'   => 'border: none; width: 100%; height: 800px; display: block;',
            'file_url'       => '',
        ], $atts, 'pdf_embed');

        $file_url = trim((string) $atts['file_url']);
        if ($file_url === '') {
            return '';
        }

        $wrapper_styles = trim((string) $atts['wrapper_styles']);
        $frame_styles = trim((string) $atts['frame_styles']);

        return sprintf(
            '<div style="%1$s"><iframe style="%2$s" src="%3$s" loading="lazy" allowfullscreen></iframe></div>',
            esc_attr($wrapper_styles),
            esc_attr($frame_styles),
            esc_url($file_url)
        );
    }
}

add_shortcode('pdf_embed', 'wr_shortcode_pdf_embed');
