<?php
/**
 * RTS Syndication Engine — REST API (Embed Widget)
 *
 * GET /wp-json/rts/v1/embed/random?exclude=1,2,3
 *
 * Returns a single random published letter as lightweight JSON:
 *   { id, title, content_html, author, date, link, site_url, logo_url }
 *
 * CORS is explicitly enabled for this endpoint so partner sites can fetch it.
 *
 * @package ReasonsToStay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'rts_register_embed_routes' );
add_filter( 'rest_pre_serve_request', 'rts_embed_cors_headers', 10, 4 );

/**
 * Register public embed routes.
 */
function rts_register_embed_routes() {

    // Data route.
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
 * Add explicit CORS headers for the embed endpoint.
 *
 * @param bool             $served  Whether the request has already been served.
 * @param WP_HTTP_Response $result  Result to send to the client.
 * @param WP_REST_Request  $request Request object.
 * @param WP_REST_Server   $server  Server instance.
 * @return bool
 */
function rts_embed_cors_headers( $served, $result, $request, $server ) {
    $route = $request->get_route();

    // Only affect our public embed endpoint.
    if ( 0 !== strpos( $route, '/rts/v1/embed/random' ) ) {
        return $served;
    }

    // Allow any origin to read this public endpoint.
    // If you want to restrict, swap "*" for a whitelist.
    header( 'Access-Control-Allow-Origin: *' );
    header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Content-Type, X-WP-Nonce' );
    header( 'Access-Control-Max-Age: 600' );

    return $served;
}

/**
 * Parse a comma-separated exclude list into a sanitized integer array.
 *
 * @param string $exclude_raw
 * @return int[]
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
 *
 * @param  WP_REST_Request $request
 * @return WP_REST_Response
 */
function rts_embed_random_letter( WP_REST_Request $request ) {

    // Handle preflight.
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

    // Cache for 15 minutes to protect the DB from embed traffic spikes.
    set_transient( $transient_key, $data, 15 * MINUTE_IN_SECONDS );

    $response = new WP_REST_Response( $data, 200 );
    $response->header( 'X-RTS-Cache', 'MISS' );
    return $response;
}
