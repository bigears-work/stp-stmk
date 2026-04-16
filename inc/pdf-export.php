<?php
/**
 * PDF-Export via Dompdf
 *
 * @package Stolpersteine
 */

use Dompdf\Dompdf;
use Dompdf\Options;

add_action( 'template_redirect', 'stolpersteine_pdf_export' );

function stolpersteine_pdf_export() {

    if ( ! isset( $_GET['pdf'] ) || '1' !== $_GET['pdf'] ) {
        return;
    }

    if ( ! is_singular( array( 'stolpersteine', 'ststeiermark' ) ) ) {
        return;
    }

    $autoload = SS_THEME_DIR . '/vendor/dompdf/autoload.inc.php';

    if ( ! file_exists( $autoload ) ) {
        wp_die( 'Dompdf nicht gefunden unter: ' . esc_html( $autoload ) );
    }

    require_once $autoload;

    // --------------------------------------------------------
    // Post-Daten
    // --------------------------------------------------------
    $post_id   = get_the_ID();
    $title     = get_the_title();
    $content   = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
    $thumbnail = get_the_post_thumbnail_url( $post_id, 'large' );

    $adresse = get_field( 'stolpersteine_textmedium', $post_id );
    if ( ! $adresse ) {
        $adresse = get_post_meta( $post_id, '_stolpersteine_textmedium', true );
    }

    // Opfergruppen
    $opfergruppen_terms = get_the_terms( $post_id, 'opfergruppen' );
    $opfergruppen       = array();
    if ( is_array( $opfergruppen_terms ) ) {
        foreach ( $opfergruppen_terms as $term ) {
            $opfergruppen[] = esc_html( $term->name );
        }
    }

    // Verbundene Gedenksteine via zuordnung
    $zuordnung_terms = get_the_terms( $post_id, 'zuordnung' );
    $verbundene      = array();
    if ( is_array( $zuordnung_terms ) ) {
        foreach ( $zuordnung_terms as $zterm ) {
            $related = get_posts( array(
                'post_type'      => array( 'stolpersteine', 'ststeiermark' ),
                'posts_per_page' => -1,
                'post__not_in'   => array( $post_id ),
                'tax_query'      => array( array(
                    'taxonomy' => 'zuordnung',
                    'field'    => 'term_id',
                    'terms'    => $zterm->term_id,
                ) ),
            ) );
            foreach ( $related as $rel ) {
                $verbundene[] = esc_html( get_the_title( $rel->ID ) );
            }
        }
        $verbundene = array_unique( $verbundene );
        sort( $verbundene );
    }

    // Logo als base64
    $logo_path = SS_THEME_DIR . '/assets/images/logo.png';
    $logo_src  = file_exists( $logo_path )
        ? 'data:image/png;base64,' . base64_encode( file_get_contents( $logo_path ) )
        : '';

    $logo_html = $logo_src
        ? '<img src="' . $logo_src . '" style="height:15mm;width:auto;">'
        : '<strong style="font-size:11pt;">Verein für Gedenkkultur in Graz</strong>';

    // Beitragsbild als base64
    $img_html = '';
    if ( $thumbnail ) {
        $img_path = stolpersteine_url_to_path( $thumbnail );
        if ( $img_path && file_exists( $img_path ) ) {
            $mime     = mime_content_type( $img_path );
            $img_b64  = base64_encode( file_get_contents( $img_path ) );
            $img_html = '<img src="data:' . $mime . ';base64,' . $img_b64 . '" '
                . 'style="max-width:100%;max-height:120mm;width:auto;height:auto;">';
        }
    }

    // Sidebar
    $sidebar_html = '';
    if ( ! empty( $opfergruppen ) ) {
        $sidebar_html .= '<p class="sidebar-opfer">' . implode( '<br>', $opfergruppen ) . '</p>';
    }
    if ( ! empty( $verbundene ) ) {
        $sidebar_html .= '<p class="sidebar-heading">Verbundene Gedenksteine</p>';
        $sidebar_html .= '<p class="sidebar-list">' . implode( '<br>', $verbundene ) . '</p>';
    }

    // --------------------------------------------------------
    // HTML
    // --------------------------------------------------------
    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>

@page {
    margin: 25mm 22mm 35mm 22mm;
}

    * {
        box-sizing: border-box;
    }

    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 9.5pt;
        line-height: 1.5;
        color: #1a1a1a;
        margin: 0;
        padding: 0;
    }

    /* ---- Header ---- */
    .pdf-header {
        margin-bottom: 10mm;
    }

    /* ---- Titelseite ---- */
    h1 {
        font-size: 24pt;
        font-weight: bold;
        margin-top: 8mm;
        margin-bottom: 2mm;
        line-height: 1.2;
    }

    .adresse {
        font-size: 12pt;
        font-weight: bold;
        margin-bottom: 6mm;
    }

    /* ---- Zweispaltiges Layout ---- */
    .layout-table {
        width: 100%;
        margin-bottom: 6mm;
    }

    .col-image {
        width: 58%;
        vertical-align: top;
        padding-right: 8mm;
    }

    .col-sidebar {
        width: 42%;
        vertical-align: top;
    }

    /* ---- Sidebar ---- */
    .sidebar-opfer {
        font-size: 9pt;
        margin-bottom: 5mm;
        line-height: 1.5;
    }

    .sidebar-heading {
        font-size: 8.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin-bottom: 2mm;
        margin-top: 0;
    }

    .sidebar-list {
        font-size: 9pt;
        line-height: 1.8;
        margin: 0;
    }

    /* ---- Biografie ---- */
    .biography {
        font-size: 9.5pt;
        line-height: 1.5;
    }

    .biography p {
        margin-bottom: 3mm;
        margin-top: 0;
    }

    .biography h1,
    .biography h2,
    .biography h3 {
        font-size: 11pt;
        font-weight: bold;
        margin-bottom: 2mm;
        margin-top: 4mm;
    }

    .biography a {
        color: #1a1a1a;
        text-decoration: none;
    }

    .biography img {
        max-width: 100%;
        height: auto;
    }

    /* ---- Seitenumbruch ---- */
    .page-break {
        page-break-after: always;
    }

</style>
</head>
<body>

<!-- SEITE 1 — TITELSEITE -->
<div class="pdf-header">' . $logo_html . '</div>

<h1>' . esc_html( $title ) . '</h1>

' . ( $adresse ? '<p class="adresse">' . esc_html( $adresse ) . '</p>' : '' ) . '

<table class="layout-table">
    <tr>
        <td class="col-image">' . $img_html . '</td>
        <td class="col-sidebar">' . $sidebar_html . '</td>
    </tr>
</table>

<div class="page-break"></div>

<!-- SEITE 2+ — BIOGRAFIE -->
<div class="pdf-header">' . $logo_html . '</div>

<div class="biography">' . $content . '</div>

</body>
</html>';

    // --------------------------------------------------------
    // Dompdf initialisieren
    // --------------------------------------------------------
    $options = new Options();
    $options->set( 'defaultFont', 'DejaVu Sans' );
    $options->set( 'isRemoteEnabled', false );
    $options->set( 'isHtml5ParserEnabled', true );
    $options->set( 'isFontSubsettingEnabled', true );
    $options->set( 'chroot', array(
        SS_THEME_DIR,
        WP_CONTENT_DIR . '/uploads',
    ) );

    $dompdf = new Dompdf( $options );
    $dompdf->loadHtml( $html, 'UTF-8' );
    $dompdf->setPaper( 'A4', 'portrait' );
    $dompdf->render();

    // --------------------------------------------------------
    // Footer + Seitenzahlen via Canvas
    // Auf jeder Seite fix am unteren Rand — unabhängig vom Inhalt
    // --------------------------------------------------------
$canvas      = $dompdf->getCanvas();
$page_width  = $canvas->get_width();
$page_height = $canvas->get_height();
$font        = $dompdf->getFontMetrics()->getFont( 'DejaVu Sans', 'normal' );
$font_bold   = $dompdf->getFontMetrics()->getFont( 'DejaVu Sans', 'bold' );

// Trennlinie
$canvas->page_line(
    62,
    $page_height - 99,
    $page_width - 62,
    $page_height - 99,
    array( 0, 0, 0 ),
    0.5
);

// Footer Zeile 1 — fett
$canvas->page_text(
    62,
    $page_height - 92,
    'Stolpersteine | ' . $title,
    $font_bold,
    7.5,
    array( 0, 0, 0 )
);

// Footer Zeile 2
$canvas->page_text(
    62,
    $page_height - 80,
    'Verein für Gedenkkultur in Graz | Lendkai 29 | A-8020 Graz | www.stolpersteine-graz.at',
    $font,
    7,
    array( 0, 0, 0 )
);

// Seitenzahl rechts
$canvas->page_text(
    $page_width - 110,
    $page_height - 80,
    'Seite {PAGE_NUM} von {PAGE_COUNT}',
    $font,
    7,
    array( 0, 0, 0 )
);

    // --------------------------------------------------------
    // Stream
    // --------------------------------------------------------
    $filename = 'stolperstein-' . sanitize_file_name( get_post_field( 'post_name', $post_id ) ) . '.pdf';
    $dompdf->stream( $filename, array( 'Attachment' => true ) );
    exit;
}

// ============================================================
// HILFSFUNKTION: URL → Serverpfad
// ============================================================

function stolpersteine_url_to_path( $url ) {
    $upload_dir = wp_upload_dir();
    $base_url   = $upload_dir['baseurl'];
    $base_dir   = $upload_dir['basedir'];

    if ( strpos( $url, $base_url ) === 0 ) {
        return str_replace( $base_url, $base_dir, $url );
    }

    return null;
}