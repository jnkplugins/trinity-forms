import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "quantity" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <input type="number" disabled value={ props.attributes.min || 0 } style={ { width: "6rem", padding: ".5rem" } } />
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
