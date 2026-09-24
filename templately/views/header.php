<?php
	defined('ABSPATH') or die( 'Access denied!' ); // Avoid direct file request
?>

<div class="templately-header">
    <div class="templately-header-left">
        <h1><?php echo esc_html__( 'Templately', 'templately' ); ?></h1>
    </div>
    <div class="templately-header-right">
        <?php echo esc_html( sprintf( '%s: %s', __( 'Version', 'templately' ), TEMPLATELY_VERSION ) ); ?>
    </div>
</div>