<?php
/**
 * Shortcodes
 *
 * @package Stolpersteine
 */

// ============================================================
// SHORTCODE [stolpersteine_filter]
// ============================================================

add_shortcode( 'stolpersteine_filter', 'stolpersteine_filter_shortcode' );

function stolpersteine_filter_shortcode( $atts ) {

    $atts = shortcode_atts( array(
        'typ' => 'auto',
    ), $atts, 'stolpersteine_filter' );

    if ( 'auto' === $atts['typ'] ) {
        if ( is_post_type_archive( 'stolpersteine' ) || is_singular( 'stolpersteine' ) ) {
            $post_type = 'stolpersteine';
        } elseif ( is_post_type_archive( 'ststeiermark' ) || is_singular( 'ststeiermark' ) ) {
            $post_type = 'ststeiermark';
        } else {
            $post_type = 'both';
        }
    } elseif ( 'graz' === $atts['typ'] ) {
        $post_type = 'stolpersteine';
    } elseif ( 'stmk' === $atts['typ'] ) {
        $post_type = 'ststeiermark';
    } else {
        $post_type = 'both';
    }

    $opfergruppen = get_terms( array(
        'taxonomy'   => 'opfergruppen',
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ) );

    $jahre = get_terms( array(
        'taxonomy'   => 'jahr',
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'DESC',
    ) );

    if ( 'ststeiermark' === $post_type ) {
        $bezirke = get_terms( array(
            'taxonomy'   => 'bezirksteiermark',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
    } elseif ( 'stolpersteine' === $post_type ) {
        $bezirke = get_terms( array(
            'taxonomy'   => 'bezirk',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
    } else {
        $bezirke_graz = get_terms( array(
            'taxonomy'   => 'bezirk',
            'hide_empty' => true,
            'orderby'    => 'name',
        ) );
        $bezirke_stmk = get_terms( array(
            'taxonomy'   => 'bezirksteiermark',
            'hide_empty' => true,
            'orderby'    => 'name',
        ) );
        $bezirke = array_merge(
            is_array( $bezirke_graz ) ? $bezirke_graz : array(),
            is_array( $bezirke_stmk ) ? $bezirke_stmk : array()
        );
        usort( $bezirke, function( $a, $b ) {
            return strcmp( $a->name, $b->name );
        } );
    }

    stolpersteine_enqueue_filter_assets();

    ob_start();
    ?>
    <div class="stp-filter-wrap" data-post-type="<?php echo esc_attr( $post_type ); ?>">

        <form id="stp-filter-form" class="stp-filter-form" novalidate onsubmit="return false;">
            <input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">

            <div class="stp-filter-controls">

                <div class="stp-filter-field stp-filter-search">
                    <label for="stp-search">Name suchen</label>
                    <input
                        type="search"
                        id="stp-search"
                        name="search"
                        placeholder="z.B. Weinberger"
                        autocomplete="off"
                    >
                </div>

                <div class="stp-filter-field">
                    <label for="stp-opfergruppen">Opfergruppe</label>
                    <select id="stp-opfergruppen" name="opfergruppen">
                        <option value="">Alle Gruppen</option>
                        <?php if ( ! is_wp_error( $opfergruppen ) && is_array( $opfergruppen ) ) : ?>
                            <?php foreach ( $opfergruppen as $term ) : ?>
                                <option value="<?php echo esc_attr( $term->slug ); ?>">
                                    <?php echo esc_html( $term->name ); ?>
                                    (<?php echo (int) $term->count; ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="stp-filter-field">
                    <label for="stp-bezirk">Bezirk</label>
                    <select id="stp-bezirk" name="bezirk">
                        <option value="">Alle Bezirke</option>
                        <?php if ( ! is_wp_error( $bezirke ) && is_array( $bezirke ) ) : ?>
                            <?php foreach ( $bezirke as $term ) : ?>
                                <option value="<?php echo esc_attr( $term->slug ); ?>">
                                    <?php echo esc_html( $term->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="stp-filter-field">
                    <label for="stp-jahr">Verlegejahr</label>
                    <select id="stp-jahr" name="jahr">
                        <option value="">Alle Jahre</option>
                        <?php if ( ! is_wp_error( $jahre ) && is_array( $jahre ) ) : ?>
                            <?php foreach ( $jahre as $term ) : ?>
                                <option value="<?php echo esc_attr( $term->slug ); ?>">
                                    <?php echo esc_html( $term->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <button type="button" id="stp-reset" class="stp-reset-btn">
                    Filter zurücksetzen
                </button>

            </div>
        </form>

        <div class="stp-results-meta">
            <span id="stp-count" aria-live="polite" aria-atomic="true"></span>
        </div>

        <div class="stp-table-wrap">
            <table id="stp-table" class="stp-table">
                <thead>
                    <tr>
                        <th scope="col" class="stp-col-name">Name</th>
                        <th scope="col" class="stp-col-adresse">Adresse</th>
                        <th scope="col" class="stp-col-opfer">Opfergruppe</th>
                        <th scope="col" class="stp-col-bezirk">Bezirk</th>
                        <th scope="col" class="stp-col-jahr">Jahr</th>
                    </tr>
                </thead>
                <tbody id="stp-tbody">
                    <tr>
                        <td colspan="5" class="stp-loading">Wird geladen…</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav id="stp-pagination" class="stp-pagination" aria-label="Seitennavigation"></nav>

    </div>
    <?php

    return ob_get_clean();
}

// ============================================================
// SHORTCODE [stolpersteine_karte]
//
// Attribute:
//   typ   = 'auto' | 'graz' | 'stmk' | 'both'  (Standard: auto)
//   hoehe = Pixelwert                             (Standard: 600)
//   liste = 'ja' | 'nein'                        (Standard: ja)
//
// Beispiel Homepage (nur Filter + Pins, keine Listenansicht):
//   [stolpersteine_karte liste="nein" hoehe="500"]
// ============================================================

add_shortcode( 'stolpersteine_karte', 'stolpersteine_karte_shortcode' );

function stolpersteine_karte_shortcode( $atts ) {

    $atts = shortcode_atts( array(
        'typ'   => 'auto',
        'hoehe' => '600',
        'liste' => 'ja',
    ), $atts, 'stolpersteine_karte' );

    if ( 'auto' === $atts['typ'] ) {
        if ( is_post_type_archive( 'stolpersteine' ) || is_singular( 'stolpersteine' ) ) {
            $post_type = 'stolpersteine';
        } elseif ( is_post_type_archive( 'ststeiermark' ) || is_singular( 'ststeiermark' ) ) {
            $post_type = 'ststeiermark';
        } else {
            $post_type = 'both';
        }
    } elseif ( 'graz' === $atts['typ'] ) {
        $post_type = 'stolpersteine';
    } elseif ( 'stmk' === $atts['typ'] ) {
        $post_type = 'ststeiermark';
    } else {
        $post_type = 'both';
    }

    $hoehe      = absint( $atts['hoehe'] );
    $zeige_liste = ( 'nein' !== $atts['liste'] );

    if ( $hoehe < 300 ) {
        $hoehe = 300;
    }

    $opfergruppen = get_terms( array(
        'taxonomy'   => 'opfergruppen',
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ) );

    $jahre = get_terms( array(
        'taxonomy'   => 'jahr',
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'DESC',
    ) );

    if ( 'ststeiermark' === $post_type ) {
        $bezirke = get_terms( array(
            'taxonomy'   => 'bezirksteiermark',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
    } elseif ( 'stolpersteine' === $post_type ) {
        $bezirke = get_terms( array(
            'taxonomy'   => 'bezirk',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
    } else {
        $bezirke_graz = get_terms( array(
            'taxonomy'   => 'bezirk',
            'hide_empty' => true,
            'orderby'    => 'name',
        ) );
        $bezirke_stmk = get_terms( array(
            'taxonomy'   => 'bezirksteiermark',
            'hide_empty' => true,
            'orderby'    => 'name',
        ) );
        $bezirke = array_merge(
            is_array( $bezirke_graz ) ? $bezirke_graz : array(),
            is_array( $bezirke_stmk ) ? $bezirke_stmk : array()
        );
        usort( $bezirke, function( $a, $b ) {
            return strcmp( $a->name, $b->name );
        } );
    }

    stolpersteine_enqueue_karte_assets();

    ob_start();
    ?>
    <div
        class="stp-karte-wrap<?php echo $zeige_liste ? '' : ' stp-karte-wrap--ohne-liste'; ?>"
        data-post-type="<?php echo esc_attr( $post_type ); ?>"
    >

        <aside class="stp-karte-sidebar">

            <form id="stp-karte-form" class="stp-filter-form" novalidate onsubmit="return false;">
                <input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">

                <div class="stp-filter-field stp-filter-search">
                    <label for="stp-karte-search">Name suchen</label>
                    <input
                        type="search"
                        id="stp-karte-search"
                        name="search"
                        placeholder="z.B. Weinberger"
                        autocomplete="off"
                    >
                </div>

                <div class="stp-filter-field">
                    <label for="stp-karte-opfergruppen">Opfergruppe</label>
                    <select id="stp-karte-opfergruppen" name="opfergruppen">
                        <option value="">Alle Gruppen</option>
                        <?php if ( ! is_wp_error( $opfergruppen ) && is_array( $opfergruppen ) ) : ?>
                            <?php foreach ( $opfergruppen as $term ) : ?>
                                <option value="<?php echo esc_attr( $term->slug ); ?>">
                                    <?php echo esc_html( $term->name ); ?>
                                    (<?php echo (int) $term->count; ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="stp-filter-field">
                    <label for="stp-karte-bezirk">Bezirk</label>
                    <select id="stp-karte-bezirk" name="bezirk">
                        <option value="">Alle Bezirke</option>
                        <?php if ( ! is_wp_error( $bezirke ) && is_array( $bezirke ) ) : ?>
                            <?php foreach ( $bezirke as $term ) : ?>
                                <option value="<?php echo esc_attr( $term->slug ); ?>">
                                    <?php echo esc_html( $term->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="stp-filter-field">
                    <label for="stp-karte-jahr">Verlegejahr</label>
                    <select id="stp-karte-jahr" name="jahr">
                        <option value="">Alle Jahre</option>
                        <?php if ( ! is_wp_error( $jahre ) && is_array( $jahre ) ) : ?>
                            <?php foreach ( $jahre as $term ) : ?>
                                <option value="<?php echo esc_attr( $term->slug ); ?>">
                                    <?php echo esc_html( $term->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <button type="button" id="stp-karte-reset" class="stp-reset-btn">
                    Filter zurücksetzen
                </button>

            </form>

            <?php if ( $zeige_liste ) : ?>

                <div class="stp-karte-liste" id="stp-karte-liste">
                    <p class="stp-loading">Wird geladen…</p>
                </div>

            <?php endif; ?>

            <div class="stp-karte-count">
                <span id="stp-karte-count" aria-live="polite" aria-atomic="true"></span>
            </div>

        </aside>

        <div
            id="stp-karte-map"
            class="stp-karte-map"
            style="height: <?php echo esc_attr( $hoehe ); ?>px"
            aria-label="Karte der Stolpersteine"
            role="region"
        ></div>

    </div>
    <?php

    return ob_get_clean();
}

// ============================================================
// SHORTCODE [stolperstein_karte_einzel]
// ============================================================

add_shortcode( 'stolperstein_karte_einzel', 'stolperstein_karte_einzel_shortcode' );

function stolperstein_karte_einzel_shortcode( $atts ) {

    $atts = shortcode_atts( array(
        'hoehe' => '300',
    ), $atts, 'stolperstein_karte_einzel' );

    if ( ! is_singular( array( 'stolpersteine', 'ststeiermark' ) ) ) {
        return '';
    }

    $post_id = get_the_ID();
    $coords  = get_field( 'koordinaten', $post_id );
    $adresse = get_field( 'stolpersteine_textmedium', $post_id );
    if ( ! $adresse ) {
        $adresse = get_post_meta( $post_id, '_stolpersteine_textmedium', true );
    }

    $lat  = isset( $coords['lat'] ) ? (float) $coords['lat'] : null;
    $lng  = isset( $coords['lng'] ) ? (float) $coords['lng'] : null;
    $zoom = ( $lat && $lng ) ? 17 : 13;

    $hoehe = absint( $atts['hoehe'] );
    if ( $hoehe < 200 ) {
        $hoehe = 200;
    }

    stolpersteine_enqueue_karte_assets();

    ob_start();
    ?>
    <div
        id="stp-einzel-map"
        class="stp-einzel-map"
        style="height: <?php echo esc_attr( $hoehe ); ?>px"
        data-lat="<?php echo esc_attr( $lat ? $lat : '' ); ?>"
        data-lng="<?php echo esc_attr( $lng ? $lng : '' ); ?>"
        data-zoom="<?php echo esc_attr( $zoom ); ?>"
        data-title="<?php echo esc_attr( get_the_title() ); ?>"
        data-adresse="<?php echo esc_attr( $adresse ? $adresse : '' ); ?>"
        data-url="<?php echo esc_url( get_permalink() ); ?>"
        aria-label="Standort von <?php echo esc_attr( get_the_title() ); ?>"
        role="region"
    ></div>
    <?php if ( ! $lat || ! $lng ) : ?>
        <p class="stp-keine-koordinaten">
            Für diesen Stolperstein sind noch keine Koordinaten erfasst.
        </p>
    <?php endif; ?>
    <?php

    return ob_get_clean();
}


// ============================================================
// Dynamic Tag: Beitragsbild-Caption (GenerateBlocks 2.0)
// ============================================================

add_action( 'init', function(): void {

    if ( ! class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
        return;
    }

    new GenerateBlocks_Register_Dynamic_Tag( array(
        'title'    => __( 'Featured Image Caption', 'sto' ),
        'tag'      => 'featured_image_caption',
        'type'     => 'post',
        'supports' => array( 'source' ),
        'return'   => function( $options, $block, $instance ): string {

            $post_id      = get_the_ID();
            $thumbnail_id = get_post_thumbnail_id( $post_id );

            if ( ! $thumbnail_id ) {
                return '';
            }

            $caption = get_post_field( 'post_excerpt', $thumbnail_id );

            return GenerateBlocks_Dynamic_Tag_Callbacks::output(
                wp_kses_post( $caption ),
                $options,
                $instance
            );
        },
    ) );
} );