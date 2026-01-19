<?php
// Dynamically display the <h1> title
if ( is_home() ) {
        // On the main blog page
        echo '<h1 class="blog-archive-title">BLOG</h1>';
} elseif ( is_category() ) {
        // On category archive pages
        echo '<h1 class="blog-archive-title">' . single_cat_title( '', false ) . '</h1>';
}

// Display the category menu on both blog and category archive pages
echo '<div class="blog-category-menu">';
// Manually add "Tất cả bài viết" as the first item
echo '<a href="' . get_permalink( get_option( 'page_for_posts' ) ) . '" style="padding: 0 10px;">Tất cả bài viết</a>';
wp_list_categories( array(
        'title_li' => '', // Remove the default "Categories" title
        'style'    => 'none', // Disable list styling
        'separator'=> '', // Use a separator between categories
) );
echo '</div>';
