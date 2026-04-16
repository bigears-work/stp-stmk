( function () {
    'use strict';

    var karteWrap = document.getElementById( 'stp-karte-map' );
    var karteForm = document.getElementById( 'stp-karte-form' );

    if ( karteWrap && karteForm ) {
        beobachte( karteWrap, initUebersichtskarte );
    }

    var einzelEl = document.getElementById( 'stp-einzel-map' );

    if ( einzelEl ) {
        initEinzelkarte( einzelEl );
    }

    // Übersichtskarte: MapLibre erst initialisieren wenn der Container
    // in den Viewport kommt. rootMargin '300px' = 300px Vorlauf.
    function beobachte( el, callback ) {
        if ( ! ( 'IntersectionObserver' in window ) ) {
            callback();
            return;
        }
        var observer = new IntersectionObserver( function( entries, obs ) {
            if ( ! entries[ 0 ].isIntersecting ) return;
            obs.disconnect();
            callback();
        }, { rootMargin: '300px' } );
        observer.observe( el );
    }

    function initUebersichtskarte() {

        var map = new maplibregl.Map( {
            container : 'stp-karte-map',
            style     : {
                version  : 8,
                sources  : {
                    osm: {
                        type        : 'raster',
                        tiles       : [ stpKarteData.tileUrl ],
                        tileSize    : 256,
                        attribution : '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                    }
                },
                layers: [ {
                    id     : 'osm',
                    type   : 'raster',
                    source : 'osm',
                } ]
            },
            center : [ stpKarteData.centerLng, stpKarteData.centerLat ],
            zoom   : 13,
        } );

        var markers       = [];
        var currentXhr    = null;
        var debounceTimer = null;
        var listeEl       = document.getElementById( 'stp-karte-liste' );
        var countEl       = document.getElementById( 'stp-karte-count' );

        // Click-Handler für Listeneinträge — einmalig registrieren.
        if ( listeEl ) {
            listeEl.addEventListener( 'click', function ( e ) {
                var item = e.target.closest( '[data-index]' );
                if ( ! item ) return;
                var idx = parseInt( item.getAttribute( 'data-index' ), 10 );
                if ( isNaN( idx ) || ! markers[ idx ] ) return;
                map.flyTo( { center: markers[ idx ].getLngLat(), zoom: 17 } );
                markers[ idx ].togglePopup();
            } );
        }

        map.on( 'load', function () {
            ladeMarker();
        } );

        var selects = karteForm.querySelectorAll( 'select' );
        for ( var i = 0; i < selects.length; i++ ) {
            selects[ i ].addEventListener( 'change', function () {
                ladeMarker();
            } );
        }

        var searchEl = karteForm.elements[ 'search' ];
        if ( searchEl ) {
            searchEl.addEventListener( 'input', function () {
                clearTimeout( debounceTimer );
                debounceTimer = setTimeout( ladeMarker, 350 );
            } );
        }

        var resetBtn = document.getElementById( 'stp-karte-reset' );
        if ( resetBtn ) {
            resetBtn.addEventListener( 'click', function () {
                var selects = karteForm.querySelectorAll( 'select' );
                for ( var i = 0; i < selects.length; i++ ) {
                    selects[ i ].value = '';
                }
                if ( searchEl ) searchEl.value = '';
                ladeMarker();
            } );
        }

        function getParams() {
            return {
                post_type    : getVal( 'post_type' ),
                opfergruppen : getVal( 'opfergruppen' ),
                bezirk       : getVal( 'bezirk' ),
                jahr         : getVal( 'jahr' ),
                search       : getVal( 'search' ),
                map_only     : true,
                per_page     : 500,
            };
        }

        function getVal( name ) {
            var el = karteForm.elements[ name ];
            return el ? el.value.trim() : '';
        }

        function ladeMarker() {
            if ( currentXhr ) currentXhr.abort();

            var params = getParams();
            var parts  = [];
            for ( var key in params ) {
                if ( ! params.hasOwnProperty( key ) ) continue;
                if ( params[ key ] !== '' && params[ key ] !== null ) {
                    parts.push(
                        encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] )
                    );
                }
            }

            var xhr = new XMLHttpRequest();
            currentXhr = xhr;
            xhr.open( 'GET', stpKarteData.apiBase + '?' + parts.join( '&' ), true );
            xhr.setRequestHeader( 'X-WP-Nonce', stpKarteData.nonce );
            xhr.setRequestHeader( 'Accept', 'application/json' );

            xhr.onreadystatechange = function () {
                if ( xhr.readyState !== 4 ) return;
                if ( xhr.status === 0 )     return; // aborted

                if ( xhr.status !== 200 ) {
                    console.error( 'Karte: API-Fehler ' + xhr.status );
                    if ( countEl ) countEl.textContent = 'Fehler beim Laden';
                    if ( listeEl ) listeEl.innerHTML =
                        '<p class="stp-error">Einträge konnten nicht geladen werden (HTTP ' + xhr.status + ').</p>';
                    return;
                }

                try {
                    var data = JSON.parse( xhr.responseText );
                    aktualisiereMarker( data.results || [] );
                    aktualisiereListe( data.results || [], data.total || 0 );
                } catch ( e ) {
                    console.error( 'Karte: Parse-Fehler', e );
                    if ( countEl ) countEl.textContent = 'Fehler beim Laden';
                }
            };

            xhr.send();
        }

        function aktualisiereMarker( results ) {
            for ( var i = 0; i < markers.length; i++ ) {
                markers[ i ].remove();
            }
            markers = [];

            for ( var j = 0; j < results.length; j++ ) {
                var stone = results[ j ];
                if ( ! stone.lat || ! stone.lng ) continue;

                var el = document.createElement( 'div' );
                el.className = 'stp-marker';
                el.setAttribute( 'title', stone.title );

                var popup = new maplibregl.Popup( {
                    offset     : 15,
                    closeButton: true,
                    maxWidth   : '240px',
                } ).setHTML(
                    '<div class="stp-popup">' +
                    '<strong class="stp-popup-title">' + stone.title + '</strong>' +
                    ( stone.adresse
                        ? '<span class="stp-popup-adresse">' + stone.adresse + '</span>'
                        : '' ) +
                    '<a href="' + stone.url + '" class="stp-popup-link">Zur Biografie →</a>' +
                    '</div>'
                );

                var marker = new maplibregl.Marker( { element: el } )
                    .setLngLat( [ stone.lng, stone.lat ] )
                    .setPopup( popup )
                    .addTo( map );

                markers.push( marker );
            }

            if ( markers.length > 0 ) {
                var bounds = new maplibregl.LngLatBounds();
                for ( var k = 0; k < results.length; k++ ) {
                    if ( results[ k ].lat && results[ k ].lng ) {
                        bounds.extend( [ results[ k ].lng, results[ k ].lat ] );
                    }
                }
                map.fitBounds( bounds, { padding: 40, maxZoom: 16 } );
            }
        }

        function aktualisiereListe( results, total ) {
            if ( countEl ) {
                countEl.textContent = total + ' Stolpersteine';
            }

            if ( ! listeEl ) return;

            if ( results.length === 0 ) {
                listeEl.innerHTML = '<p class="stp-no-results">Keine Stolpersteine gefunden.</p>';
                return;
            }

            var mitKoords  = [];
            var ohneKoords = [];
            for ( var i = 0; i < results.length; i++ ) {
                if ( results[ i ].lat && results[ i ].lng ) {
                    mitKoords.push( results[ i ] );
                } else {
                    ohneKoords.push( results[ i ] );
                }
            }

            var html = '';
            for ( var j = 0; j < mitKoords.length; j++ ) {
                html += renderListeEintrag( mitKoords[ j ], true );
            }

            if ( ohneKoords.length > 0 ) {
                html += '<p class="stp-ohne-coords-hinweis">' +
                    ohneKoords.length + ' Einträge ohne Koordinaten</p>';
                for ( var k = 0; k < ohneKoords.length; k++ ) {
                    html += renderListeEintrag( ohneKoords[ k ], false );
                }
            }

            listeEl.innerHTML = html;

            listeEl.addEventListener( 'click', function ( e ) {
                var item = e.target.closest( '[data-index]' );
                if ( ! item ) return;
                var idx = parseInt( item.getAttribute( 'data-index' ), 10 );
                if ( isNaN( idx ) || ! markers[ idx ] ) return;
                var lngLat = markers[ idx ].getLngLat();
                map.flyTo( { center: lngLat, zoom: 17 } );
                markers[ idx ].togglePopup();
            } );
        }

        function renderListeEintrag( stone, hatKoords ) {
            return '<div class="stp-liste-eintrag' + ( hatKoords ? ' hat-coords' : '' ) + '"' +
                ( hatKoords ? ' data-index="' + ( markers.length - 1 ) + '"' : '' ) +
                ' role="' + ( hatKoords ? 'button' : 'listitem' ) + '"' +
                ( hatKoords ? ' tabindex="0"' : '' ) + '>' +
                '<span class="stp-liste-name">' +
                '<a href="' + stone.url + '">' + stone.title + '</a>' +
                '</span>' +
                ( stone.adresse
                    ? '<span class="stp-liste-adresse">' + stone.adresse + '</span>'
                    : '' ) +
                '</div>';
        }
    }

    function initEinzelkarte( el ) {"show "

        var lat  = parseFloat( el.getAttribute( 'data-lat' ) );
        var lng  = parseFloat( el.getAttribute( 'data-lng' ) );
        var zoom = parseInt( el.getAttribute( 'data-zoom' ), 10 ) || 15;

        var centerLng = isNaN( lng ) ? stpKarteData.centerLng : lng;
        var centerLat = isNaN( lat ) ? stpKarteData.centerLat : lat;

        var map = new maplibregl.Map( {
            container : el,
            style     : {
                version  : 8,
                sources  : {
                    osm: {
                        type        : 'raster',
                        tiles       : [ stpKarteData.tileUrl ],
                        tileSize    : 256,
                        attribution : '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                    }
                },
                layers: [ {
                    id     : 'osm',
                    type   : 'raster',
                    source : 'osm',
                } ]
            },
            center : [ centerLng, centerLat ],
            zoom   : zoom,
        } );

        if ( ! isNaN( lat ) && ! isNaN( lng ) ) {
            var title   = el.getAttribute( 'data-title' ) || '';
            var adresse = el.getAttribute( 'data-adresse' ) || '';

            var markerEl = document.createElement( 'div' );
            markerEl.className = 'stp-marker stp-marker--einzel';

            var popup = new maplibregl.Popup( {
                offset     : 15,
                closeButton: false,
                maxWidth   : '220px',
            } ).setHTML(
                '<div class="stp-popup">' +
                '<strong class="stp-popup-title">' + title + '</strong>' +
                ( adresse ? '<span class="stp-popup-adresse">' + adresse + '</span>' : '' ) +
                '</div>'
            );

            new maplibregl.Marker( { element: markerEl } )
                .setLngLat( [ lng, lat ] )
                .setPopup( popup )
                .addTo( map );

            map.on( 'load', function () {
                popup.addTo( map );
            } );
        }
    }

} )();