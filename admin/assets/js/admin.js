// Trinity Forms admin scripts
( function () {
    "use strict";

    // Settings: a row of jump links built from the section headings, so a long
    // settings page (payments, AI, spam, licence) is one click from any section.
    // Pure progressive enhancement: without it the page reads top to bottom.
    function buildJumpLinks() {
        var nav = document.querySelector( ".jnkui .tf-jump" );
        if ( ! nav ) {
            return;
        }
        var heads = document.querySelectorAll( ".jnkui .tf-settings > h2, .jnkui #tf-licence" );
        if ( heads.length < 3 ) {
            return;
        }
        heads.forEach( function ( h, i ) {
            if ( ! h.id ) {
                h.id = "tf-section-" + ( i + 1 );
            }
            var a = document.createElement( "a" );
            a.href = "#" + h.id;
            a.textContent = h.textContent.trim();
            nav.appendChild( a );
        } );
        nav.hidden = false;
    }

    // Close an open "More" tab menu when clicking anywhere else.
    document.addEventListener( "click", function ( e ) {
        document.querySelectorAll( ".jnkui .jnkui-more[open]" ).forEach( function ( d ) {
            if ( ! d.contains( e.target ) ) {
                d.removeAttribute( "open" );
            }
        } );
    } );

    if ( document.readyState === "loading" ) {
        document.addEventListener( "DOMContentLoaded", buildJumpLinks );
    } else {
        buildJumpLinks();
    }
} )();
