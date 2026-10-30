/**
 * Visual builder shell: palette | canvas | field panel, over the Builder_REST
 * contract.
 *
 * The client never parses or serializes block markup — it fetches a block list,
 * edits it, and posts it back. All the fidelity lives server-side; see blocks.js for
 * the rules this file has to respect.
 */
import {
	closestCenter,
	DndContext,
	DragOverlay,
	KeyboardSensor,
	PointerSensor,
	useDraggable,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import { sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import apiFetch from '@wordpress/api-fetch';
import { Button, Modal, Notice, SelectControl, Spinner, TextareaControl, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { attrValue, isField, keyOf, newField, schemaFor, toPayload } from './blocks';
import Canvas from './canvas';
import FieldPanel from './field-panel';
import useHistory from './use-history';

const PALETTE_PREFIX = 'palette::';

/**
 * A palette entry that can be both clicked (append — the accessible fallback) and
 * dragged onto the canvas to drop at a specific position. The drag id is prefixed so
 * onDragEnd can tell a palette drag from a canvas reorder.
 */
function PaletteItem( { entry, onAdd } ) {
	const { attributes, listeners, setNodeRef, isDragging } = useDraggable( {
		id: `${ PALETTE_PREFIX }${ entry.name }`,
		data: { type: 'palette', name: entry.name, title: entry.title },
	} );
	return (
		<Button
			ref={ setNodeRef }
			variant="secondary"
			className={ `tf-builder__palette-item${ isDragging ? ' is-dragging' : '' }` }
			onClick={ () => onAdd( entry.name ) }
			{ ...attributes }
			{ ...listeners }
		>
			{ entry.title }
		</Button>
	);
}

export default function App( { config } ) {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const { blocks, set: setBlocks, undo, redo, reset, canUndo, canRedo } = useHistory( [] );
	const [ palette, setPalette ] = useState( [] );
	const [ locked, setLocked ] = useState( [] );
	const [ hash, setHash ] = useState( '' );
	const [ title, setTitle ] = useState( '' );
	const [ status, setStatus ] = useState( 'draft' );
	const [ canPublish, setCanPublish ] = useState( false );
	const [ selectedKey, setSelectedKey ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ conflict, setConflict ] = useState( false );
	// A form stored in a format the builder can't show. It would look empty here, and
	// saving would replace the real fields with nothing.
	const [ uneditable, setUneditable ] = useState( false );
	const [ dirty, setDirty ] = useState( false );
	const [ activeDrag, setActiveDrag ] = useState( null );
	const [ copied, setCopied ] = useState( false );
	const [ copyFailed, setCopyFailed ] = useState( false );
	const shortcode = `[trinity-forms id="${ config.formId }"]`;

	// The one question every first-time user has on this screen is "how do I put
	// this on a page?", and until now the builder never answered it: you had to
	// find your way back to the forms list to see the shortcode at all.
	const copyShortcode = async () => {
		try {
			if ( navigator.clipboard?.writeText ) {
				await navigator.clipboard.writeText( shortcode );
			} else {
				// navigator.clipboard only exists in a secure context, and plenty of
				// sites still run wp-admin over plain http. The throwaway textarea is
				// the only thing that copies there.
				const ta = document.createElement( 'textarea' );
				ta.value = shortcode;
				ta.setAttribute( 'readonly', '' );
				ta.style.position = 'fixed';
				ta.style.opacity = '0';
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				document.body.removeChild( ta );
			}
			setCopied( true );
			setCopyFailed( false );
			setTimeout( () => setCopied( false ), 2000 );
		} catch ( e ) {
			// Copying can fail: a denied permission, an unfocused document, an odd
			// browser. Saying nothing would leave someone pasting an empty clipboard
			// into a page and wondering why the form never appears, so select the
			// shortcode for them and say what to press.
			setCopyFailed( true );
			const el = document.querySelector( '.tf-builder__shortcode-code' );
			if ( el ) {
				const r = document.createRange();
				r.selectNodeContents( el );
				const sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange( r );
			}
			setTimeout( () => setCopyFailed( false ), 4000 );
		}
	};

	// Submit text and what happens after submit: the same settings (Form_Basics)
	// the block editor's settings box writes, so the two editors never disagree.
	const [ settings, setSettings ] = useState( null );
	const [ pages, setPages ] = useState( [] );
	const [ settingsOpen, setSettingsOpen ] = useState( false );
	const changeSetting = ( key, value ) => {
		setSettings( ( prev ) => ( { ...( prev || {} ), [ key ]: value } ) );
		setDirty( true );
	};

	const [ templatesOpen, setTemplatesOpen ] = useState( false );
	const [ templates, setTemplates ] = useState( null );

	const sensors = useSensors(
		// A small distance threshold so a click to select/append isn't read as a drag.
		useSensor( PointerSensor, { activationConstraint: { distance: 4 } } ),
		useSensor( KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates } )
	);

	const path = `/trinity-forms/v1/builder/forms/${ config.formId }`;

	useEffect( () => {
		apiFetch( { path } )
			.then( ( data ) => {
				reset( data.blocks || [] );
				setPalette( data.palette || [] );
				setLocked( Array.isArray( data.locked ) ? data.locked : [] );
				setHash( data.content_hash );
				setTitle( data.title || '' );
				setStatus( data.status === 'publish' ? 'publish' : 'draft' );
				setCanPublish( !! data.can_publish );
				setUneditable( !! data.uneditable );
				setSettings( data.settings || null );
				setPages( Array.isArray( data.pages ) ? data.pages : [] );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setNotice( {
					status: 'error',
					text: ( err && err.message ) || __( 'Could not load this form.', 'trinity-forms' ),
				} );
				setLoading( false );
			} );
	}, [ path ] );

	// Losing unsaved edits to a stray click is its own kind of data loss.
	useEffect( () => {
		const warn = ( e ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	useEffect( () => {
		const onKey = ( e ) => {
			if ( ! ( e.metaKey || e.ctrlKey ) || e.key.toLowerCase() !== 'z' ) {
				return;
			}
			// Let a text field keep its own undo stack; only take over outside one.
			const tag = e.target && e.target.tagName;
			if ( tag === 'INPUT' || tag === 'TEXTAREA' ) {
				return;
			}
			e.preventDefault();
			if ( e.shiftKey ) {
				redo();
			} else {
				undo();
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ undo, redo ] );

	const commit = ( next, mergeKey = null ) => {
		setBlocks( next, mergeKey );
		setDirty( true );
	};

	const ids = blocks.map( ( b, i ) => keyOf( b, i ) );
	const selectedIndex = ids.indexOf( selectedKey );
	const selected = selectedIndex >= 0 ? blocks[ selectedIndex ] : null;

	const addField = ( name ) => insertField( name, blocks.length );

	// Screen-reader announcements in words: dnd-kit's defaults read out internal ids
	// ("Draggable item b24 was dropped over droppable area b0").
	const labelForId = ( id ) => {
		const sid = String( id );
		if ( sid.startsWith( PALETTE_PREFIX ) ) {
			const entry = palette.find( ( p ) => p.name === sid.slice( PALETTE_PREFIX.length ) );
			return entry ? entry.title : __( 'new field', 'trinity-forms' );
		}
		if ( sid === 'tf-canvas-end' ) {
			return __( 'the end of the form', 'trinity-forms' );
		}
		const i = ids.indexOf( id );
		const b = i >= 0 ? blocks[ i ] : null;
		if ( ! b ) {
			return __( 'field', 'trinity-forms' );
		}
		const label = isField( b ) ? attrValue( b, schemaFor( palette, b.name ), 'label' ) : b.title;
		// A field with no label attribute (Custom HTML) is announced by its palette
		// title, the same name its canvas card shows, never as its raw id.
		const entry = palette.find( ( p ) => p.name === b.name );
		return label || ( entry && entry.title ) || ( b.attrs && b.attrs.fieldId ) || b.name;
	};
	const position = ( id ) => {
		const i = ids.indexOf( id );
		/* translators: 1: position, 2: total number of fields. */
		return i >= 0 ? sprintf( __( 'position %1$d of %2$d', 'trinity-forms' ), i + 1, ids.length ) : '';
	};
	const announcements = {
		onDragStart: ( { active } ) =>
			/* translators: %s: field label. */
			sprintf( __( 'Picked up %s.', 'trinity-forms' ), labelForId( active.id ) ),
		onDragOver: ( { active, over } ) =>
			over
				? /* translators: 1: dragged field label, 2: field it is over, 3: position text. */
				  sprintf( __( '%1$s is over %2$s, %3$s.', 'trinity-forms' ), labelForId( active.id ), labelForId( over.id ), position( over.id ) )
				: /* translators: %s: field label. */
				  sprintf( __( '%s is no longer over a drop position.', 'trinity-forms' ), labelForId( active.id ) ),
		onDragEnd: ( { active, over } ) =>
			over
				? /* translators: 1: dragged field label, 2: target field label. */
				  sprintf( __( '%1$s was dropped at %2$s.', 'trinity-forms' ), labelForId( active.id ), labelForId( over.id ) )
				: /* translators: %s: field label. */
				  sprintf( __( '%s was dropped outside the form; nothing changed.', 'trinity-forms' ), labelForId( active.id ) ),
		onDragCancel: ( { active } ) =>
			/* translators: %s: field label. */
			sprintf( __( 'Moving %s was cancelled.', 'trinity-forms' ), labelForId( active.id ) ),
	};

	// Insert a new field at a specific position (drag-drop placement); clamps to the
	// list bounds and selects the new field so its settings panel opens immediately.
	const insertField = ( name, index ) => {
		const block = newField( name, blocks );
		// Rich Text defaults to "Message" and Payment summary to "Total" — the same
		// labels a Textarea and a Calculation already carry. Two cards (and two View
		// columns) called "Message" cannot be told apart, so number the newcomer.
		const schema = schemaFor( palette, name );
		const base = schema.label && schema.label.default;
		if ( base ) {
			const used = new Set(
				blocks.filter( isField ).map( ( b ) => attrValue( b, schemaFor( palette, b.name ), 'label' ) )
			);
			if ( used.has( base ) ) {
				let n = 2;
				while ( used.has( `${ base } ${ n }` ) ) {
					n++;
				}
				block.attrs = { ...block.attrs, label: `${ base } ${ n }` };
			}
		}
		const at = Math.max( 0, Math.min( index, blocks.length ) );
		const next = [ ...blocks.slice( 0, at ), block, ...blocks.slice( at ) ];
		commit( next );
		setSelectedKey( keyOf( block, at ) );
	};

	const onDragStart = ( { active } ) => {
		const id = String( active.id );
		if ( id.startsWith( PALETTE_PREFIX ) ) {
			setActiveDrag( { type: 'palette', label: active.data.current?.title || id } );
		} else {
			const i = ids.indexOf( active.id );
			const b = i >= 0 ? blocks[ i ] : null;
			setActiveDrag( { type: 'row', label: b ? ( b.attrs?.label || b.title || b.name ) : id } );
		}
	};

	// One handler for both gestures: a palette drag inserts a new field at the drop
	// position; a canvas drag reorders. 'tf-canvas-end' (and a drop with no row under
	// it) means the end of the list — including dropping into an empty form.
	const onDragEnd = ( { active, over } ) => {
		setActiveDrag( null );
		const activeId = String( active.id );
		if ( activeId.startsWith( PALETTE_PREFIX ) ) {
			const name = active.data.current?.name;
			if ( ! name ) {
				return;
			}
			let index = blocks.length;
			if ( over && over.id !== 'tf-canvas-end' ) {
				const oi = ids.indexOf( over.id );
				if ( oi >= 0 ) {
					index = oi;
				}
			}
			insertField( name, index );
			return;
		}
		if ( over && active.id !== over.id ) {
			reorder( ids.indexOf( active.id ), ids.indexOf( over.id ) );
		}
	};

	// mergeKey collapses consecutive edits to the same attribute of the same field, so
	// undo rewinds "the label I typed", not one character of it.
	const updateSelected = ( updated, mergeKey = null ) =>
		commit(
			blocks.map( ( b, i ) => ( i === selectedIndex ? updated : b ) ),
			mergeKey === null ? null : `${ selectedKey }:${ mergeKey }`
		);

	const move = ( index, delta ) => {
		const target = index + delta;
		if ( target < 0 || target >= blocks.length ) {
			return;
		}
		const next = [ ...blocks ];
		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
		commit( next );
	};

	const reorder = ( from, to ) => {
		if ( from < 0 || to < 0 ) {
			return;
		}
		const next = [ ...blocks ];
		next.splice( to, 0, next.splice( from, 1 )[ 0 ] );
		commit( next );
	};

	const remove = ( index ) => {
		if ( ids[ index ] === selectedKey ) {
			setSelectedKey( null );
		}
		commit( blocks.filter( ( _, i ) => i !== index ) );
	};

	const openTemplates = () => {
		setTemplatesOpen( true );
		if ( templates === null ) {
			apiFetch( { path: '/trinity-forms/v1/builder/templates' } )
				.then( ( data ) => setTemplates( Array.isArray( data ) ? data : [] ) )
				.catch( () => setTemplates( [] ) );
		}
	};

	// Loading a template replaces the canvas. Guard existing work behind a confirm so
	// a stray click never wipes a form; the empty canvas skips the prompt.
	const pickTemplate = ( tpl ) => {
		if (
			blocks.length &&
			// eslint-disable-next-line no-alert
			! window.confirm(
				__( 'Replace the current form with this template? Your unsaved changes will be lost.', 'trinity-forms' )
			)
		) {
			return;
		}
		commit( tpl.blocks || [] );
		setSelectedKey( null );
		setDirty( true );
		setTemplatesOpen( false );
	};

	const save = () => {
		setSaving( true );
		setNotice( null );
		apiFetch( {
			path,
			method: 'POST',
			data: {
				content_hash: hash,
				title,
				status,
				blocks: toPayload( blocks ),
				...( settings ? { settings } : {} ),
			},
		} )
			.then( ( data ) => {
				// Re-seed from the server's answer: it re-derives the refs, and a stale
				// ref would resolve against the wrong parse on the next save. This is a
				// new baseline, not an edit, so the history starts over here too.
				reset( data.blocks || [] );
				setHash( data.content_hash );
				if ( data.title ) {
					setTitle( data.title );
				}
				if ( data.status ) {
					setStatus( data.status );
				}
				setSelectedKey( null );
				setDirty( false );
				setSaving( false );
				setNotice( {
					status: 'success',
					text:
						data.status === 'publish'
							? __( 'Form saved. It is published, so it shows wherever you embed it.', 'trinity-forms' )
							: __( 'Form saved as a draft. Visitors will not see it until you publish it.', 'trinity-forms' ),
				} );
			} )
			.catch( ( err ) => {
				setSaving( false );
				// A 409 is not an error to report and forget: the form moved under us,
				// so every ref we hold is suspect. Make the user choose.
				const status = err && err.data ? err.data.status : null;
				if ( status === 409 || ( err && err.code === 'trinity_forms_builder_conflict' ) ) {
					setConflict( true );
					return;
				}
				setNotice( {
					status: 'error',
					text: ( err && err.message ) || __( 'Could not save this form.', 'trinity-forms' ),
				} );
			} );
	};

	if ( loading ) {
		return (
			<div className="tf-builder is-loading">
				<Spinner />
			</div>
		);
	}

	return (
		<div className="tf-builder">
			<div className="tf-builder__bar">
				<input
					type="text"
					className="tf-builder__title-input"
					value={ title }
					placeholder={ __( 'Form title', 'trinity-forms' ) }
					aria-label={ __( 'Form title', 'trinity-forms' ) }
					onChange={ ( e ) => {
						setTitle( e.target.value );
						setDirty( true );
					} }
				/>
				<div className="tf-builder__bar-actions">
					<Button
						icon="undo"
						label={ __( 'Undo', 'trinity-forms' ) }
						shortcut={ __( 'Ctrl+Z', 'trinity-forms' ) }
						disabled={ ! canUndo }
						onClick={ undo }
					/>
					<Button
						icon="redo"
						label={ __( 'Redo', 'trinity-forms' ) }
						shortcut={ __( 'Ctrl+Shift+Z', 'trinity-forms' ) }
						disabled={ ! canRedo }
						onClick={ redo }
					/>
					<Button variant="tertiary" onClick={ openTemplates }>
						{ __( 'Templates', 'trinity-forms' ) }
					</Button>
					{ settings && (
						<Button
							variant="tertiary"
							className="tf-builder__settings-button"
							onClick={ () => setSettingsOpen( true ) }
							label={ __( 'Submit button text, and what visitors see after they submit', 'trinity-forms' ) }
							showTooltip
						>
							{ __( 'Settings', 'trinity-forms' ) }
						</Button>
					) }
					{ config.formId > 0 && (
						<div className="tf-builder__shortcode">
							<code
								className="tf-builder__shortcode-code"
								onClick={ ( e ) => {
									const r = document.createRange();
									r.selectNodeContents( e.currentTarget );
									const sel = window.getSelection();
									sel.removeAllRanges();
									sel.addRange( r );
								} }
							>
								{ shortcode }
							</code>
							<Button
								variant="tertiary"
								onClick={ copyShortcode }
								label={ __( 'Copy the shortcode that puts this form on a page or post', 'trinity-forms' ) }
								showTooltip
							>
								{ copied && __( 'Copied', 'trinity-forms' ) }
								{ copyFailed && __( 'Press Ctrl+C to copy', 'trinity-forms' ) }
								{ ! copied &&
									! copyFailed &&
									__( 'Copy shortcode', 'trinity-forms' ) }
							</Button>
						</div>
					) }
					{ config.createPageUrl && (
						<Button
							variant="tertiary"
							href={ config.createPageUrl }
							label={ __( 'Make a draft page holding this form and open it. Save your changes first.', 'trinity-forms' ) }
							showTooltip
						>
							{ __( 'Create a page', 'trinity-forms' ) }
						</Button>
					) }
					{ config.blockEditorUrl && (
						<Button
							variant="tertiary"
							href={ `${ config.blockEditorUrl }#trinity-forms-notifications` }
							// Notifications live in a metabox the canvas does not host, so
							// without a way in from here a form built entirely in the builder
							// silently emails nobody. The link opens that panel directly.
							label={ __( 'Set up the emails sent when this form is submitted', 'trinity-forms' ) }
							showTooltip
						>
							{ __( 'Notifications', 'trinity-forms' ) }
						</Button>
					) }
					{ config.blockEditorUrl && (
						<Button variant="tertiary" href={ config.blockEditorUrl }>
							{ __( 'Open in block editor', 'trinity-forms' ) }
						</Button>
					) }
					{ config.aiUrl && (
						<Button
							variant="tertiary"
							href={ config.aiUrl }
							label={ __( 'Describe your form and let AI draft the fields (opens the block editor)', 'trinity-forms' ) }
							showTooltip
						>
							{ __( 'Draft with AI', 'trinity-forms' ) }
						</Button>
					) }
					<select
						className="tf-builder__status"
						aria-label={ __( 'Form status', 'trinity-forms' ) }
						value={ status }
						onChange={ ( e ) => {
							setStatus( e.target.value );
							setDirty( true );
						} }
					>
						<option value="draft">{ __( 'Draft', 'trinity-forms' ) }</option>
						{ ( canPublish || status === 'publish' ) && (
							<option value="publish">{ __( 'Published', 'trinity-forms' ) }</option>
						) }
					</select>
					<Button variant="primary" onClick={ save } disabled={ saving || conflict || uneditable }>
						{ saving ? __( 'Saving…', 'trinity-forms' ) : __( 'Save form', 'trinity-forms' ) }
					</Button>
				</div>
			</div>

			{ uneditable && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							"This form was saved in a format the builder can't open, so its fields don't show here. It still works on your site, and saving is turned off so nothing gets overwritten.",
							'trinity-forms'
						) }
					</p>
				</Notice>
			) }

			{ conflict && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'This form changed somewhere else, most likely in the block editor in another tab. Your edits here have not been saved, and saving now would overwrite that change.',
							'trinity-forms'
						) }
					</p>
					<Button variant="primary" onClick={ () => window.location.reload() }>
						{ __( 'Reload the form', 'trinity-forms' ) }
					</Button>
				</Notice>
			) }

			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			{ settingsOpen && settings && (
				<Modal
					title={ __( 'Form settings', 'trinity-forms' ) }
					onRequestClose={ () => setSettingsOpen( false ) }
					className="tf-builder__settings-modal"
				>
					<TextControl
						label={ __( 'Submit button text', 'trinity-forms' ) }
						value={ settings.submit_button_text || '' }
						placeholder={ __( 'Submit', 'trinity-forms' ) }
						onChange={ ( v ) => changeSetting( 'submit_button_text', v ) }
					/>
					<SelectControl
						label={ __( 'After submit', 'trinity-forms' ) }
						value={ settings.confirmation_type || 'text' }
						options={ [
							{ value: 'text', label: __( 'Show a message', 'trinity-forms' ) },
							{ value: 'page', label: __( 'Go to a page', 'trinity-forms' ) },
							{ value: 'redirect', label: __( 'Go to a URL', 'trinity-forms' ) },
						] }
						onChange={ ( v ) => changeSetting( 'confirmation_type', v ) }
					/>
					{ ( settings.confirmation_type || 'text' ) === 'text' && (
						<TextareaControl
							label={ __( 'Confirmation message', 'trinity-forms' ) }
							value={ settings.confirmation_message || '' }
							placeholder={ __( 'Thank you!', 'trinity-forms' ) }
							onChange={ ( v ) => changeSetting( 'confirmation_message', v ) }
						/>
					) }
					{ settings.confirmation_type === 'page' && (
						<SelectControl
							label={ __( 'Page', 'trinity-forms' ) }
							value={ String( settings.confirmation_page_id || 0 ) }
							options={ [
								{ value: '0', label: __( 'Choose a page', 'trinity-forms' ) },
								...pages.map( ( pg ) => ( { value: String( pg.id ), label: pg.title } ) ),
							] }
							onChange={ ( v ) => changeSetting( 'confirmation_page_id', parseInt( v, 10 ) || 0 ) }
						/>
					) }
					{ settings.confirmation_type === 'redirect' && (
						<TextControl
							label={ __( 'Redirect URL', 'trinity-forms' ) }
							type="url"
							value={ settings.redirect_url || '' }
							placeholder="https://…"
							onChange={ ( v ) => changeSetting( 'redirect_url', v ) }
						/>
					) }
					<p className="tf-builder__palette-hint">
						{ __( 'These are saved with the form when you press Save form.', 'trinity-forms' ) }
					</p>
					<Button variant="primary" onClick={ () => setSettingsOpen( false ) }>
						{ __( 'Done', 'trinity-forms' ) }
					</Button>
				</Modal>
			) }

			{ templatesOpen && (
				<Modal
					title={ __( 'Start from a template', 'trinity-forms' ) }
					onRequestClose={ () => setTemplatesOpen( false ) }
					className="tf-builder__templates-modal"
				>
					{ templates === null ? (
						<Spinner />
					) : (
						<div className="tf-builder__templates-grid">
							{ templates.map( ( tpl ) => (
								<button
									key={ tpl.slug }
									type="button"
									className="tf-builder__template-card"
									onClick={ () => pickTemplate( tpl ) }
								>
									<span className={ `dashicons dashicons-${ tpl.icon }` } aria-hidden="true" />
									<strong>{ tpl.label }</strong>
									<span className="tf-builder__template-desc">{ tpl.desc }</span>
									<span className="tf-builder__template-count">
										{ ( tpl.blocks || [] ).length }{ ' ' }
										{ __( 'fields', 'trinity-forms' ) }
									</span>
								</button>
							) ) }
						</div>
					) }
				</Modal>
			) }

			{ /* Nothing to edit: an empty canvas here would contradict the notice above. */ }
			{ ! uneditable && (
			<DndContext
				accessibility={ { announcements } }
				sensors={ sensors }
				collisionDetection={ closestCenter }
				onDragStart={ onDragStart }
				onDragEnd={ onDragEnd }
				onDragCancel={ () => setActiveDrag( null ) }
			>
				<div className="tf-builder__grid">
					<div className="tf-builder__palette">
						<h2>{ __( 'Add a field', 'trinity-forms' ) }</h2>
						<p className="tf-builder__palette-hint">
							{ __( 'Click to append, or drag onto the canvas to place.', 'trinity-forms' ) }
						</p>
						{ palette.filter( ( p ) => p.addable !== false ).map( ( p ) => (
							<PaletteItem key={ p.name } entry={ p } onAdd={ addField } />
						) ) }
						{ locked.length > 0 && (
							<div className="tf-builder__locked">
								<h3>{ __( 'Pro fields', 'trinity-forms' ) }</h3>
								<p className="tf-builder__palette-hint">
									{ __( 'Activate your Pro license to add these.', 'trinity-forms' ) }{ ' ' }
									{ config.settingsUrl && (
										<a href={ config.settingsUrl }>{ __( 'Enter license key', 'trinity-forms' ) }</a>
									) }
								</p>
								{ locked.map( ( p ) => (
									<Button
										key={ p.name }
										variant="secondary"
										className="tf-builder__palette-item is-locked"
										disabled
										aria-disabled="true"
										title={ __( 'Requires an active Pro license', 'trinity-forms' ) }
									>
										<span className="dashicons dashicons-lock" aria-hidden="true" /> { p.title }
									</Button>
								) ) }
							</div>
						) }
					</div>

					<Canvas
						blocks={ blocks }
						palette={ palette }
						selectedKey={ selectedKey }
						onSelect={ setSelectedKey }
						onMove={ move }
						onRemove={ remove }
						blockEditorUrl={ config.blockEditorUrl }
						onOpenTemplates={ openTemplates }
					/>

					<FieldPanel
						block={ selected && isField( selected ) ? selected : null }
						blocks={ blocks }
						palette={ palette }
						onChange={ updateSelected }
					/>
				</div>

				<DragOverlay>
					{ activeDrag ? (
						<div className="tf-builder__drag-overlay">{ activeDrag.label }</div>
					) : null }
				</DragOverlay>
			</DndContext>
			) }
		</div>
	);
}
