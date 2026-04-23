<?php
/**
 * REST API — Filter Endpoint
 *
 * @package Stolpersteine
 */

add_action( 'rest_api_init', 'stolpersteine_expose_acf_in_rest' );

function stolpersteine_expose_acf_in_rest() {

    $post_types = array( 'stolpersteine', 'ststeiermark' );

    foreach ( $post_types as $post_type ) {

        register_rest_field( $post_type, 'acf_koordinaten', array(
            'get_callback' => 'stolpersteine_get_acf_koordinaten',
            'schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'lat'  => array( 'type' => 'number' ),
                    'lng'  => array( 'type' => 'number' ),
                    'zoom' => array( 'type' => 'integer' ),
                ),
            ),
        ) );

        register_rest_field( $post_type, 'acf_adresse', array(
            'get_callback' => 'stolpersteine_get_acf_adresse',
            'schema'       => array( 'type' => 'string' ),
        ) );

        register_rest_field( $post_type, 'acf_verlegejahr', array(
            'get_callback' => 'stolpersteine_get_acf_verlegejahr',
            'schema'       => array( 'type' => 'string' ),
        ) );
    }
}

function stolpersteine_get_acf_koordinaten( $post ) {
    $coords = get_post_meta( $post['id'], 'koordinaten', true );
    if ( ! is_array( $coords ) || empty( $coords['lat'] ) || empty( $coords['lng'] ) ) {
        return null;
    }
    return array(
        'lat'  => (float) $coords['lat'],
        'lng'  => (float) $coords['lng'],
        'zoom' => (int) ( isset( $coords['zoom'] ) ? $coords['zoom'] : 15 ),
    );
}

function stolpersteine_get_acf_adresse( $post ) {
    $value = get_post_meta( $post['id'], 'stolpersteine_textmedium', true );
    return sanitize_text_field( $value ? $value : '' );
}

function stolpersteine_get_acf_verlegejahr( $post ) {
    $value = get_field( 'verlegejahr', $post['id'] );
    return sanitize_text_field( $value ? $value : '' );
}

add_action( 'rest_api_init', 'stolpersteine_register_filter_endpoint' );

function stolpersteine_register_filter_endpoint() {
    register_rest_route( 'stolpersteine/v1', '/filter', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'stolpersteine_filter_callback',
        'permission_callback' => '__return_true',
        'args'                => stolpersteine_filter_args(),
    ) );
}

function stolpersteine_filter_args() {
    return array(
        'post_type' => array(
            'type'              => 'string',
            'default'           => 'both',
            'enum'              => array( 'stolpersteine', 'ststeiermark', 'both' ),
            'sanitize_callback' => 'sanitize_key',
        ),
        'opfergruppen' => array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ),
        'bezirk' => array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ),
        'jahr' => array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ),
        'search' => array(
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ),
        'page' => array(
            'type'              => 'integer',
            'default'           => 1,
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
        ),
        'per_page' => array(
            'type'              => 'integer',
            'default'           => 24,
            'minimum'           => 1,
            'maximum'           => 500,
            'sanitize_callback' => 'absint',
        ),
        'map_only' => array(
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ),
        // filter_only: schlanke Antwort für den Filter-Table (kein thumbnail, excerpt, koordinaten, zuordnung)
        'filter_only' => array(
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ),
    );
}

function stolpersteine_filter_callback( $request ) {

    $post_type_param = $request->get_param( 'post_type' );

    if ( 'stolpersteine' === $post_type_param ) {
        $post_types = array( 'stolpersteine' );
    } elseif ( 'ststeiermark' === $post_type_param ) {
        $post_types = array( 'ststeiermark' );
    } else {
        $post_types = array( 'stolpersteine', 'ststeiermark' );
    }

    $query_args = array(
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => $request->get_param( 'per_page' ),
        'paged'          => $request->get_param( 'page' ),
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => false,
    );

    $tax_query = stolpersteine_build_tax_query( $request );
    if ( ! empty( $tax_query ) ) {
        $query_args['tax_query'] = $tax_query;
    }

    $search = $request->get_param( 'search' );
    if ( '' !== $search ) {
        $query_args['s'] = $search;
    }

    if ( '' !== $search ) {
        // Relevance sort: title matches first, body-text matches second.
        // Uses a CASE expression in ORDER BY so pagination stays correct across all pages.
        // The closure is removed immediately after the query to prevent filter leak.
        global $wpdb;
        $like           = '%' . $wpdb->esc_like( $search ) . '%';
        $like_safe      = esc_sql( $like );
        $posts_table    = $wpdb->posts;
        $orderby_filter = function () use ( $like_safe, $posts_table ) {
            return "CASE WHEN {$posts_table}.post_title LIKE '{$like_safe}' THEN 0 ELSE 1 END ASC,
                    {$posts_table}.post_title ASC";
        };
        add_filter( 'posts_orderby', $orderby_filter );
        $query = new WP_Query( $query_args );
        remove_filter( 'posts_orderby', $orderby_filter );
    } else {
        $query = new WP_Query( $query_args );
    }
    $map_only    = $request->get_param( 'map_only' );
    $filter_only = $request->get_param( 'filter_only' );
    $results     = array();

    foreach ( $query->posts as $post ) {
        $results[] = stolpersteine_format_post( $post, $map_only, $filter_only );
    }

    return new WP_REST_Response( array(
        'results'     => $results,
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
        'page'        => (int) $request->get_param( 'page' ),
        'post_types'  => $post_types,
    ), 200 );
}

function stolpersteine_build_tax_query( $request ) {

    $tax_query    = array();
    $opfergruppen = $request->get_param( 'opfergruppen' );
    $jahr         = $request->get_param( 'jahr' );
    $bezirk       = $request->get_param( 'bezirk' );

    if ( '' !== $opfergruppen ) {
        $tax_query[] = array(
            'taxonomy' => 'opfergruppen',
            'field'    => 'slug',
            'terms'    => $opfergruppen,
        );
    }

    if ( '' !== $jahr ) {
        $tax_query[] = array(
            'taxonomy' => 'jahr',
            'field'    => 'slug',
            'terms'    => $jahr,
        );
    }

    if ( '' !== $bezirk ) {
        $tax_query[] = array(
            'relation' => 'OR',
            array(
                'taxonomy' => 'bezirk',
                'field'    => 'slug',
                'terms'    => $bezirk,
            ),
            array(
                'taxonomy' => 'bezirksteiermark',
                'field'    => 'slug',
                'terms'    => $bezirk,
            ),
        );
    }

    if ( count( $tax_query ) > 1 ) {
        $tax_query['relation'] = 'AND';
    }

    return $tax_query;
}

function stolpersteine_format_post( $post, $map_only = false, $filter_only = false ) {

    if ( $map_only ) {
        $coords  = get_post_meta( $post->ID, 'koordinaten', true );
        $adresse = get_post_meta( $post->ID, 'stolpersteine_textmedium', true );
        return array(
            'id'      => $post->ID,
            'title'   => esc_html( $post->post_title ),
            'url'     => get_permalink( $post->ID ),
            'adresse' => esc_html( $adresse ? $adresse : '' ),
            'lat'     => is_array( $coords ) && isset( $coords['lat'] ) ? (float) $coords['lat'] : null,
            'lng'     => is_array( $coords ) && isset( $coords['lng'] ) ? (float) $coords['lng'] : null,
        );
    }

    $adresse = get_post_meta( $post->ID, 'stolpersteine_textmedium', true );

    $opfergruppen_terms = get_the_terms( $post->ID, 'opfergruppen' );
    $jahr_terms         = get_the_terms( $post->ID, 'jahr' );
    $bezirk_terms       = get_the_terms( $post->ID, 'bezirk' );
    $bezirkst_terms     = get_the_terms( $post->ID, 'bezirksteiermark' );

    // Schlanke Antwort für den Filter-Table:
    // Kein thumbnail, excerpt, koordinaten oder zuordnung — das spart
    // bei 308 Einträgen ~5 DB-Queries pro Post, also ~1.500 Queries gesamt.
    // Slugs werden mitgeliefert damit das JS keinen eigenen slugify() braucht.
    if ( $filter_only ) {
        return array(
            'id'        => $post->ID,
            'post_type' => $post->post_type,
            'title'     => esc_html( $post->post_title ),
            'url'       => get_permalink( $post->ID ),
            'adresse'   => esc_html( $adresse ? $adresse : '' ),
            'opfergruppen' => is_array( $opfergruppen_terms )
                ? array_map( function( $t ) {
                    return array( 'name' => $t->name, 'slug' => $t->slug );
                  }, $opfergruppen_terms )
                : array(),
            'jahr'      => is_array( $jahr_terms )
                ? array_map( function( $t ) {
                    return array( 'name' => $t->name, 'slug' => $t->slug );
                  }, $jahr_terms )
                : array(),
            'bezirk'    => array_merge(
                is_array( $bezirk_terms )
                    ? array_map( function( $t ) {
                        return array( 'name' => $t->name, 'slug' => $t->slug );
                      }, $bezirk_terms )
                    : array(),
                is_array( $bezirkst_terms )
                    ? array_map( function( $t ) {
                        return array( 'name' => $t->name, 'slug' => $t->slug );
                      }, $bezirkst_terms )
                    : array()
            ),
        );
    }

    $zuordnung_terms = get_the_terms( $post->ID, 'zuordnung' );
    $coords          = get_post_meta( $post->ID, 'koordinaten', true );

    return array(
        'id'           => $post->ID,
        'post_type'    => $post->post_type,
        'title'        => esc_html( $post->post_title ),
        'url'          => get_permalink( $post->ID ),
        'thumbnail'    => get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: null,
        'excerpt'      => wp_trim_words( get_the_excerpt( $post ), 20, '...' ),
        'adresse'      => esc_html( $adresse ? $adresse : '' ),
        'lat'          => is_array( $coords ) && isset( $coords['lat'] ) ? (float) $coords['lat'] : null,
        'lng'          => is_array( $coords ) && isset( $coords['lng'] ) ? (float) $coords['lng'] : null,
        'opfergruppen' => is_array( $opfergruppen_terms ) ? wp_list_pluck( $opfergruppen_terms, 'name' ) : array(),
        'jahr'         => is_array( $jahr_terms )         ? wp_list_pluck( $jahr_terms, 'name' )         : array(),
        'bezirk'       => array_merge(
                            is_array( $bezirk_terms )   ? wp_list_pluck( $bezirk_terms, 'name' )   : array(),
                            is_array( $bezirkst_terms ) ? wp_list_pluck( $bezirkst_terms, 'name' ) : array()
                          ),
        'zuordnung'    => is_array( $zuordnung_terms ) ? wp_list_pluck( $zuordnung_terms, 'name' ) : array(),
    );
}