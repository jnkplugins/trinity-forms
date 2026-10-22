import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { PanelBody, TextControl, TextareaControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import { useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "section" );
        const { attributes, setAttributes } = props;
        return (
            <div { ...useBlockProps( { className: "trinity-forms-field-block" } ) }>
                <InspectorControls>
                    <PanelBody title={ __( "Section", "trinity-forms" ) }>
                        <TextControl label={ __( "Heading", "trinity-forms" ) } value={ attributes.label || "" } onChange={ ( v ) => setAttributes( { label: v } ) } />
                        <TextareaControl label={ __( "Description", "trinity-forms" ) } value={ attributes.description || "" } onChange={ ( v ) => setAttributes( { description: v } ) } />
                    </PanelBody>
                </InspectorControls>
                <h3 style={ { margin: "1rem 0 .25rem" } }>{ attributes.label }</h3>
                { attributes.description && <p style={ { color: "#50575e" } }>{ attributes.description }</p> }
            </div>
        );
    },
    save() { return null; }
} );
