<?php
/**
 * The SEO Framework — Integration
 *
 * @package Stolpersteine
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Nichts tun, wenn TSF nicht aktiv ist.
if ( ! function_exists( 'tsf' ) ) {
    return;
}

// 1. OPEN GRAPH / SOCIAL IMAGE FALLBACK

add_filter(
    'the_seo_framework_image_generation_params',
    'ss_tsf_image_generation_params',
    10,
    3
);

/**
 * Adds a content image fallback to the generator.
 *
 * @param array      $params  Generator parameters.
 * @param array|null $args    Query context.
 * @param string     $size    Image size.
 * @return array
 */
function ss_tsf_image_generation_params( array $params, ?array $args, string $size ): array {

    if ( ! isset( $params['cbs'] ) || ! is_array( $params['cbs'] ) ) {
        return $params;
    }

    // Nur für unsere CPTs aktiv.
    $post_id = $args['id'] ?? get_the_ID();
    if ( ! $post_id ) {
        return $params;
    }

    $post_type = get_post_type( $post_id );
    if ( ! in_array( $post_type, array( 'stolpersteine', 'ststeiermark' ), true ) ) {
        return $params;
    }

    // Fallback nur wenn kein Featured Image gesetzt.
    if ( has_post_thumbnail( $post_id ) ) {
        return $params;
    }

    $params['cbs']['ss_content_image'] = 'ss_tsf_content_image_generator';

    return $params;
}

/**
 * Generator: First image from the post content.
 *
 * @param array|null $args  Query context.
 * @param string     $size  Image size.
 * @return \Generator<array>
 */
function ss_tsf_content_image_generator( ?array $args = null, string $size = 'full' ): \Generator {

    $post_id = $args['id'] ?? get_the_ID();
    if ( ! $post_id ) {
        return;
    }

    $content = get_post_field( 'post_content', $post_id );
    if ( ! $content ) {
        return;
    }

    // Erstes <img>-src aus dem Inhalt extrahieren.
    if ( ! preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches ) ) {
        return;
    }

    $url = esc_url_raw( $matches[1] );
    if ( ! $url ) {
        return;
    }

    yield array(
        'url' => $url,
        'id'  => 0, // Keine Attachment-ID bekannt; TSF überspringt Alt-Tag-Suche.
    );
}

// 2. TITLE GENERATION

add_filter(
    'the_seo_framework_title_from_generation',
    'ss_tsf_archive_title',
    10,
    2
);

/**
 * Replaces generated archive titles for CPTs.
 *
 * @param string     $title  Generated title.
 * @param array|null $args   Query context.
 * @return string
 */
function ss_tsf_archive_title( string $title, ?array $args ): string {

    // Nur im Frontend und nur für Post-Type-Archive.
    if ( null !== $args ) {
        return $title;
    }

    if ( is_post_type_archive( 'stolpersteine' ) ) {
        return 'Stolpersteine Graz';
    }

    if ( is_post_type_archive( 'ststeiermark' ) ) {
        return 'Stolpersteine Steiermark';
    }

    return $title;
}

// 3. DESCRIPTION FALLBACK

add_filter(
    'the_seo_framework_generated_description',
    'ss_tsf_description_with_adresse',
    10,
    2
);

/**
 * Prepends the address to the generated description if no excerpt exists.
 *
 * @param string     $description  Generated description.
 * @param array|null $args         Query context.
 * @return string
 */
function ss_tsf_description_with_adresse( string $description, ?array $args ): string {

    // Nur Singular-Seiten im Frontend.
    if ( null !== $args ) {
        return $description;
    }

    if ( ! is_singular( array( 'stolpersteine', 'ststeiermark' ) ) ) {
        return $description;
    }

    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return $description;
    }

    // Nicht eingreifen wenn ein manueller Excerpt gesetzt ist.
    if ( has_excerpt( $post_id ) ) {
        return $description;
    }

    $adresse = get_field( 'stolpersteine_textmedium', $post_id );
    if ( ! $adresse ) {
        $adresse = get_post_meta( $post_id, '_stolpersteine_textmedium', true );
    }

    if ( ! $adresse ) {
        return $description;
    }

    $adresse = sanitize_text_field( $adresse );

    // Präfix: "Stolperstein in [Adresse] — [generierte Description]"
    $prefix = sprintf(
    /* translators: %s = Straße/Adresse */
        'Stolperstein in %s',
        $adresse
    );

    if ( $description ) {
        return $prefix . ' — ' . $description;
    }

    return $prefix;
}

// 4. TAXONOMIES NOINDEX

add_filter(
    'the_seo_framework_robots_meta_array',
    'ss_tsf_taxonomien_noindex',
    10,
    2
);

/**
 * Sets noindex for all Stolpersteine taxonomy archives.
 *
 * @param array      $meta  Robots meta array.
 * @param array|null $args  Query context.
 * @return array
 */
function ss_tsf_taxonomien_noindex( array $meta, ?array $args ): array {

    // Nur im Frontend auswerten.
    if ( null !== $args ) {
        return $meta;
    }

    $ss_taxonomies = array(
        'opfergruppen',
        'bezirk',
        'bezirksteiermark',
        'jahr',
        'zuordnung',
    );

    if ( is_tax( $ss_taxonomies ) ) {
        $meta['noindex'] = true;
    }

    return $meta;
}

add_filter(
    'the_seo_framework_sitemap_supported_taxonomies',
    'ss_tsf_taxonomien_aus_sitemap'
);

/**
 * Removes Stolpersteine taxonomies from the TSF sitemap.
 *
 * @param array $taxonomies  Sitemap taxonomies.
 * @return array
 */
function ss_tsf_taxonomien_aus_sitemap( array $taxonomies ): array {

    $ausschliessen = array(
        'opfergruppen',
        'bezirk',
        'bezirksteiermark',
        'jahr',
        'zuordnung',
    );

    return array_values(
        array_diff( $taxonomies, $ausschliessen )
    );
}
// 5. SCHEMA.ORG — Person Schema

add_action( 'wp_head', 'ss_schema_person', 5 );

/**
 * Outputs JSON-LD Person schema for single entries.
 *
 * Cleans the name by removing "(Address)" suffixes.
 */
function ss_schema_person(): void {

    if ( ! is_singular( array( 'stolpersteine', 'ststeiermark' ) ) ) {
        return;
    }

    $post_id   = get_the_ID();
    $post_type = get_post_type( $post_id );

    // Clean name: remove "(Address)" suffix.
    $name = trim(
        preg_replace( '/\s*\([^)]+\)\s*$/', '', get_the_title( $post_id ) )
    );

    if ( ! $name ) {
        return;
    }

    // Collect data.
    $schema = array(
        '@context' => 'https://schema.org',
        '@type'    => 'Person',
        'name'     => $name,
        'url'      => get_permalink( $post_id ),
    );

    // Description: manual excerpt > automatic excerpt.
    $description = has_excerpt( $post_id )
        ? get_the_excerpt( $post_id )
        : wp_trim_words( get_the_excerpt( $post_id ), 30, '…' );

    if ( $description ) {
        $schema['description'] = wp_strip_all_tags( $description );
    }

    // Featured Image.
    $thumbnail = get_the_post_thumbnail_url( $post_id, 'large' );
    if ( $thumbnail ) {
        $schema['image'] = esc_url( $thumbnail );
    }

    // Last known address (ACF).
    $adresse = get_field( 'stolpersteine_textmedium', $post_id );
    if ( ! $adresse ) {
        $adresse = get_post_meta( $post_id, '_stolpersteine_textmedium', true );
    }
    if ( $adresse ) {
        $schema['address'] = array(
            '@type'           => 'PostalAddress',
            'streetAddress'   => sanitize_text_field( $adresse ),
            'addressLocality' => ( 'ststeiermark' === $post_type ) ? 'Steiermark' : 'Graz',
            'addressCountry'  => 'AT',
        );
    }

    // Victim group as additionalType.
    $opfergruppen = get_the_terms( $post_id, 'opfergruppen' );
    if ( is_array( $opfergruppen ) && ! empty( $opfergruppen ) ) {
        $schema['additionalType'] = array_map(
            fn( $term ) => esc_html( $term->name ),
            $opfergruppen
        );
    }

    // Year of laying -> disambiguatingDescription.
    $jahre = get_the_terms( $post_id, 'jahr' );
    if ( is_array( $jahre ) && ! empty( $jahre ) ) {
        $schema['disambiguatingDescription'] = sprintf(
            'Stolperstein verlegt %s in %s',
            esc_html( $jahre[0]->name ),
            ( 'ststeiermark' === $post_type ) ? 'der Steiermark' : 'Graz'
        );
    }

    // Geo coordinates.
    $koordinaten = get_field( 'koordinaten', $post_id );
    if ( ! empty( $koordinaten['lat'] ) && ! empty( $koordinaten['lng'] ) ) {
        $schema['geo'] = array(
            '@type'     => 'GeoCoordinates',
            'latitude'  => (float) $koordinaten['lat'],
            'longitude' => (float) $koordinaten['lng'],
        );
    }

    // Publisher / Source.
    $schema['subjectOf'] = array(
        '@type'     => 'CreativeWork',
        'name'      => 'Stolperstein-Biografie — Verein für Gedenkkultur in Graz',
        'publisher' => array(
            '@type' => 'Organization',
            'name'  => 'Verein für Gedenkkultur in Graz',
            'url'   => 'https://stolpersteine-graz.at',
        ),
    );

    // Output.
    printf(
        '<script type="application/ld+json">%s</script>' . "\n",
        wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
    );
}