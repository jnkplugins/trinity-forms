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
                <div dangerouslySetInnerHTML={ { __html: attributes.html || "" } } />
            </div>
        );
    },
    save() { return null; }
} );
