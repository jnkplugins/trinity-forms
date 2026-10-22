import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "image_choice" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <div style={ { display: "flex", gap: ".5rem", flexWrap: "wrap" } }>{ ( props.attributes.options || [] ).map( ( o, i ) => <span key={ i } style={ { border: "1px solid #ddd", borderRadius: 4, padding: ".4rem .6rem" } }>{ o.label }</span> ) }</div>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
