import { createRoot } from '@wordpress/element'
import App from './app'

/**
 * Ahentic admin entry point.
 *
 * Mounts the React app into #ahentic-root (injected on admin screens).
 */
const container = document.getElementById( 'ahentic-root' )
if ( container ) {
	const root = createRoot( container )
	root.render( <App /> )
}
