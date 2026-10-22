import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId } from "../field-shared";
import { TextControl, ToggleControl, __experimentalNumberControl as NumberControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "file" );
        const { attributes, setAttributes } = props;
        return (
            <>
                <CommonControls
                    { ...props }
                    extra={
                        <>
                            <TextControl label={ __( "Accepted extensions (.pdf, .png …)", "trinity-forms" ) } value={ attributes.accept || "" } onChange={ ( v ) => setAttributes( { accept: v } ) } />
                            <ToggleControl label={ __( "Allow multiple", "trinity-forms" ) } checked={ !! attributes.multiple } onChange={ ( v ) => setAttributes( { multiple: v } ) } />
                            <NumberControl label={ __( "Max size (MB)", "trinity-forms" ) } value={ attributes.maxSize ?? "" } min={ 0 } onChange={ ( v ) => setAttributes( { maxSize: v === "" ? undefined : Number( v ) } ) } />
                        </>
                    }
                />
                <FieldPreview attributes={ attributes }>
                    <input type="file" disabled />
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
