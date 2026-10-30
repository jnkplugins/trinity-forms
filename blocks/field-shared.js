import { useBlockProps, InspectorControls, useBlockEditContext } from "@wordpress/block-editor";
import { PanelBody, SelectControl, TextControl, ToggleControl, TextareaControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import { useEffect, useRef, useState } from "@wordpress/element";
import { select } from "@wordpress/data";
import { choiceValue } from "./choice-value";

// A field id IS its merge tag, so one of these would shadow the built-in tag of the
// same name and quietly stop resolving.
const RESERVED_IDS = [ "all_fields", "site_name", "site_url", "admin_email", "date", "ip", "entry_id" ];

function slugify( text ) {
    return String( text || "" )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, "_" )
        .replace( /^_+|_+$/g, "" )
        .slice( 0, 32 );
}

/**
 * Give a new field a readable id, derived from its label.
 *
 * These ids are what people type as merge tags. Generating "text_a1b2c3" meant the
 * tag was undiscoverable unless you clicked the field and read the sidebar — the
 * first thing a real user got wrong. "Email" now becomes {email}, a second one
 * {email_2}, matching what the visual builder already produced.
 */
export function useEnsureFieldId( attributes, setAttributes, prefix ) {
    const { clientId } = useBlockEditContext();

    useEffect( () => {
        if ( attributes.fieldId ) {
            return;
        }

        const flatten = ( blocks, acc ) => {
            ( blocks || [] ).forEach( ( b ) => {
                acc.push( b );
                flatten( b.innerBlocks, acc );
            } );
            return acc;
        };
        const all = flatten( select( "core/block-editor" ).getBlocks(), [] );

        const used = new Set( RESERVED_IDS );
        all.forEach( ( b ) => {
            if ( b.attributes && b.attributes.fieldId ) {
                used.add( b.attributes.fieldId );
            }
        } );

        const baseOf = ( b ) =>
            slugify( b.attributes && b.attributes.label ) ||
            String( b.name || "" ).replace( "trinity-forms/field-", "" ).replace( /-/g, "_" );
        const base = slugify( attributes.label ) || prefix;

        // Blocks inserted in the same tick all mount before any of them has written
        // an id, so "is it taken yet" is the wrong question — each would answer no
        // and they would collide, and a duplicate id means one field's value
        // overwrites the other's on submit. Claim a slot by position instead: count
        // the same-base fields ahead of me and skip that many free ids.
        let rank = 0;
        for ( const b of all ) {
            if ( b.clientId === clientId ) {
                break;
            }
            if ( ! ( b.attributes && b.attributes.fieldId ) && baseOf( b ) === base ) {
                rank++;
            }
        }

        let id = base;
        let n = 2;
        let skipped = 0;
        while ( used.has( id ) || skipped < rank ) {
            if ( ! used.has( id ) ) {
                skipped++;
            }
            id = base + "_" + n++;
        }
        setAttributes( { fieldId: id } );
    }, [] );
}

export function CommonControls( { attributes, setAttributes, extra } ) {
    return (
        <InspectorControls>
            <PanelBody title={ __( "Field", "trinity-forms" ) }>
                <TextControl
                    label={ __( "Field ID", "trinity-forms" ) }
                    help={ __( "Used in merge tags and entry export.", "trinity-forms" ) }
                    value={ attributes.fieldId || "" }
                    onChange={ ( v ) => setAttributes( { fieldId: v.replace( /[^a-z0-9_]/gi, "" ).toLowerCase() } ) }
                />
                <TextControl
                    label={ __( "Label", "trinity-forms" ) }
                    value={ attributes.label || "" }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                />
                { typeof attributes.placeholder !== "undefined" && (
                    <TextControl
                        label={ __( "Placeholder", "trinity-forms" ) }
                        value={ attributes.placeholder || "" }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                    />
                ) }
                <TextControl
                    label={ __( "Help text", "trinity-forms" ) }
                    value={ attributes.help || "" }
                    onChange={ ( v ) => setAttributes( { help: v } ) }
                />
                <ToggleControl
                    label={ __( "Required", "trinity-forms" ) }
                    checked={ !! attributes.required }
                    onChange={ ( v ) => setAttributes( { required: v } ) }
                />
                { extra }
            </PanelBody>

            <AdvancedFieldControls attributes={ attributes } setAttributes={ setAttributes } />
        </InspectorControls>
    );
}

/**
 * The settings every field type shares (Field_Registry::COMMON_ATTRIBUTES).
 *
 * The visual builder's field panel offers the same set over the same attributes,
 * so a form edited in one editor and reopened in the other keeps every setting.
 * Leaving this out would mean the block editor silently offered less.
 */
function AdvancedFieldControls( { attributes, setAttributes } ) {
    const set = ( key ) => ( v ) => setAttributes( { [ key ]: v } );

    return (
        <>
            <PanelBody title={ __( "Appearance", "trinity-forms" ) } initialOpen={ false }>
                <SelectControl
                    label={ __( "Field width", "trinity-forms" ) }
                    value={ attributes.fieldWidth || "" }
                    options={ [
                        { label: __( "Full width", "trinity-forms" ), value: "" },
                        { label: __( "One half", "trinity-forms" ), value: "50" },
                        { label: __( "One third", "trinity-forms" ), value: "33" },
                        { label: __( "One quarter", "trinity-forms" ), value: "25" },
                    ] }
                    onChange={ set( "fieldWidth" ) }
                />
                <SelectControl
                    label={ __( "Help text position", "trinity-forms" ) }
                    value={ attributes.descriptionPlacement || "" }
                    options={ [
                        { label: __( "Use the form's setting", "trinity-forms" ), value: "" },
                        { label: __( "Below the field", "trinity-forms" ), value: "below" },
                        { label: __( "Above the field", "trinity-forms" ), value: "above" },
                    ] }
                    onChange={ set( "descriptionPlacement" ) }
                />
                <TextControl
                    label={ __( "CSS class", "trinity-forms" ) }
                    help={ __( "Added to the field wrapper, for your own styling.", "trinity-forms" ) }
                    value={ attributes.cssClass || "" }
                    onChange={ set( "cssClass" ) }
                />
            </PanelBody>

            <PanelBody title={ __( "Advanced", "trinity-forms" ) } initialOpen={ false }>
                <TextControl
                    label={ __( "Default value", "trinity-forms" ) }
                    value={ attributes.defaultValue || "" }
                    onChange={ set( "defaultValue" ) }
                />
                <TextControl
                    label={ __( "Admin label", "trinity-forms" ) }
                    help={ __( "Shown instead of the label in entries and exports.", "trinity-forms" ) }
                    value={ attributes.adminLabel || "" }
                    onChange={ set( "adminLabel" ) }
                />
                <SelectControl
                    label={ __( "Visible to", "trinity-forms" ) }
                    value={ attributes.visibility || "everyone" }
                    options={ [
                        { label: __( "Everyone", "trinity-forms" ), value: "everyone" },
                        { label: __( "Logged-in users", "trinity-forms" ), value: "logged_in" },
                        { label: __( "Administrators only", "trinity-forms" ), value: "admin" },
                    ] }
                    onChange={ set( "visibility" ) }
                />
                <ToggleControl
                    label={ __( "Allow this field to be pre-filled from the URL", "trinity-forms" ) }
                    help={ __( "Off by default: a form whose every field can be set from a link is a form whose data you cannot trust.", "trinity-forms" ) }
                    checked={ !! attributes.allowPrefill }
                    onChange={ set( "allowPrefill" ) }
                />
                { !! attributes.allowPrefill && (
                    <TextControl
                        label={ __( "Parameter name", "trinity-forms" ) }
                        help={ __( "Defaults to the field ID. Also reads user_email, first_name, post_id and user_meta_<key>.", "trinity-forms" ) }
                        value={ attributes.prefillParam || "" }
                        onChange={ ( v ) => setAttributes( { prefillParam: v.replace( /[^a-z0-9_]/gi, "" ).toLowerCase() } ) }
                    />
                ) }
                <ToggleControl
                    label={ __( "No duplicates", "trinity-forms" ) }
                    help={ __( "Refuse a submission if another entry on this form already has the same value.", "trinity-forms" ) }
                    checked={ !! attributes.preventDuplicates }
                    onChange={ set( "preventDuplicates" ) }
                />
                <TextControl
                    label={ __( "Custom error message", "trinity-forms" ) }
                    value={ attributes.validationMessage || "" }
                    onChange={ set( "validationMessage" ) }
                />
                <TextControl
                    label={ __( "Autocomplete", "trinity-forms" ) }
                    help={ __( "The HTML autocomplete hint, e.g. email, given-name, organization.", "trinity-forms" ) }
                    value={ attributes.autocomplete || "" }
                    onChange={ set( "autocomplete" ) }
                />
            </PanelBody>
        </>
    );
}

export function FieldPreview( { attributes, children } ) {
    return (
        <div { ...useBlockProps( { className: "trinity-forms-field-block" } ) }>
            <label style={ { display: "block", fontWeight: 600 } }>
                { attributes.label || __( "Field", "trinity-forms" ) }
                { attributes.required && <span style={ { color: "#d63638" } }> *</span> }
            </label>
            { children }
            { attributes.help && <small style={ { color: "#50575e", display: "block", marginTop: 4 } }>{ attributes.help }</small> }
        </div>
    );
}

export { TextareaControl };

export function optionsToText( opts ) {
    return ( opts || [] ).map( ( o ) => o.label === o.value ? o.label : `${ o.label } | ${ o.value }` ).join( "\n" );
}

export function textToOptions( text ) {
    return text.split( "\n" ).map( ( l ) => l.trim() ).filter( Boolean ).map( ( line ) => {
        const [ label, value ] = line.split( "|" ).map( ( s ) => s.trim() );
        return { label: label || "", value: choiceValue( value || label ) };
    } );
}

/**
 * The "one option per line" textarea, with its own text state.
 *
 * The old control re-derived its text from the parsed options on every keystroke:
 * typing "Gold | gold" became "G | goldgold" (the label was re-serialised after the
 * first letter), Enter and pasted newlines vanished because empty lines are dropped
 * by the parser, so no choice field could get a real second option. The text the
 * author types is now kept as typed; the options attribute follows it, and the text
 * is only replaced when the options change from somewhere else (undo, a template).
 */
export function OptionsTextarea( { label, help, options, onChange, rows = 6 } ) {
    const [ text, setText ] = useState( () => optionsToText( options ) );
    const lastSent = useRef( JSON.stringify( options || [] ) );

    useEffect( () => {
        const incoming = JSON.stringify( options || [] );
        if ( incoming !== lastSent.current ) {
            lastSent.current = incoming;
            setText( optionsToText( options ) );
        }
    }, [ options ] );

    return (
        <TextareaControl
            label={ label }
            help={ help }
            rows={ rows }
            value={ text }
            onChange={ ( v ) => {
                setText( v );
                const parsed = textToOptions( v );
                lastSent.current = JSON.stringify( parsed );
                onChange( parsed );
            } }
        />
    );
}
