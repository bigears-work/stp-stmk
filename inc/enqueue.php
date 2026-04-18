<?php
/**
 * Scripts and Styles
 *
 * @package Stolpersteine
 */

define( 'SS_MAPLIBRE_VERSION', '4.7.1' );

// Preconnect

add_action( 'wp_head', 'stolpersteine_preconnect', 1 );

function stolpersteine_preconnect() {

    $is_stolperstein_page =
        is_post_type_archive( array( 'stolpersteine', 'ststeiermark' ) ) ||
        is_singular( array( 'stolpersteine', 'ststeiermark' ) ) ||
        is_page( array( 'karte', 'stolpersteine-graz', 'stolpersteine-steiermark' ) );

    if ( ! $is_stolperstein_page ) {
        return;
    }

    echo '<link rel="preconnect" href="https://tile.openstreetmap.org" crossorigin>' . "\n";
    echo '<link rel="dns-prefetch" href="https://tile.openstreetmap.org">' . "\n";
}

// MapLibre

function stolpersteine_enqueue_maplibre() {

    if ( wp_style_is( 'maplibre-gl', 'enqueued' ) ) {
        return;
    }

    $js_path  = SS_ASSETS_DIR . '/vendor/maplibre-gl/maplibre-gl.js';
    $css_path = SS_ASSETS_DIR . '/vendor/maplibre-gl/maplibre-gl.css';

    if ( ! file_exists( $js_path ) || ! file_exists( $css_path ) ) {
        trigger_error(
            'Stolpersteine: MapLibre missing at ' . $js_path,
            E_USER_WARNING
        );
        return;
    }

    wp_enqueue_style(
        'maplibre-gl',
        SS_ASSETS_URI . '/vendor/maplibre-gl/maplibre-gl.css',
        array(),
        SS_MAPLIBRE_VERSION,
        'print'
    );

    wp_enqueue_script(
        'maplibre-gl',
        SS_ASSETS_URI . '/vendor/maplibre-gl/maplibre-gl.js',
        array(),
        SS_MAPLIBRE_VERSION,
        array(
            'in_footer' => true,
            'strategy'  => 'defer',
        )
    );
}

// CSS Non-Blocking

add_filter( 'style_loader_tag', 'stolpersteine_nonblocking_css', 10, 2 );

function stolpersteine_nonblocking_css( string $tag, string $handle ): string {

    if ( ! in_array( $handle, array( 'maplibre-gl' ), true ) ) {
        return $tag;
    }

    $nonblocking = str_replace(
        "media='print'",
        "media='print' onload=\"this.onload=null;this.media='all'\"",
        $tag
    );

    $noscript = '<noscript>' . str_replace( "media='print'", "media='all'", $tag ) . '</noscript>';

    return $nonblocking . $noscript;
}

// Filter Assets

function stolpersteine_enqueue_filter_assets() {

    $js_path  = SS_ASSETS_DIR . '/js/stolpersteine-filter.js';
    $css_path = SS_ASSETS_DIR . '/css/stolpersteine-filter.css';

    wp_enqueue_style(
        'stp-filter',
        SS_ASSETS_URI . '/css/stolpersteine-filter.css',
        array(),
        file_exists( $css_path ) ? filemtime( $css_path ) : '1.0.0'
    );

    wp_enqueue_script(
        'stp-filter',
        SS_ASSETS_URI . '/js/stolpersteine-filter.js',
        array(),
        file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0',
        array(
            'in_footer' => true,
            'strategy'  => 'defer',
        )
    );

    wp_localize_script( 'stp-filter', 'stpData', array(
        'apiBase' => esc_url( rest_url( 'stolpersteine/v1/filter' ) ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
    ) );
}

// Map Assets

function stolpersteine_enqueue_karte_assets() {

    $js_path  = SS_ASSETS_DIR . '/js/stolpersteine-karte.js';
    $css_path = SS_ASSETS_DIR . '/css/stolpersteine-karte.css';

    stolpersteine_enqueue_maplibre();

    wp_enqueue_style(
        'stp-karte',
        SS_ASSETS_URI . '/css/stolpersteine-karte.css',
        array( 'maplibre-gl' ),
        file_exists( $css_path ) ? filemtime( $css_path ) : '1.0.0'
    );

    wp_enqueue_script(
        'stp-karte',
        SS_ASSETS_URI . '/js/stolpersteine-karte.js',
        array( 'maplibre-gl' ),
        file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0',
        array(
            'in_footer' => true,
            'strategy'  => 'defer',
        )
    );

    wp_localize_script( 'stp-karte', 'stpKarteData', array(
        'apiBase'   => esc_url( rest_url( 'stolpersteine/v1/filter' ) ),
        'nonce'     => wp_create_nonce( 'wp_rest' ),
        'centerLat' => 47.0707,
        'centerLng' => 15.4395,
        'tileUrl'   => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    ) );
}