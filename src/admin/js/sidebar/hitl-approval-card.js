/**
 * Human-in-the-loop approval card for mutating abilities.
 */

import { useCallback, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'

/**
 * @param {Object}   props
 * @param {Object}   props.pendingTool Pending tool from session REST.
 * @param {Function} props.onDecide    (decision: string) => Promise|void
 * @param {boolean}  [props.disabled]
 */
export default function HitlApprovalCard( { pendingTool, onDecide, disabled = false } ) {
	const [ submitting, setSubmitting ] = useState( '' )

	const summary = pendingTool?.summary || pendingTool?.name || __( 'Pending action', 'ahentic' )
	const ability = pendingTool?.name || ''

	const decide = useCallback( async decision => {
		if ( submitting || disabled ) {
			return
		}
		setSubmitting( decision )
		try {
			await onDecide( decision )
		} catch ( _err ) {
			setSubmitting( '' )
		}
	}, [ disabled, onDecide, submitting ] )

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
					disabled={ Boolean( submitting ) || disabled }
					onClick={ () => decide( 'allow_once' ) }
				>
					{ submitting === 'allow_once'
						? __( 'Allowing…', 'ahentic' )
						: __( 'Allow once', 'ahentic' ) }
				</button>
				<button
					type="button"
					className="ahentic-hitl__session"
					disabled={ Boolean( submitting ) || disabled }
					onClick={ () => decide( 'allow_session' ) }
				>
					{ submitting === 'allow_session'
						? __( 'Allowing…', 'ahentic' )
						: __( 'Allow for this chat', 'ahentic' ) }
				</button>
				<button
					type="button"
					className="ahentic-hitl__deny"
					disabled={ Boolean( submitting ) || disabled }
					onClick={ () => decide( 'deny' ) }
				>
					{ submitting === 'deny'
						? __( 'Denying…', 'ahentic' )
						: __( 'Deny', 'ahentic' ) }
				</button>
			</div>
		</div>
	)
}
