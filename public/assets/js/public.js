(function () {
    "use strict";

    function serialize( form ) {
        var fd = new FormData( form );
        var payload = { trinity_forms_fields: {} };
        fd.forEach( function ( value, key ) {
            // Match trinity_forms_fields[id], [id][] (array) and [id][sub] (composite
            // fields like name/address). $2 is undefined (simple), "" (array) or a sub-key.
            // Repeater rows: trinity_forms_fields[id][row][sub].
            var r = key.match( /^trinity_forms_fields\[([^\]]+)\]\[(\d+)\]\[([^\]]+)\]$/ );
            if ( r ) {
                var rows = payload.trinity_forms_fields[ r[1] ];
                if ( ! Array.isArray( rows ) ) { rows = payload.trinity_forms_fields[ r[1] ] = []; }
                var idx = parseInt( r[2], 10 );
                if ( ! rows[ idx ] || typeof rows[ idx ] !== "object" ) { rows[ idx ] = {}; }
                rows[ idx ][ r[3] ] = value;
                return;
            }
            var m = key.match( /^trinity_forms_fields\[([^\]]+)\](?:\[([^\]]*)\])?$/ );
            if ( m ) {
                var fid = m[1];
                var sub = m[2];
                if ( sub === undefined ) {
                    payload.trinity_forms_fields[ fid ] = value;
                } else if ( sub === "" ) {
                    if ( ! Array.isArray( payload.trinity_forms_fields[ fid ] ) ) {
                        payload.trinity_forms_fields[ fid ] = [];
                    }
                    payload.trinity_forms_fields[ fid ].push( value );
                } else {
                    if ( ! payload.trinity_forms_fields[ fid ] || typeof payload.trinity_forms_fields[ fid ] !== "object" || Array.isArray( payload.trinity_forms_fields[ fid ] ) ) {
                        payload.trinity_forms_fields[ fid ] = {};
                    }
                    payload.trinity_forms_fields[ fid ][ sub ] = value;
                }
            } else {
                payload[ key ] = value;
            }
        } );
        return payload;
    }

    function showMessages( form, msgs, type ) {
        var box = form.querySelector( ".trinity-forms-messages" );
        if ( ! box ) return;
        box.className = "trinity-forms-messages trinity-forms-" + ( type || "info" );
        box.textContent = "";
        if ( typeof msgs === "string" ) {
            box.textContent = msgs;
            return;
        }
        var ul = document.createElement( "ul" );
        ( msgs || [] ).forEach( function ( m ) {
            var li = document.createElement( "li" );
            li.textContent = m.message || String( m );
            ul.appendChild( li );
        } );
        box.appendChild( ul );
    }

    function clearFieldErrors( form ) {
        form.querySelectorAll( ".trinity-forms-field-error" ).forEach( function ( el ) { el.remove(); } );
        form.querySelectorAll( ".trinity-forms-field.has-error" ).forEach( function ( el ) { el.classList.remove( "has-error" ); } );
        var summary = form.querySelector( ".trinity-forms-summary" );
        if ( summary ) {
            summary.innerHTML = "";
            summary.hidden = true;
        }
    }

    function paintFieldErrors( form, errors ) {
        var summary = form.querySelector( ".trinity-forms-summary" );
        var listed = [];

        ( errors || [] ).forEach( function ( e ) {
            if ( ! e.field ) return;
            var control = form.querySelector( '[name^="trinity_forms_fields[' + e.field + ']"]' );
            if ( ! control ) return;
            var wrap = control.closest( ".trinity-forms-field" ) || control.parentNode;
            wrap.classList.add( "has-error" );
            var msg = document.createElement( "div" );
            msg.className = "trinity-forms-field-error";
            msg.textContent = e.message;
            wrap.appendChild( msg );

            // The label, not the field id — "Email" is what the person sees next to
            // the box they have to go and fix.
            var label = wrap.querySelector( "label" );
            listed.push( {
                text: ( label ? label.textContent.replace( /\*|\(optional\)|\(required\)/g, "" ).trim() + ": " : "" ) + e.message,
                wrap: wrap,
                control: control
            } );
        } );

        if ( ! summary ) {
            return;
        }

        summary.innerHTML = "";
        if ( ! listed.length ) {
            summary.hidden = true;
            return;
        }

        var heading = document.createElement( "p" );
        heading.textContent = listed.length === 1
            ? ( window.TrinityFormsI18n && window.TrinityFormsI18n.oneProblem ) || "There is one thing to fix:"
            : ( window.TrinityFormsI18n && window.TrinityFormsI18n.someProblems ) || "There are some things to fix:";
        summary.appendChild( heading );

        var ul = document.createElement( "ul" );
        listed.forEach( function ( item ) {
            var li = document.createElement( "li" );
            var a = document.createElement( "a" );
            a.href = "#";
            a.textContent = item.text;
            // The whole point of the summary on a long form: jump straight to the
            // field rather than hunt for it.
            a.addEventListener( "click", function ( e ) {
                e.preventDefault();
                item.wrap.scrollIntoView( { behavior: "smooth", block: "center" } );
                if ( item.control.focus ) item.control.focus();
            } );
            li.appendChild( a );
            ul.appendChild( li );
        } );
        summary.appendChild( ul );

        summary.hidden = false;
        summary.scrollIntoView( { behavior: "smooth", block: "nearest" } );
    }

    function hasFiles( form ) {
        var inputs = form.querySelectorAll( 'input[type="file"]' );
        for ( var i = 0; i < inputs.length; i++ ) {
            if ( inputs[ i ].files && inputs[ i ].files.length ) return true;
        }
        return false;
    }

    function submit( form ) {
        if ( form.dataset.submitting === "1" ) return;
        form.dataset.submitting = "1";
        clearFieldErrors( form );
        showMessages( form, "", "info" );

        var btn = form.querySelector( '[type="submit"]' );
        if ( btn ) btn.disabled = true;

        var url      = form.getAttribute( "action" );
        var nonce    = ( form.querySelector( '[name="_wpnonce"]' ) || {} ).value || ( window.TrinityFormsData && window.TrinityFormsData.nonce ) || "";
        var multipart = hasFiles( form );
        var headers  = { "Accept": "application/json", "X-WP-Nonce": nonce };
        var body;

        if ( multipart ) {
            body = new FormData( form );
        } else {
            headers[ "Content-Type" ] = "application/json";
            body = JSON.stringify( serialize( form ) );
        }

        fetch( url, {
            method: "POST",
            credentials: "same-origin",
            headers: headers,
            body: body
        } )
        .then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
        .then( function ( res ) {
            var body = res.body || {};
            if ( res.ok && body.success ) {
                if ( body.data && body.data.redirect ) {
                    window.location.assign( body.data.redirect );
                    return;
                }
                // Listeners (abandoned-form capture) start over for the next person.
                try { form.dispatchEvent( new CustomEvent( "trinity-forms:submitted" ) ); } catch ( e ) {}
                form.reset();
                // Server returns the form's configured message under data.confirmation.
                showMessages( form, ( body.data && ( body.data.confirmation || body.data.message ) ) || "Thanks — your submission was received.", "success" );
                form.classList.add( "is-submitted" );
            } else {
                paintFieldErrors( form, body.errors );
                showMessages( form, body.errors && body.errors.length ? body.errors : "Submission failed.", "error" );
            }
        } )
        .catch( function () {
            showMessages( form, "Network error — please try again.", "error" );
        } )
        .finally( function () {
            form.dataset.submitting = "0";
            if ( btn ) btn.disabled = false;
        } );
    }

    // Read the current value(s) of a referenced field by its field id. Returns a
    // string, or an array of strings for multi-value controls (checkbox groups,
    // multi-select). Mirrors how the server reads payload[ field ].
    function fieldValue( form, fieldId ) {
        var controls = form.querySelectorAll(
            '[name="trinity_forms_fields[' + fieldId + ']"], [name="trinity_forms_fields[' + fieldId + '][]"]'
        );
        if ( ! controls.length ) return "";

        var multi = [];
        var single = "";
        var sawSingle = false;
        controls.forEach( function ( c ) {
            var type = ( c.type || "" ).toLowerCase();
            if ( type === "checkbox" || type === "radio" ) {
                if ( c.checked ) {
                    multi.push( String( c.value ) );
                }
            } else if ( c.tagName === "SELECT" && c.multiple ) {
                Array.prototype.forEach.call( c.options, function ( o ) {
                    if ( o.selected ) multi.push( String( o.value ) );
                } );
            } else {
                single = String( c.value );
                sawSingle = true;
            }
        } );

        // A name ending in [] (or multiple checked controls) is array-valued.
        var isArrayName = controls[0] && /\[\]$/.test( controls[0].getAttribute( "name" ) || "" );
        if ( isArrayName || multi.length > 1 ) return multi;
        if ( multi.length === 1 && ! sawSingle ) return multi[0];
        return single;
    }

    function isEmptyVal( v ) {
        return v === "" || ( Array.isArray( v ) && v.length === 0 );
    }

    // Mirrors Conditional_Logic::compare() on the server.
    function compare( left, op, right ) {
        var rStr = right == null ? "" : String( right );
        var arr  = Array.isArray( left );
        switch ( op ) {
            case "equals":       return arr ? left.indexOf( rStr ) !== -1 : String( left ) === rStr;
            case "not_equals":   return arr ? left.indexOf( rStr ) === -1 : String( left ) !== rStr;
            case "contains":     return arr ? left.indexOf( rStr ) !== -1 : String( left ).indexOf( rStr ) !== -1;
            case "not_contains": return arr ? left.indexOf( rStr ) === -1 : String( left ).indexOf( rStr ) === -1;
            case "empty":        return isEmptyVal( left );
            case "not_empty":    return ! isEmptyVal( left );
            case "greater_than": return isFinite( +left ) && isFinite( +rStr ) && parseFloat( left ) > parseFloat( rStr );
            case "less_than":    return isFinite( +left ) && isFinite( +rStr ) && parseFloat( left ) < parseFloat( rStr );

            case "starts_with":  return ! arr && rStr !== "" && String( left ).indexOf( rStr ) === 0;
            case "ends_with":    return ! arr && rStr !== "" && String( left ).slice( -rStr.length ) === rStr;

            // Dates, not numbers: "2026-03-01" vs "2026-11-01" is meaningless to
            // greater_than, which refuses non-numeric values outright.
            case "is_before":
            case "is_after": {
                if ( arr || left === "" || rStr === "" ) return false;
                var lt = Date.parse( String( left ) );
                var rt = Date.parse( rStr );
                if ( isNaN( lt ) || isNaN( rt ) ) return false;
                return op === "is_before" ? lt < rt : lt > rt;
            }

            case "in_list":
            case "not_in_list": {
                var list = rStr.split( "," ).map( function ( s ) { return s.trim(); } )
                    .filter( function ( s ) { return s !== ""; } );
                var hit = arr
                    ? left.some( function ( v ) { return list.indexOf( String( v ) ) !== -1; } )
                    : list.indexOf( String( left ) ) !== -1;
                return op === "in_list" ? hit : ! hit;
            }

            case "matches_pattern": {
                if ( arr || rStr === "" ) return false;
                try {
                    return new RegExp( rStr ).test( String( left ) );
                } catch ( e ) {
                    // A broken pattern hides the field rather than throwing, matching
                    // the server's suppressed preg_match.
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * Evaluate one group, recursing into nested groups.
     *
     * Mirrors Conditional_Logic::evaluate_group(). A group has "conditions"; a leaf
     * has "field" — which is what allows "A and (B or C)".
     */
    function evaluateGroup( form, group, depth ) {
        depth = depth || 0;
        if ( depth > 10 ) return true;

        var conditions = group.conditions || [];
        if ( ! conditions.length ) return true;

        var results = conditions.map( function ( c ) {
            if ( c && c.conditions ) {
                return evaluateGroup( form, c, depth + 1 );
            }
            return compare( fieldValue( form, c.field || "" ), c.operator || "equals", c.value != null ? c.value : "" );
        } );

        return ( group.match === "any" )
            ? results.indexOf( true ) !== -1
            : results.indexOf( false ) === -1;
    }

    function evaluate( form, rules ) {
        if ( ! ( rules.conditions || [] ).length ) return true;
        var passed = evaluateGroup( form, rules, 0 );
        return ( rules.action === "hide" ) ? ! passed : passed;
    }

    function setVisibility( wrap, visible ) {
        wrap.style.display = visible ? "" : "none";
        if ( visible ) {
            wrap.removeAttribute( "aria-hidden" );
        } else {
            wrap.setAttribute( "aria-hidden", "true" );
        }
        // Disable hidden inputs so they don't submit (and re-enable when shown).
        wrap.querySelectorAll( "input, select, textarea" ).forEach( function ( el ) {
            if ( visible ) {
                if ( el.dataset.trinityFormsCondDisabled === "1" ) {
                    el.disabled = false;
                    delete el.dataset.trinityFormsCondDisabled;
                }
            } else if ( ! el.disabled ) {
                el.disabled = true;
                el.dataset.trinityFormsCondDisabled = "1";
            }
        } );
    }

    /**
     * Every field a rule set depends on, nested groups included.
     *
     * Walking only the top level meant a field inside "A and (B or C)" was never
     * watched, so changing it re-evaluated nothing and the rule appeared to be
     * ignored until something else on the form was touched.
     */
    function collectWatched( group, watched ) {
        ( group.conditions || [] ).forEach( function ( c ) {
            if ( ! c ) return;
            if ( c.conditions ) {
                collectWatched( c, watched );
            } else if ( c.field ) {
                watched[ c.field ] = true;
            }
        } );
    }

    function attachConditional( form ) {
        var wraps = form.querySelectorAll( "[data-trinity-forms-conditional]" );
        if ( ! wraps.length ) return;

        var entries = [];
        var watched = {};
        wraps.forEach( function ( wrap ) {
            var rules;
            try {
                rules = JSON.parse( wrap.getAttribute( "data-trinity-forms-conditional" ) || "{}" );
            } catch ( err ) {
                return;
            }
            if ( ! rules || ! rules.conditions || ! rules.conditions.length ) return;
            entries.push( { wrap: wrap, rules: rules } );
            collectWatched( rules, watched );
        } );
        if ( ! entries.length ) return;

        function recompute() {
            entries.forEach( function ( e ) {
                setVisibility( e.wrap, evaluate( form, e.rules ) );
            } );
        }

        // Re-evaluate when any control changes (covers referenced fields cheaply).
        form.addEventListener( "input", recompute );
        form.addEventListener( "change", recompute );

        // Initial pass: resolve the real visibility now (overrides the server's
        // conservative default-hidden state).
        recompute();
    }

    // Multi-step wizard. Shows one .trinity-forms-step at a time with Previous/Next,
    // validating the current step before advancing. Required fields in non-current
    // steps have their `required` attribute parked (not disabled — disabled fields
    // wouldn't submit) so native validation doesn't block on hidden steps; values
    // from every step are still submitted together at the end.
    function attachStepper( form ) {
        var steps = Array.prototype.slice.call( form.querySelectorAll( ".trinity-forms-step" ) );
        if ( steps.length < 2 ) return;

        var prevBtn   = form.querySelector( ".trinity-forms-prev" );
        var nextBtn   = form.querySelector( ".trinity-forms-next" );
        var submitBtn = form.querySelector( ".trinity-forms-submit" );
        var status    = form.querySelector( ".trinity-forms-step-status" );
        var current   = 0;

        function setStepRequired( step, on ) {
            step.querySelectorAll( "[required], [data-ff-required]" ).forEach( function ( el ) {
                if ( on ) {
                    if ( el.dataset.ffRequired === "1" ) { el.required = true; delete el.dataset.ffRequired; }
                } else if ( el.required ) {
                    el.required = false;
                    el.dataset.ffRequired = "1";
                }
            } );
        }

        function show( i ) {
            current = Math.max( 0, Math.min( i, steps.length - 1 ) );
            steps.forEach( function ( s, idx ) {
                if ( idx === current ) {
                    s.removeAttribute( "hidden" );
                    setStepRequired( s, true );
                } else {
                    s.setAttribute( "hidden", "" );
                    setStepRequired( s, false );
                }
            } );
            var last = current === steps.length - 1;
            if ( prevBtn )   prevBtn.hidden   = current === 0;
            if ( nextBtn )   nextBtn.hidden   = last;
            if ( submitBtn ) submitBtn.hidden = ! last;
            if ( status )    status.textContent = "Step " + ( current + 1 ) + " of " + steps.length;
            var first = steps[ current ].querySelector( "input, select, textarea" );
            if ( first ) { try { first.focus(); } catch ( e ) {} }
        }

        function validateCurrent() {
            var controls = steps[ current ].querySelectorAll( "input, select, textarea" );
            clearFieldErrors( form );
            for ( var i = 0; i < controls.length; i++ ) {
                var el = controls[ i ];
                if ( el.disabled ) continue;
                if ( typeof el.checkValidity === "function" && ! el.checkValidity() ) {
                    // The native bubble alone is easy to miss (and some browsers and
                    // embedded views never show it), which left "Next" looking dead on
                    // a one-question screen. Mark the field the way a failed submit does.
                    var wrap = el.closest( ".trinity-forms-field" );
                    if ( wrap ) {
                        wrap.classList.add( "has-error" );
                        var p = document.createElement( "p" );
                        p.className = "trinity-forms-field-error";
                        p.setAttribute( "role", "alert" );
                        p.textContent = el.validationMessage || "This field is required.";
                        wrap.appendChild( p );
                    }
                    el.setAttribute( "aria-invalid", "true" );
                    if ( typeof el.focus === "function" ) el.focus();
                    if ( typeof el.reportValidity === "function" ) el.reportValidity();
                    return false;
                }
                el.removeAttribute( "aria-invalid" );
            }
            return true;
        }

        form.__ffIsLast = function () { return current === steps.length - 1; };
        form.__ffNext   = function () { if ( validateCurrent() ) show( current + 1 ); };

        if ( nextBtn ) nextBtn.addEventListener( "click", function () { form.__ffNext(); } );
        if ( prevBtn ) prevBtn.addEventListener( "click", function () { show( current - 1 ); } );

        show( 0 );
    }

    /**
     * Ask for the resume email inside the form rather than with window.prompt():
     * a native dialog cannot be styled or translated by the theme, and embedded /
     * in-app browsers (and people who block dialogs) suppress it, which silently
     * broke save & continue.
     */
    function askResumeEmail( form, done ) {
        var existing = form.querySelector( ".trinity-forms-resume-ask" );
        if ( existing ) {
            var again = existing.querySelector( "input" );
            if ( again ) again.focus();
            return;
        }
        var box = document.createElement( "div" );
        box.className = "trinity-forms-resume-ask";
        box.setAttribute( "role", "group" );
        var uid = "tf-resume-email-" + Math.random().toString( 36 ).slice( 2 );
        box.innerHTML =
            '<label for="' + uid + '">Email me a link to finish this form later</label>' +
            '<input type="email" id="' + uid + '" autocomplete="email" required />' +
            '<button type="button" class="trinity-forms-resume-send">Send link</button> ' +
            '<button type="button" class="trinity-forms-resume-cancel">Cancel</button>';
        var anchor = form.querySelector( ".trinity-forms-save-progress" );
        if ( anchor && anchor.parentNode ) {
            anchor.parentNode.insertBefore( box, anchor.nextSibling );
        } else {
            form.appendChild( box );
        }
        var input = box.querySelector( "input" );
        function send() {
            var value = ( input.value || "" ).trim();
            if ( ! value || ( input.checkValidity && ! input.checkValidity() ) ) {
                input.setAttribute( "aria-invalid", "true" );
                showMessages( form, "Please enter a valid email address.", "error" );
                input.focus();
                return;
            }
            box.parentNode.removeChild( box );
            done( value );
        }
        box.querySelector( ".trinity-forms-resume-send" ).addEventListener( "click", send );
        box.querySelector( ".trinity-forms-resume-cancel" ).addEventListener( "click", function () {
            box.parentNode.removeChild( box );
        } );
        input.addEventListener( "keydown", function ( ev ) {
            // Enter would submit the whole form; here it means "send the link".
            if ( ev.key === "Enter" ) { ev.preventDefault(); send(); }
        } );
        input.focus();
    }

    function saveProgress( form ) {
        var url   = form.dataset.saveUrl;
        if ( ! url ) return;
        askResumeEmail( form, function ( email ) { sendProgress( form, url, email ); } );
    }

    function sendProgress( form, url, email ) {

        var nonce = ( form.querySelector( '[name="_wpnonce"]' ) || {} ).value || ( window.TrinityFormsData && window.TrinityFormsData.nonce ) || "";
        var payload = serialize( form );
        payload.email = email;

        showMessages( form, "Saving…", "info" );
        fetch( url, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type":  "application/json",
                "Accept":        "application/json",
                "X-WP-Nonce":    nonce
            },
            body: JSON.stringify( payload )
        } )
        .then( function ( r ) { return r.json(); } )
        .then( function ( res ) {
            if ( res && res.success ) {
                var msg = res.data && res.data.emailed
                    ? "Saved. We've emailed you a link to resume."
                    : "Saved. Bookmark this URL to resume: " + ( res.data && res.data.resume_url );
                showMessages( form, msg, "success" );
            } else {
                showMessages( form, "Could not save progress.", "error" );
            }
        } )
        .catch( function () { showMessages( form, "Network error.", "error" ); } );
    }

    document.addEventListener( "click", function ( e ) {
        var btn = e.target;
        if ( ! btn || ! btn.classList || ! btn.classList.contains( "trinity-forms-save-progress" ) ) return;
        e.preventDefault();
        var form = btn.closest( ".trinity-forms-form" );
        if ( form ) saveProgress( form );
    } );

    document.addEventListener( "submit", function ( e ) {
        var form = e.target;
        if ( ! form || ! form.classList || ! form.classList.contains( "trinity-forms-form" ) ) return;
        // Forms that post back to their own page (a Trinity View edit form) keep the
        // browser's normal submit.
        if ( form.hasAttribute( "data-trinity-forms-native" ) ) return;
        e.preventDefault();
        // On a multi-step form, a submit before the last step (e.g. Enter key)
        // advances to the next step instead of submitting.
        if ( typeof form.__ffIsLast === "function" && ! form.__ffIsLast() ) {
            if ( typeof form.__ffNext === "function" ) form.__ffNext();
            return;
        }
        submit( form );
    } );

    // --- Calculation fields (Pro): live client-side preview ---
    // The server recomputes the value authoritatively at save time; this only
    // updates the on-screen display. Safe recursive-descent eval (no eval()).
    function tfNum( v ) { var f = parseFloat( v ); return isFinite( f ) ? f : 0; }
    /**
     * Mirrors the server-side formula evaluator in the premium build.
     *
     * Both halves are hand-written parsers rather than eval()/new Function(): a
     * formula is author input, and the two must agree because this one produces
     * what the visitor sees while the PHP one produces what gets stored.
     *
     * (Deliberately no class name here — bin/build.sh greps the free tree for
     * premium symbols and fails the Lite build on a match, comments included.)
     */
    var TF_FUNCTIONS = {
        round: function ( a ) { var d = a.length > 1 ? ( a[ 1 ] | 0 ) : 0; var m = Math.pow( 10, d ); return Math.round( a[ 0 ] * m ) / m; },
        ceil:  function ( a ) { return Math.ceil( a[ 0 ] ); },
        floor: function ( a ) { return Math.floor( a[ 0 ] ); },
        abs:   function ( a ) { return Math.abs( a[ 0 ] ); },
        min:   function ( a ) { return Math.min.apply( null, a ); },
        max:   function ( a ) { return Math.max.apply( null, a ); },
        sum:   function ( a ) { return a.reduce( function ( x, y ) { return x + y; }, 0 ); },
        avg:   function ( a ) { return a.reduce( function ( x, y ) { return x + y; }, 0 ) / a.length; },
        "if":  function ( a ) { return a[ 0 ] !== 0 ? a[ 1 ] : a[ 2 ]; }
    };
    var TF_ARITY = { ceil: 1, floor: 1, abs: 1, "if": 3 };

    function tfEval( expr ) {
        var s = expr, i = 0, n = s.length;
        function ws() { while ( i < n && /\s/.test( s[ i ] ) ) i++; }
        function num() { ws(); var st = i; while ( i < n && /[0-9.]/.test( s[ i ] ) ) i++; if ( i === st ) throw 0; return parseFloat( s.slice( st, i ) ); }

        function call() {
            var st = i;
            while ( i < n && /[A-Za-z_]/.test( s[ i ] ) ) i++;
            var name = s.slice( st, i ).toLowerCase();
            if ( ! Object.prototype.hasOwnProperty.call( TF_FUNCTIONS, name ) ) throw 0;
            ws();
            if ( s[ i ] !== "(" ) throw 0;
            i++;
            var args = [];
            ws();
            if ( s[ i ] !== ")" ) {
                while ( true ) {
                    args.push( expr1() );
                    ws();
                    if ( s[ i ] === "," ) { i++; continue; }
                    break;
                }
            }
            ws();
            if ( s[ i ] !== ")" ) throw 0;
            i++;
            if ( ! args.length ) throw 0;
            if ( TF_ARITY[ name ] && args.length !== TF_ARITY[ name ] ) throw 0;
            return TF_FUNCTIONS[ name ]( args );
        }

        function factor() {
            ws();
            var c = s[ i ];
            if ( c === "-" ) { i++; return -factor(); }
            if ( c === "+" ) { i++; return factor(); }
            if ( c === "(" ) { i++; var v = expr1(); ws(); if ( s[ i ] !== ")" ) throw 0; i++; return v; }
            if ( /[A-Za-z_]/.test( c || "" ) ) { return call(); }
            return num();
        }
        function term() { var v = factor(); while ( true ) { ws(); var c = s[ i ]; if ( c === "*" ) { i++; v *= factor(); } else if ( c === "/" ) { i++; var d = factor(); if ( d === 0 ) throw 0; v /= d; } else break; } return v; }
        function additive() { var v = term(); while ( true ) { ws(); var c = s[ i ]; if ( c === "+" ) { i++; v += term(); } else if ( c === "-" ) { i++; v -= term(); } else break; } return v; }

        // One comparison per expression, two-character operators first so ">=" is
        // not read as ">" plus a stray "=".
        function expr1() {
            var left = additive();
            ws();
            var two = s.substr( i, 2 );
            if ( two === ">=" || two === "<=" || two === "==" || two === "!=" ) {
                i += 2;
                var r2 = additive();
                if ( two === ">=" ) return left >= r2 ? 1 : 0;
                if ( two === "<=" ) return left <= r2 ? 1 : 0;
                if ( two === "==" ) return left === r2 ? 1 : 0;
                return left !== r2 ? 1 : 0;
            }
            var c = s[ i ];
            if ( c === ">" || c === "<" ) {
                i++;
                var r1 = additive();
                return c === ">" ? ( left > r1 ? 1 : 0 ) : ( left < r1 ? 1 : 0 );
            }
            return left;
        }

        var r = expr1(); ws(); if ( i < n ) throw 0; return isFinite( r ) ? r : null;
    }
    function attachCalc( form ) {
        var calcs = form.querySelectorAll( ".trinity-forms-calc" );
        if ( ! calcs.length ) return;
        function fieldVal( fid ) {
            var checked = form.querySelector( '[name="trinity_forms_fields[' + fid + ']"]:checked' );
            var input   = checked || form.querySelector( '[name="trinity_forms_fields[' + fid + ']"]' );
            return tfNum( input ? input.value : 0 );
        }
        function recompute() {
            calcs.forEach( function ( el ) {
                var formula = el.getAttribute( "data-formula" ) || "";
                if ( ! formula ) return;
                var resolved = formula.replace( /\{([a-zA-Z0-9_]+)\}/g, function ( _, fid ) { return String( fieldVal( fid ) ); } );
                var val;
                try { val = tfEval( resolved ); } catch ( e ) { val = null; }
                var dec = parseInt( el.getAttribute( "data-decimals" ) || "2", 10 );
                if ( isNaN( dec ) ) dec = 2;
                var text = ( val === null || val === undefined ) ? "" : val.toFixed( dec );
                var out = el.querySelector( ".trinity-forms-calc-out" );
                var hidden = el.querySelector( 'input[type="hidden"]' );
                if ( out ) out.textContent = text;
                if ( hidden ) hidden.value = text;
            } );
        }
        form.addEventListener( "input", recompute );
        form.addEventListener( "change", recompute );
        recompute();
    }

    /**
     * Live order total.
     *
     * Display only. Pricing::calculate() recomputes every amount server-side from
     * the form's saved configuration, so editing anything here changes what the
     * visitor sees and nothing that is stored or charged.
     */
    function attachPricing( form ) {
        var totalInput = form.querySelector( "input[data-total]" );
        if ( ! totalInput ) {
            return;
        }
        var display  = form.querySelector( ".trinity-forms-total-display" );
        var currency = totalInput.getAttribute( "data-currency" ) || "";

        /** The quantity bound to a product field, defaulting to one. */
        function quantityFor( productId ) {
            if ( ! productId ) {
                return 1;
            }
            var qty = form.querySelector( 'input[data-product="' + productId + '"]' );
            if ( ! qty ) {
                return 1;
            }
            var n = parseInt( qty.value, 10 );
            return isNaN( n ) ? 0 : Math.max( 0, n );
        }

        function recompute() {
            var total = 0;

            // A product is either a fixed price (hidden input + .trinity-forms-price)
            // or the chosen radio / selected option.
            form.querySelectorAll( ".trinity-forms-field-product" ).forEach( function ( wrap ) {
                var id = wrap.getAttribute( "data-field-id" );
                var priced = wrap.querySelector( "[data-price]:checked, .trinity-forms-price[data-price]" );
                if ( ! priced ) {
                    var sel = wrap.querySelector( "select" );
                    if ( sel && sel.selectedOptions.length ) {
                        priced = sel.selectedOptions[ 0 ];
                    }
                }
                if ( priced ) {
                    total += ( parseFloat( priced.getAttribute( "data-price" ) ) || 0 ) * quantityFor( id );
                }
            } );

            // Options ride on their product's quantity: two shirts with gift wrap is
            // two lots of gift wrap.
            form.querySelectorAll( ".trinity-forms-field-option [data-price]:checked" ).forEach( function ( el ) {
                total += ( parseFloat( el.getAttribute( "data-price" ) ) || 0 )
                    * quantityFor( el.getAttribute( "data-product" ) || "" );
            } );

            form.querySelectorAll( ".trinity-forms-field-shipping" ).forEach( function ( wrap ) {
                var priced = wrap.querySelector( "[data-price]:checked, .trinity-forms-price[data-price]" );
                if ( priced ) {
                    total += parseFloat( priced.getAttribute( "data-price" ) ) || 0;
                }
            } );

            if ( total < 0 ) {
                total = 0;
            }
            totalInput.value = total.toFixed( 2 );
            if ( display ) {
                display.textContent = currency + total.toFixed( 2 );
            }
        }

        form.addEventListener( "input", recompute );
        form.addEventListener( "change", recompute );
        recompute();
    }

    /**
     * Add / remove rows on a List field.
     *
     * Row indexes in the input names are renumbered after every change, because PHP
     * reads them as array keys and a gap would leave a hole in the stored list.
     */
    function attachLists( form ) {
        form.querySelectorAll( ".trinity-forms-field-list" ).forEach( function ( wrap ) {
            var table = wrap.querySelector( "table.trinity-forms-list" );
            var addBtn = wrap.querySelector( ".trinity-forms-list-add" );
            if ( ! table || ! addBtn ) {
                return;
            }
            var body = table.querySelector( "tbody" );

            function renumber() {
                Array.prototype.forEach.call( body.rows, function ( row, i ) {
                    row.querySelectorAll( "input[name]" ).forEach( function ( input ) {
                        input.name = input.name.replace( /\[\d+\]/, "[" + i + "]" );
                    } );
                } );
            }

            addBtn.addEventListener( "click", function () {
                var last = body.rows[ body.rows.length - 1 ];
                if ( ! last ) {
                    return;
                }
                var clone = last.cloneNode( true );
                clone.querySelectorAll( "input" ).forEach( function ( input ) {
                    input.value = "";
                } );
                body.appendChild( clone );
                renumber();
            } );

            wrap.addEventListener( "click", function ( e ) {
                if ( ! e.target.classList.contains( "trinity-forms-list-remove" ) ) {
                    return;
                }
                // Never remove the last row: an empty table has no row to clone from,
                // so "Add row" would stop working. Clear it instead.
                if ( body.rows.length > 1 ) {
                    e.target.closest( "tr" ).remove();
                    renumber();
                } else {
                    body.rows[ 0 ].querySelectorAll( "input" ).forEach( function ( i ) { i.value = ""; } );
                }
            } );
        } );
    }

    /**
     * Dependent dropdowns.
     *
     * The whole dataset arrives once in a data attribute and is filtered here, so
     * changing a level is instant rather than a network round-trip. The server
     * re-checks the submitted combination against the same rows, so a hand-crafted
     * POST cannot invent one.
     */
    function attachChained( form ) {
        form.querySelectorAll( ".trinity-forms-chained" ).forEach( function ( wrap ) {
            var data;
            try {
                data = JSON.parse( wrap.getAttribute( "data-chained" ) || "{}" );
            } catch ( e ) {
                return;
            }
            var headers = data.headers || [];
            var rows = data.rows || [];
            if ( ! headers.length ) {
                return;
            }

            var selects = headers.map( function ( _, i ) {
                return wrap.querySelector( 'select[data-level="' + i + '"]' );
            } );

            /** Rows still possible given every level above `level`. */
            function rowsMatching( level ) {
                return rows.filter( function ( row ) {
                    for ( var i = 0; i < level; i++ ) {
                        if ( ! selects[ i ] || selects[ i ].value === "" ) {
                            return false;
                        }
                        if ( String( row[ i ] ) !== selects[ i ].value ) {
                            return false;
                        }
                    }
                    return true;
                } );
            }

            function fill( level ) {
                var select = selects[ level ];
                if ( ! select ) {
                    return;
                }
                var previous = select.value;
                var seen = [];

                rowsMatching( level ).forEach( function ( row ) {
                    var v = String( row[ level ] );
                    if ( v !== "" && seen.indexOf( v ) === -1 ) {
                        seen.push( v );
                    }
                } );

                // Rebuild from the placeholder down, keeping the current answer only
                // if it survived the change above it.
                var placeholder = select.options[ 0 ];
                select.innerHTML = "";
                if ( placeholder ) {
                    select.appendChild( placeholder );
                }
                seen.forEach( function ( v ) {
                    var opt = document.createElement( "option" );
                    opt.value = v;
                    opt.textContent = v;
                    select.appendChild( opt );
                } );
                select.value = seen.indexOf( previous ) !== -1 ? previous : "";

                // A level with nothing to choose from is noise, not a control.
                select.disabled = seen.length === 0;
            }

            selects.forEach( function ( select, level ) {
                if ( ! select ) {
                    return;
                }
                select.addEventListener( "change", function () {
                    for ( var i = level + 1; i < selects.length; i++ ) {
                        fill( i );
                    }
                } );
            } );

            for ( var i = 1; i < selects.length; i++ ) {
                fill( i );
            }
        } );
    }

    /**
     * Abandoned-form capture.
     *
     * Only runs on forms whose owner switched it on. Debounced hard and sent with
     * keepalive so leaving the page still delivers the last state — the whole point
     * is the submission that never happened.
     */
    function attachPartials( form ) {
        var url = form.getAttribute( "data-partial-url" );
        if ( ! url ) {
            return;
        }

        var consentNeeded = form.getAttribute( "data-partial-consent" ) === "1";
        var consented = ! consentNeeded;
        var token = "";
        var timer = null;
        var lastSent = "";

        // A per-visit id so repeated beacons update one row instead of leaving a
        // trail of half-finished entries.
        try {
            token = window.sessionStorage.getItem( "tf_partial_" + ( form.getAttribute( "data-form-id" ) || "" ) ) || "";
        } catch ( e ) {
            token = "";
        }
        if ( ! token ) {
            token = String( Date.now() ) + Math.random().toString( 36 ).slice( 2, 10 );
            try {
                window.sessionStorage.setItem( "tf_partial_" + ( form.getAttribute( "data-form-id" ) || "" ), token );
            } catch ( e ) {}
        }

        // Carried on the real submission so the server can drop the partial rather
        // than leaving a ghost beside every completed entry.
        var hidden = document.createElement( "input" );
        hidden.type = "hidden";
        hidden.name = "partial_token";
        hidden.value = token;
        form.appendChild( hidden );

        // After a real submission the old token must not be reused: the next person
        // on this device would otherwise overwrite the same partial row.
        form.addEventListener( "trinity-forms:submitted", function () {
            token = String( Date.now() ) + Math.random().toString( 36 ).slice( 2, 10 );
            hidden.value = token;
            lastSent = "";
            try {
                window.sessionStorage.setItem( "tf_partial_" + ( form.getAttribute( "data-form-id" ) || "" ), token );
            } catch ( e ) {}
        } );

        if ( consentNeeded ) {
            // Consent is whatever the form already asks: a ticked consent field is
            // taken as permission, and nothing is captured until one exists.
            form.addEventListener( "change", function () {
                var box = form.querySelector( ".trinity-forms-field-consent input[type=checkbox]" );
                consented = !! ( box && box.checked );
            } );
        }

        function collect() {
            var data = new FormData( form );
            var out = {};
            data.forEach( function ( value, key ) {
                var m = key.match( /^trinity_forms_fields\[([^\]]+)\](.*)$/ );
                if ( ! m ) return;
                if ( m[ 2 ] ) {
                    // Composite or multi-value field: keep the shape the server expects.
                    out[ m[ 1 ] ] = out[ m[ 1 ] ] || {};
                    var sub = m[ 2 ].replace( /[\[\]]/g, "" );
                    if ( sub === "" ) {
                        out[ m[ 1 ] ] = value;
                    } else {
                        out[ m[ 1 ] ][ sub ] = value;
                    }
                } else {
                    out[ m[ 1 ] ] = value;
                }
            } );
            return out;
        }

        function send() {
            if ( ! consented ) {
                return;
            }
            var values = collect();
            var body = JSON.stringify( { trinity_forms_fields: values, partial_token: token } );
            if ( body === lastSent ) {
                return;
            }
            lastSent = body;

            var nonceInput = form.querySelector( 'input[name="_wpnonce"]' );
            fetch( url, {
                method: "POST",
                credentials: "same-origin",
                keepalive: true,
                headers: {
                    "Content-Type": "application/json",
                    "X-WP-Nonce": nonceInput ? nonceInput.value : ""
                },
                body: body
            } ).catch( function () {
                // Silent by design: this is a background convenience and must never
                // put an error in front of somebody filling in a form.
            } );
        }

        form.addEventListener( "input", function () {
            clearTimeout( timer );
            timer = setTimeout( send, 2000 );
        } );
        // Leaving is the moment that matters most, so flush immediately.
        document.addEventListener( "visibilitychange", function () {
            if ( document.visibilityState === "hidden" ) {
                clearTimeout( timer );
                send();
            }
        } );
    }

    /**
     * Repeater (Pro): the server renders an empty container with the sub-field
     * schema in data-template; nothing drew rows from it, so the field showed only
     * its label. Rows are drawn here with Add / Remove.
     */
    function attachRepeaters( form ) {
        form.querySelectorAll( ".trinity-forms-repeater[data-template]" ).forEach( function ( box ) {
            if ( box.__tfRepeater ) return;
            box.__tfRepeater = true;
            var subs;
            try { subs = JSON.parse( box.getAttribute( "data-template" ) || "[]" ); } catch ( e ) { subs = []; }
            if ( ! Array.isArray( subs ) || ! subs.length ) return;
            // A second form on the page has prefixed ids; the field name uses the original.
            var fieldId = box.getAttribute( "data-tf-base-id" ) || box.id;
            var addText = box.getAttribute( "data-add-label" ) || "Add item";
            var list = document.createElement( "div" );
            list.className = "trinity-forms-repeater-rows";
            box.appendChild( list );
            var count = 0;
            function addRow() {
                var i = count++;
                var row = document.createElement( "div" );
                row.className = "trinity-forms-repeater-row";
                subs.forEach( function ( sub ) {
                    if ( ! sub || ! sub.id ) return;
                    var type = [ "text", "email", "number", "tel", "url", "date", "textarea" ].indexOf( sub.type ) >= 0 ? sub.type : "text";
                    var uid = box.id + "-" + i + "-" + sub.id;
                    var label = document.createElement( "label" );
                    label.setAttribute( "for", uid );
                    label.textContent = sub.label || sub.id;
                    var input = document.createElement( type === "textarea" ? "textarea" : "input" );
                    if ( type !== "textarea" ) input.type = type;
                    input.id = uid;
                    input.name = "trinity_forms_fields[" + fieldId + "][" + i + "][" + sub.id + "]";
                    if ( sub.required ) input.required = true;
                    var cell = document.createElement( "div" );
                    cell.className = "trinity-forms-repeater-cell";
                    cell.appendChild( label );
                    cell.appendChild( input );
                    row.appendChild( cell );
                } );
                var remove = document.createElement( "button" );
                remove.type = "button";
                remove.className = "trinity-forms-repeater-remove";
                remove.textContent = "Remove";
                remove.addEventListener( "click", function () { row.parentNode.removeChild( row ); } );
                row.appendChild( remove );
                list.appendChild( row );
            }
            var add = document.createElement( "button" );
            add.type = "button";
            add.className = "trinity-forms-repeater-add";
            add.textContent = addText;
            add.addEventListener( "click", addRow );
            box.appendChild( add );
            addRow();
        } );
    }

    document.addEventListener( "DOMContentLoaded", function () {
        document.querySelectorAll( ".trinity-forms-form" ).forEach( function ( form ) {
            attachRepeaters( form );
            attachConditional( form );
            attachStepper( form );
            attachCalc( form );
            attachPricing( form );
            attachLists( form );
            attachChained( form );
            attachPartials( form );
        } );
    } );
})();
