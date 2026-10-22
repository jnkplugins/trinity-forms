/**
 * Undo/redo over the block list.
 *
 * A builder without undo is a builder people are afraid to touch, so this is table
 * stakes rather than polish.
 *
 * The blocks array is treated as immutable (blocks.js only ever returns new objects),
 * so history is just a stack of past values — no cloning, no diffing.
 *
 * Typing is coalesced: consecutive edits tagged with the same `mergeKey` (say, the
 * label of one field) collapse into a single entry, so one undo doesn't rewind a
 * single keystroke. A different key, or any structural change, starts a new entry.
 */
import { useCallback, useRef, useState } from '@wordpress/element';

const LIMIT = 100;

export default function useHistory( initial ) {
	const [ present, setPresent ] = useState( initial );
	const [ past, setPast ] = useState( [] );
	const [ future, setFuture ] = useState( [] );
	const lastKey = useRef( null );

	/**
	 * @param {Array}       next     The new blocks array.
	 * @param {string|null} mergeKey Coalesce with the previous entry when it matches.
	 */
	const set = useCallback(
		( next, mergeKey = null ) => {
			setPast( ( prev ) => {
				const merge = mergeKey !== null && mergeKey === lastKey.current && prev.length > 0;
				lastKey.current = mergeKey;
				if ( merge ) {
					return prev;
				}
				return [ ...prev, present ].slice( -LIMIT );
			} );
			setFuture( [] );
			setPresent( next );
		},
		[ present ]
	);

	const undo = useCallback( () => {
		setPast( ( prev ) => {
			if ( ! prev.length ) {
				return prev;
			}
			setFuture( ( f ) => [ present, ...f ] );
			setPresent( prev[ prev.length - 1 ] );
			lastKey.current = null;
			return prev.slice( 0, -1 );
		} );
	}, [ present ] );

	const redo = useCallback( () => {
		setFuture( ( f ) => {
			if ( ! f.length ) {
				return f;
			}
			setPast( ( p ) => [ ...p, present ] );
			setPresent( f[ 0 ] );
			lastKey.current = null;
			return f.slice( 1 );
		} );
	}, [ present ] );

	/** Re-seed after a save: the server's answer is the new baseline, not an edit. */
	const reset = useCallback( ( value ) => {
		setPresent( value );
		setPast( [] );
		setFuture( [] );
		lastKey.current = null;
	}, [] );

	return {
		blocks: present,
		set,
		undo,
		redo,
		reset,
		canUndo: past.length > 0,
		canRedo: future.length > 0,
	};
}
