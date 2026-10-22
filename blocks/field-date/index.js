import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "date" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <input type="date" disabled style={ { width: "100%", padding: ".5rem" } } />
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
