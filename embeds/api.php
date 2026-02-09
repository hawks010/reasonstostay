<?php
/**
 * RTS Syndication Engine — REST API (Embed Widget)
 *
 * GET /wp-json/rts/v1/embed/random?exclude=1,2,3
 *
 * Returns a single random published letter as lightweight JSON.
 * CORS and Caching headers are handled centrally in functions.php (RTS API Safeguards).
 *
 * @package ReasonsToStay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'rts_register_embed_routes' );
add_action( 'init', 'rts_serve_stable_widget_js' );

/**
 * Register public embed routes.
 */
function rts_register_embed_routes() {
    register_rest_route( 'rts/v1', '/embed/random', [
        'methods'             => [ 'GET', 'OPTIONS' ],
        'callback'            => 'rts_embed_random_letter',
        'permission_callback' => '__return_true', // Public endpoint
        'args'                => [
            'exclude' => [
                'description' => 'Comma-separated list of letter IDs to exclude.',
                'type'        => 'string',
                'required'    => false,
            ],
        ],
    ] );
}

/**
 * Parse a comma-separated exclude list into a sanitized integer array.
 */
function rts_embed_parse_exclude( $exclude_raw ) {
    if ( ! is_string( $exclude_raw ) || $exclude_raw === '' ) {
        return [];
    }

    $parts = preg_split( '/\s*,\s*/', $exclude_raw );
    $ids   = [];

    foreach ( (array) $parts as $p ) {
        $id = absint( $p );
        if ( $id > 0 ) {
            $ids[] = $id;
        }
    }

    return array_values( array_unique( $ids ) );
}

/**
 * Return a random published letter for the embed widget.
 */
function rts_embed_random_letter( WP_REST_Request $request ) {

    // Handle preflight (Standard 200 OK)
    if ( strtoupper( $request->get_method() ) === 'OPTIONS' ) {
        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    $exclude = rts_embed_parse_exclude( (string) $request->get_param( 'exclude' ) );

    // Cache key includes exclude list (so "read another" isn't stuck on one cached item).
    $transient_key = 'rts_embed_random_letter_' . md5( implode( ',', $exclude ) );
    $cached        = get_transient( $transient_key );

    if ( false !== $cached ) {
        $response = new WP_REST_Response( $cached, 200 );
        $response->header( 'X-RTS-Cache', 'HIT' );
        return $response;
    }

    $query = new WP_Query( [
        'post_type'      => 'letter',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'rand',
        'no_found_rows'  => true,
        'fields'         => 'ids',
        'post__not_in'   => $exclude,
    ] );

    if ( ! $query->have_posts() ) {
        return new WP_REST_Response(
            [ 'error' => 'No letters available.' ],
            404
        );
    }

    $post_id = (int) $query->posts[0];
    $post    = get_post( $post_id );

    $author_name = get_post_meta( $post_id, 'rts_author_name', true );
    $author_name = $author_name ? sanitize_text_field( $author_name ) : 'Anonymous';

    // Render content similarly to the site, but sanitize for safe embedding.
    $content_html = apply_filters( 'the_content', $post->post_content );
    $content_html = wp_kses_post( $content_html );

    // Use specific size for performance
    $logo_url = get_site_icon_url( 96 );
    if ( ! $logo_url ) {
        $logo_url = get_site_icon_url( 32 );
    }

    $data = [
        'id'           => $post_id,
        'title'        => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
        'content_html' => $content_html,
        'author'       => $author_name,
        'date'         => get_the_date( 'c', $post_id ),
        'link'         => get_permalink( $post_id ),
        'site_url'     => home_url( '/' ),
        'logo_url'     => $logo_url ? esc_url_raw( $logo_url ) : '',
    ];

    // Cache this specific letter query result for 5 minutes
    set_transient( $transient_key, $data, 5 * MINUTE_IN_SECONDS );

    $response = new WP_REST_Response( $data, 200 );
    $response->header( 'X-RTS-Cache', 'MISS' );
    return $response;
}

/**
 * Stable Asset Loader (Virtual URL)
 * * Intercepts requests to /rts-widget.js and serves the file from the current theme directory.
 * This hides the theme path/version from the public URL.
 */
function rts_serve_stable_widget_js() {
    // Check if the request URL ends with /rts-widget.js
    if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/rts-widget.js' ) !== false ) {
        
        $js_file = __DIR__ . '/assets/rts-widget.js';
        
        if ( file_exists( $js_file ) ) {
            // Serve as JS with CORS enabled so it works on external sites
            header( 'Content-Type: application/javascript; charset=utf-8' );
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Cache-Control: public, max-age=3600, stale-while-revalidate=86400' );
            
            readfile( $js_file );
            exit;
        }
    }
}