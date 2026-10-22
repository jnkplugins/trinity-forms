/**
 * The form canvas: a sortable list of blocks.
 *
 * Drag-and-drop is dnd-kit's SortableContext, which brings keyboard reordering and
 * touch support with it. The ↑/↓ buttons stay regardless — they're the obvious
 * affordance for anyone who never discovers the keyboard sensor, and cost nothing.
 */
import { useDroppable } from '@dnd-kit/core';
import {
	SortableContext,
	useSortable,
	rectSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { attrValue, isField, keyOf, schemaFor } from './blocks';
import FieldPreview from './field-preview';
import OpaqueCard from './opaque-card';

/**
 * A drop target that represents "the end of the list" — also the whole surface when
 * the form is empty. The parent DndContext resolves a drop here to an append.
 */
function EndZone( { empty, onOpenTemplates } ) {
	const { setNodeRef, isOver } = useDroppable( { id: 'tf-canvas-end' } );
	if ( empty ) {
		return (
			<div
				ref={ setNodeRef }
				className={ `tf-builder__canvas is-empty${ isOver ? ' is-over' : '' }` }
			>
				<p>{ __( 'This form is empty. Drag a field here, or click one on the left.', 'trinity-forms' ) }</p>
				{ onOpenTemplates && (
					<Button variant="secondary" onClick={ onOpenTemplates }>
						{ __( 'Or start from a template', 'trinity-forms' ) }
					</Button>
				) }
			</div>
		);
	}
	return (
		<div
			ref={ setNodeRef }
			className={ `tf-builder__end-zone${ isOver ? ' is-over' : '' }` }
			aria-hidden="true"
		/>
	);
}

/** Label, the real (disabled) control, help text — the shape a visitor would see. */
function titleFor( palette, block ) {
	const entry = ( palette || [] ).find( ( p ) => p.name === block.name );
	return ( entry && entry.title ) || block.title || block.name.replace( 'trinity-forms/field-', '' );
}

function FieldRow( { block, schema, palette } ) {
	// A block with no label attribute (Custom HTML) falls back to its palette title,
	// never to the raw "trinity-forms/field-html" block name.
	const label = attrValue( block, schema, 'label' ) || titleFor( palette, block );
	const required = !! attrValue( block, schema, 'required' );
	const help = attrValue( block, schema, 'help' );
	const hasRule = !! ( block.attrs && block.attrs.conditional );
	const type = block.name.replace( 'trinity-forms/field-', '' );
	// These render their own heading/marker; a second label above would be noise.
	const selfLabelled = [ 'section', 'page-break', 'consent' ].includes( type );

	return (
		<>
			{ ! selfLabelled && (
				<span className="tf-builder__field-label">
					{ label }
					{ required && <span className="tf-builder__req"> *</span> }
				</span>
			) }

			<div className="tf-builder__field-preview">
				<FieldPreview block={ block } schema={ schema } />
			</div>

			{ help && <span className="tf-builder__field-help">{ help }</span> }

			{ /* The field ID lives in the settings panel; on the canvas it was noise. */ }
			{ hasRule && (
				<span className="tf-builder__field-meta">
					<span className="tf-builder__badge">{ __( 'conditional', 'trinity-forms' ) }</span>
				</span>
			) }
		</>
	);
}

function SortableRow( props ) {
	const { id, index, total, block, palette, selected, onSelect, onMove, onRemove, blockEditorUrl } = props;
	const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable( { id } );
	const field = isField( block );
	const schema = field ? schemaFor( palette, block.name ) : {};
	// Half, third and quarter width fields sit side by side, as on the published
	// form, instead of every field filling the canvas.
	const width = field
		? parseInt( attrValue( block, schema, 'fieldWidth' ) || '', 10 )
		: 0;
	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.4 : 1,
		flexBasis:
			width > 0 && width < 100 ? `calc(${ width }% - 8px)` : '100%',
	};
	const name = field
		? attrValue( block, schema, 'label' ) || titleFor( palette, block )
		: block.title || block.name;

	return (
		<li
			ref={ setNodeRef }
			style={ style }
			className={ `tf-builder__row${ selected ? ' is-selected' : '' }${ field ? '' : ' is-opaque' }` }
		>
			<button
				type="button"
				className="tf-builder__drag"
				aria-label={ sprintf(
					/* translators: %s: block name. */
					__( 'Reorder %s', 'trinity-forms' ),
					name
				) }
				{ ...attributes }
				{ ...listeners }
			>
				⠿
			</button>

			<div className="tf-builder__row-body">
				{ field ? (
					<button
						type="button"
						className="tf-builder__row-select"
						aria-pressed={ selected }
						onClick={ () => onSelect( id ) }
					>
						<FieldRow block={ block } schema={ schema } palette={ palette } />
					</button>
				) : (
					<OpaqueCard block={ block } blockEditorUrl={ blockEditorUrl } />
				) }
			</div>

			<div className="tf-builder__row-actions">
				<Button
					size="small"
					icon="arrow-up-alt2"
					disabled={ index === 0 }
					label={ sprintf( /* translators: %s: block name. */ __( 'Move %s up', 'trinity-forms' ), name ) }
					onClick={ () => onMove( index, -1 ) }
				/>
				<Button
					size="small"
					icon="arrow-down-alt2"
					disabled={ index === total - 1 }
					label={ sprintf( /* translators: %s: block name. */ __( 'Move %s down', 'trinity-forms' ), name ) }
					onClick={ () => onMove( index, 1 ) }
				/>
				<Button
					size="small"
					icon="trash"
					isDestructive
					label={ sprintf( /* translators: %s: block name. */ __( 'Remove %s', 'trinity-forms' ), name ) }
					onClick={ () => onRemove( index ) }
				/>
			</div>
		</li>
	);
}

export default function Canvas( props ) {
	const { blocks, palette, selectedKey, onSelect, onMove, onRemove, blockEditorUrl, onOpenTemplates } = props;
	const ids = blocks.map( ( b, i ) => keyOf( b, i ) );

	// The parent App owns the DndContext (it spans the palette too), so this only
	// declares the sortable list + the end drop zone.
	if ( ! blocks.length ) {
		return <EndZone empty onOpenTemplates={ onOpenTemplates } />;
	}

	return (
		<div className="tf-builder__canvas">
			<SortableContext items={ ids } strategy={ rectSortingStrategy }>
				<ul className="tf-builder__list">
					{ blocks.map( ( block, index ) => (
						<SortableRow
							key={ ids[ index ] }
							id={ ids[ index ] }
							index={ index }
							total={ blocks.length }
							block={ block }
							palette={ palette }
							selected={ selectedKey === ids[ index ] }
							onSelect={ onSelect }
							onMove={ onMove }
							onRemove={ onRemove }
							blockEditorUrl={ blockEditorUrl }
						/>
					) ) }
				</ul>
			</SortableContext>
			<EndZone />
		</div>
	);
}
