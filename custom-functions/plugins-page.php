<?php

add_filter( 'manage_plugins_columns', 'add_plugin_slug_column' );
function add_plugin_slug_column( $columns ) {
    $columns['slug'] = 'Slug';
    return $columns;
}

add_action( 'manage_plugins_custom_column', 'show_plugin_slug_column', 10, 3 );
function show_plugin_slug_column( $column_name, $plugin_file, $plugin_data ) {
    if ( 'slug' === $column_name ) {
        echo dirname( $plugin_file );
    }
}

