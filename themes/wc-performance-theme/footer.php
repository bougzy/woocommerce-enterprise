    </div><!-- .site-container -->
</main><!-- .site-main -->

<footer class="site-footer" role="contentinfo">
    <div class="site-container">
        <?php if ( is_active_sidebar( 'footer-widgets' ) ) : ?>
        <div class="footer-widgets">
            <?php dynamic_sidebar( 'footer-widgets' ); ?>
        </div>
        <?php endif; ?>

        <div class="footer-bottom">
            <p>&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php esc_html_e( 'All rights reserved.', 'wc-performance' ); ?></p>
            <?php
            wp_nav_menu( [
                'theme_location' => 'footer',
                'container'      => false,
                'depth'          => 1,
                'fallback_cb'    => false,
            ] );
            ?>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
