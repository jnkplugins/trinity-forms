import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { PanelBody, TextControl, TextareaControl, ToggleControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import { useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "consent" );
        const { attributes, setAttributes } = props;
        return (
            <div { ...useBlockProps( { className: "trinity-forms-field-block" } ) }>
                <InspectorControls>
                    <PanelBody title={ __( "Consent", "trinity-forms" ) }>
                        <TextControl label={ __( "Internal label", "trinity-forms" ) } value={ attributes.label || "" } onChange={ ( v ) => setAttributes( { label: v } ) } />
                        <TextareaControl label={ __( "Consent text", "trinity-forms" ) } value={ attributes.consentText || "" } onChange={ ( v ) => setAttributes( { consentText: v } ) } rows={ 3 } />
                        <ToggleControl label={ __( "Required", "trinity-forms" ) } checked={ !! attributes.required } onChange={ ( v ) => setAttributes( { required: v } ) } />
                    </PanelBody>
                </InspectorControls>
                <label>
                    <input type="checkbox" disabled />
                    { " " }{ attributes.consentText || "" }
                    { attributes.required && <span style={ { color: "#d63638" } }> *</span> }
                </label>
            </div>
        );
    },
    save() { return null; }
} );
