<section>
    <?php 
    if( 'yes' == get_option( 'roneous_shop_enable_pagination', 'no' ) ) { 
        get_template_part( 'templates/post/inc', 'pagination');
    }
    ?>
    <div class="container">
        <div class="row">
            <div class="col-sm-12 product-single">
                <?php
                if ( post_password_required() ) {
                    echo get_the_password_form();
                } else {
                    woocommerce_content();
                }
                ?>
            </div>
        </div>
    </div>
</section>