<?php
/**
 * RTS Custom REST API – WAF-Safe Letter Endpoint
 *
 * Registers GET /rts/v1/letter/random so the frontend never hits
 * /wp-json/wp/v2/letter?orderby=rand, which Cloudflare / Wordfence
 * block as a potential DDoS vector (403 Forbidden).
 *
 * The randomisation happens server-side via get_posts(), keeping the
 * public URL pattern clean and WAF-friendly.
 *
 * @package ReasonsToStay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'rts_register_random_letter_route' );

function rts_register_random_letter_route() {
    register_rest_route( 'rts/v1', '/letter/random', [
        'methods'             => 'GET',
        'callback'            => 'rts_rest_random_letter',
        'permission_callback' => '__return_true',
        'args'                => [
            'exclude' => [
                'description'       => 'Comma-separated post IDs to exclude (already viewed).',
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ],
        ],
    ] );
}

/**
 * Return a single random published letter.
 *
 * Response shape matches the normalised object the JS already expects:
 *   { id, title, content, date, link }
 */
function rts_rest_random_letter( WP_REST_Request $request ) {
    $exclude_raw = $request->get_param( 'exclude' );
    $exclude_ids = [];

    if ( $exclude_raw ) {
        $exclude_ids = array_filter( array_map( 'absint', explode( ',', $exclude_raw ) ) );
    }

    $args = [
        'post_type'      => 'letter',
        'post_status'    => 'publish',
        'orderby'        => 'rand',
        'posts_per_page' => 1,
    ];

    if ( ! empty( $exclude_ids ) ) {
        // Cap the exclude list to prevent abuse via huge query strings.
        $args['post__not_in'] = array_slice( $exclude_ids, 0, 50 );
    }

    $posts = get_posts( $args );

    if ( empty( $posts ) ) {
        return new WP_REST_Response( [ 'error' => 'no_letters', 'message' => 'No letters available.' ], 404 );
    }

    $post = $posts[0];

    // Gather taxonomy terms for tone (used by onboarding preference matching).
    $tones = wp_get_post_terms( $post->ID, 'letter_tone', [ 'fields' => 'slugs' ] );
    if ( is_wp_error( $tones ) ) {
        $tones = [];
    }

    $feelings = wp_get_post_terms( $post->ID, 'letter_feeling', [ 'fields' => 'slugs' ] );
    if ( is_wp_error( $feelings ) ) {
        $feelings = [];
    }

    return new WP_REST_Response( [
        'id'       => $post->ID,
        'title'    => get_the_title( $post ),
        'content'  => apply_filters( 'the_content', $post->post_content ),
        'date'     => $post->post_date,
        'link'     => get_permalink( $post ),
        'tone'     => $tones,
        'feelings' => $feelings,
    ], 200 );
}
