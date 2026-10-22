import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "option" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <div>{ ( props.attributes.options || [] ).map( ( o, i ) => <div key={ i }>{ o.label }</div> ) }</div>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
