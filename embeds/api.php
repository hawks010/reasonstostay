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
        'permission_callback' => 'rts_rest_public_read_permission',
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
 * Get cached list of published letter IDs for embeds.
 *
 * Caches the whole pool so exclude lists don't destroy cache hit rate.
 *
 * @return int[]
 */
function rts_embed_get_cached_letter_ids() {
    $key = 'rts_embed_letter_ids_v1';
    $ids = get_transient($key);
    if (is_array($ids) && !empty($ids)) {
        return array_values(array_unique(array_map('absint', $ids)));
    }

    $q_args = [
        'post_type'      => 'letter',
        'post_status'    => 'publish',
        'posts_per_page' => 2000,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ];

    // Primary workflow decision: stage=published when available.
    if (class_exists('RTS_Workflow')) {
        $q_args['meta_query'] = [
            [
                'key'   => RTS_Workflow::META_STAGE,
                'value' => RTS_Workflow::STAGE_PUBLISHED,
            ],
        ];
    }

    $ids = [];
    $paged = 1;
    do {
        $q_args['paged'] = $paged;
        $q = new WP_Query($q_args);
        if (!empty($q->posts)) {
            $ids = array_merge($ids, array_map('absint', $q->posts));
        }
        $paged++;
    } while (!empty($q->posts) && $paged <= 200);

    $ids = array_values(array_unique(array_filter($ids)));
    set_transient($key, $ids, 60 * MINUTE_IN_SECONDS);

    return $ids;
}

/**
 * Pick a random embed letter ID from cached pool.
 *
 * @param int[] $exclude
 * @return int|null
 */
function rts_embed_pick_random_id(array $exclude = []) {
    $pool = rts_embed_get_cached_letter_ids();
    if (empty($pool)) {
        return null;
    }
    if (!empty($exclude)) {
        $exclude = array_slice(array_values(array_unique(array_map('absint', $exclude))), 0, 200);
        $pool = array_values(array_diff($pool, $exclude));
    }
    if (empty($pool)) {
        return null;
    }
    $idx = array_rand($pool);
    return (int) $pool[$idx];
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

    $post_id = rts_embed_pick_random_id($exclude);
    if ( empty( $post_id ) ) {
        return new WP_REST_Response(
            [ 'error' => 'No letters available.' ],
            404
        );
    }

    // Cache the rendered payload per-letter for fast repeat delivery.
    $payload_key = 'rts_embed_letter_payload_' . (int) $post_id;
    $cached = get_transient($payload_key);
    if ( false !== $cached && is_array($cached) ) {
        $response = new WP_REST_Response( $cached, 200 );
        $response->header( 'X-RTS-Cache', 'HIT' );
        return $response;
    }

    $post = get_post( (int) $post_id );
    if ( ! $post ) {
        return new WP_REST_Response(
            [ 'error' => 'No letters available.' ],
            404
        );
    }

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

    set_transient( $payload_key, $data, 10 * MINUTE_IN_SECONDS );

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
