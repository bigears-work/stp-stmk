( function () {
    'use strict';

    var form    = document.getElementById( 'stp-filter-form' );
    var tbody   = document.getElementById( 'stp-tbody' );
    var countEl = document.getElementById( 'stp-count' );
    var paginEl = document.getElementById( 'stp-pagination' );

    if ( ! form || ! tbody ) return;

    var PER_PAGE = 50;

    var currentPage   = 1;
    var debounceTimer = null;
    var allResults    = [];   // kompletter Datensatz — einmalig geladen
    var lastFiltered  = [];   // nach Filter + Sortierung

    // Standard: alphabetisch nach Nachname aufsteigend
    var sortCol = 'name';
    var sortDir = 'asc';

    var COLUMNS = [
        { key: 'name',    label: 'Name' },
        { key: 'adresse', label: 'Adresse' },
        { key: 'opfer',   label: 'Opfergruppe' },
        { key: 'bezirk',  label: 'Bezirk' },
        { key: 'jahr',    label: 'Jahr' },
    ];

    // --------------------------------------------------------
    // Filter-State lesen
    // --------------------------------------------------------
    function getFilterState() {
        return {
            post_type    : getVal( 'post_type' ),
            opfergruppen : getVal( 'opfergruppen' ),
            bezirk       : getVal( 'bezirk' ),
            jahr         : getVal( 'jahr' ),
            search       : getVal( 'search' ).toLowerCase(),
        };
    }

    function getVal( name ) {
        var el = form.elements[ name ];
        return el ? el.value.trim() : '';
    }

    // --------------------------------------------------------
    // URL für initialen Komplett-Load
    // --------------------------------------------------------
    function buildLoadUrl( postType ) {
        return stpData.apiBase +
            '?post_type=' + encodeURIComponent( postType ) +
            '&per_page=500' +
            '&page=1' +
            '&filter_only=true';
    }

    // --------------------------------------------------------
    // XHR
    // --------------------------------------------------------
    function fetchAll( postType, callback ) {
        var xhr = new XMLHttpRequest();
        xhr.open( 'GET', buildLoadUrl( postType ), true );
        xhr.setRequestHeader( 'X-WP-Nonce', stpData.nonce );
        xhr.setRequestHeader( 'Accept', 'application/json' );

        xhr.onreadystatechange = function () {
            if ( xhr.readyState !== 4 ) return;
            if ( xhr.status === 200 ) {
                try {
                    callback( null, JSON.parse( xhr.responseText ) );
                } catch ( e ) {
                    callback( 'Parse-Fehler', null );
                }
            } else {
                callback( 'HTTP-Fehler ' + xhr.status, null );
            }
        };

        xhr.send();
    }

    // --------------------------------------------------------
    // Term-Normalisierung
    // Alte API liefert ["Jüdische Opfer"] (Strings),
    // neue API liefert [{name, slug}] (Objekte).
    // Einmalig beim Laden normalisieren — danach immer Objekte.
    // --------------------------------------------------------
    function normalizeTerm( term ) {
        if ( typeof term === 'string' ) {
            return { name: term, slug: term.toLowerCase().replace( /[äöü]/g, function(c) {
                    return { 'ä':'ae', 'ö':'oe', 'ü':'ue' }[c];
                } ).replace( /ß/g, 'ss' ).replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ) };
        }
        return term;
    }

    function normalizeResults( results ) {
        for ( var i = 0; i < results.length; i++ ) {
            var s = results[ i ];
            s.opfergruppen = ( s.opfergruppen || [] ).map( normalizeTerm );
            s.bezirk       = ( s.bezirk       || [] ).map( normalizeTerm );
            s.jahr         = ( s.jahr         || [] ).map( normalizeTerm );
        }
        return results;
    }

    // --------------------------------------------------------
    // Client-seitiger Filter
    // --------------------------------------------------------
    function applyClientFilter( results, state ) {
        return results.filter( function ( stone ) {

            if ( state.post_type && state.post_type !== 'both' &&
                stone.post_type !== state.post_type ) {
                return false;
            }

            if ( state.opfergruppen ) {
                var match = false;
                for ( var i = 0; i < stone.opfergruppen.length; i++ ) {
                    if ( stone.opfergruppen[ i ].slug === state.opfergruppen ) {
                        match = true; break;
                    }
                }
                if ( ! match ) return false;
            }

            if ( state.bezirk ) {
                var bMatch = false;
                for ( var j = 0; j < stone.bezirk.length; j++ ) {
                    if ( stone.bezirk[ j ].slug === state.bezirk ) {
                        bMatch = true; break;
                    }
                }
                if ( ! bMatch ) return false;
            }

            if ( state.jahr ) {
                var jMatch = false;
                for ( var k = 0; k < stone.jahr.length; k++ ) {
                    if ( stone.jahr[ k ].slug === state.jahr ) {
                        jMatch = true; break;
                    }
                }
                if ( ! jMatch ) return false;
            }

            if ( state.search ) {
                var opferNames = stone.opfergruppen.map( function( t ) { return t.name; } ).join( ' ' );
                var haystack = ( stone.title + ' ' + stone.adresse + ' ' + opferNames ).toLowerCase();
                if ( haystack.indexOf( state.search ) === -1 ) return false;
            }

            return true;
        } );
    }

    // --------------------------------------------------------
    // Sortierung
    // --------------------------------------------------------
    function getSortValue( stone, col ) {
        switch ( col ) {
            case 'name':
                // Klammern + Inhalt entfernen, dann mehrfache Leerzeichen normalisieren.
                // Ohne Normalisierung klebt "Albert (Bertel) Lichtenstein"
                // nach dem Strip zu "AlbertLichtenstein" zusammen.
                var clean = ( stone.title || '' )
                    .replace( /\(.*?\)/g, '' )
                    .replace( /\s+/g, ' ' )
                    .trim();
                var parts = clean.split( ' ' );
                return parts.length > 1
                    ? parts[ parts.length - 1 ] + ' ' + parts.slice( 0, -1 ).join( ' ' )
                    : ( clean || stone.title || '' );
            case 'adresse': return stone.adresse || '';
            case 'opfer':   return stone.opfergruppen.map( function(t) { return t.name; } ).join( ', ' );
            case 'bezirk':  return stone.bezirk.map( function(t) { return t.name; } ).join( ', ' );
            case 'jahr':    return stone.jahr.map( function(t) { return t.name; } ).join( ', ' );
            default:        return '';
        }
    }

    function sortResults( results ) {
        var sorted = results.slice();
        sorted.sort( function ( a, b ) {
            var valA = getSortValue( a, sortCol ).toLowerCase();
            var valB = getSortValue( b, sortCol ).toLowerCase();
            var cmp  = valA.localeCompare( valB, 'de', { sensitivity: 'base' } );
            return sortDir === 'asc' ? cmp : -cmp;
        } );
        return sorted;
    }

    // --------------------------------------------------------
    // Thead aufbauen — einmalig
    // --------------------------------------------------------
    function initThead() {
        var table = document.getElementById( 'stp-table' );
        if ( ! table ) return;

        var thead = table.querySelector( 'thead' );
        if ( ! thead ) return;

        var cells = '';
        for ( var i = 0; i < COLUMNS.length; i++ ) {
            var col = COLUMNS[ i ];
            cells +=
                '<th scope="col" class="stp-col-' + col.key + '" aria-sort="none">' +
                '<button type="button" class="stp-sort-btn" data-col="' + col.key + '">' +
                col.label +
                '<span class="stp-sort-icon" aria-hidden="true">' +
                getSortIcon( col.key ) +
                '</span>' +
                '</button>' +
                '</th>';
        }
        thead.innerHTML = '<tr>' + cells + '</tr>';

        thead.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '.stp-sort-btn' );
            if ( ! btn ) return;

            var col = btn.getAttribute( 'data-col' );

            if ( sortCol === col ) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortCol = col;
                sortDir = 'asc';
            }

            currentPage = 1;
            renderTable();
            updateTheadState();
        } );
    }

    function updateTheadState() {
        var table = document.getElementById( 'stp-table' );
        if ( ! table ) return;

        var buttons = table.querySelectorAll( '.stp-sort-btn' );
        for ( var i = 0; i < buttons.length; i++ ) {
            var btn      = buttons[ i ];
            var col      = btn.getAttribute( 'data-col' );
            var th       = btn.parentElement;
            var isActive = sortCol === col;

            th.className = 'stp-col-' + col + ( isActive ? ' is-sorted' : '' );
            th.setAttribute( 'aria-sort', isActive
                ? ( sortDir === 'asc' ? 'ascending' : 'descending' )
                : 'none'
            );

            var icon = btn.querySelector( '.stp-sort-icon' );
            if ( icon ) icon.innerHTML = getSortIcon( col );
        }
    }

    function getSortIcon( col ) {
        var upOpacity   = ( sortCol === col && sortDir === 'asc' )  ? '1' : '0.35';
        var downOpacity = ( sortCol === col && sortDir === 'desc' ) ? '1' : '0.35';

        return '<svg width="10" height="12" viewBox="0 0 10 12">' +
            '<path d="M5 1l3 4H2z" fill="currentColor" opacity="' + upOpacity + '"/>' +
            '<path d="M5 11L2 7h6z" fill="currentColor" opacity="' + downOpacity + '"/>' +
            '</svg>';
    }

    // --------------------------------------------------------
    // Row rendern
    // --------------------------------------------------------
    function renderRow( stone ) {
        var opfer  = stone.opfergruppen.map( function(t) { return t.name; } ).join( ', ' );
        var bezirk = stone.bezirk.map( function(t) { return t.name; } ).join( ', ' );
        var jahr   = stone.jahr.map( function(t) { return t.name; } ).join( ', ' );
        return '<tr>' +
            '<td class="stp-col-name"><a href="' + stone.url + '">' + stone.title + '</a></td>' +
            '<td class="stp-col-adresse">' + ( stone.adresse || '—' ) + '</td>' +
            '<td class="stp-col-opfer">'   + ( opfer  || '—' ) + '</td>' +
            '<td class="stp-col-bezirk">'  + ( bezirk || '—' ) + '</td>' +
            '<td class="stp-col-jahr">'    + ( jahr   || '—' ) + '</td>' +
            '</tr>';
    }

    // --------------------------------------------------------
    // Tabelle rendern (Filter + Sort + Paginierung — alles lokal)
    // --------------------------------------------------------
    function renderTable() {
        var state    = getFilterState();
        var filtered = applyClientFilter( allResults, state );
        var sorted   = sortResults( filtered );

        lastFiltered = sorted;

        var totalItems = sorted.length;
        var totalPages = Math.ceil( totalItems / PER_PAGE ) || 1;

        // Seite korrigieren falls nötig
        if ( currentPage > totalPages ) currentPage = totalPages;

        var start  = ( currentPage - 1 ) * PER_PAGE;
        var end    = Math.min( start + PER_PAGE, totalItems );
        var paged  = sorted.slice( start, end );

        if ( ! paged.length ) {
            tbody.innerHTML =
                '<tr><td colspan="5" class="stp-no-results">' +
                'Keine Stolpersteine gefunden.' +
                '</td></tr>';
        } else {
            var html = '';
            for ( var i = 0; i < paged.length; i++ ) {
                html += renderRow( paged[ i ] );
            }
            tbody.innerHTML = html;
        }

        if ( countEl ) {
            countEl.textContent = totalItems + ' Einträge';
        }

        renderPagination( totalPages, currentPage );
        updateTheadState();

        if ( currentPage > 1 ) {
            var table = document.getElementById( 'stp-table' );
            if ( table ) {
                table.scrollIntoView( { behavior: 'smooth', block: 'start' } );
            }
        }
    }

    // --------------------------------------------------------
    // Paginierung
    // --------------------------------------------------------
    function renderPagination( totalPages, current ) {
        if ( ! paginEl ) return;
        if ( totalPages <= 1 ) {
            paginEl.innerHTML = '';
            return;
        }

        var html = '';

        html += '<button class="stp-page-btn" data-page="' + ( current - 1 ) + '"' +
            ( current <= 1 ? ' disabled' : '' ) +
            ' aria-label="Vorherige Seite">&#8249;</button>';

        var range = buildPageRange( current, totalPages );
        for ( var i = 0; i < range.length; i++ ) {
            if ( range[ i ] === '...' ) {
                html += '<span class="stp-ellipsis">&#8230;</span>';
            } else {
                html += '<button class="stp-page-btn' +
                    ( range[ i ] === current ? ' is-active' : '' ) + '"' +
                    ' data-page="' + range[ i ] + '"' +
                    ( range[ i ] === current ? ' aria-current="page"' : '' ) +
                    '>' + range[ i ] + '</button>';
            }
        }

        html += '<button class="stp-page-btn" data-page="' + ( current + 1 ) + '"' +
            ( current >= totalPages ? ' disabled' : '' ) +
            ' aria-label="Nächste Seite">&#8250;</button>';

        paginEl.innerHTML = html;
    }

    function buildPageRange( current, total ) {
        var i, range = [];
        if ( total <= 7 ) {
            for ( i = 1; i <= total; i++ ) range.push( i );
            return range;
        }
        range.push( 1 );
        if ( current > 3 )         range.push( '...' );
        var start = Math.max( 2, current - 1 );
        var end   = Math.min( total - 1, current + 1 );
        for ( i = start; i <= end; i++ ) range.push( i );
        if ( current < total - 2 ) range.push( '...' );
        range.push( total );
        return range;
    }

    // --------------------------------------------------------
    // Laden-Zustand
    // --------------------------------------------------------
    function setLoading( loading ) {
        var table = document.getElementById( 'stp-table' );
        if ( ! table ) return;
        table.setAttribute( 'aria-busy', loading ? 'true' : 'false' );
        table.style.opacity = loading ? '0.5' : '1';
    }

    // --------------------------------------------------------
    // Events
    // --------------------------------------------------------
    var selects = form.querySelectorAll( 'select' );
    for ( var i = 0; i < selects.length; i++ ) {
        selects[ i ].addEventListener( 'change', function () {
            currentPage = 1;
            renderTable();
        } );
    }

    var searchEl = form.elements[ 'search' ];
    if ( searchEl ) {
        searchEl.addEventListener( 'input', function () {
            clearTimeout( debounceTimer );
            debounceTimer = setTimeout( function () {
                currentPage = 1;
                renderTable();
            }, 350 );
        } );
    }

    var resetBtn = document.getElementById( 'stp-reset' );
    if ( resetBtn ) {
        resetBtn.addEventListener( 'click', function () {
            var selects = form.querySelectorAll( 'select' );
            for ( var i = 0; i < selects.length; i++ ) {
                selects[ i ].value = '';
            }
            if ( searchEl ) searchEl.value = '';
            currentPage = 1;
            renderTable();
        } );
    }

    if ( paginEl ) {
        paginEl.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest( '[data-page]' );
            if ( ! btn || btn.disabled ) return;
            var page = parseInt( btn.getAttribute( 'data-page' ), 10 );
            if ( isNaN( page ) || page < 1 ) return;
            currentPage = page;
            renderTable();
        } );
    }

    // --------------------------------------------------------
    // Skeleton — verhindert Layout-Shift beim initialen Laden
    // --------------------------------------------------------
    function renderSkeleton() {
        var rows = '';
        var nameWidths   = [ '62%', '55%', '70%', '58%', '65%', '52%', '68%', '60%',
            '72%', '50%', '66%', '61%', '57%', '69%', '54%', '63%' ];
        var opferWidths  = [ '80%', '90%', '75%', '85%', '80%', '90%', '75%', '85%',
            '70%', '85%', '90%', '75%', '80%', '70%', '85%', '90%' ];
        var bezirkWidths = [ '55%', '65%', '50%', '60%', '55%', '65%', '50%', '60%',
            '70%', '55%', '60%', '50%', '65%', '55%', '60%', '70%' ];

        for ( var i = 0; i < 16; i++ ) {
            rows +=
                '<tr class="stp-skeleton-row">' +
                '<td class="stp-col-name"><span class="stp-skel" style="width:' + nameWidths[i] + '"></span></td>' +
                '<td class="stp-col-adresse"><span class="stp-skel" style="width:72%"></span></td>' +
                '<td class="stp-col-opfer"><span class="stp-skel" style="width:' + opferWidths[i] + '"></span></td>' +
                '<td class="stp-col-bezirk"><span class="stp-skel" style="width:' + bezirkWidths[i] + '"></span></td>' +
                '<td class="stp-col-jahr"><span class="stp-skel" style="width:50%"></span></td>' +
                '</tr>';
        }
        return rows;
    }

    // --------------------------------------------------------
    // Init — einmaliger API-Call, dann alles lokal
    // --------------------------------------------------------
    initThead();
    updateTheadState();

    if ( countEl ) {
        countEl.innerHTML = '<span class="stp-skel" style="width:80px;display:inline-block;vertical-align:middle;"></span>';
    }

    tbody.innerHTML = renderSkeleton();

    var postType = getVal( 'post_type' ) || 'both';

    fetchAll( postType, function ( err, data ) {

        if ( err || ! data ) {
            tbody.innerHTML =
                '<tr><td colspan="5" class="stp-error">' +
                'Fehler beim Laden. Bitte die Seite neu laden.' +
                '</td></tr>';
            return;
        }

        allResults = normalizeResults( data.results || [] );
        renderTable();
    } );

} )();