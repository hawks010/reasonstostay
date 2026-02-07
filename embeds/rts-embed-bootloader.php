<?php
/**
 * RTS Syndication Engine — Bootloader
 *
 * Loads the embed API endpoint and enqueues the public widget script.
 * This file is required from functions.php and kept isolated so bugs
 * in the embed system never affect the core theme.
 *
 * @package ReasonsToStay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load the REST API endpoint for the embed widget.
require_once __DIR__ . '/api.php';

/**
 * Enqueue the embeddable widget script on the frontend.
 * Partners include this via <script src="…/rts-widget.js"> but we also
 * make it available through the WP enqueue system for local usage.
 */
add_action( 'wp_enqueue_scripts', 'rts_enqueue_embed_widget' );
function rts_enqueue_embed_widget() {
    wp_register_script(
        'rts-embed-widget',
        get_stylesheet_directory_uri() . '/embeds/assets/rts-widget.js',
        [],
        defined( 'RTS_THEME_VERSION' ) ? RTS_THEME_VERSION : '1.0',
        true
    );
}
