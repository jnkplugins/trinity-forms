import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "multiselect" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <select multiple disabled size={ 3 } style={ { width: "100%", padding: ".5rem" } }>{ ( props.attributes.options || [] ).map( ( o, i ) => <option key={ i }>{ o.label }</option> ) }</select>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
