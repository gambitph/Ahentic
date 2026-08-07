/**
 * Overlay when this window is viewer-only for a live session.
 */

import { __ } from '@wordpress/i18n'

const TITLE = __( 'This agent is active in another window', 'ahentic' )

/**
 * @param {Object}   props
 * @param {Function} [props.onStop]   Stop/cancel the run from a viewer window.
 * @param {boolean}  [props.stopping] Whether stop is in flight.
 */
export default function ViewerOverlay( { onStop, stopping = false } ) {
	return (
		<div className="ahentic-viewer-overlay" role="status" aria-live="polite">
			<h2 className="ahentic-viewer-overlay__title">
				{ TITLE }
			</h2>
			{ typeof onStop === 'function' ? (
				<button
					type="button"
					className="ahentic-viewer-overlay__stop"
					onClick={ () => onStop() }
					disabled={ stopping }
				>
					{ stopping
						? __( 'Stopping…', 'ahentic' )
						: __( 'Stop', 'ahentic' ) }
				</button>
			) : null }
		</div>
	)
}
