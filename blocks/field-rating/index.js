import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import { __experimentalNumberControl as NumberControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "rating" );
        const { attributes, setAttributes } = props;
        const max = Math.max( 1, attributes.max || 5 );
        return (
            <>
                <CommonControls
                    { ...props }
                    extra={
                        <NumberControl
                            label={ __( "Stars", "trinity-forms" ) }
                            value={ max }
                            min={ 2 }
                            max={ 10 }
                            onChange={ ( v ) => setAttributes( { max: Number( v ) || 5 } ) }
                        />
                    }
                />
                <FieldPreview attributes={ attributes }>
                    <span style={ { fontSize: "1.4em", letterSpacing: 4 } }>
                        { Array.from( { length: max } ).map( ( _, i ) => "☆" ).join( "" ) }
                    </span>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
