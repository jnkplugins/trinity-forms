/**
 * Adds "Conditional logic" (and a format pattern, where the block supports it) to
 * every trinity-forms field block's Inspector.
 *
 * This is a filter rather than an addition to CommonControls because only 19 of the
 * 25 field blocks use CommonControls — field-html, field-section and friends build
 * their own Inspector. Hooking editor.BlockEdit covers all of them, and any block
 * added later, for free.
 *
 * editor.BlockEdit runs at render time, so this file's position in the enqueue
 * order relative to the 25 block scripts doesn't matter. A blocks.registerBlockType
 * filter would have to win a race against all of them.
 */
import { InspectorControls } from "@wordpress/block-editor";
import { PanelBody, TextControl } from "@wordpress/components";
import { createHigherOrderComponent } from "@wordpress/compose";
import { useSelect } from "@wordpress/data";
import { Fragment } from "@wordpress/element";
import { addFilter } from "@wordpress/hooks";
import { __ } from "@wordpress/i18n";
import { ConditionalRules } from "./conditional-rules";

const PREFIX = "trinity-forms/field-";

// A page break is a step separator, not a rendered field: step_groups() skips it
// before wrap_conditional() ever sees it, so a rule here would silently do nothing.
const EXCLUDED = [ PREFIX + "page-break" ];

const supportsConditional = ( name ) =>
	name.indexOf( PREFIX ) === 0 && ! EXCLUDED.includes( name );

/** Flatten the editor's block tree — fields may sit inside columns or groups. */
function collectFields( blocks, selfClientId, out = [] ) {
	blocks.forEach( ( block ) => {
		if ( block.name.indexOf( PREFIX ) === 0 && block.clientId !== selfClientId ) {
			const id = block.attributes.fieldId;
			if ( id ) {
				const entry = { value: id, label: block.attributes.label || id };
				// Choice fields carry their choices, so a rule's value can be picked
				// from them (label shown, stored value saved).
				if ( Array.isArray( block.attributes.options ) && block.attributes.options.length ) {
					entry.choices = block.attributes.options;
				}
				out.push( entry );
			}
		}
		if ( block.innerBlocks && block.innerBlocks.length ) {
			collectFields( block.innerBlocks, selfClientId, out );
		}
	} );
	return out;
}

/**
 * Kept as its own component so its hooks only ever run for blocks that support
 * conditional logic — an early return inside the HOC would call useSelect
 * conditionally.
 */
function ConditionalInspector( { attributes, setAttributes, clientId } ) {
	const fields = useSelect(
		( select ) => collectFields( select( "core/block-editor" ).getBlocks(), clientId ),
		[ clientId ]
	);

	// Only the text-ish blocks declare a pattern attribute, so it is defined
	// exactly where a regex makes sense.
	const hasPattern = typeof attributes.pattern !== "undefined";

	return (
		<InspectorControls>
			{ hasPattern && (
				<PanelBody title={ __( "Format", "trinity-forms" ) } initialOpen={ false }>
					<TextControl
						__next40pxDefaultSize
						label={ __( "Pattern", "trinity-forms" ) }
						help={ __( "A regular expression the value must match, e.g. ^[0-9]{5}$ for a 5-digit ZIP. Leave empty for no check.", "trinity-forms" ) }
						value={ attributes.pattern || "" }
						onChange={ ( pattern ) => setAttributes( { pattern } ) }
					/>
					{ attributes.pattern && (
						<TextControl
							__next40pxDefaultSize
							label={ __( "Error message", "trinity-forms" ) }
							help={ __( "Shown when the value doesn't match.", "trinity-forms" ) }
							value={ attributes.patternMessage || "" }
							onChange={ ( patternMessage ) => setAttributes( { patternMessage } ) }
						/>
					) }
				</PanelBody>
			) }
			<PanelBody title={ __( "Conditional logic", "trinity-forms" ) } initialOpen={ false }>
				<ConditionalRules
					value={ attributes.conditional }
					onChange={ ( conditional ) => setAttributes( { conditional } ) }
					fields={ fields }
				/>
			</PanelBody>
		</InspectorControls>
	);
}

const withConditionalPanel = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => (
		<Fragment>
			<BlockEdit { ...props } />
			{ supportsConditional( props.name ) && <ConditionalInspector { ...props } /> }
		</Fragment>
	),
	"withConditionalPanel"
);

addFilter( "editor.BlockEdit", "trinity-forms/conditional-panel", withConditionalPanel );
