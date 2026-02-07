<?php
/**
 * RTS Syndication Engine — REST API
 *
 * GET /wp-json/rts/v1/embed/random
 *
 * Returns a single random published letter as lightweight JSON:
 *   { content, author, link }
 *
 * The query result is cached with a 15-minute transient to protect the
 * database from traffic spikes when many external sites embed the widget.
 *
 * @package ReasonsToStay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'rts_register_embed_routes' );

function rts_register_embed_routes() {
    register_rest_route( 'rts/v1', '/embed/random', [
        'methods'             => 'GET',
        'callback'            => 'rts_embed_random_letter',
        'permission_callback' => '__return_true', // Public endpoint
    ] );
}

/**
 * Return a random published letter for the embed widget.
 *
 * @param  WP_REST_Request $request
 * @return WP_REST_Response
 */
function rts_embed_random_letter( WP_REST_Request $request ) {
    $transient_key = 'rts_embed_random_letter';
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
    ] );

    if ( ! $query->have_posts() ) {
        return new WP_REST_Response(
            [ 'error' => 'No letters available.' ],
            404
        );
    }

    $post_id = $query->posts[0];
    $post    = get_post( $post_id );

    $author_name = get_post_meta( $post_id, 'rts_author_name', true );

    $data = [
        'content' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 80, '&hellip;' ),
        'author'  => $author_name ? sanitize_text_field( $author_name ) : 'Anonymous',
        'link'    => get_permalink( $post_id ),
    ];

    // Cache for 15 minutes to protect the DB from embed traffic spikes.
    set_transient( $transient_key, $data, 15 * MINUTE_IN_SECONDS );

    $response = new WP_REST_Response( $data, 200 );
    $response->header( 'X-RTS-Cache', 'MISS' );
    return $response;
}
