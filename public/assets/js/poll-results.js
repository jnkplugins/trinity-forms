/**
 * Trinity Forms: poll results for visitors.
 *
 * [trinity_forms_poll_results] prints the same markup for every visitor, so a
 * page cache can keep it. This script makes it personal and current:
 *   - after_vote: nothing is shown until this browser has voted on that form;
 *   - a vote on this page (the "trinity-forms:submitted" event) is remembered
 *     and every results box for that form reloads with the new numbers;
 *   - Poll mode (.trinity-forms-poll): once this browser has voted, the form is
 *     replaced by its results, right after the vote and on every later visit. A
 *     repeat vote refused by a per-user or per-IP limit counts as "voted" too
 *     ("trinity-forms:poll-repeat").
 */
( function () {
	"use strict";

	function key( id ) {
		return "tf_voted_" + id;
	}
	function voted( id ) {
		try {
			return !! window.localStorage.getItem( key( id ) );
		} catch ( e ) {
			return false;
		}
	}
	function remember( id ) {
		try {
			window.localStorage.setItem( key( id ), String( Date.now() ) );
		} catch ( e ) {}
	}

	function load( box ) {
		var url = box.getAttribute( "data-endpoint" );
		if ( ! url || ! window.fetch ) {
			return;
		}
		url += ( url.indexOf( "?" ) === -1 ? "?" : "&" )
			+ "field=" + encodeURIComponent( box.getAttribute( "data-field" ) || "" )
			+ "&sig=" + encodeURIComponent( box.getAttribute( "data-sig" ) || "" )
			+ "&_=" + Date.now();
		box.setAttribute( "aria-busy", "true" );
		window.fetch( url, { credentials: "same-origin", headers: { Accept: "application/json" }, cache: "no-store" } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( j && j.success && j.data && typeof j.data.html === "string" ) {
					// Server-built, wp_kses_post-safe markup (Poll_Results::render()).
					box.innerHTML = j.data.html;
				}
			} )
			.catch( function () {} )
			.then( function () { box.removeAttribute( "aria-busy" ); } );
	}

	function wrapperFor( id ) {
		return document.querySelector( '.trinity-forms-poll[data-tf-poll-form="' + id + '"]' );
	}

	// Poll mode: hide the form (CSS on .tf-poll-voted), show the live results, and
	// keep the confirmation text above them unless it already holds the results.
	function reveal( wrap, thanks ) {
		var box = wrap.querySelector( ".tf-poll-results[data-tf-poll-mode]" );
		wrap.classList.add( "tf-poll-voted" );
		if ( ! box ) {
			return;
		}
		if ( thanks && ! wrap.querySelector( ".tf-poll-thanks" ) ) {
			var note = document.createElement( "div" );
			note.className = "tf-poll-thanks";
			note.setAttribute( "role", "status" );
			// Taken from the form's own message area, which is kses-cleaned server HTML.
			note.innerHTML = thanks;
			wrap.insertBefore( note, box );
		}
		box.hidden = false;
		load( box );
	}

	function boxes( id ) {
		var sel = ".tf-poll-results[data-tf-poll" + ( id ? '="' + id + '"' : "" ) + "]";
		// Poll-mode boxes are revealed by reveal(), not loaded on their own.
		return Array.prototype.slice.call( document.querySelectorAll( sel ) ).filter( function ( b ) {
			return ! b.hasAttribute( "data-tf-poll-mode" );
		} );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( ".trinity-forms-poll[data-tf-poll-form]" ), function ( wrap ) {
			if ( voted( wrap.getAttribute( "data-tf-poll-form" ) ) ) {
				reveal( wrap, "" );
			}
		} );
		boxes().forEach( function ( box ) {
			if ( box.getAttribute( "data-after-vote" ) === "1" && voted( box.getAttribute( "data-tf-poll" ) ) ) {
				load( box );
			}
		} );
	}

	// The event does not bubble, so listen while it travels down (capture).
	document.addEventListener( "trinity-forms:submitted", function ( e ) {
		var form = e.target && e.target.getAttribute ? e.target : null;
		var id   = form ? form.getAttribute( "data-form-id" ) : "";
		if ( ! id ) {
			return;
		}
		remember( id );
		// The entry is already saved when the event fires.
		boxes( id ).forEach( load );
		var wrap = wrapperFor( id );
		if ( wrap ) {
			// The form shows its confirmation just after this event: look next tick.
			window.setTimeout( function () {
				var msg    = form.querySelector( ".trinity-forms-messages" );
				var thanks = msg && ! msg.querySelector( ".tf-poll-results-list" ) ? msg.innerHTML : "";
				reveal( wrap, thanks );
			}, 0 );
		}
	}, true );

	// A per-user or per-IP limit refused a repeat vote: that voter has voted.
	document.addEventListener( "trinity-forms:poll-repeat", function ( e ) {
		var form = e.target && e.target.getAttribute ? e.target : null;
		var id   = form ? form.getAttribute( "data-form-id" ) : "";
		var wrap = id ? wrapperFor( id ) : null;
		if ( wrap ) {
			remember( id );
			reveal( wrap, "" );
		}
	}, true );

	if ( document.readyState === "loading" ) {
		document.addEventListener( "DOMContentLoaded", init );
	} else {
		init();
	}
} )();
