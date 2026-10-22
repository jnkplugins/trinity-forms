import { registerBlockType } from "@wordpress/blocks";
import { CommonControls, FieldPreview, useEnsureFieldId, OptionsTextarea } from "../field-shared";
import { TextareaControl, TextControl, __experimentalNumberControl as NumberControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "select" );
        const { attributes, setAttributes } = props;
        return (
            <>
                <CommonControls
                    { ...props }
                    extra={
                        <>
                            <OptionsTextarea
                                label={ __( "Options (one per line, label | value)", "trinity-forms" ) }
                                options={ attributes.options }
                                onChange={ ( options ) => setAttributes( { options } ) }
                            />
                            <TextControl
                                label={ __( "Correct answer value (quiz — optional)", "trinity-forms" ) }
                                help={ __( "The option value that scores points. Leave blank for non-quiz forms.", "trinity-forms" ) }
                                value={ attributes.correctAnswer || "" }
                                onChange={ ( v ) => setAttributes( { correctAnswer: v } ) }
                            />
                            { attributes.correctAnswer ? (
                                <NumberControl
                                    label={ __( "Points", "trinity-forms" ) }
                                    value={ attributes.points ?? 1 }
                                    onChange={ ( v ) => setAttributes( { points: v === "" ? undefined : Number( v ) } ) }
                                />
                            ) : null }
                        </>
                    }
                />
                <FieldPreview attributes={ attributes }>
                    <select disabled style={ { width: "100%", padding: ".5rem" } }>
                        { attributes.placeholder && <option>{ attributes.placeholder }</option> }
                        { ( attributes.options || [] ).map( ( o, i ) => (
                            <option key={ i } value={ o.value }>{ o.label }</option>
                        ) ) }
                    </select>
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
