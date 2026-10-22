import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { PanelBody, TextControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import { useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "hidden" );
        const { attributes, setAttributes } = props;
        return (
            <div { ...useBlockProps( { className: "trinity-forms-field-block trinity-forms-field-hidden" } ) }>
                <InspectorControls>
                    <PanelBody title={ __( "Hidden field", "trinity-forms" ) }>
                        <TextControl
                            label={ __( "Field ID", "trinity-forms" ) }
                            value={ attributes.fieldId || "" }
                            onChange={ ( v ) => setAttributes( { fieldId: v.replace( /[^a-z0-9_]/gi, "" ).toLowerCase() } ) }
                        />
                        <TextControl
                            label={ __( "Internal label", "trinity-forms" ) }
                            value={ attributes.label || "" }
                            onChange={ ( v ) => setAttributes( { label: v } ) }
                        />
                        <TextControl
                            label={ __( "Default value", "trinity-forms" ) }
                            value={ attributes.default || "" }
                            onChange={ ( v ) => setAttributes( { default: v } ) }
                        />
                    </PanelBody>
                </InspectorControls>
                <em style={ { color: "#50575e" } }>
                    { __( "Hidden", "trinity-forms" ) }: { attributes.fieldId || "—" }
                    { attributes.default ? ` = ${ attributes.default }` : "" }
                </em>
            </div>
        );
    },
    save() { return null; }
} );
