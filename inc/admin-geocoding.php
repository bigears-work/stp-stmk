<?php
/**
 * Admin Tool: Coordinates via Nominatim Geocoding
 *
 * @package Stolpersteine
 */

// Admin Page

add_action( 'admin_menu', 'stolpersteine_geocoding_menu' );

function stolpersteine_geocoding_menu() {
    add_submenu_page(
        'tools.php',
        'Koordinaten geocodieren',
        'Koordinaten geocodieren',
        'manage_options',
        'ss-geocoding',
        'stolpersteine_geocoding_page'
    );
}

function stolpersteine_geocoding_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Stolpersteine — Koordinaten geocodieren</h1>
        <p>
            Liest gespeicherte Adressen und holt via
            <strong>OpenStreetMap Nominatim</strong> automatisch Koordinaten.
            Pro Eintrag wird 1 Sekunde gewartet (Fair Use Policy).
        </p>

        <nav class="nav-tab-wrapper" style="margin-bottom: 1.5rem;">
            <a href="#" class="nav-tab nav-tab-active" id="btn-tab-geocoding">
                Geocoding
            </a>
            <a href="#" class="nav-tab" id="btn-tab-korrekturen">
                Korrekturen
                <span id="ss-fehler-badge" style="
                    display: none;
                    background: #d63638;
                    color: #fff;
                    border-radius: 10px;
                    padding: 1px 7px;
                    font-size: 0.75rem;
                    margin-left: 5px;
                "></span>
            </a>
        </nav>

        <div id="tab-geocoding">

            <div id="ss-geo-status" style="
                margin: 1rem 0; padding: 1rem;
                background: #f0f0f0; border-radius: 4px;
                display: none;">
                <strong id="ss-geo-status-text">Bereit.</strong>
            </div>

            <div id="ss-geo-progress-wrap" style="display: none; margin: 1rem 0;">
                <div style="background: #e0e0e0; border-radius: 4px; height: 24px; overflow: hidden;">
                    <div id="ss-geo-progress-bar" style="
                        height: 100%; background: #c8a951;
                        width: 0%; transition: width 0.3s; border-radius: 4px;">
                    </div>
                </div>
                <p id="ss-geo-progress-text" style="margin: 0.5rem 0 0; font-size: 0.9rem; color: #555;"></p>
            </div>

            <div id="ss-geo-log" style="
                display: none;
                max-height: 300px; overflow-y: auto;
                background: #1a1a1a; color: #eee;
                font-family: monospace; font-size: 0.8rem;
                padding: 1rem; border-radius: 4px; margin: 1rem 0;">
            </div>

            <p>
                <button id="ss-geo-start" class="button button-primary">
                    Geocoding starten
                </button>
                <button id="ss-geo-stop" class="button" style="display: none; margin-left: 0.5rem;">
                    Stoppen
                </button>
            </p>

            <p style="color: #888; font-size: 0.85rem;">
                Einträge mit bereits vorhandenen Koordinaten werden übersprungen.
                Nicht gefundene Adressen erscheinen nach dem Durchlauf im Tab „Korrekturen".
            </p>

        </div>

        <div id="tab-korrekturen" style="display: none;">

            <p>Einträge ohne Koordinaten — Adresse korrigieren und einzeln geocodieren.</p>

            <div id="ss-korr-loading" style="color: #888; font-style: italic;">
                Wird geladen…
            </div>

            <div id="ss-korr-liste"></div>

        </div>

    </div>

    <style>
        .ss-korr-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 0;
            border-bottom: 1px solid #e8e8e8;
        }
        .ss-korr-name {
            width: 220px;
            flex-shrink: 0;
            font-weight: 500;
            font-size: 0.9rem;
        }
        .ss-korr-name a {
            color: #1a1a1a;
            text-decoration: none;
        }
        .ss-korr-name a:hover {
            color: #c8a951;
        }
        .ss-korr-input {
            flex: 1;
            padding: 0.4rem 0.6rem;
            border: 1px solid #ccc;
            border-radius: 3px;
            font-size: 0.9rem;
        }
        .ss-korr-input:focus {
            border-color: #c8a951;
            outline: 2px solid #c8a951;
            outline-offset: 1px;
        }
        .ss-korr-btn {
            white-space: nowrap;
        }
        .ss-korr-status {
            width: 120px;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        .ss-korr-status.ok    { color: #2a7a2a; }
        .ss-korr-status.error { color: #d63638; }
        .ss-korr-status.wait  { color: #888;    }
    </style>

    <script>
    ( function() {

        // --------------------------------------------------------
        // Tabs
        // --------------------------------------------------------
        var tabGeo  = document.getElementById( 'tab-geocoding' );
        var tabKorr = document.getElementById( 'tab-korrekturen' );
        var btnGeo  = document.getElementById( 'btn-tab-geocoding' );
        var btnKorr = document.getElementById( 'btn-tab-korrekturen' );

        btnGeo.addEventListener( 'click', function(e) {
            e.preventDefault();
            tabGeo.style.display  = 'block';
            tabKorr.style.display = 'none';
            btnGeo.classList.add( 'nav-tab-active' );
            btnKorr.classList.remove( 'nav-tab-active' );
        } );

        btnKorr.addEventListener( 'click', function(e) {
            e.preventDefault();
            tabGeo.style.display  = 'none';
            tabKorr.style.display = 'block';
            btnGeo.classList.remove( 'nav-tab-active' );
            btnKorr.classList.add( 'nav-tab-active' );
            ladeFehlerListe();
        } );

        // --------------------------------------------------------
        // Geocoding Tab
        // --------------------------------------------------------
        var startBtn     = document.getElementById( 'ss-geo-start' );
        var stopBtn      = document.getElementById( 'ss-geo-stop' );
        var statusEl     = document.getElementById( 'ss-geo-status' );
        var statusText   = document.getElementById( 'ss-geo-status-text' );
        var progressWrap = document.getElementById( 'ss-geo-progress-wrap' );
        var progressBar  = document.getElementById( 'ss-geo-progress-bar' );
        var progressText = document.getElementById( 'ss-geo-progress-text' );
        var logEl        = document.getElementById( 'ss-geo-log' );
        var badge        = document.getElementById( 'ss-fehler-badge' );

        var running   = false;
        var offset    = 0;
        var total     = 0;
        var success   = 0;
        var skipped   = 0;
        var failed    = 0;
        var failedIds = [];

        function log( msg, type ) {
            var colors = {
                success : '#7dff7d',
                warning : '#ffd27d',
                error   : '#ff7d7d',
                info    : '#aaa'
            };
            var line = document.createElement( 'div' );
            line.style.color = colors[ type ] || '#eee';
            line.textContent = msg;
            logEl.appendChild( line );
            logEl.scrollTop = logEl.scrollHeight;
        }

        function updateProgress() {
            if ( total === 0 ) return;
            var pct = Math.round( ( offset / total ) * 100 );
            progressBar.style.width = pct + '%';
            progressText.textContent =
                offset + ' / ' + total + ' verarbeitet — ' +
                success + ' geocodiert, ' +
                skipped + ' übersprungen, ' +
                failed  + ' fehlgeschlagen';
        }

        function processNext() {
            if ( ! running ) return;

            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', ajaxurl, true );
            xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

            xhr.onreadystatechange = function() {
                if ( xhr.readyState !== 4 ) return;

                try {
                    var data = JSON.parse( xhr.responseText );
                } catch(e) {
                    log( 'Parse-Fehler: ' + xhr.responseText, 'error' );
                    stopGeo();
                    return;
                }

                if ( ! data.success ) {
                    log( 'Fehler: ' + ( data.data || 'Unbekannt' ), 'error' );
                    stopGeo();
                    return;
                }

                var r = data.data;

                if ( offset === 0 ) {
                    total = r.total;
                    progressWrap.style.display = 'block';
                }

                if ( r.status === 'success' ) {
                    success++;
                    log( '✓ [' + r.post_id + '] ' + r.adresse + ' → ' + r.lat + ', ' + r.lng, 'success' );
                } else if ( r.status === 'skipped' ) {
                    skipped++;
                } else if ( r.status === 'empty' ) {
                    skipped++;
                    log( '? [' + r.post_id + '] Keine Adresse vorhanden.', 'warning' );
                    failedIds.push( r.post_id );
                } else if ( r.status === 'failed' ) {
                    failed++;
                    log( '✗ [' + r.post_id + '] ' + r.adresse + ' — nicht gefunden.', 'error' );
                    failedIds.push( r.post_id );
                } else if ( r.status === 'done' ) {
                    log( '', 'info' );
                    log(
                        '=== Fertig! ' + success + ' geocodiert, ' +
                        skipped + ' übersprungen, ' + failed + ' fehlgeschlagen ===',
                        'success'
                    );
                    statusText.textContent = 'Geocoding abgeschlossen.';
                    if ( failedIds.length > 0 ) {
                        badge.textContent   = failedIds.length;
                        badge.style.display = 'inline';
                    }
                    stopGeo( true );
                    return;
                }

                offset++;
                updateProgress();
                setTimeout( processNext, 1100 );
            };

            xhr.send(
                'action=ss_geocode_single' +
                '&offset=' + offset +
                '&nonce='  + ssGeoData.nonce
            );
        }

        function startGeo() {
            running   = true;
            offset    = 0;
            total     = 0;
            success   = 0;
            skipped   = 0;
            failed    = 0;
            failedIds = [];

            logEl.innerHTML            = '';
            statusEl.style.display     = 'block';
            logEl.style.display        = 'block';
            startBtn.style.display     = 'none';
            stopBtn.style.display      = 'inline-block';
            statusText.textContent     = 'Geocoding läuft…';

            processNext();
        }

        function stopGeo( finished ) {
            running                = false;
            stopBtn.style.display  = 'none';
            startBtn.style.display = 'inline-block';
            if ( ! finished ) {
                statusText.textContent = 'Gestoppt.';
                log( '— Gestoppt —', 'warning' );
            }
        }

        startBtn.addEventListener( 'click', startGeo );
        stopBtn.addEventListener( 'click', function() { stopGeo( false ); } );

        // --------------------------------------------------------
        // Korrekturen Tab
        // --------------------------------------------------------
        var korrLoading = document.getElementById( 'ss-korr-loading' );
        var korrListe   = document.getElementById( 'ss-korr-liste' );
        var korrLoaded  = false;

        function ladeFehlerListe() {
            if ( korrLoaded ) return;
            korrLoaded = true;

            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', ajaxurl, true );
            xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

            xhr.onreadystatechange = function() {
                if ( xhr.readyState !== 4 ) return;
                if ( xhr.status !== 200 )   return;

                try {
                    var data = JSON.parse( xhr.responseText );
                } catch(e) { return; }

                if ( ! data.success || ! data.data.posts ) {
                    korrLoading.textContent = 'Fehler beim Laden.';
                    return;
                }

                korrLoading.style.display = 'none';

                var posts = data.data.posts;

                if ( posts.length === 0 ) {
                    korrListe.innerHTML = '<p style="color: #2a7a2a;">Alle Einträge haben Koordinaten ✓</p>';
                    return;
                }

                badge.textContent   = posts.length;
                badge.style.display = 'inline';

                var html = '<p style="color: #888; font-size: 0.85rem; margin-bottom: 1rem;">' +
                    posts.length + ' Einträge ohne Koordinaten</p>';

                for ( var i = 0; i < posts.length; i++ ) {
                    var p = posts[ i ];
                    html +=
                        '<div class="ss-korr-row" id="row-' + p.id + '">' +
                            '<div class="ss-korr-name">' +
                                '<a href="' + p.edit_url + '" target="_blank">' + p.title + '</a>' +
                            '</div>' +
                            '<input ' +
                                'type="text" ' +
                                'class="ss-korr-input" ' +
                                'id="adresse-' + p.id + '" ' +
                                'value="' + escAttr( p.adresse ) + '" ' +
                                'placeholder="Adresse eingeben…"' +
                            '>' +
                            '<button ' +
                                'type="button" ' +
                                'class="button ss-korr-btn" ' +
                                'data-id="' + p.id + '">' +
                                'Geocodieren' +
                            '</button>' +
                            '<span class="ss-korr-status" id="status-' + p.id + '"></span>' +
                        '</div>';
                }

                korrListe.innerHTML = html;

                korrListe.addEventListener( 'click', function(e) {
                    var btn = e.target.closest( '.ss-korr-btn' );
                    if ( ! btn ) return;
                    geocodeEinzel( btn.getAttribute( 'data-id' ) );
                } );

                korrListe.addEventListener( 'keydown', function(e) {
                    if ( e.key !== 'Enter' ) return;
                    var input = e.target.closest( '.ss-korr-input' );
                    if ( ! input ) return;
                    var row = input.closest( '.ss-korr-row' );
                    if ( ! row ) return;
                    geocodeEinzel( row.id.replace( 'row-', '' ) );
                } );
            };

            xhr.send(
                'action=ss_geocode_fehler_liste' +
                '&nonce=' + ssGeoData.nonce
            );
        }

        function geocodeEinzel( postId ) {
            var input    = document.getElementById( 'adresse-' + postId );
            var statusEl = document.getElementById( 'status-' + postId );
            var adresse  = input ? input.value.trim() : '';

            if ( ! adresse ) {
                statusEl.textContent = 'Adresse fehlt.';
                statusEl.className   = 'ss-korr-status error';
                return;
            }

            statusEl.textContent = 'Wird geocodiert…';
            statusEl.className   = 'ss-korr-status wait';

            var btn = document.querySelector( '[data-id="' + postId + '"]' );
            if ( btn ) btn.disabled = true;

            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', ajaxurl, true );
            xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

            xhr.onreadystatechange = function() {
                if ( xhr.readyState !== 4 ) return;
                if ( btn ) btn.disabled = false;

                try {
                    var data = JSON.parse( xhr.responseText );
                } catch(e) {
                    statusEl.textContent = 'Parse-Fehler.';
                    statusEl.className   = 'ss-korr-status error';
                    return;
                }

                if ( ! data.success ) {
                    statusEl.textContent = 'Fehler.';
                    statusEl.className   = 'ss-korr-status error';
                    return;
                }

                var r = data.data;

                if ( r.status === 'success' ) {
                    statusEl.textContent = '✓ ' + parseFloat( r.lat ).toFixed(5) + ', ' + parseFloat( r.lng ).toFixed(5);
                    statusEl.className   = 'ss-korr-status ok';
                    var row = document.getElementById( 'row-' + postId );
                    if ( row ) row.style.opacity = '0.4';
                    var remaining = korrListe.querySelectorAll( '.ss-korr-row:not([style*="opacity"])' ).length - 1;
                    badge.textContent   = remaining > 0 ? remaining : '';
                    badge.style.display = remaining > 0 ? 'inline' : 'none';
                } else {
                    statusEl.textContent = '✗ Nicht gefunden.';
                    statusEl.className   = 'ss-korr-status error';
                }
            };

            xhr.send(
                'action=ss_geocode_korrektur' +
                '&post_id=' + encodeURIComponent( postId ) +
                '&adresse=' + encodeURIComponent( adresse ) +
                '&nonce='   + ssGeoData.nonce
            );
        }

        function escAttr( str ) {
            if ( ! str ) return '';
            return str
                .replace( /&/g, '&amp;' )
                .replace( /"/g, '&quot;' )
                .replace( /</g, '&lt;' )
                .replace( />/g, '&gt;' );
        }

    } )();
    </script>
    <?php
}

// Assets

add_action( 'admin_enqueue_scripts', 'stolpersteine_geocoding_enqueue' );

function stolpersteine_geocoding_enqueue( $hook ) {
    if ( 'tools_page_ss-geocoding' !== $hook ) {
        return;
    }
    wp_add_inline_script(
        'jquery',
        'var ssGeoData = ' . json_encode( array(
            'nonce' => wp_create_nonce( 'ss_geocoding' ),
        ) ) . ';'
    );
}

// AJAX: Batch Geocoding

add_action( 'wp_ajax_ss_geocode_single', 'stolpersteine_geocode_single' );

function stolpersteine_geocode_single() {

    check_ajax_referer( 'ss_geocoding', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.' );
    }

    $offset = absint( $_POST['offset'] );

    $posts = get_posts( array(
        'post_type'      => array( 'stolpersteine', 'ststeiermark' ),
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ) );

    $total_query = new WP_Query( array(
        'post_type'      => array( 'stolpersteine', 'ststeiermark' ),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );
    $total = (int) $total_query->found_posts;

    if ( empty( $posts ) ) {
        wp_send_json_success( array(
            'status' => 'done',
            'total'  => $total,
        ) );
    }

    $post_id   = $posts[0];
    $post_type = get_post_type( $post_id );

    $existing = get_post_meta( $post_id, 'koordinaten', true );
    if ( ! empty( $existing['lat'] ) && ! empty( $existing['lng'] ) ) {
        wp_send_json_success( array(
            'status'  => 'skipped',
            'post_id' => $post_id,
            'total'   => $total,
        ) );
    }

    $adresse_raw = get_post_meta( $post_id, 'stolpersteine_textmedium', true );
    if ( empty( $adresse_raw ) ) {
        $adresse_raw = get_post_meta( $post_id, '_stolpersteine_textmedium', true );
        if ( $adresse_raw === 'field_ss_adresse' ) {
            $adresse_raw = '';
        }
    }
    if ( empty( $adresse_raw ) ) {
        wp_send_json_success( array(
            'status'  => 'empty',
            'post_id' => $post_id,
            'total'   => $total,
        ) );
    }

    $kontext = ( 'ststeiermark' === $post_type )
        ? 'Steiermark, Österreich'
        : 'Graz, Steiermark, Österreich';

    $queries    = stolpersteine_geocoding_queries( $adresse_raw, $kontext );
    $result     = null;
    $versuch_nr = 0;

    foreach ( $queries as $query ) {
        $versuch_nr++;
        $result = stolpersteine_nominatim_request( $query );
        if ( $result ) {
            break;
        }
    }

    if ( ! $result ) {
        wp_send_json_success( array(
            'status'  => 'failed',
            'post_id' => $post_id,
            'adresse' => $adresse_raw,
            'total'   => $total,
        ) );
    }

    stolpersteine_koordinaten_speichern( $post_id, $result['lat'], $result['lng'], $adresse_raw );

    wp_send_json_success( array(
        'status'  => 'success',
        'post_id' => $post_id,
        'adresse' => $adresse_raw,
        'lat'     => $result['lat'],
        'lng'     => $result['lng'],
        'versuch' => $versuch_nr,
        'total'   => $total,
    ) );
}

// AJAX: Load error list

add_action( 'wp_ajax_ss_geocode_fehler_liste', 'stolpersteine_geocode_fehler_liste' );

function stolpersteine_geocode_fehler_liste() {

    check_ajax_referer( 'ss_geocoding', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.' );
    }

    $posts = get_posts( array(
        'post_type'      => array( 'stolpersteine', 'ststeiermark' ),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => 'koordinaten',
                'compare' => 'NOT EXISTS',
            ),
        ),
    ) );

    $result = array();
    foreach ( $posts as $post_id ) {
        $adresse = get_post_meta( $post_id, 'stolpersteine_textmedium', true );
        if ( empty( $adresse ) || $adresse === 'field_ss_adresse' ) {
            $adresse = get_post_meta( $post_id, '_stolpersteine_textmedium', true );
            if ( $adresse === 'field_ss_adresse' ) {
                $adresse = '';
            }
        }
        $result[] = array(
            'id'       => $post_id,
            'title'    => esc_html( get_the_title( $post_id ) ),
            'adresse'  => $adresse ? $adresse : '',
            'edit_url' => esc_url( get_edit_post_link( $post_id ) ),
        );
    }

    wp_send_json_success( array( 'posts' => $result ) );
}

// AJAX: Single correction

add_action( 'wp_ajax_ss_geocode_korrektur', 'stolpersteine_geocode_korrektur' );

function stolpersteine_geocode_korrektur() {

    check_ajax_referer( 'ss_geocoding', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.' );
    }

    $post_id = absint( $_POST['post_id'] );
    $adresse = sanitize_text_field( $_POST['adresse'] );

    if ( ! $post_id || ! $adresse ) {
        wp_send_json_error( 'Fehlende Parameter.' );
    }

    $post_type = get_post_type( $post_id );
    $kontext   = ( 'ststeiermark' === $post_type )
        ? 'Steiermark, Österreich'
        : 'Graz, Steiermark, Österreich';

    $queries = stolpersteine_geocoding_queries( $adresse, $kontext );
    $result  = null;

    foreach ( $queries as $query ) {
        $result = stolpersteine_nominatim_request( $query );
        if ( $result ) {
            break;
        }
    }

    if ( ! $result ) {
        wp_send_json_success( array( 'status' => 'failed' ) );
    }

    stolpersteine_koordinaten_speichern( $post_id, $result['lat'], $result['lng'], $adresse );

    wp_send_json_success( array(
        'status' => 'success',
        'lat'    => $result['lat'],
        'lng'    => $result['lng'],
    ) );
}

// Helper Functions

/**
 * Builds a prioritized list of query descriptors.
 *
 * Strategy order:
 *   1. Nominatim Structured Search (ZIP + City + optional Street) with countrycodes=at
 *   2. Nominatim Structured Search without street (ZIP + City only)
 *   3. Nominatim Freitext with countrycodes=at
 *   4. Cleaned freitext (removed city name) with countrycodes=at
 *   5. Photon (Komoot) with Styria bounding box
 *   6. Photon without bounding box (broader search)
 *
 * Nominatim Structured is significantly more reliable for small Austrian municipalities
 * than freitext, as ZIP and City are passed as separate parameters.
 * Photon uses the same OSM data but a different engine and finds locations
 * that Nominatim misses in freitext mode.
 *
 * @param string $adresse_raw  Raw value from ACF field.
 * @param string $kontext      e.g. "Steiermark, Österreich".
 * @return array<array{type: string, args: array}> List of query descriptors.
 */
function stolpersteine_geocoding_queries( string $adresse_raw, string $kontext ): array {

    $queries = [];
    $adresse = trim( $adresse_raw );

    // --- Extract ZIP and City from address ---
    // Supported formats:
    //   "Schlacherweg 1, 8616 Gasen"
    //   "8616 Gasen"
    //   "Hauptplatz 1, 8010 Graz"
    $plz = null;
    $ort = null;
    $str = null;

    if ( preg_match( '/\b(\d{4})\s+([A-ZÄÖÜ][^\s,][^,]*)/u', $adresse, $m ) ) {
        $plz = $m[1];
        $ort = trim( $m[2] );
    }

    // Street: everything before comma or ZIP
    if ( $plz ) {
        $vor_plz = trim( preg_replace( '/,?\s*' . preg_quote( $plz, '/' ) . '.*$/u', '', $adresse ) );
        if ( ! empty( $vor_plz ) ) {
            $str = $vor_plz;
        }
    }

    // --- Strategy 1: Nominatim Structured Search with street ---
    if ( $plz && $ort && $str ) {
        $queries[] = [
            'type' => 'nominatim_structured',
            'args' => [
                'street'         => $str,
                'postalcode'     => $plz,
                'city'           => $ort,
                'country'        => 'Austria',
                'format'         => 'json',
                'limit'          => 1,
                'countrycodes'   => 'at',
                'addressdetails' => 0,
            ],
        ];
    }

    // --- Strategy 2: Nominatim Structured Search without street ---
    // (finds at least the city if street is unknown)
    if ( $plz && $ort ) {
        $queries[] = [
            'type' => 'nominatim_structured',
            'args' => [
                'postalcode'     => $plz,
                'city'           => $ort,
                'country'        => 'Austria',
                'format'         => 'json',
                'limit'          => 1,
                'countrycodes'   => 'at',
                'addressdetails' => 0,
            ],
        ];
    }

    // --- Strategy 3: Nominatim Freitext with countrycodes=at ---
    $queries[] = [
        'type' => 'nominatim_free',
        'args' => [
            'q'            => $adresse . ', ' . $kontext,
            'format'       => 'json',
            'limit'        => 1,
            'countrycodes' => 'at',
        ],
    ];

    // --- Strategy 4: Cleaned freitext (remove known city names at start/end) ---
    $adresse_clean = $adresse;
    foreach ( stolpersteine_ortsnamen_liste() as $name ) {
        $adresse_clean = preg_replace(
            '/^' . preg_quote( $name, '/' ) . '[,\s]+/iu',
            '',
            $adresse_clean
        );
        $adresse_clean = preg_replace(
            '/[,\s]+' . preg_quote( $name, '/' ) . '$/iu',
            '',
            $adresse_clean
        );
    }
    $adresse_clean = trim( $adresse_clean, ', ' );

    if ( $adresse_clean !== $adresse && ! empty( $adresse_clean ) ) {
        $queries[] = [
            'type' => 'nominatim_free',
            'args' => [
                'q'            => $adresse_clean . ', ' . $kontext,
                'format'       => 'json',
                'limit'        => 1,
                'countrycodes' => 'at',
            ],
        ];
    }

    // --- Strategy 5: Photon (Komoot) with Styria bounding box ---
    // Photon uses the same OSM data but a better Elasticsearch-based engine for DACH.
    // bbox = lon_min,lat_min,lon_max,lat_max (Styria roughly)
    $queries[] = [
        'type' => 'photon',
        'args' => [
            'q'     => $adresse . ', ' . $kontext,
            'lang'  => 'de',
            'limit' => 3,
            'bbox'  => '13.5,46.5,16.2,47.8',
        ],
    ];

    // --- Strategy 6: Photon without bounding box (all Austria) ---
    $queries[] = [
        'type' => 'photon',
        'args' => [
            'q'     => $adresse . ', Österreich',
            'lang'  => 'de',
            'limit' => 3,
        ],
    ];

    return $queries;
}

/**
 * Known city names for cleaning freitext addresses.
 *
 * @return string[]
 */
function stolpersteine_ortsnamen_liste(): array {
    return [
        'Graz',
        'Leoben',
        'Frohnleiten',
        'Kindberg',
        'Köflach',
        'Bruck an der Mur',
        'Bruck a.d. Mur',
        'Bruck a.d.M.',
        'St. Ruprecht an der Raab',
        'Sinabelkirchen',
        'Ramsau',
        'Schladming',
        'Gasen',
    ];
}

/**
 * Executes a geocoding request based on a query descriptor.
 *
 * Supported types:
 *   nominatim_structured  Nominatim with separate address fields
 *   nominatim_free        Nominatim freitext search
 *   photon                Photon (Komoot), GeoJSON response
 *
 * @param array{type: string, args: array} $query
 * @return array{lat: float, lng: float}|null Coordinates or null on error/no result.
 */
function stolpersteine_nominatim_request( array $query ): ?array {

    $user_agent = 'Stolpersteine-Graz/1.0 (verein-fuer-gedenkkultur-graz@gmx.at)';

    switch ( $query['type'] ) {

        // Nominatim — Structured and Freitext work the same,
        // only the args differ.
        case 'nominatim_structured':
        case 'nominatim_free':

            $api_url  = 'https://nominatim.openstreetmap.org/search?' . http_build_query( $query['args'] );
            $response = wp_remote_get( $api_url, [
                'headers' => [ 'User-Agent' => $user_agent ],
                'timeout' => 10,
            ] );

            if ( is_wp_error( $response ) ) {
                return null;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $body[0]['lat'] ) ) {
                return null;
            }

            return [
                'lat' => (float) $body[0]['lat'],
                'lng' => (float) $body[0]['lon'],
            ];

        // Photon (Komoot) — returns GeoJSON
        // features[0].geometry.coordinates = [lng, lat]
        case 'photon':

            $api_url  = 'https://photon.komoot.io/api/?' . http_build_query( $query['args'] );
            $response = wp_remote_get( $api_url, [
                'headers' => [ 'User-Agent' => $user_agent ],
                'timeout' => 10,
            ] );

            if ( is_wp_error( $response ) ) {
                return null;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $body['features'] ) ) {
                return null;
            }

            // Check all features — the first one located in Austria wins.
            foreach ( $body['features'] as $feature ) {
                $coords = $feature['geometry']['coordinates'] ?? [];
                if ( empty( $coords[0] ) || empty( $coords[1] ) ) {
                    continue;
                }

                $lng = (float) $coords[0];
                $lat = (float) $coords[1];

                // Sanity check: coordinates must be in Austria (rough bounding box).
                if ( $lat >= 46.0 && $lat <= 49.1 && $lng >= 9.5 && $lng <= 17.2 ) {
                    return [ 'lat' => $lat, 'lng' => $lng ];
                }
            }

            return null;

        default:
            return null;
    }
}

/**
 * Saves coordinates as ACF-compatible post meta.
 *
 * @param int    $post_id
 * @param float  $lat
 * @param float  $lng
 * @param string $adresse
 */
function stolpersteine_koordinaten_speichern( int $post_id, float $lat, float $lng, string $adresse ): void {
    $koordinaten = array(
        'lat'     => $lat,
        'lng'     => $lng,
        'zoom'    => 17,
        'address' => sanitize_text_field( $adresse ),
    );
    update_post_meta( $post_id, 'koordinaten', $koordinaten );
    update_post_meta( $post_id, '_koordinaten', 'field_ss_koordinaten' );
}

// Admin Page: Meta Migration

add_action( 'admin_menu', 'stolpersteine_migration_menu' );

function stolpersteine_migration_menu() {
    add_submenu_page(
        'tools.php',
        'Meta-Migration',
        'Meta-Migration',
        'manage_options',
        'ss-migration',
        'stolpersteine_migration_page'
    );
}

function stolpersteine_migration_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Stolpersteine — Meta-Migration</h1>
        <p>
            Migriert <code>_stolpersteine_textmedium</code> zu
            <code>stolpersteine_textmedium</code> damit ACF und
            GenerateBlocks den Wert korrekt lesen können.
        </p>

        <div id="mig-status" style="
            margin: 1rem 0; padding: 1rem;
            background: #f0f0f0; border-radius: 4px;
            display: none;">
            <strong id="mig-status-text">Bereit.</strong>
        </div>

        <div id="mig-progress-wrap" style="display: none; margin: 1rem 0;">
            <div style="background: #e0e0e0; border-radius: 4px; height: 24px; overflow: hidden;">
                <div id="mig-progress-bar" style="
                    height: 100%; background: #c8a951;
                    width: 0%; transition: width 0.3s; border-radius: 4px;">
                </div>
            </div>
            <p id="mig-progress-text" style="margin: 0.5rem 0 0; font-size: 0.9rem; color: #555;"></p>
        </div>

        <div id="mig-log" style="
            display: none;
            max-height: 300px; overflow-y: auto;
            background: #1a1a1a; color: #eee;
            font-family: monospace; font-size: 0.8rem;
            padding: 1rem; border-radius: 4px; margin: 1rem 0;">
        </div>

        <p>
            <button id="mig-start" class="button button-primary">
                Migration starten
            </button>
        </p>

        <p style="color: #888; font-size: 0.85rem;">
            Bereits migrierte Einträge werden übersprungen.
            Das Script kann mehrfach ausgeführt werden.
        </p>
    </div>

    <script>
    ( function() {
        var startBtn  = document.getElementById( 'mig-start' );
        var statusEl  = document.getElementById( 'mig-status' );
        var statusTxt = document.getElementById( 'mig-status-text' );
        var progWrap  = document.getElementById( 'mig-progress-wrap' );
        var progBar   = document.getElementById( 'mig-progress-bar' );
        var progTxt   = document.getElementById( 'mig-progress-text' );
        var logEl     = document.getElementById( 'mig-log' );

        var offset    = 0;
        var total     = 0;
        var migrated  = 0;
        var skipped   = 0;
        var running   = false;

        function log( msg, type ) {
            var colors = { success: '#7dff7d', info: '#aaa', warning: '#ffd27d' };
            var line = document.createElement( 'div' );
            line.style.color = colors[ type ] || '#eee';
            line.textContent = msg;
            logEl.appendChild( line );
            logEl.scrollTop = logEl.scrollHeight;
        }

        function updateProgress() {
            if ( ! total ) return;
            var pct = Math.round( ( offset / total ) * 100 );
            progBar.style.width = pct + '%';
            progTxt.textContent = offset + ' / ' + total +
                ' — ' + migrated + ' migriert, ' + skipped + ' übersprungen';
        }

        function processNext() {
            if ( ! running ) return;

            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', ajaxurl, true );
            xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

            xhr.onreadystatechange = function() {
                if ( xhr.readyState !== 4 ) return;

                try {
                    var data = JSON.parse( xhr.responseText );
                } catch(e) {
                    log( 'Parse-Fehler', 'error' );
                    running = false;
                    return;
                }

                if ( ! data.success ) {
                    log( 'Fehler: ' + data.data, 'error' );
                    running = false;
                    return;
                }

                var r = data.data;

                if ( offset === 0 ) {
                    total = r.total;
                    progWrap.style.display = 'block';
                }

                if ( r.status === 'done' ) {
                    log( '=== Fertig! ' + migrated + ' migriert, ' + skipped + ' übersprungen ===', 'success' );
                    statusTxt.textContent = 'Migration abgeschlossen.';
                    startBtn.style.display = 'inline-block';
                    running = false;
                    return;
                }

                if ( r.status === 'migrated' ) {
                    migrated++;
                    log( '✓ [' + r.post_id + '] ' + r.title + ' → ' + r.value, 'success' );
                } else if ( r.status === 'skipped' ) {
                    skipped++;
                } else if ( r.status === 'empty' ) {
                    skipped++;
                    log( '? [' + r.post_id + '] ' + r.title + ' — kein Wert', 'info' );
                }

                offset++;
                updateProgress();
                setTimeout( processNext, 50 );
            };

            xhr.send(
                'action=stp_migrate_single' +
                '&offset=' + offset +
                '&nonce=' + stpMigData.nonce
            );
        }

        startBtn.addEventListener( 'click', function() {
            running  = true;
            offset   = 0;
            total    = 0;
            migrated = 0;
            skipped  = 0;

            logEl.innerHTML           = '';
            statusEl.style.display    = 'block';
            logEl.style.display       = 'block';
            startBtn.style.display    = 'none';
            statusTxt.textContent     = 'Migration läuft…';

            processNext();
        } );

    } )();
    </script>
    <?php
}

add_action( 'admin_enqueue_scripts', 'stolpersteine_migration_enqueue' );

function stolpersteine_migration_enqueue( $hook ) {
    if ( 'tools_page_ss-migration' !== $hook ) {
        return;
    }
    wp_add_inline_script(
        'jquery',
        'var stpMigData = ' . json_encode( array(
            'nonce' => wp_create_nonce( 'stp_migration' ),
        ) ) . ';'
    );
}

add_action( 'wp_ajax_stp_migrate_single', 'stolpersteine_migrate_single' );

function stolpersteine_migrate_single() {

    check_ajax_referer( 'stp_migration', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.' );
    }

    $offset = absint( $_POST['offset'] );

    $posts = get_posts( array(
        'post_type'      => array( 'stolpersteine', 'ststeiermark' ),
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ) );

    $total_query = new WP_Query( array(
        'post_type'      => array( 'stolpersteine', 'ststeiermark' ),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );
    $total = (int) $total_query->found_posts;

    if ( empty( $posts ) ) {
        wp_send_json_success( array(
            'status' => 'done',
            'total'  => $total,
        ) );
    }

    $post_id = $posts[0];

    // Already migrated?
    $existing_ref = get_post_meta( $post_id, '_stolpersteine_textmedium', true );
    if ( $existing_ref === 'field_ss_adresse' ) {
        wp_send_json_success( array(
            'status'  => 'skipped',
            'post_id' => $post_id,
            'total'   => $total,
        ) );
    }

    // Get value
    $value = get_post_meta( $post_id, '_stolpersteine_textmedium', true );

    if ( empty( $value ) ) {
        wp_send_json_success( array(
            'status'  => 'empty',
            'post_id' => $post_id,
            'title'   => get_the_title( $post_id ),
            'total'   => $total,
        ) );
    }

    // 1. Save value under ACF field name
    update_post_meta( $post_id, 'stolpersteine_textmedium', $value );

    // 2. Set ACF reference
    update_post_meta( $post_id, '_stolpersteine_textmedium', 'field_ss_adresse' );

    wp_send_json_success( array(
        'status'  => 'migrated',
        'post_id' => $post_id,
        'title'   => get_the_title( $post_id ),
        'value'   => $value,
        'total'   => $total,
    ) );
}