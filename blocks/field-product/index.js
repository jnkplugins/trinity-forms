import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "product" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <p style={ { margin: 0, fontWeight: 600 } }>{ props.attributes.productType === "single" ? props.attributes.price : ( props.attributes.options || [] ).length + " choices" }</p>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
