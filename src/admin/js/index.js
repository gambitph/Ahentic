import { createRoot } from '@wordpress/element'
import App from './app'

/**
 * Ahentic entry point.
 *
 * Mounts the React sidebar into #ahentic-root (admin + front-end for capable users).
 */
const container = document.getElementById( 'ahentic-root' )
if ( container ) {
	const root = createRoot( container )
	root.render( <App /> )
}
