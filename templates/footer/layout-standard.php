<footer class="footer-widget <?php echo ( !is_active_sidebar('footer1') && !is_active_sidebar('footer2') && !is_active_sidebar('footer3') && !is_active_sidebar('footer4') ) ? 'p0' : '' ?> ">
    <div class="large-container">
        <div class="row-1">
        	<?php
   			dynamic_sidebar('footer1');
		?>
	</div>
        <div class="row-2">
		<?php
       			dynamic_sidebar('footer2');
   			dynamic_sidebar('footer3');
       			dynamic_sidebar('footer4');
        	?>
        </div>
    </div>
    <?php if ( 'yes' == get_option( 'roneous_enable_copyright', 'yes' ) ) : ?>
    <div class="large-container sub-footer">
        <div class="row">
            <div class="col-sm-6">
                <span class="sub">
                    <?php echo wp_kses(get_option( 'roneous_footer_copyright', esc_html__( 'Modify this text in: Appearance > Customize > Footer', 'roneous' ) ), roneous_allowed_tags()); ?>
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</footer>
