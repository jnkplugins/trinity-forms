/**
 * A block the canvas can hold but not edit: a foreign block (columns, prose), a
 * nested one, or a field type this site can't register.
 *
 * It renders as a locked card on purpose. The alternative — pretending to edit it —
 * is how a builder eats a form. The card can be moved and deleted, and the block
 * editor link is the way to actually change it.
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function OpaqueCard( { block, blockEditorUrl } ) {
	// A Pro field on a site that now runs the free version: name it the way people
	// know it ("Signature (Pro)"), not "trinity-forms/field-signature", and skip the
	// block editor link, which can only show an "unsupported block" box there.
	const proBlocks = ( window.TrinityFormsBuilder && window.TrinityFormsBuilder.proBlocks ) || {};
	const proName = proBlocks[ block.name ];
	if ( proName ) {
		return (
			<div className="tf-builder__opaque">
				<span className="tf-builder__opaque-badge">{ __( 'Pro field', 'trinity-forms' ) }</span>
				<strong>{ proName }</strong>
				<p>
					{ __( 'Needs Trinity Forms Pro. Kept exactly as it is; visitors do not see it until Pro is active again.', 'trinity-forms' ) }
				</p>
			</div>
		);
	}
	return (
		<div className="tf-builder__opaque">
			<span className="tf-builder__opaque-badge">{ __( 'Advanced block', 'trinity-forms' ) }</span>
			<strong>{ block.title || block.name }</strong>
			<p>
				{ __( 'Kept exactly as it is. Edit it in the block editor.', 'trinity-forms' ) }
			</p>
			{ blockEditorUrl && (
				<Button variant="link" href={ blockEditorUrl }>
					{ sprintf(
						/* translators: %s: block title, e.g. "Columns". */
						__( 'Open %s in the block editor', 'trinity-forms' ),
						block.title || block.name
					) }
				</Button>
			) }
		</div>
	);
}
