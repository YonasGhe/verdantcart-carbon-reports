<?php
defined('ABSPATH') || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>

<body <?php body_class('vcarb-app-template'); ?>>
    <?php
    if (function_exists('wp_body_open')) {
        wp_body_open();
    }
    ?>

    <a class="screen-reader-text skip-link" href="#main">
        <?php esc_html_e('Skip to content', 'verdantcart-ai-reports'); ?>
    </a>

    <main id="main" class="vcarb-app-template__main">
        <div class="vcarb-app-template__inner">
            <?php
            while (have_posts()) :
                the_post();
                the_content();
            endwhile;
            ?>
        </div>
    </main>

    <?php wp_footer(); ?>
</body>

</html>