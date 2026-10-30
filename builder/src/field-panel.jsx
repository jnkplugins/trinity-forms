/**
 * Settings for the selected field.
 *
 * Controls are driven off the attribute schema the server sent, not a hard-coded
 * list — a block that declares `pattern` gets a pattern control, one that doesn't,
 * doesn't. Attributes this panel has no UI for are simply never touched, which is
 * how a premium field's settings survive being opened here.
 *
 * The conditional-logic editor is literally the component the Gutenberg Inspector
 * uses (blocks/conditional-rules.js): one UI over one attribute, two hosts.
 */
import { PanelBody, SelectControl, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ConditionalRules } from '../../blocks/conditional-rules';
import { choiceValue } from '../../blocks/choice-value';
import { attrValue, conditionTargets, declares, schemaFor, setAttr, unsetAttr } from './blocks';

const TEXTS = [
	{ key: 'label', label: __( 'Label', 'trinity-forms' ) },
	{ key: 'placeholder', label: __( 'Placeholder', 'trinity-forms' ) },
	{ key: 'help', label: __( 'Help text', 'trinity-forms' ) },
];

/** "Label | value" per line — the same shape the block editor's choice fields use. */
function optionsToText( opts ) {
	return ( opts || [] )
		.map( ( o ) => ( o.label === o.value ? o.label : `${ o.label } | ${ o.value }` ) )
		.join( '\n' );
}

function textToOptions( text ) {
	return text
		.split( '\n' )
		.map( ( l ) => l.trim() )
		.filter( Boolean )
		.map( ( line ) => {
			const [ label, val ] = line.split( '|' ).map( ( s ) => s.trim() );
			return {
				label: label || '',
				value: choiceValue( val || label ),
			};
		} );
}

/**
 * Choices textarea with its own text state. Re-deriving the text from the parsed
 * options on every keystroke mangled typing ("Gold | gold" -> "G | goldgold") and
 * swallowed Enter and pasted newlines; see blocks/field-shared.js OptionsTextarea.
 */
function ChoicesControl( { blockKey, options, onChange } ) {
	const [ text, setText ] = useState( () => optionsToText( options ) );
	const lastSent = useRef( JSON.stringify( options || [] ) );
	useEffect( () => {
		const incoming = JSON.stringify( options || [] );
		if ( incoming !== lastSent.current ) {
			lastSent.current = incoming;
			setText( optionsToText( options ) );
		}
	}, [ options, blockKey ] );
	return (
		<TextareaControl
			__nextHasNoMarginBottom
			label={ __( 'Choices', 'trinity-forms' ) }
			help={ __( 'One per line. Use "Label | value" to store a different value.', 'trinity-forms' ) }
			rows={ 5 }
			value={ text }
			onChange={ ( v ) => {
				setText( v );
				const parsed = textToOptions( v );
				lastSent.current = JSON.stringify( parsed );
				onChange( parsed );
			} }
		/>
	);
}

/** Attributes the panels above already own, or that are not author settings. */
const HANDLED = [
	'fieldId', 'label', 'placeholder', 'help', 'required', 'options', 'pattern', 'patternMessage',
	'fieldWidth', 'descriptionPlacement', 'cssClass', 'allowPrefill', 'prefillParam', 'defaultValue',
	'adminLabel', 'visibility', 'preventDuplicates', 'validationMessage', 'autocomplete', 'conditional',
	'className', 'lock', 'metadata', 'style',
];

/** Friendly labels (and fixed choices) for type-specific settings, premium fields included. */
const EXTRA = {
	accept: { label: __( 'Accepted extensions (.pdf, .png …)', 'trinity-forms' ) },
	multiple: { label: __( 'Allow multiple files', 'trinity-forms' ) },
	maxSize: { label: __( 'Max size (MB)', 'trinity-forms' ) },
	formula: { label: __( 'Formula', 'trinity-forms' ), textarea: true, help: __( 'Use field ids in braces, e.g. {quantity} * 12.50', 'trinity-forms' ) },
	prefix: { label: __( 'Prefix', 'trinity-forms' ) },
	suffix: { label: __( 'Suffix', 'trinity-forms' ) },
	decimals: { label: __( 'Decimal places', 'trinity-forms' ) },
	postTarget: {
		label: __( 'Fills in', 'trinity-forms' ),
		choices: [ 'post_title', 'post_content', 'post_excerpt', 'post_tags', 'post_category', 'post_custom' ],
	},
	rows: { label: __( 'Rows', 'trinity-forms' ) },
	chainedData: { label: __( 'Data (CSV)', 'trinity-forms' ), textarea: true, help: __( 'First line names the levels (e.g. Country,State); each further line is one path.', 'trinity-forms' ) },
	amountMode: {
		label: __( 'Amount mode', 'trinity-forms' ),
		choices: [ 'fixed', 'field' ],
	},
	amount: { label: __( 'Amount', 'trinity-forms' ) },
	amountField: { label: __( 'Amount from field (id)', 'trinity-forms' ) },
	currency: { label: __( 'Currency', 'trinity-forms' ) },
	addButton: { label: __( 'Add button text', 'trinity-forms' ) },
	subFieldsJson: { label: __( 'Sub-field schema (JSON array)', 'trinity-forms' ), textarea: true, help: __( 'e.g. [{"id":"name","type":"text","label":"Name"}]. Left empty, one "Item" column is shown.', 'trinity-forms' ) },
	correctAnswer: { label: __( 'Correct answer value (quiz)', 'trinity-forms' ) },
	points: { label: __( 'Points', 'trinity-forms' ) },
	intl: { label: __( 'Check it is a full phone number (7 to 15 digits, + allowed)', 'trinity-forms' ) },
};

function humanize( key ) {
	const words = key.replace( /([A-Z])/g, ' $1' ).replace( /_/g, ' ' ).trim().toLowerCase();
	return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
}

/**
 * Every declared, scalar attribute no panel above covers — premium fields' settings
 * (formula, accepted file types, chained-select data, payment amount…) used to be
 * unreachable here, so a Calculation built in the builder had no formula at all.
 */
function TypeSettings( { block, schema, set } ) {
	const keys = Object.keys( schema ).filter( ( k ) => {
		if ( HANDLED.includes( k ) || k.startsWith( '_' ) ) {
			return false;
		}
		const t = schema[ k ] && schema[ k ].type;
		return t === 'string' || t === 'number' || t === 'integer' || t === 'boolean';
	} );
	if ( ! keys.length ) {
		return null;
	}
	return (
		<PanelBody title={ __( 'Field settings', 'trinity-forms' ) } initialOpen>
			{ keys.map( ( key ) => {
				const meta = EXTRA[ key ] || {};
				const type = schema[ key ].type;
				// "multiple" means several files only on the File field; on Image
				// Choice and Option it allows several selections.
				const isFile = block.name === 'trinity-forms/field-file';
				const label =
					key === 'multiple' && ! isFile
						? __( 'Allow multiple selections', 'trinity-forms' )
						: meta.label || humanize( key );
				const value = attrValue( block, schema, key );
				if ( type === 'boolean' ) {
					return (
						<ToggleControl
							__nextHasNoMarginBottom
							key={ key }
							label={ label }
							checked={ !! value }
							onChange={ ( v ) => set( key, v ) }
						/>
					);
				}
				if ( meta.choices ) {
					return (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							key={ key }
							label={ label }
							value={ value || meta.choices[ 0 ] }
							options={ meta.choices.map( ( c ) => ( { label: c, value: c } ) ) }
							onChange={ ( v ) => set( key, v ) }
						/>
					);
				}
				if ( type === 'number' || type === 'integer' ) {
					return (
						<TextControl
							__next40pxDefaultSize
							key={ key }
							type="number"
							label={ label }
							help={ meta.help }
							value={ value === undefined || value === null ? '' : String( value ) }
							onChange={ ( v ) => set( key, v === '' ? undefined : Number( v ) ) }
						/>
					);
				}
				const Control = meta.textarea ? TextareaControl : TextControl;
				return (
					<Control
						key={ key }
						__nextHasNoMarginBottom
						__next40pxDefaultSize={ ! meta.textarea }
						label={ label }
						help={ meta.help }
						rows={ meta.textarea ? 5 : undefined }
						value={ value || '' }
						onChange={ ( v ) => set( key, v ) }
					/>
				);
			} ) }
		</PanelBody>
	);
}

export default function FieldPanel( { block, blocks, palette, onChange } ) {
	if ( ! block ) {
		return (
			<div className="tf-builder__panel is-empty">
				<p>{ __( 'Select a field to edit its settings.', 'trinity-forms' ) }</p>
			</div>
		);
	}

	const schema = schemaFor( palette, block.name );
	// The key coalesces consecutive edits to the same attribute into one history
	// entry, so undo rewinds the label you typed rather than one keystroke of it.
	const set = ( key, value ) => onChange( setAttr( block, key, value ), key );

	return (
		<div className="tf-builder__panel">
			<PanelBody title={ __( 'Field', 'trinity-forms' ) } initialOpen>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Field ID', 'trinity-forms' ) }
					help={ __( 'Used in merge tags and entry export.', 'trinity-forms' ) }
					value={ attrValue( block, schema, 'fieldId' ) || '' }
					onChange={ ( v ) => set( 'fieldId', v.replace( /[^a-z0-9_]/gi, '' ).toLowerCase() ) }
				/>

				{ TEXTS.filter( ( t ) => declares( schema, t.key ) ).map( ( t ) => (
					<TextControl
						__next40pxDefaultSize
						key={ t.key }
						label={ t.label }
						value={ attrValue( block, schema, t.key ) || '' }
						onChange={ ( v ) => set( t.key, v ) }
					/>
				) ) }

				{ declares( schema, 'required' ) && (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Required', 'trinity-forms' ) }
						checked={ !! attrValue( block, schema, 'required' ) }
						onChange={ ( v ) => set( 'required', v ) }
					/>
				) }
			</PanelBody>

			{ declares( schema, 'options' ) &&
				// A single product has a price, not a list of choices.
				! ( block.name === 'trinity-forms/field-product' &&
					( attrValue( block, schema, 'productType' ) || 'single' ) === 'single' ) && (
				<PanelBody title={ __( 'Choices', 'trinity-forms' ) } initialOpen={ false }>
					<ChoicesControl
						blockKey={ block.key }
						options={ attrValue( block, schema, 'options' ) }
						onChange={ ( opts ) => set( 'options', opts ) }
					/>
				</PanelBody>
			) }

			<TypeSettings block={ block } schema={ schema } set={ set } />

			{ declares( schema, 'pattern' ) && (
				<PanelBody title={ __( 'Format', 'trinity-forms' ) } initialOpen={ false }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Pattern', 'trinity-forms' ) }
						help={ __( 'A regular expression the value must match. Leave empty for no check.', 'trinity-forms' ) }
						value={ attrValue( block, schema, 'pattern' ) || '' }
						onChange={ ( v ) => set( 'pattern', v ) }
					/>
					{ !! attrValue( block, schema, 'pattern' ) && (
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Error message', 'trinity-forms' ) }
							value={ attrValue( block, schema, 'patternMessage' ) || '' }
							onChange={ ( v ) => set( 'patternMessage', v ) }
						/>
					) }
				</PanelBody>
			) }

			{ declares( schema, 'fieldWidth' ) && (
				<PanelBody title={ __( 'Appearance', 'trinity-forms' ) } initialOpen={ false }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Field width', 'trinity-forms' ) }
						value={ attrValue( block, schema, 'fieldWidth' ) || '' }
						options={ [
							{ label: __( 'Full width', 'trinity-forms' ), value: '' },
							{ label: __( 'One half', 'trinity-forms' ), value: '50' },
							{ label: __( 'One third', 'trinity-forms' ), value: '33' },
							{ label: __( 'One quarter', 'trinity-forms' ), value: '25' },
						] }
						onChange={ ( v ) => set( 'fieldWidth', v ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Help text position', 'trinity-forms' ) }
						value={ attrValue( block, schema, 'descriptionPlacement' ) || '' }
						options={ [
							{ label: __( "Use the form's setting", 'trinity-forms' ), value: '' },
							{ label: __( 'Below the field', 'trinity-forms' ), value: 'below' },
							{ label: __( 'Above the field', 'trinity-forms' ), value: 'above' },
						] }
						onChange={ ( v ) => set( 'descriptionPlacement', v ) }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'CSS class', 'trinity-forms' ) }
						help={ __( 'Added to the field wrapper, for your own styling.', 'trinity-forms' ) }
						value={ attrValue( block, schema, 'cssClass' ) || '' }
						onChange={ ( v ) => set( 'cssClass', v ) }
					/>
				</PanelBody>
			) }

			{ declares( schema, 'allowPrefill' ) && (
				<PanelBody title={ __( 'Advanced', 'trinity-forms' ) } initialOpen={ false }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Default value', 'trinity-forms' ) }
						value={ attrValue( block, schema, 'defaultValue' ) || '' }
						onChange={ ( v ) => set( 'defaultValue', v ) }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Admin label', 'trinity-forms' ) }
						help={ __( 'Shown instead of the label in entries and exports. Useful when the public label is a long question.', 'trinity-forms' ) }
						value={ attrValue( block, schema, 'adminLabel' ) || '' }
						onChange={ ( v ) => set( 'adminLabel', v ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Visible to', 'trinity-forms' ) }
						value={ attrValue( block, schema, 'visibility' ) || 'everyone' }
						options={ [
							{ label: __( 'Everyone', 'trinity-forms' ), value: 'everyone' },
							{ label: __( 'Logged-in users', 'trinity-forms' ), value: 'logged_in' },
							{ label: __( 'Administrators only', 'trinity-forms' ), value: 'admin' },
						] }
						onChange={ ( v ) => set( 'visibility', v ) }
					/>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Allow this field to be pre-filled from the URL', 'trinity-forms' ) }
						help={ __( 'Off by default: a form whose every field can be set from a link is a form whose data you cannot trust.', 'trinity-forms' ) }
						checked={ !! attrValue( block, schema, 'allowPrefill' ) }
						onChange={ ( v ) => set( 'allowPrefill', v ) }
					/>
					{ !! attrValue( block, schema, 'allowPrefill' ) && (
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Parameter name', 'trinity-forms' ) }
							help={ __( 'Defaults to the field ID. Also reads user_email, first_name, post_id and user_meta_<key>.', 'trinity-forms' ) }
							value={ attrValue( block, schema, 'prefillParam' ) || '' }
							onChange={ ( v ) => set( 'prefillParam', v.replace( /[^a-z0-9_]/gi, '' ).toLowerCase() ) }
						/>
					) }

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'No duplicates', 'trinity-forms' ) }
						help={ __( 'Refuse a submission if another entry on this form already has the same value.', 'trinity-forms' ) }
						checked={ !! attrValue( block, schema, 'preventDuplicates' ) }
						onChange={ ( v ) => set( 'preventDuplicates', v ) }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Custom error message', 'trinity-forms' ) }
						help={ __( 'Replaces the built-in "this field is required" wording.', 'trinity-forms' ) }
						value={ attrValue( block, schema, 'validationMessage' ) || '' }
						onChange={ ( v ) => set( 'validationMessage', v ) }
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Autocomplete', 'trinity-forms' ) }
						help={ __( 'The HTML autocomplete hint, e.g. email, given-name, organization.', 'trinity-forms' ) }
						value={ attrValue( block, schema, 'autocomplete' ) || '' }
						onChange={ ( v ) => set( 'autocomplete', v ) }
					/>
				</PanelBody>
			) }

			{ declares( schema, 'conditional' ) && (
				<PanelBody title={ __( 'Conditional logic', 'trinity-forms' ) } initialOpen={ false }>
					<ConditionalRules
						value={ block.attrs ? block.attrs.conditional : undefined }
						fields={ conditionTargets( blocks, block, palette ) }
						onChange={ ( rules ) =>
							onChange(
								rules === undefined
									? unsetAttr( block, 'conditional' )
									: setAttr( block, 'conditional', rules )
							)
						}
					/>
				</PanelBody>
			) }
		</div>
	);
}
