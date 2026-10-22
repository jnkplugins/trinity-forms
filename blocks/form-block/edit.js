import { useBlockProps, InspectorControls } from "@wordpress/block-editor";
import { PanelBody, SelectControl, ToggleControl, Placeholder, Spinner } from "@wordpress/components";
import { useSelect } from "@wordpress/data";
import { __ } from "@wordpress/i18n";

export default function Edit( { attributes, setAttributes } ) {
    const { formId, showTitle } = attributes;

    const { forms, isLoading } = useSelect( ( select ) => {
        const core = select( "core" );
        const query = { per_page: 100, status: [ "publish", "draft" ] };
        return {
            forms:     core.getEntityRecords( "postType", "trinity_forms_form", query ) || [],
            isLoading: core.isResolving( "getEntityRecords", [ "postType", "trinity_forms_form", query ] ),
        };
    }, [] );

    const options = [
        { label: __( "— Select a form —", "trinity-forms" ), value: 0 },
        ...forms.map( ( f ) => ( {
            label: f.title?.rendered || `#${ f.id }`,
            value: f.id,
        } ) ),
    ];

    return (
        <div { ...useBlockProps() }>
            <InspectorControls>
                <PanelBody title={ __( "Form", "trinity-forms" ) }>
                    <SelectControl
                        label={ __( "Choose form", "trinity-forms" ) }
                        value={ formId }
                        options={ options }
                        onChange={ ( v ) => setAttributes( { formId: parseInt( v, 10 ) || 0 } ) }
                    />
                    <ToggleControl
                        label={ __( "Show title", "trinity-forms" ) }
                        checked={ !! showTitle }
                        onChange={ ( v ) => setAttributes( { showTitle: v } ) }
                    />
                </PanelBody>
            </InspectorControls>

            { isLoading && <Spinner /> }
            { ! isLoading && ! formId && (
                <Placeholder
                    icon="feedback"
                    label={ __( "Trinity Forms Form", "trinity-forms" ) }
                    instructions={ __( "Pick a form from the sidebar to embed.", "trinity-forms" ) }
                />
            ) }
            { !! formId && (
                <div className="trinity-forms-block-preview">
                    <strong>{ __( "Trinity Forms Form", "trinity-forms" ) }</strong>
                    <div>{ options.find( ( o ) => o.value === formId )?.label || `#${ formId }` }</div>
                </div>
            ) }
        </div>
    );
}
