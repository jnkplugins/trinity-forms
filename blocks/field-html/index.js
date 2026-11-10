import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { PanelBody, TextareaControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import { useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "html" );
        const { attributes, setAttributes } = props;
        return (
            <div { ...useBlockProps( { className: "trinity-forms-field-block" } ) }>
                <InspectorControls>
                    <PanelBody title={ __( "HTML", "trinity-forms" ) }>
                        <TextareaControl
                            label={ __( "HTML markup", "trinity-forms" ) }
                            value={ attributes.html || "" }
                            onChange={ ( v ) => setAttributes( { html: v } ) }
                            rows={ 8 }
                        />
                    </PanelBody>
                </InspectorControls>
                { attributes.html ? (
                    <div dangerouslySetInnerHTML={ { __html: attributes.html } } />
                ) : (
                    // Editor-only hint: an empty HTML field prints nothing to visitors.
                    <p className="trinity-forms-field-block__placeholder" style={ { opacity: 0.6, fontStyle: "italic" } }>
                        { __( "Custom HTML: write the markup in the block settings. Visitors see nothing until you do.", "trinity-forms" ) }
                    </p>
                ) }
            </div>
        );
    },
    save() { return null; }
} );
