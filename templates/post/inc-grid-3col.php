<?php 
$sticky = is_sticky() ? '<span class="featured-stick">'.esc_html__( 'Featured', 'roneous' ).'</span>' : '';
$format = get_post_format();
?>
<div class="col-sm-4 col-xs-12 mb32 mb-xs-24">
    <div class="clearfix m0-xs border-line-bottom light-inner">
        <?php if ( has_post_thumbnail() || get_post_meta( $post->ID, '_tlg_title_bg_img', true ) ) : ?>
            <div class="mb-xs-24 post-thumb">
                <a href="<?php the_permalink(); ?>">
                    <div class="bg-overlay mb24">
                        <?php if ( has_post_thumbnail() ) : ?>
                            <?php the_post_thumbnail( 'roneous_grid', array() ); ?>
                        <?php elseif (get_post_meta( $post->ID, '_tlg_title_bg_img', true )) : ?>
                            <img class="background-image" alt="<?php esc_html_e( 'post-image', 'roneous' ); ?>" src="<?php echo esc_url(get_post_meta( $post->ID, '_tlg_title_bg_img', true )) ?>" />
                        <?php endif; ?>
                        <div class="bg-mask"><i class="ti-plus"></i></div>
                    </div>
                </a>
            </div>
        <?php endif; ?>
        <a href="<?php the_permalink(); ?>">
            <?php the_title('<h5 class="blog-title mb0">'.$sticky, '</h5>'); ?>
        </a>
        <div class="entry-meta mt8 mb16 p0">
            <span class="inline-block"><?php echo get_the_time(get_option('date_format')) ?></span>
            <!--span class="inline-block"><span><?php esc_html_e( 'by', 'roneous' ); ?></span><?php the_author_posts_link() ?></span-->
        </div>
        <?php if( 'quote' != $format && 'link' != $format ) the_excerpt(); ?>
        <div class="pull-left mt8">
            <?php 
            if (function_exists('tlg_framework_setup')) {
                echo tlg_framework_like_display(); 
            }
            ?>
            <?php if ( 'yes' == get_option( 'roneous_blog_comment', 'yes' ) && !post_password_required() && ( comments_open() || get_comments_number() ) ) : ?>
                <span class="middot-divider"></span>
                <span class="comments-link"><?php comments_popup_link( '<i class="ti-comment"></i><span>0</span>', '<i class="ti-comment"></i><span>1</span>', '<i class="ti-comment"></i><span>%</span>' ); ?></span>
            <?php endif; ?>
        </div>
        <?php if ( has_category() ) : ?>
            <div class="pull-right mt8 category-more">
            <?php 
            $category = get_the_category();
            if ( $category ) echo '<a href="' . get_category_link( $category[0]->term_id ) . '">' . $category[0]->name.'</a>';
            ?>
            </div>
        <?php endif; ?>
    </div>
</div>
