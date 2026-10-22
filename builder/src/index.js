/**
 * Visual builder entry point. Mounts the app into the container Form_Builder renders.
 */
import { createRoot } from '@wordpress/element';
import App from './app';
import './style.scss';

document.addEventListener( 'DOMContentLoaded', function () {
	const root = document.getElementById( 'trinity-forms-builder-root' );
	if ( ! root ) {
		return;
	}
	const config = window.TrinityFormsBuilder || { formId: 0 };
	if ( ! config.formId ) {
		return;
	}
	createRoot( root ).render( <App config={ config } /> );
} );
