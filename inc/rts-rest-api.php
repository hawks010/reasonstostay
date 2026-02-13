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

/*
 * AJAX fallback: admin-ajax.php URLs are never blocked by Cloudflare / Wordfence,
 * so the frontend can use this when the REST endpoint returns 403.
 */
add_action( 'wp_ajax_rts_random_letter',        'rts_ajax_random_letter' );
add_action( 'wp_ajax_nopriv_rts_random_letter',  'rts_ajax_random_letter' );


/**
 * Get cached list of published letter IDs (stage=published when available).
 *
 * Avoids ORDER BY RAND() which does not scale.
 *
 * @return int[]
 */
function rts_get_cached_published_letter_ids() {
    $key = 'rts_published_letter_ids_v1';
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

    // Primary workflow decision: stage=published. Post status is used only as a visibility constraint.
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
 * Pick a random letter ID from cached pool, excluding provided IDs.
 *
 * @param int[] $exclude_ids
 * @return int|null
 */
function rts_pick_random_letter_id(array $exclude_ids = []) {
    $pool = rts_get_cached_published_letter_ids();
    if (empty($pool)) {
        return null;
    }

    if (!empty($exclude_ids)) {
        $exclude_ids = array_slice(array_values(array_unique(array_map('absint', $exclude_ids))), 0, 200);
        $pool = array_values(array_diff($pool, $exclude_ids));
    }

    if (empty($pool)) {
        return null;
    }

    $idx = array_rand($pool);
    return (int) $pool[$idx];
}


function rts_ajax_random_letter() {
    $exclude_raw = isset( $_GET['exclude'] ) ? sanitize_text_field( wp_unslash( $_GET['exclude'] ) ) : '';
    $exclude_ids = [];

    if ( $exclude_raw ) {
        $exclude_ids = array_filter( array_map( 'absint', explode( ',', $exclude_raw ) ) );
    }

    $post_id = rts_pick_random_letter_id($exclude_ids);

    if ( empty( $post_id ) ) {
        wp_send_json( [ 'error' => 'no_letters', 'message' => 'No letters available.' ], 404 );
    }

    $post = get_post( (int) $post_id );

    if ( ! $post ) {
        wp_send_json( [ 'error' => 'no_letters', 'message' => 'No letters available.' ], 404 );
    }

    $tones = wp_get_post_terms( $post->ID, 'letter_tone', [ 'fields' => 'slugs' ] );
    if ( is_wp_error( $tones ) ) $tones = [];

    $feelings = wp_get_post_terms( $post->ID, 'letter_feeling', [ 'fields' => 'slugs' ] );
    if ( is_wp_error( $feelings ) ) $feelings = [];

    wp_send_json( [
        'id'       => $post->ID,
        'title'    => '',
        'content'  => apply_filters( 'the_content', $post->post_content ),
        'date'     => $post->post_date,
        'link'     => get_permalink( $post ),
        'tone'     => $tones,
        'feelings' => $feelings,
    ], 200 );
}

function rts_register_random_letter_route() {
    register_rest_route( 'rts/v1', '/letter/random', [
        'methods'             => 'GET',
        'callback'            => 'rts_rest_random_letter',
        'permission_callback' => 'rts_rest_random_letter_permission',
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

function rts_rest_random_letter_permission( WP_REST_Request $request ) {
    return true;
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

    $post_id = rts_pick_random_letter_id($exclude_ids);

    if ( empty( $post_id ) ) {
        return new WP_REST_Response( [ 'error' => 'no_letters', 'message' => 'No letters available.' ], 404 );
    }

    $post = get_post( (int) $post_id );

    if ( ! $post ) {
        return new WP_REST_Response( [ 'error' => 'no_letters', 'message' => 'No letters available.' ], 404 );
    }

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
        'title'    => '',
        'content'  => apply_filters( 'the_content', $post->post_content ),
        'date'     => $post->post_date,
        'link'     => get_permalink( $post ),
        'tone'     => $tones,
        'feelings' => $feelings,
    ], 200 );
}

