/**
 * A non-interactive preview of a field, so the canvas shows the form rather than a
 * list of names.
 *
 * Fidelity is deliberately close to the front end without rendering it for real (that
 * would need the theme's CSS and the server's markup): the real control, disabled,
 * reflecting the field's configured attributes (options, min/max, defaults) so the
 * canvas reads like the form a visitor would see.
 *
 * Anything without a case here falls back to a plain text input. A field type this
 * file doesn't know still edits and saves correctly; it just previews plainly.
 */
import { __, sprintf } from '@wordpress/i18n';
import { attrValue } from './blocks';

function Options( { block, schema, type } ) {
	const options = attrValue( block, schema, 'options' ) || [];
	const shown = options.slice( 0, 4 );
	return (
		<div className="tf-preview__options">
			{ shown.map( ( o, i ) => (
				<label key={ i }>
					<input type={ type } disabled />
					<span>{ o.label }</span>
				</label>
			) ) }
			{ options.length > shown.length && (
				<span className="tf-preview__more">
					{ sprintf(
						/* translators: %d: number of further choices not shown. */
						__( '+ %d more', 'trinity-forms' ),
						options.length - shown.length
					) }
				</span>
			) }
			{ ! options.length && (
				<span className="tf-preview__more">{ __( 'No choices yet', 'trinity-forms' ) }</span>
			) }
		</div>
	);
}

export default function FieldPreview( { block, schema } ) {
	const type = block.name.replace( 'trinity-forms/field-', '' );
	const placeholder = attrValue( block, schema, 'placeholder' ) || '';

	switch ( type ) {
		case 'textarea':
			return <textarea className="tf-preview__control" disabled rows={ 3 } placeholder={ placeholder } />;

		case 'select': {
			const options = attrValue( block, schema, 'options' ) || [];
			return (
				<select className="tf-preview__control" disabled>
					<option>{ placeholder || __( 'Select an option', 'trinity-forms' ) }</option>
					{ options.slice( 0, 8 ).map( ( o, i ) => (
						<option key={ i }>{ o.label }</option>
					) ) }
				</select>
			);
		}

		case 'country':
			return (
				<select className="tf-preview__control" disabled>
					<option>{ placeholder || __( 'Select a country', 'trinity-forms' ) }</option>
					<option>{ __( 'United States', 'trinity-forms' ) }</option>
					<option>{ __( 'United Kingdom', 'trinity-forms' ) }</option>
					<option>{ __( 'India', 'trinity-forms' ) }</option>
				</select>
			);

		case 'radio':
			return <Options block={ block } schema={ schema } type="radio" />;

		case 'checkbox':
			return <Options block={ block } schema={ schema } type="checkbox" />;

		case 'consent':
			return (
				<label className="tf-preview__consent">
					<input type="checkbox" disabled />
					<span>{ attrValue( block, schema, 'consentText' ) || __( 'Consent text', 'trinity-forms' ) }</span>
				</label>
			);

		case 'rating': {
			const max = Math.max( 1, attrValue( block, schema, 'max' ) || 5 );
			const val = Number( attrValue( block, schema, 'default' ) || 0 );
			return (
				<span className="tf-preview__stars">
					{ Array.from( { length: max } ).map( ( _, i ) => ( i < val ? '★' : '☆' ) ).join( '' ) }
				</span>
			);
		}

		case 'section':
			return (
				<div className="tf-preview__section">
					<h3>{ attrValue( block, schema, 'label' ) || __( 'Section', 'trinity-forms' ) }</h3>
					{ !! attrValue( block, schema, 'description' ) && (
						<p>{ attrValue( block, schema, 'description' ) }</p>
					) }
				</div>
			);

		case 'html':
			return (
				<div className="tf-preview__html">
					<code>{ __( 'Custom HTML', 'trinity-forms' ) }</code>
				</div>
			);

		case 'hidden':
			return (
				<div className="tf-preview__html">
					<code>{ __( 'Hidden: not shown to visitors', 'trinity-forms' ) }</code>
				</div>
			);

		case 'page-break':
			return <div className="tf-preview__pagebreak">{ __( 'Page break', 'trinity-forms' ) }</div>;

		case 'name':
			return (
				<div className="tf-preview__row">
					<input className="tf-preview__control" disabled placeholder={ __( 'First', 'trinity-forms' ) } />
					<input className="tf-preview__control" disabled placeholder={ __( 'Last', 'trinity-forms' ) } />
				</div>
			);

		case 'address':
			return (
				<div className="tf-preview__stack">
					<input className="tf-preview__control" disabled placeholder={ __( 'Street', 'trinity-forms' ) } />
					<div className="tf-preview__row">
						<input className="tf-preview__control" disabled placeholder={ __( 'City', 'trinity-forms' ) } />
						<input className="tf-preview__control" disabled placeholder={ __( 'Postcode', 'trinity-forms' ) } />
					</div>
				</div>
			);

		case 'range': {
			const min = attrValue( block, schema, 'min' ) ?? 0;
			const max = attrValue( block, schema, 'max' ) ?? 100;
			const def = attrValue( block, schema, 'default' );
			return (
				<div className="tf-preview__range">
					<input
						className="tf-preview__control"
						type="range"
						min={ min }
						max={ max }
						defaultValue={ def ?? '' }
						disabled
						readOnly
					/>
					<span className="tf-preview__more">
						{ min }–{ max }{ def !== undefined && def !== '' ? ` (${ def })` : '' }
					</span>
				</div>
			);
		}

		case 'date':
			return <input className="tf-preview__control" type="date" disabled />;

		case 'time':
			return <input className="tf-preview__control" type="time" disabled />;

		case 'number':
		case 'calculation': {
			const min = attrValue( block, schema, 'min' );
			const max = attrValue( block, schema, 'max' );
			const step = attrValue( block, schema, 'step' );
			return (
				<input
					className="tf-preview__control"
					type="number"
					min={ min }
					max={ max }
					step={ step }
					disabled
					placeholder={ placeholder }
				/>
			);
		}

		case 'file':
			return (
				<div className="tf-preview__drop">
					{ __( 'Drop a file here or browse', 'trinity-forms' ) }
				</div>
			);

		case 'signature':
			return <div className="tf-preview__signature">{ __( 'Signature pad', 'trinity-forms' ) }</div>;

		case 'payment':
			return (
				<div className="tf-preview__html">
					<code>
						{ ( attrValue( block, schema, 'currency' ) || 'USD' ) + ' ' + ( attrValue( block, schema, 'amount' ) ?? '0.00' ) }
					</code>
				</div>
			);

		case 'repeater':
			return <div className="tf-preview__signature">{ __( 'Repeatable group', 'trinity-forms' ) }</div>;

		case 'email':
			return <input className="tf-preview__control" type="email" disabled placeholder={ placeholder || 'you@example.com' } />;

		case 'phone':
			return <input className="tf-preview__control" type="tel" disabled placeholder={ placeholder } />;

		default:
			return <input className="tf-preview__control" type="text" disabled placeholder={ placeholder } />;
	}
}
