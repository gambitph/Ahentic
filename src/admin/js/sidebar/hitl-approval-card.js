/**
 * Human-in-the-loop approval card for mutating abilities.
 */

import { useCallback } from '@wordpress/element'
import { __ } from '@wordpress/i18n'

/**
 * @param {Object}   props
 * @param {Object}   props.pendingTool Pending tool from session REST.
 * @param {Function} props.onDecide    (decision: string) => Promise|void
 * @param {string}   [props.submitting] Decision currently in flight (parent-owned).
 * @param {boolean}  [props.disabled]
 */
export default function HitlApprovalCard( {
	pendingTool,
	onDecide,
	submitting = '',
	disabled = false,
} ) {
	const summary = pendingTool?.summary || pendingTool?.name || __( 'Pending action', 'ahentic' )
	const ability = pendingTool?.name || ''
	const busy = Boolean( submitting ) || disabled

	const decide = useCallback( async decision => {
		if ( busy ) {
			return
		}
		try {
			await onDecide( decision )
		} catch ( _err ) {
			// Parent restores HITL state; keep the card usable.
		}
	}, [ busy, onDecide ] )

	return (
		<div className="ahentic-hitl" role="group" aria-label={ __( 'Approve action', 'ahentic' ) }>
			<div className="ahentic-hitl__eyebrow">
				{ __( 'Approval needed', 'ahentic' ) }
			</div>
			<p className="ahentic-hitl__summary">{ summary }</p>
			{ ability ? (
				<p className="ahentic-hitl__meta">{ ability }</p>
			) : null }
			<div className="ahentic-hitl__actions">
				<button
					type="button"
					className="ahentic-hitl__allow"
					disabled={ busy }
					onClick={ () => decide( 'allow_once' ) }
				>
					{ submitting === 'allow_once'
						? __( 'Allowing…', 'ahentic' )
						: __( 'Allow once', 'ahentic' ) }
				</button>
				<button
					type="button"
					className="ahentic-hitl__session"
					disabled={ busy }
					onClick={ () => decide( 'allow_session' ) }
				>
					{ submitting === 'allow_session'
						? __( 'Allowing…', 'ahentic' )
						: __( 'Allow for this chat', 'ahentic' ) }
				</button>
				<button
					type="button"
					className="ahentic-hitl__deny"
					disabled={ busy }
					onClick={ () => decide( 'deny' ) }
				>
					{ submitting === 'deny'
						? __( 'Skipping…', 'ahentic' )
						: __( 'Skip', 'ahentic' ) }
				</button>
			</div>
		</div>
	)
}
