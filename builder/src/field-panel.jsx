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
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { ConditionalRules } from '../../blocks/conditional-rules';
import { choiceValue } from '../../blocks/choice-value';
import { attrValue, conditionTargets, declares, dependents, schemaFor, setAttr, unsetAttr } from './blocks';

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

/**
 * Friendly labels (and fixed choices) for type-specific settings, premium fields
 * included. Choices are { value, label } with the same translated labels the block
 * editor uses: the raw value (post_title, autofill...) meant nothing to an owner,
 * and a key without an entry here falls back to an untranslated humanized name.
 */
const EXTRA = {
	accept: { label: __( 'Accepted extensions (.pdf, .png …)', 'trinity-forms' ) },
	multiple: { label: __( 'Allow multiple files', 'trinity-forms' ) },
	maxSize: { label: __( 'Max size (MB)', 'trinity-forms' ) },
	formula: { label: __( 'Formula', 'trinity-forms' ), textarea: true, help: __( 'Use field ids in braces, e.g. {quantity} * 12.50. For a repeater column: sum({items.price}), avg({items.price}), min, max, and count({items}) for the number of rows.', 'trinity-forms' ) },
	prefix: { label: __( 'Prefix', 'trinity-forms' ) },
	suffix: { label: __( 'Suffix', 'trinity-forms' ) },
	decimals: { label: __( 'Decimal places', 'trinity-forms' ) },
	postTarget: {
		label: __( 'Fills in', 'trinity-forms' ),
		choices: [
			{ value: 'post_title', label: __( 'Post title', 'trinity-forms' ) },
			{ value: 'post_content', label: __( 'Post body', 'trinity-forms' ) },
			{ value: 'post_excerpt', label: __( 'Post excerpt', 'trinity-forms' ) },
			{ value: 'post_tags', label: __( 'Post tags', 'trinity-forms' ) },
			{ value: 'post_category', label: __( 'Post category', 'trinity-forms' ) },
			{ value: 'post_custom', label: __( 'Custom field', 'trinity-forms' ) },
		],
	},
	rows: { label: __( 'Rows', 'trinity-forms' ) },
	chainedData: { label: __( 'Data (CSV)', 'trinity-forms' ), textarea: true, help: __( 'First line names the levels (e.g. Country,State); each further line is one path.', 'trinity-forms' ) },
	amountMode: {
		label: __( 'Amount mode', 'trinity-forms' ),
		choices: [
			{ value: 'fixed', label: __( 'Fixed', 'trinity-forms' ) },
			{ value: 'field', label: __( 'From a field', 'trinity-forms' ) },
		],
	},
	amount: { label: __( 'Amount', 'trinity-forms' ) },
	amountField: { label: __( 'Amount from field (id)', 'trinity-forms' ) },
	currency: { label: __( 'Currency', 'trinity-forms' ) },
	addButton: { label: __( 'Add button text', 'trinity-forms' ) },
	subFieldsJson: { label: __( 'Sub-field schema (JSON array)', 'trinity-forms' ), textarea: true, help: __( 'e.g. [{"id":"name","type":"text","label":"Name"}]. Left empty, one "Item" column is shown.', 'trinity-forms' ) },
	correctAnswer: { label: __( 'Correct answer value (quiz)', 'trinity-forms' ) },
	points: { label: __( 'Points', 'trinity-forms' ) },
	intl: { label: __( 'Check it is a full phone number (7 to 15 digits, + allowed)', 'trinity-forms' ) },
	statements: { label: __( 'Statements (one per line)', 'trinity-forms' ), textarea: true },
	lowLabel: { label: __( 'Label under 0', 'trinity-forms' ) },
	highLabel: { label: __( 'Label under 10', 'trinity-forms' ) },
	slotDays: { label: __( 'Open days (1 = Monday ... 7 = Sunday)', 'trinity-forms' ) },
	slotStart: { label: __( 'Opens at (HH:MM)', 'trinity-forms' ) },
	slotEnd: { label: __( 'Closes at (HH:MM)', 'trinity-forms' ) },
	slotLength: { label: __( 'Slot length (minutes)', 'trinity-forms' ) },
	slotBuffer: { label: __( 'Break between slots (minutes)', 'trinity-forms' ) },
	slotCapacity: { label: __( 'People per slot', 'trinity-forms' ) },
	slotDaysAhead: { label: __( 'Days ahead people can book', 'trinity-forms' ) },
	slotNotice: { label: __( 'Minimum notice (hours)', 'trinity-forms' ) },
	slotBlocked: { label: __( 'Closed dates (one per line, YYYY-MM-DD or YYYY-MM-DD..YYYY-MM-DD)', 'trinity-forms' ), textarea: true },
	slotIcs: { label: __( 'Attach a calendar file (.ics) to emails', 'trinity-forms' ) },
	lookupForm: { label: __( 'Take answers from form (form ID)', 'trinity-forms' ), help: __( 'The number in the form\'s shortcode, e.g. 12 in [trinity-forms id="12"].', 'trinity-forms' ) },
	lookupField: { label: __( 'Field in that form', 'trinity-forms' ), lookupPicker: true },
	lookupMode: {
		label: __( 'Show as', 'trinity-forms' ),
		choices: [
			{ value: 'select', label: __( 'Dropdown of the answers', 'trinity-forms' ) },
			{ value: 'autofill', label: __( 'Text box filled with the person\'s latest answer', 'trinity-forms' ) },
		],
	},
	choiceStyle: {
		label: __( 'Show as', 'trinity-forms' ),
		help: __( 'Switching style keeps your options — no need to delete the field and start again.', 'trinity-forms' ),
		choices: [
			{ value: 'radio', label: __( 'Radio buttons (pick one)', 'trinity-forms' ) },
			{ value: 'checkbox', label: __( 'Checkboxes (pick several)', 'trinity-forms' ) },
			{ value: 'select', label: __( 'Dropdown (pick one)', 'trinity-forms' ) },
			{ value: 'multi', label: __( 'Dropdown (pick several)', 'trinity-forms' ) },
		],
	},
	productType: {
		label: __( 'Product type', 'trinity-forms' ),
		choices: [
			{ value: 'single', label: __( 'Single item at a fixed price', 'trinity-forms' ) },
			{ value: 'radio', label: __( 'Radio buttons (pick one)', 'trinity-forms' ) },
			{ value: 'select', label: __( 'Dropdown (pick one)', 'trinity-forms' ) },
		],
	},
	price: { label: __( 'Price', 'trinity-forms' ) },
	productField: { label: __( 'Product (field ID)', 'trinity-forms' ) },
	consentText: { label: __( 'Consent text', 'trinity-forms' ) },
	nextText: { label: __( 'Next button text', 'trinity-forms' ) },
	prevText: { label: __( 'Back button text', 'trinity-forms' ) },
	description: { label: __( 'Description', 'trinity-forms' ) },
	html: { label: __( 'HTML markup', 'trinity-forms' ), textarea: true },
	default: { label: __( 'Default value', 'trinity-forms' ) },
	min: { label: __( 'Min', 'trinity-forms' ) },
	max: { label: __( 'Max', 'trinity-forms' ) },
	step: { label: __( 'Step', 'trinity-forms' ) },
	minLength: { label: __( 'Minimum length', 'trinity-forms' ) },
	maxLength: { label: __( 'Maximum length', 'trinity-forms' ) },
	maxRows: { label: __( 'Maximum rows', 'trinity-forms' ) },
	maxChoices: { label: __( 'Maximum choices', 'trinity-forms' ) },
	confirm: { label: __( 'Confirm password', 'trinity-forms' ) },
	lookupMine: { label: __( 'Only the logged-in person\'s own entries', 'trinity-forms' ), help: __( 'Off: every visitor sees the answers from all entries in the dropdown, so email, phone and name fields can\'t be used.', 'trinity-forms' ) },
};

/**
 * Lookup source field: a dropdown of the fields the server allows (email, phone
 * and name only with "own entries" on). Falls back to a text box when the route
 * is not there.
 */
function LookupFieldPicker( { label, formId, mine, value, onChange } ) {
	const [ fields, setFields ] = useState( null );
	useEffect( () => {
		if ( ! formId ) {
			setFields( [] );
			return undefined;
		}
		let live = true;
		apiFetch( { path: `/trinity-forms/v1/lookup/fields?form=${ formId }&mine=${ mine ? 1 : 0 }` } )
			.then( ( list ) => live && setFields( Array.isArray( list ) ? list : [] ) )
			.catch( () => live && setFields( false ) );
		return () => {
			live = false;
		};
	}, [ formId, mine ] );
	const help = __( 'Email, phone and name fields are listed only when "Only the logged-in person\'s own entries" is on.', 'trinity-forms' );
	if ( fields === false ) {
		return <TextControl __next40pxDefaultSize __nextHasNoMarginBottom label={ label } help={ help } value={ value || '' } onChange={ onChange } />;
	}
	const list = fields || [];
	const missing = value && fields && ! list.some( ( f ) => f.id === value );
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			help={ missing ? __( 'The saved field can\'t be used here, so the dropdown stays empty. Pick another field or turn on "Only the logged-in person\'s own entries".', 'trinity-forms' ) : help }
			value={ value || '' }
			options={ [ { label: '—', value: '' } ].concat( list.map( ( f ) => ( { label: `${ f.label } (${ f.id })`, value: f.id } ) ) ) }
			onChange={ onChange }
		/>
	);
}

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
				let label = meta.label || humanize( key );
				if ( key === 'multiple' && ! isFile ) {
					label = __( 'Allow multiple selections', 'trinity-forms' );
				} else if ( key === 'max' && block.name === 'trinity-forms/field-rating' ) {
					label = __( 'Stars', 'trinity-forms' );
				}
				const value = attrValue( block, schema, key );
				if ( type === 'boolean' ) {
					return (
						<ToggleControl
							__nextHasNoMarginBottom
							key={ key }
							label={ label }
							help={ meta.help }
							checked={ !! value }
							onChange={ ( v ) => set( key, v ) }
						/>
					);
				}
				if ( meta.lookupPicker ) {
					return (
						<LookupFieldPicker
							key={ key }
							label={ label }
							formId={ Number( attrValue( block, schema, 'lookupForm' ) ) || 0 }
							mine={ !! attrValue( block, schema, 'lookupMine' ) }
							value={ value }
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
							help={ meta.help }
							value={ value || meta.choices[ 0 ].value }
							options={ meta.choices }
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

/** "Used by": fields whose rules or formulas depend on this one (shown when an extension turns it on). */
function UsedBy( { items } ) {
	return (
		<PanelBody title={ __( 'Used by', 'trinity-forms' ) } initialOpen={ items.length > 0 }>
			{ items.length ? (
				<ul className="tf-builder__used-by">
					{ items.map( ( it, i ) => (
						<li key={ i }>
							{ it.how === 'formula'
								? sprintf( /* translators: %s: field label */ __( '%s (calculation)', 'trinity-forms' ), it.label )
								: sprintf( /* translators: %s: field label */ __( '%s (show / hide rule)', 'trinity-forms' ), it.label ) }
						</li>
					) ) }
				</ul>
			) : (
				<p className="description">{ __( 'No other field uses this one in a rule or a calculation.', 'trinity-forms' ) }</p>
			) }
			{ items.length > 0 && <p className="description">{ __( 'Changing this field\'s ID or choices can break these.', 'trinity-forms' ) }</p> }
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

			{ window.TrinityFormsBuilder && window.TrinityFormsBuilder.logicMap && (
				<UsedBy items={ dependents( blocks, block, palette ) } />
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
