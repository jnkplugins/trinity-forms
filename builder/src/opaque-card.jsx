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
