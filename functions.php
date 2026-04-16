<?php
/**
 * GeneratePress Child Theme — Stolpersteine Graz
 *
 * @package Stolpersteine
 */

// ============================================================
// KONSTANTEN
// ============================================================

define( 'SS_THEME_DIR', get_stylesheet_directory() );
define( 'SS_THEME_URI', get_stylesheet_directory_uri() );
define( 'SS_ASSETS_DIR', SS_THEME_DIR . '/assets' );
define( 'SS_ASSETS_URI', SS_THEME_URI . '/assets' );
define( 'SS_INC_DIR',    SS_THEME_DIR . '/inc' );

// ============================================================
// ACF LOCAL JSON
// Feldgruppen werden aus acf-json/ geladen und dort gespeichert.
// Änderungen im ACF-Backend werden automatisch in die JSON-Datei
// geschrieben — versionierbar via Git, kein DB-Export nötig.
// ============================================================

add_filter( 'acf/settings/save_json', function() {
    return SS_THEME_DIR . '/acf-json';
} );

add_filter( 'acf/settings/load_json', function( $paths ) {
    $paths[] = SS_THEME_DIR . '/acf-json';
    return $paths;
} );

// ============================================================
// INCLUDES
// ============================================================

require_once SS_INC_DIR . '/post-types.php';
require_once SS_INC_DIR . '/rest-api.php';
require_once SS_INC_DIR . '/shortcodes.php';
require_once SS_INC_DIR . '/enqueue.php';
require_once SS_INC_DIR . '/admin-geocoding.php';
require_once SS_INC_DIR . '/pdf-export.php';
require_once SS_INC_DIR . '/event-queries.php';