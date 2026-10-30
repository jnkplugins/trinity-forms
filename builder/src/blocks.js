/**
 * The client's view of the block contract served by Builder_REST.
 *
 * The server hands us blocks as { kind, name, attrs } (editable fields) or
 * { kind, ref, name, title } (everything else — foreign blocks, nested blocks, or a
 * premium field this site cannot register). We never parse or serialize block markup
 * here: that happens in PHP, because PHP's parser keeps attributes the block registry
 * doesn't declare, and the JS one drops them.
 *
 * The rules for this file:
 *   - Never invent an attribute the server didn't send.
 *   - Never drop one either. Editing a label must leave every other attribute — and
 *     every attribute we have no UI for — exactly as it arrived.
 *   - Opaque blocks are carried by ref alone. We must not send back anything else,
 *     because the server rebuilds them from its own parse.
 */

/** A stable key for React lists that survives reordering. */
export function keyOf( block, index ) {
	return block.key || `${ block.kind }-${ index }`;
}

export function isField( block ) {
	return block.kind === 'field';
}

/** The palette entry (and therefore the attribute schema) for a block. */
export function schemaFor( palette, name ) {
	const entry = palette.find( ( p ) => p.name === name );
	return entry ? entry.attributes || {} : {};
}

/**
 * What to show in a control: the block's own value, else the registry default.
 * The server deliberately sends attrs raw (not default-merged) so it can tell the
 * difference between "unset" and "set to the default".
 */
export function attrValue( block, schema, key ) {
	if ( block.attrs && Object.prototype.hasOwnProperty.call( block.attrs, key ) ) {
		return block.attrs[ key ];
	}
	const declared = schema[ key ];
	return declared && Object.prototype.hasOwnProperty.call( declared, 'default' )
		? declared.default
		: undefined;
}

/** True when the block type declares this attribute at all. */
export function declares( schema, key ) {
	return Object.prototype.hasOwnProperty.call( schema, key );
}

/** Immutably set one attribute, leaving the rest of the block untouched. */
export function setAttr( block, key, value ) {
	return { ...block, attrs: { ...block.attrs, [ key ]: value } };
}

/** Remove an attribute entirely (not the same as setting it to ''). */
export function unsetAttr( block, key ) {
	const attrs = { ...block.attrs };
	delete attrs[ key ];
	return { ...block, attrs };
}

/** A new field block, carrying only a fieldId — the server fills in the defaults. */
export function newField( name, existing ) {
	const slug = name.replace( 'trinity-forms/field-', '' ).replace( /-/g, '_' );
	const taken = new Set(
		( existing || [] ).map( ( b ) => ( b.attrs ? b.attrs.fieldId : null ) ).filter( Boolean )
	);
	// A field id doubles as its merge tag, so it must not shadow a built-in one.
	const reserved = [ 'all_fields', 'site_name', 'site_url', 'admin_email', 'date', 'ip', 'entry_id' ];
	// "date" is the {date} merge tag, so the first Date field is "date_field" rather
	// than a "date_2" that reads like the form's second date.
	const base = reserved.includes( slug ) ? `${ slug }_field` : slug;
	let id = base;
	let n = 2;
	while ( taken.has( id ) || reserved.includes( id ) ) {
		id = `${ base }_${ n++ }`;
	}
	return { kind: 'field', name, attrs: { fieldId: id } };
}

/**
 * Strip the payload down to what the contract accepts.
 *
 * Opaque blocks lose everything but their ref on purpose: the server resolves the ref
 * against its own copy of post_content, so anything else we sent would be ignored at
 * best. Sending it anyway would imply the client can author those bytes — it cannot.
 */
export function toPayload( blocks ) {
	return blocks.map( ( block ) =>
		isField( block )
			? { kind: 'field', name: block.name, attrs: block.attrs || {} }
			: { kind: 'opaque', ref: block.ref }
	);
}

/**
 * Fields available as the subject of a conditional rule (excluding the block itself).
 *
 * Labels go through attrValue() rather than reading attrs.label directly: the server
 * sends attributes raw, so a field left on its default label has no label attribute
 * at all, and reading it directly would show the field ID instead of "Email".
 */
export function conditionTargets( blocks, self, palette ) {
	return blocks
		.filter( ( b ) => isField( b ) && b !== self && b.attrs && b.attrs.fieldId )
		.map( ( b ) => {
			const schema  = schemaFor( palette, b.name );
			const options = attrValue( b, schema, 'options' );
			const entry   = {
				value: b.attrs.fieldId,
				label: attrValue( b, schema, 'label' ) || b.attrs.fieldId,
			};
			// Choice fields carry their choices so the rule editor can offer them.
			if ( Array.isArray( options ) && options.length ) {
				entry.choices = options;
			}
			return entry;
		} );
}
