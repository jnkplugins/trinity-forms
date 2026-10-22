import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "country" );
        const { attributes } = props;
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ attributes }>
                    <select disabled style={ { width: "100%", padding: ".5rem" } }>
                        <option>{ attributes.placeholder || __( "Select a country…", "trinity-forms" ) }</option>
                    </select>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
