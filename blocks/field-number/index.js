import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import { __experimentalNumberControl as NumberControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

const num = ( v ) => v === "" || v === undefined ? undefined : Number( v );

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "number" );
        const { attributes, setAttributes } = props;
        return (
            <>
                <CommonControls
                    { ...props }
                    extra={
                        <>
                            <NumberControl label={ __( "Min", "trinity-forms" ) }  value={ attributes.min  ?? "" } onChange={ ( v ) => setAttributes( { min:  num( v ) } ) } />
                            <NumberControl label={ __( "Max", "trinity-forms" ) }  value={ attributes.max  ?? "" } onChange={ ( v ) => setAttributes( { max:  num( v ) } ) } />
                            <NumberControl label={ __( "Step", "trinity-forms" ) } value={ attributes.step ?? "" } onChange={ ( v ) => setAttributes( { step: num( v ) } ) } />
                        </>
                    }
                />
                <FieldPreview attributes={ attributes }>
                    <input type="number" disabled placeholder={ attributes.placeholder } style={ { width: "100%", padding: ".5rem" } } />
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
