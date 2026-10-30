/**
 * Conditional-logic rules editor.
 *
 * Deliberately free of @wordpress/block-editor so it can be hosted both by the
 * Gutenberg Inspector (conditional-panel.js) and by the visual builder's field
 * panel — one UI over one attribute, two hosts.
 *
 * The shape written here is consumed verbatim by Conditional_Logic::is_visible()
 * (PHP) and by evaluate() in public/assets/js/public.js:
 *
 *   { action: "show"|"hide", match: "all"|"any",
 *     conditions: [
 *       { field, operator, value },
 *       // …or a nested group, for "A and (B or C)":
 *       { match: "any", conditions: [ … ] },
 *     ] }
 *
 * A leaf has `field`; a group has `conditions`. That one distinction is the whole
 * nesting format — no separate node type, no ids, nothing for the evaluator to
 * disagree with.
 */
import { Button, SelectControl, TextControl } from "@wordpress/components";
import { __ } from "@wordpress/i18n";

/**
 * Must stay in lockstep with Conditional_Logic::operators() and its compare()
 * branches, and with the mirror of compare() in public/assets/js/public.js.
 * Test_Conditional_Operators::test_every_advertised_operator_is_implemented is the
 * guard on the PHP half.
 */
export const OPERATORS = [
	{ value: "equals", label: __( "is", "trinity-forms" ) },
	{ value: "not_equals", label: __( "is not", "trinity-forms" ) },
	{ value: "contains", label: __( "contains", "trinity-forms" ) },
	{ value: "not_contains", label: __( "does not contain", "trinity-forms" ) },
	{ value: "starts_with", label: __( "starts with", "trinity-forms" ) },
	{ value: "ends_with", label: __( "ends with", "trinity-forms" ) },
	{ value: "greater_than", label: __( "is greater than", "trinity-forms" ) },
	{ value: "less_than", label: __( "is less than", "trinity-forms" ) },
	{ value: "is_before", label: __( "is before", "trinity-forms" ) },
	{ value: "is_after", label: __( "is after", "trinity-forms" ) },
	{ value: "in_list", label: __( "is one of", "trinity-forms" ) },
	{ value: "not_in_list", label: __( "is none of", "trinity-forms" ) },
	{ value: "matches_pattern", label: __( "matches pattern", "trinity-forms" ) },
	{ value: "empty", label: __( "is empty", "trinity-forms" ) },
	{ value: "not_empty", label: __( "is not empty", "trinity-forms" ) },
];

/** Operators that compare against nothing, so the value input is hidden. */
const VALUELESS = [ "empty", "not_empty" ];

/**
 * Operators that compare a whole answer, so a choice field offers its choices
 * instead of a text box. The others ("starts with", patterns, lists) stay typed.
 */
const CHOICE_OPERATORS = [ "equals", "not_equals", "contains", "not_contains" ];

/** How Conditional_Logic::choice_value() compares: trimmed, NFC, any case. */
const fold = ( s ) => String( s == null ? "" : s ).trim().normalize( "NFC" ).toLowerCase();

/**
 * The field's choices as { value, label } pairs, or [] for a field without any.
 *
 * Hosts pass `choices` on each entry of `fields` (the field's own options).
 */
export function choicesOf( fields, fieldId ) {
	const f = ( fields || [] ).find( ( x ) => x.value === fieldId );
	if ( ! f || ! Array.isArray( f.choices ) ) {
		return [];
	}
	return f.choices
		.map( ( o ) =>
			o && typeof o === "object"
				? { value: String( o.value != null && o.value !== "" ? o.value : o.label || "" ), label: String( o.label != null && o.label !== "" ? o.label : o.value || "" ) }
				: { value: String( o ), label: String( o ) }
		)
		.filter( ( o ) => o.value !== "" );
}

/**
 * Dropdown options for a rule's value, and which one to show as picked.
 *
 * A rule saved as the label (typed before this dropdown existed) shows as that
 * choice; the server reads it the same way. A value that is no longer a choice
 * stays listed as typed, so opening the rule does not lose it.
 */
export function choiceOptions( choices, current ) {
	const value   = current != null ? String( current ) : "";
	let selected  = value;
	const exact   = choices.some( ( c ) => c.value === value );
	if ( value !== "" && ! exact ) {
		const hit = choices.find( ( c ) => fold( c.value ) === fold( value ) ) ||
			choices.find( ( c ) => fold( c.label ) === fold( value ) );
		if ( hit ) {
			selected = hit.value;
		}
	}
	const options = [
		{ value: "", label: __( "— Select —", "trinity-forms" ) },
		...choices.map( ( c ) => ( { value: c.value, label: c.label } ) ),
	];
	if ( selected !== "" && ! choices.some( ( c ) => c.value === selected ) ) {
		options.push( { value: selected, label: selected } );
	}
	return { options, selected };
}

const DEFAULTS = { action: "show", match: "all", conditions: [] };

/** A group is anything carrying its own conditions; everything else is a leaf. */
const isGroup = ( node ) => !! ( node && Array.isArray( node.conditions ) );

const MATCH_OPTIONS = [
	{ value: "all", label: __( "All rules match", "trinity-forms" ) },
	{ value: "any", label: __( "Any rule matches", "trinity-forms" ) },
];

/**
 * How deep the editor will let you go.
 *
 * The evaluator stops at ten on both sides; two is as deep as anyone has ever
 * needed to express a real rule, and a UI that nests forever is one nobody can
 * read back.
 */
const MAX_DEPTH = 2;

/** One leaf condition. */
function ConditionRow( { condition, fields, onChange, onRemove } ) {
	const operator = condition.operator || "equals";
	const choices  = CHOICE_OPERATORS.includes( operator ) ? choicesOf( fields, condition.field ) : [];
	const picker   = choices.length ? choiceOptions( choices, condition.value ) : null;
	return (
		<div style={ { border: "1px solid #ddd", borderRadius: 4, padding: 8, marginBottom: 8 } }>
			<SelectControl
				__next40pxDefaultSize
				label={ __( "Field", "trinity-forms" ) }
				value={ condition.field || "" }
				options={ [ { value: "", label: __( "— Select —", "trinity-forms" ) }, ...fields ] }
				onChange={ ( field ) => onChange( { field } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				label={ __( "Condition", "trinity-forms" ) }
				value={ condition.operator || "equals" }
				options={ OPERATORS }
				onChange={ ( operator ) => onChange( { operator } ) }
			/>
			{ picker && (
				<SelectControl
					__next40pxDefaultSize
					label={ __( "Value", "trinity-forms" ) }
					value={ picker.selected }
					options={ picker.options }
					onChange={ ( value ) => onChange( { value } ) }
				/>
			) }
			{ ! picker && ! VALUELESS.includes( operator ) && (
				<TextControl
					__next40pxDefaultSize
					label={ __( "Value", "trinity-forms" ) }
					value={ condition.value != null ? String( condition.value ) : "" }
					onChange={ ( value ) => onChange( { value } ) }
				/>
			) }
			<Button isDestructive variant="tertiary" onClick={ onRemove }>
				{ __( "Remove rule", "trinity-forms" ) }
			</Button>
		</div>
	);
}

/**
 * A list of conditions plus its own all/any matcher — used for the top level and,
 * recursively, for every nested group.
 */
function ConditionList( { group, fields, depth, onChange, onRemove } ) {
	const conditions = group.conditions || [];

	const replaceAt = ( i, next ) =>
		onChange( {
			...group,
			conditions: conditions.map( ( c, n ) => ( n === i ? next : c ) ),
		} );

	const removeAt = ( i ) =>
		onChange( { ...group, conditions: conditions.filter( ( c, n ) => n !== i ) } );

	const append = ( node ) =>
		onChange( { ...group, conditions: [ ...conditions, node ] } );

	return (
		<>
			{ conditions.length > 1 && (
				<SelectControl
					__next40pxDefaultSize
					label={ depth === 0 ? __( "When", "trinity-forms" ) : __( "Within this group", "trinity-forms" ) }
					value={ group.match || "all" }
					options={ MATCH_OPTIONS }
					onChange={ ( match ) => onChange( { ...group, match } ) }
				/>
			) }

			{ conditions.map( ( node, i ) =>
				isGroup( node ) ? (
					<div
						key={ i }
						style={ {
							border: "1px dashed #757575",
							borderRadius: 4,
							padding: 8,
							marginBottom: 8,
							// Indented and dashed so the shape of "A and (B or C)" is
							// visible at a glance rather than having to be reasoned out.
							marginLeft: 8,
						} }
					>
						<p style={ { margin: "0 0 6px", fontSize: 11, textTransform: "uppercase", color: "#50575e" } }>
							{ __( "Group", "trinity-forms" ) }
						</p>
						<ConditionList
							group={ node }
							fields={ fields }
							depth={ depth + 1 }
							onChange={ ( next ) => replaceAt( i, next ) }
							onRemove={ () => removeAt( i ) }
						/>
					</div>
				) : (
					<ConditionRow
						key={ i }
						condition={ node }
						fields={ fields }
						onChange={ ( patch ) => replaceAt( i, { ...node, ...patch } ) }
						onRemove={ () => removeAt( i ) }
					/>
				)
			) }

			<Button
				variant="secondary"
				onClick={ () => append( { field: "", operator: "equals", value: "" } ) }
			>
				{ __( "Add rule", "trinity-forms" ) }
			</Button>

			{ depth < MAX_DEPTH && (
				<Button
					variant="tertiary"
					onClick={ () =>
						append( { match: "any", conditions: [ { field: "", operator: "equals", value: "" } ] } )
					}
				>
					{ __( "Add group", "trinity-forms" ) }
				</Button>
			) }

			{ depth > 0 && (
				<Button isDestructive variant="tertiary" onClick={ onRemove }>
					{ __( "Remove group", "trinity-forms" ) }
				</Button>
			) }
		</>
	);
}

/** Is there a real, answerable condition anywhere in here? */
function hasAnyCondition( group ) {
	return ( group.conditions || [] ).some( ( node ) =>
		isGroup( node ) ? hasAnyCondition( node ) : true
	);
}

export function ConditionalRules( { value, onChange, fields } ) {
	const rules = { ...DEFAULTS, ...( value || {} ) };

	// An empty rule set must clear the attribute entirely rather than persist
	// `{conditions: []}` — schema_from_blocks() ignores that, but leaving it behind
	// would serialize noise into every block comment. A group containing only empty
	// groups counts as empty too.
	const commit = ( next ) => onChange( hasAnyCondition( next ) ? next : undefined );

	if ( ! fields.length ) {
		return (
			<p style={ { color: "#50575e" } }>
				{ __( "Add another field to this form first — a rule needs a field to test.", "trinity-forms" ) }
			</p>
		);
	}

	return (
		<>
			{ hasAnyCondition( rules ) && (
				<SelectControl
					__next40pxDefaultSize
					label={ __( "Then", "trinity-forms" ) }
					value={ rules.action }
					options={ [
						{ value: "show", label: __( "Show this field", "trinity-forms" ) },
						{ value: "hide", label: __( "Hide this field", "trinity-forms" ) },
					] }
					onChange={ ( action ) => commit( { ...rules, action } ) }
				/>
			) }

			<ConditionList
				group={ rules }
				fields={ fields }
				depth={ 0 }
				onChange={ commit }
				onRemove={ () => onChange( undefined ) }
			/>
		</>
	);
}
