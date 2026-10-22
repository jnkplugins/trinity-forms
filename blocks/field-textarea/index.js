import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import { __experimentalNumberControl as NumberControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "textarea" );
        return (
            <>
                <CommonControls
                    { ...props }
                    extra={
                        <NumberControl
                            label={ __( "Rows", "trinity-forms" ) }
                            value={ props.attributes.rows ?? 4 }
                            min={ 2 }
                            onChange={ ( v ) => props.setAttributes( { rows: Number( v ) || 4 } ) }
                        />
                    }
                />
                <FieldPreview attributes={ props.attributes }>
                    <textarea disabled rows={ props.attributes.rows ?? 4 } placeholder={ props.attributes.placeholder } style={ { width: "100%", padding: ".5rem" } } />
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
