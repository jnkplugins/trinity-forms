import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "name" );
        const { attributes } = props;
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ attributes }>
                    <div style={ { display: "flex", gap: ".5rem" } }>
                        <input type="text" disabled placeholder={ __( "First", "trinity-forms" ) } style={ { flex: 1, padding: ".5rem" } } />
                        <input type="text" disabled placeholder={ __( "Last", "trinity-forms" ) } style={ { flex: 1, padding: ".5rem" } } />
                    </div>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
