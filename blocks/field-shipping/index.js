import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "shipping" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <p style={ { margin: 0 } }>{ ( props.attributes.options || [] ).length ? ( props.attributes.options || [] ).length + " methods" : props.attributes.price }</p>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
