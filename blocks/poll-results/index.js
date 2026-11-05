import { registerBlockType } from "@wordpress/blocks";
import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { PanelBody, SelectControl, TextControl, ToggleControl, Placeholder, Spinner } from "@wordpress/components";
import { useSelect } from "@wordpress/data";
import { store as coreStore } from "@wordpress/core-data";
import { __ } from "@wordpress/i18n";
import metadata from "./block.json";

/**
 * A picker and a placeholder: the numbers are drawn on the published page,
 * where they can be fresh for each visitor (see public/assets/js/poll-results.js).
 */
registerBlockType( metadata.name, {
    edit( { attributes, setAttributes } ) {
        const { formId, field, afterVote } = attributes;
        const query = { per_page: 100, status: "publish", orderby: "title", order: "asc" };
        const { forms, loading } = useSelect( ( select ) => ( {
            forms: select( coreStore ).getEntityRecords( "postType", "trinity_forms_form", query ),
            loading: ! select( coreStore ).hasFinishedResolution( "getEntityRecords", [ "postType", "trinity_forms_form", query ] ),
        } ), [] );

        const options = [
            { value: 0, label: __( "— Select a form —", "trinity-forms" ) },
            ...( forms || [] ).map( ( f ) => ( { value: f.id, label: f.title?.rendered || `#${ f.id }` } ) ),
        ];
        const picker = (
            <SelectControl
                label={ __( "Choose form", "trinity-forms" ) }
                value={ formId }
                options={ options }
                onChange={ ( v ) => setAttributes( { formId: parseInt( v, 10 ) || 0 } ) }
            />
        );
        const chosen = ( forms || [] ).find( ( f ) => f.id === formId );

        return (
            <div { ...useBlockProps() }>
                <InspectorControls>
                    <PanelBody title={ __( "Poll results", "trinity-forms" ) }>
                        { picker }
                        <TextControl
                            label={ __( "Only this question (field ID)", "trinity-forms" ) }
                            help={ __( "Leave empty to show every choice and rating question.", "trinity-forms" ) }
                            value={ field }
                            onChange={ ( v ) => setAttributes( { field: v } ) }
                        />
                        <ToggleControl
                            label={ __( "Show the results only after the visitor votes", "trinity-forms" ) }
                            checked={ !! afterVote }
                            onChange={ ( v ) => setAttributes( { afterVote: v } ) }
                        />
                    </PanelBody>
                </InspectorControls>
                <Placeholder
                    icon="chart-bar"
                    label={ __( "Trinity Forms Poll Results", "trinity-forms" ) }
                    instructions={
                        formId && chosen
                            ? chosen.title?.rendered + " · " + __( "The results appear here on the published page.", "trinity-forms" )
                            : __( "Pick the poll whose results visitors should see.", "trinity-forms" )
                    }
                >
                    { loading ? <Spinner /> : picker }
                </Placeholder>
            </div>
        );
    },
    save: () => null,
} );
