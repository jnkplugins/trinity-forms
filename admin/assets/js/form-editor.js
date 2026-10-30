( function () {
	var data = window.TrinityFormsFormEditor || {};
	var i18n = data.i18n || {};
	var root = document.getElementById( "trinity-forms-form-settings" );
	if ( ! root ) {
		return;
	}

	// Tabs.
	function activateTab( name ) {
		var btn = root.querySelector( '.trinity-forms-tabs button[data-tab="' + name + '"]' );
		var panel = root.querySelector( '[data-panel="' + name + '"]' );
		if ( ! btn || ! panel ) {
			return false;
		}
		root.querySelectorAll( ".trinity-forms-tabs button" ).forEach( function ( b ) { b.classList.remove( "active" ); } );
		root.querySelectorAll( ".trinity-forms-tab-panel" ).forEach( function ( p ) { p.classList.remove( "active" ); } );
		btn.classList.add( "active" );
		panel.classList.add( "active" );
		return true;
	}

	root.querySelectorAll( ".trinity-forms-tabs button" ).forEach( function ( btn ) {
		btn.addEventListener( "click", function () {
			activateTab( btn.dataset.tab );
		} );
	} );

	// The visual builder links here with #trinity-forms-notifications, since it has
	// no notifications UI of its own. This panel is a metabox below the block canvas,
	// so opening the right tab is not enough — it also has to be scrolled to.
	// The block editor keeps meta boxes in a collapsed strip under the canvas, so
	// open that strip (and this box, if it was folded) before scrolling to it.
	function revealMetaBox( tries ) {
		var strip = document.querySelector(
			".edit-post-meta-boxes-main__presenter button[aria-expanded='false'], .edit-post-meta-boxes-main button[aria-expanded='false'], .edit-post-layout__metaboxes button[aria-expanded='false']"
		);
		if ( strip ) {
			strip.click();
		}
		var box = document.getElementById( "trinity-forms-form-settings" );
		if ( box && box.classList.contains( "closed" ) ) {
			var toggle = box.querySelector( ".handlediv" );
			if ( toggle ) {
				toggle.click();
			}
		}
		var visible = root.offsetParent !== null && root.getBoundingClientRect().height > 40;
		if ( ! visible && tries > 0 ) {
			window.setTimeout( function () { revealMetaBox( tries - 1 ); }, 250 );
			return;
		}
		root.scrollIntoView( { behavior: "smooth", block: "start" } );
	}
	if ( window.location.hash === "#trinity-forms-notifications" && activateTab( "notifications" ) ) {
		window.setTimeout( function () { revealMetaBox( 20 ); }, 300 );
	}

	// Copy a merge tag. Clipboard access is blocked in some admin contexts, so
	// fall back to selecting the chip's text and letting the user copy it.
	root.addEventListener( "click", function ( e ) {
		var chip = e.target.closest( ".trinity-forms-tag" );
		if ( ! chip ) {
			return;
		}
		e.preventDefault();
		var tag = chip.dataset.tag || chip.textContent;
		var done = function () {
			chip.classList.add( "is-copied" );
			window.setTimeout( function () { chip.classList.remove( "is-copied" ); }, 1200 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( tag ).then( done, function () {
				window.getSelection().selectAllChildren( chip );
			} );
		} else {
			window.getSelection().selectAllChildren( chip );
		}
	} );

	// Add / remove the repeatable cards. Notifications and confirmations are the
	// same shape of list, so one helper drives both rather than two near-copies.
	function wireCardList( kind, wrapSelector, templateKey ) {
		var wrap = root.querySelector( wrapSelector );
		var addBtn = root.querySelector( '[data-add="' + kind + '"]' );
		if ( ! addBtn || ! wrap ) {
			return;
		}
		addBtn.addEventListener( "click", function () {
			var idx = wrap.querySelectorAll( ".trinity-forms-list-card" ).length;
			var html = String( data[ templateKey ] || "" ).replace( /__INDEX__/g, idx );
			wrap.insertAdjacentHTML( "beforeend", html );
		} );
	}

	// "Send a test" on a notification card. Uses what is typed now, saved or not:
	// the point is to check an address before a visitor's email goes to it. A
	// card routed to a form field has no address yet, so the test goes to you.
	root.addEventListener( "click", function ( e ) {
		var btn = e.target.closest( "[data-test-email]" );
		if ( ! btn || ! data.mailTestUrl ) {
			return;
		}
		e.preventDefault();
		var card = btn.closest( ".trinity-forms-list-card" );
		var mode = card.querySelector( 'select[name*="[to_type]"]' );
		var input = card.querySelector( 'input[name*="[to]"]' );
		var to = mode && mode.value === "field" ? ( data.userEmail || "" ) : ( input ? input.value : "" );
		var out = card.querySelector( ".tf-test-result" );
		if ( ! out ) {
			out = document.createElement( "p" );
			out.className = "tf-test-result description";
			out.setAttribute( "role", "status" );
			btn.parentNode.insertAdjacentElement( "afterend", out );
		}
		if ( ! to.trim() ) {
			out.textContent = i18n.noAddress || "";
			return;
		}
		// A merge tag ({email}) only turns into an address when someone submits.
		// Saying "type an address" here read as if the field were empty.
		if ( to.indexOf( "{" ) !== -1 ) {
			out.textContent = i18n.mergeTagAddress || i18n.noAddress || "";
			return;
		}
		out.textContent = i18n.sending || "…";
		btn.disabled = true;
		window.fetch( data.mailTestUrl, {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/json", "X-WP-Nonce": data.nonce },
			body: JSON.stringify( { to: to } )
		} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
			out.textContent = ( res && res.message ) || i18n.networkError || "";
			out.classList.toggle( "is-error", ! ( res && res.ok ) );
		} ).catch( function () {
			out.textContent = i18n.networkError || "";
		} ).then( function () {
			btn.disabled = false;
		} );
	} );

	// Layout tab: live style preview. Same variables the front end gets.
	function syncStylePreview() {
		var preview = root.querySelector( ".tf-style-preview" );
		if ( ! preview ) {
			return;
		}
		var presetSel = root.querySelector( '[data-tf-style="preset"]' );
		var preset = presetSel ? presetSel.value : "default";
		var vars = Object.assign( {}, ( data.stylePresets || {} )[ preset ] || {} );
		root.querySelectorAll( ".tf-style-input" ).forEach( function ( el ) {
			var name = el.dataset.tfStyle || "";
			if ( name.indexOf( "--" ) !== 0 || el.value === "" || el.value === el.dataset.default ) {
				return;
			}
			vars[ name ] = el.value + ( el.dataset.unit || "" );
		} );
		preview.style.cssText = Object.keys( vars ).map( function ( k ) { return k + ":" + vars[ k ]; } ).join( ";" );
		preview.className = preview.className.replace( /\btrinity-forms-style-\S+/g, "" ).trim();
		if ( preset && preset !== "default" ) {
			preview.classList.add( "trinity-forms-style-" + preset );
		}
	}
	root.addEventListener( "input", function ( e ) {
		if ( e.target.classList && e.target.classList.contains( "tf-style-input" ) ) {
			syncStylePreview();
		}
	} );
	root.addEventListener( "change", function ( e ) {
		if ( e.target.classList && e.target.classList.contains( "tf-style-input" ) ) {
			syncStylePreview();
		}
	} );
	root.addEventListener( "click", function ( e ) {
		var reset = e.target.closest( "[data-tf-style-reset]" );
		if ( ! reset ) {
			return;
		}
		e.preventDefault();
		var input = reset.parentNode.querySelector( ".tf-style-input" );
		if ( input ) {
			input.value = input.dataset.default || "";
			syncStylePreview();
		}
	} );

	wireCardList( "notification", ".trinity-forms-notifications", "notifTemplate" );
	wireCardList( "confirmation", ".trinity-forms-confirmations", "confirmTemplate" );

	root.addEventListener( "click", function ( e ) {
		if ( e.target.matches( '[data-remove="notification"], [data-remove="confirmation"]' ) ) {
			e.target.closest( ".trinity-forms-list-card" ).remove();
			return;
		}

		// Duplicate. Copying the DOM rather than re-rendering the template keeps
		// what has been typed, which is the entire point — the usual reason to
		// duplicate a notification is "same email, one different recipient".
		var dup = e.target.closest( "[data-duplicate]" );
		if ( ! dup ) {
			return;
		}
		var card = dup.closest( ".trinity-forms-list-card" );
		var wrap = card.parentNode;
		var clone = card.cloneNode( true );
		var idx = wrap.querySelectorAll( ".trinity-forms-list-card" ).length;

		// Re-index every name so the copy is a new row rather than an overwrite of
		// the original, and carry the *current* values across — cloneNode copies the
		// HTML attributes, not what somebody has since typed into the inputs.
		var originals = card.querySelectorAll( "input, select, textarea" );
		clone.querySelectorAll( "input, select, textarea" ).forEach( function ( el, i ) {
			if ( el.name ) {
				el.name = el.name.replace( /\[\d+\]/, "[" + idx + "]" );
			}
			var source = originals[ i ];
			if ( ! source ) {
				return;
			}
			if ( el.type === "checkbox" || el.type === "radio" ) {
				el.checked = source.checked;
			} else {
				el.value = source.value;
			}
		} );
		// The picker attaches per-input; the clone carries a stale marker.
		clone.querySelectorAll( "[data-tf-tagged]" ).forEach( function ( el ) { delete el.dataset.tfTagged; } );
		clone.querySelectorAll( ".trinity-forms-tag-picker" ).forEach( function ( el ) { el.remove(); } );
		clone.querySelectorAll( ".tf-test-result" ).forEach( function ( el ) { el.remove(); } );

		wrap.appendChild( clone );
		syncCardModes();
		wireMergeTags();
	} );

	// Only show the inputs that belong to the selected mode. Rendering all of them
	// at once (a message box, a page picker AND a URL field on every card) reads as
	// three things to fill in rather than a choice between them.
	function syncCardModes() {
		root.querySelectorAll( ".trinity-forms-list-card" ).forEach( function ( card ) {
			var typeSel = card.querySelector( 'select[name*="[type]"]' );
			if ( typeSel ) {
				toggleByLabel( card, "Message", typeSel.value === "text" );
				toggleByLabel( card, "Page", typeSel.value === "page" );
				toggleByLabel( card, "URL", typeSel.value === "redirect" );
				toggleByLabel( card, "Query string", typeSel.value !== "text" );
			}
			var toSel = card.querySelector( 'select[name*="[to_type]"]' );
			if ( toSel ) {
				var toInput = card.querySelector( 'input[name*="[to]"]' );
				var toField = card.querySelector( 'select[name*="[to_field]"]' );
				if ( toInput ) { toInput.hidden = toSel.value !== "email"; }
				if ( toField ) { toField.hidden = toSel.value !== "field"; }
			}
		} );
	}

	/** Hide a labelled control (and its label + description) inside one card. */
	function toggleByLabel( card, labelText, show ) {
		var labels = card.querySelectorAll( "label" );
		for ( var i = 0; i < labels.length; i++ ) {
			if ( labels[ i ].textContent.trim() !== labelText ) {
				continue;
			}
			labels[ i ].hidden = ! show;
			var node = labels[ i ].nextElementSibling;
			while ( node && node.tagName !== "LABEL" ) {
				node.hidden = ! show;
				node = node.nextElementSibling;
			}
			return;
		}
	}

	root.addEventListener( "change", function ( e ) {
		if ( e.target.matches( 'select[name*="[type]"], select[name*="[to_type]"]' ) ) {
			syncCardModes();
		}
	} );
	root.addEventListener( "click", function ( e ) {
		if ( e.target.matches( "[data-add]" ) ) {
			syncCardModes();
		}
	} );
	syncCardModes();

	/**
	 * Choice dropdown for the one-row rules (submit button, confirmations).
	 *
	 * When the rule's field has choices and the operator compares whole answers,
	 * the value box becomes a dropdown of that field's choices: the label is shown,
	 * the stored value is saved. Same behaviour as the block editor and the visual
	 * builder (blocks/conditional-rules.js). Without JS the text box stays, and a
	 * label typed there still matches (Conditional_Logic::choice_value()).
	 */
	var ruleChoices = data.ruleChoices || {};
	var CHOICE_OPS  = [ "equals", "not_equals", "contains", "not_contains" ];
	function fold( s ) {
		s = String( s == null ? "" : s ).trim();
		return ( s.normalize ? s.normalize( "NFC" ) : s ).toLowerCase();
	}
	function syncRule( row ) {
		var fieldSel = row.querySelector( 'select[name$="[field]"]' );
		var opSel    = row.querySelector( 'select[name$="[operator]"]' );
		var input    = row.querySelector( 'input[name$="[value]"]' );
		if ( ! fieldSel || ! opSel || ! input ) {
			return;
		}
		var picker  = row.querySelector( "select.tf-rule-choices" );
		var choices = CHOICE_OPS.indexOf( opSel.value ) !== -1 ? ( ruleChoices[ fieldSel.value ] || [] ) : [];
		if ( ! choices.length ) {
			if ( picker ) {
				input.value = picker.value;
				picker.remove();
			}
			input.hidden   = false;
			input.disabled = false;
			return;
		}
		var current = picker ? picker.value : input.value;
		var hit     = null;
		choices.forEach( function ( c ) { if ( ! hit && c.value === current ) { hit = c; } } );
		choices.forEach( function ( c ) { if ( ! hit && current !== "" && fold( c.value ) === fold( current ) ) { hit = c; } } );
		choices.forEach( function ( c ) { if ( ! hit && current !== "" && fold( c.label ) === fold( current ) ) { hit = c; } } );
		if ( picker ) {
			picker.remove();
		}
		picker           = document.createElement( "select" );
		picker.className = "tf-rule-choices";
		picker.name      = input.name;
		var none         = document.createElement( "option" );
		none.value       = "";
		none.textContent = i18n.selectChoice || "— select —";
		picker.appendChild( none );
		choices.forEach( function ( c ) {
			var o         = document.createElement( "option" );
			o.value       = c.value;
			o.textContent = c.label;
			picker.appendChild( o );
		} );
		if ( current !== "" && ! hit ) {
			// No longer a choice: kept as typed so opening the form does not lose it.
			var keep         = document.createElement( "option" );
			keep.value       = current;
			keep.textContent = current;
			picker.appendChild( keep );
		}
		picker.value   = hit ? hit.value : current;
		input.hidden   = true;
		input.disabled = true;
		input.parentNode.insertBefore( picker, input.nextSibling );
	}
	function syncRules() {
		root.querySelectorAll( ".trinity-forms-inline-rule" ).forEach( syncRule );
	}
	root.addEventListener( "change", function ( e ) {
		if ( e.target.matches( '.trinity-forms-inline-rule select[name$="[field]"], .trinity-forms-inline-rule select[name$="[operator]"]' ) ) {
			syncRule( e.target.closest( ".trinity-forms-inline-rule" ) );
		}
	} );
	root.addEventListener( "click", function ( e ) {
		if ( e.target.matches( "[data-add]" ) ) {
			syncRules();
		}
	} );
	syncRules();

	/**
	 * Merge-tag picker.
	 *
	 * A button beside every text input and textarea that can carry tags, dropping
	 * the chosen one in at the cursor. Before this the tags were listed once at the
	 * top of the Notifications tab as "click to copy" — which meant scrolling up,
	 * copying, scrolling back and pasting, for something you do on nearly every
	 * field.
	 *
	 * Inserting at the caret rather than appending matters: a subject line is
	 * usually "New enquiry from {name}", not "{name}" tacked on the end.
	 */
	function wireMergeTags() {
		var groups = ( data && data.mergeTags ) || [];
		if ( ! groups.length ) {
			return;
		}

		var open = null;

		function closeMenu() {
			if ( open ) {
				open.remove();
				open = null;
			}
		}

		function insert( input, tag ) {
			var start = input.selectionStart;
			var end = input.selectionEnd;

			if ( typeof start === "number" && typeof end === "number" ) {
				input.value = input.value.slice( 0, start ) + tag + input.value.slice( end );
				// Leave the caret after what was just inserted so you can keep typing.
				input.selectionStart = input.selectionEnd = start + tag.length;
			} else {
				input.value += tag;
			}

			input.focus();
			// So anything listening for edits (autosave, dirty-state) notices.
			input.dispatchEvent( new Event( "input", { bubbles: true } ) );
		}

		function buildMenu( input ) {
			var menu = document.createElement( "div" );
			menu.className = "trinity-forms-tag-menu";

			groups.forEach( function ( group ) {
				if ( ! group.tags || ! group.tags.length ) {
					return;
				}
				var heading = document.createElement( "p" );
				heading.className = "trinity-forms-tag-menu-heading";
				heading.textContent = group.label;
				menu.appendChild( heading );

				group.tags.forEach( function ( entry ) {
					var item = document.createElement( "button" );
					item.type = "button";
					item.className = "trinity-forms-tag-menu-item";
					item.innerHTML = "";
					var code = document.createElement( "code" );
					code.textContent = entry.tag;
					var label = document.createElement( "span" );
					label.textContent = entry.label;
					item.appendChild( code );
					item.appendChild( label );
					item.addEventListener( "click", function () {
						insert( input, entry.tag );
						closeMenu();
					} );
					menu.appendChild( item );
				} );
			} );

			return menu;
		}

		/** Which inputs get a picker: the ones whose values are templates. */
		function taggable( card ) {
			return card.querySelectorAll(
				'input[name*="[subject]"], input[name*="[to]"], input[name*="[cc]"],' +
				'input[name*="[bcc]"], input[name*="[reply_to]"], input[name*="[from_name]"],' +
				'textarea[name*="[body]"], textarea[name*="[message]"], input[name*="[query_string]"]'
			);
		}

		root.querySelectorAll( ".trinity-forms-list-card" ).forEach( function ( card ) {
			taggable( card ).forEach( function ( input ) {
				if ( input.dataset.tfTagged ) {
					return;
				}
				input.dataset.tfTagged = "1";

				var wrap = document.createElement( "span" );
				wrap.className = "trinity-forms-tag-picker";

				var btn = document.createElement( "button" );
				btn.type = "button";
				btn.className = "button trinity-forms-tag-toggle";
				btn.textContent = "{ }";
				btn.title = ( data.i18n && data.i18n.insertTag ) || "Insert a merge tag";
				btn.setAttribute( "aria-label", btn.title );

				btn.addEventListener( "click", function ( e ) {
					e.stopPropagation();
					var wasOpen = open && open.parentNode === wrap;
					closeMenu();
					if ( ! wasOpen ) {
						open = buildMenu( input );
						wrap.appendChild( open );
					}
				} );

				wrap.appendChild( btn );
				input.insertAdjacentElement( "afterend", wrap );
			} );
		} );

		document.addEventListener( "click", closeMenu );
		document.addEventListener( "keydown", function ( e ) {
			if ( e.key === "Escape" ) {
				closeMenu();
			}
		} );
	}

	wireMergeTags();
	// Newly added notification/confirmation cards need pickers too.
	root.addEventListener( "click", function ( e ) {
		if ( e.target.matches( "[data-add]" ) ) {
			setTimeout( wireMergeTags, 0 );
		}
	} );


	// Test connection (premium integrations).
	root.addEventListener( "click", async function ( e ) {
		var btn = e.target.closest( "[data-test-integration]" );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var slug   = btn.dataset.testIntegration;
		var card   = btn.closest( "[data-integration-card]" );
		var result = card.querySelector( '[data-test-result-for="' + slug + '"]' );
		var config = {};
		card.querySelectorAll( "input, select, textarea" ).forEach( function ( el ) {
			var m = el.name.match( /\[integrations\]\[\d+\]\[([^\]]+)\]$/ );
			if ( ! m ) { return; }
			if ( m[ 1 ] === "slug" || m[ 1 ] === "enabled" ) { return; }
			config[ m[ 1 ] ] = el.type === "checkbox" ? ( el.checked ? "1" : "" ) : el.value;
		} );

		btn.disabled = true;
		result.textContent = i18n.testing || "Testing…";
		result.style.color = "";

		try {
			var r = await fetch( data.restUrl, {
				method: "POST",
				credentials: "same-origin",
				headers: {
					"Content-Type": "application/json",
					"X-WP-Nonce": data.nonce
				},
				body: JSON.stringify( { slug: slug, config: config } )
			} );
			var json = await r.json();
			var ok   = json && json.success && json.data && json.data.ok;
			result.textContent = ok
				? ( i18n.connected || "Connected" )
				: ( ( i18n.failed || "Failed" ) + ( json && json.data && json.data.error ? ": " + json.data.error : "" ) );
			result.style.color = ok ? "#00a32a" : "#d63638";
		} catch ( err ) {
			result.textContent = i18n.networkError || "Network error";
			result.style.color = "#d63638";
		} finally {
			btn.disabled = false;
		}
	} );
}() );
