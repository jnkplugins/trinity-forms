import { registerBlockType } from "@wordpress/blocks";
import { PanelBody, SelectControl, TextareaControl } from "@wordpress/components";
import { InspectorControls } from "@wordpress/block-editor";
import { __ } from "@wordpress/i18n";
import { CommonControls, FieldPreview, useEnsureFieldId, OptionsTextarea } from "../field-shared";
import metadata from "./block.json";
import { choiceValue } from "../choice-value";

const STYLES = [
    { label: __( "Radio buttons (pick one)", "trinity-forms" ),   value: "radio" },
    { label: __( "Checkboxes (pick several)", "trinity-forms" ),  value: "checkbox" },
    { label: __( "Dropdown (pick one)", "trinity-forms" ),        value: "select" },
    { label: __( "Dropdown (pick several)", "trinity-forms" ),    value: "multi" },
];

/** "Label | value" per line, the same shape every other choice field uses. */
function toText( opts ) {
    return ( opts || [] )
        .map( ( o ) => ( o.label === o.value ? o.label : o.label + " | " + o.value ) )
        .join( "\n" );
}

function fromText( text ) {
    return text.split( "\n" ).map( ( l ) => l.trim() ).filter( Boolean ).map( ( line ) => {
        const [ label, val ] = line.split( "|" ).map( ( s ) => s.trim() );
        return {
            label: label || "",
            value: choiceValue( val || label ),
        };
    } );
}

registerBlockType( metadata.name, {
    edit( props ) {
        useEnsureFieldId( props.attributes, props.setAttributes, "choice" );

        const style = props.attributes.choiceStyle || "radio";
        const options = props.attributes.options || [];
        const dropdown = style === "select" || style === "multi";

        return (
            <>
                <CommonControls { ...props } />
                <InspectorControls>
                    <PanelBody title={ __( "Choices", "trinity-forms" ) }>
                        <SelectControl
                            label={ __( "Show as", "trinity-forms" ) }
                            help={ __( "Switching style keeps your options — no need to delete the field and start again.", "trinity-forms" ) }
                            value={ style }
                            options={ STYLES }
                            onChange={ ( v ) => props.setAttributes( { choiceStyle: v } ) }
                        />
                        <OptionsTextarea
                            label={ __( "Options", "trinity-forms" ) }
                            help={ __( 'One per line. Use "Label | value" to store a different value.', "trinity-forms" ) }
                            rows={ 5 }
                            options={ options }
                            onChange={ ( opts ) => props.setAttributes( { options: opts } ) }
                        />
                    </PanelBody>
                </InspectorControls>
                <FieldPreview attributes={ props.attributes }>
                    { dropdown ? (
                        <select disabled multiple={ style === "multi" } style={ { width: "100%", padding: ".5rem" } }>
                            { options.map( ( o, i ) => <option key={ i }>{ o.label }</option> ) }
                        </select>
                    ) : (
                        <div style={ { display: "grid", gap: ".25rem" } }>
                            { options.map( ( o, i ) => (
                                <label key={ i }>
                                    <input type={ style } disabled />{ " " }{ o.label }
                                </label>
                            ) ) }
                        </div>
                    ) }
                </FieldPreview>
            </>
        );
    },
    save() { return null; }
} );
