import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

const cell = { padding: ".5rem", width: "100%" };

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "address" );
        const { attributes } = props;
        return (
            <>
                <CommonControls { ...props } />
                <FieldPreview attributes={ attributes }>
                    <div style={ { display: "grid", gap: ".4rem" } }>
                        <input type="text" disabled placeholder={ __( "Street address", "trinity-forms" ) } style={ cell } />
                        <input type="text" disabled placeholder={ __( "Address line 2", "trinity-forms" ) } style={ cell } />
                        <div style={ { display: "flex", gap: ".4rem" } }>
                            <input type="text" disabled placeholder={ __( "City", "trinity-forms" ) } style={ cell } />
                            <input type="text" disabled placeholder={ __( "State / Region", "trinity-forms" ) } style={ cell } />
                        </div>
                        <div style={ { display: "flex", gap: ".4rem" } }>
                            <input type="text" disabled placeholder={ __( "Postal code", "trinity-forms" ) } style={ cell } />
                            <input type="text" disabled placeholder={ __( "Country", "trinity-forms" ) } style={ cell } />
                        </div>
                    </div>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
