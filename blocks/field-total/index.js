import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "total" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <p style={ { margin: 0, fontWeight: 700, fontSize: "1.1em" } }>{ "\u2014" }</p>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
