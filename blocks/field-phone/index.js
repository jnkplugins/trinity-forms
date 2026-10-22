import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import { ToggleControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "phone" );
        const { attributes, setAttributes } = props;
        return (
            <>
                <CommonControls
                    { ...props }
                    extra={
                        <ToggleControl
                            label={ __( "Require international format", "trinity-forms" ) }
                            checked={ !! attributes.intl }
                            onChange={ ( v ) => setAttributes( { intl: v } ) }
                        />
                    }
                />
                <FieldPreview attributes={ attributes }>
                    <input type="tel" disabled placeholder={ attributes.placeholder } style={ { width: "100%", padding: ".5rem" } } />
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
