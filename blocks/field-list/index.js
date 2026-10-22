import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "list" );
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ props.attributes }>
                    <table style={ { width: "100%", borderCollapse: "collapse" } }><tbody><tr>{ ( props.attributes.columns || [] ).map( ( c, i ) => <td key={ i } style={ { border: "1px solid #ddd", padding: ".35rem" } }>{ c.label || "\u2014" }</td> ) }</tr></tbody></table>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
