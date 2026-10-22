import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "text" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <input type="text" disabled placeholder={ props.attributes.placeholder } style={ { width: "100%", padding: ".5rem" } } />
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
