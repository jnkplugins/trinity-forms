import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId, OptionsTextarea } from "../field-shared";
import { TextareaControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "checkbox" );
        const { attributes, setAttributes } = props;
        return (
            <>
                <CommonControls
                    { ...props }
                    extra={
                        <OptionsTextarea
                            label={ __( "Options (one per line, label | value)", "trinity-forms" ) }
                            options={ attributes.options }
                            onChange={ ( options ) => setAttributes( { options } ) }
                        />
                    }
                />
                <FieldPreview attributes={ attributes }>
                    { ( attributes.options || [] ).map( ( o, i ) => (
                        <label key={ i } style={ { display: "block", marginTop: 4 } }>
                            <input type="checkbox" disabled value={ o.value } /> { o.label }
                        </label>
                    ) ) }
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
