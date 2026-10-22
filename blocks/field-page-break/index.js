import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { PanelBody, TextControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import { useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "page" );
        const { attributes, setAttributes } = props;
        return (
            <div { ...useBlockProps( { className: "trinity-forms-field-block trinity-forms-page-break" } ) } style={ { borderTop: "2px dashed #aaa", margin: "16px 0", padding: "8px 0", textAlign: "center", color: "#50575e" } }>
                <InspectorControls>
                    <PanelBody title={ __( "Page break", "trinity-forms" ) }>
                        <TextControl label={ __( "Next button text", "trinity-forms" ) } value={ attributes.nextText || "" } onChange={ ( v ) => setAttributes( { nextText: v } ) } />
                        <TextControl label={ __( "Back button text", "trinity-forms" ) } value={ attributes.prevText || "" } onChange={ ( v ) => setAttributes( { prevText: v } ) } />
                    </PanelBody>
                </InspectorControls>
                — { __( "Page break", "trinity-forms" ) } —
            </div>
        );
    },
    save() { return null; }
} );
