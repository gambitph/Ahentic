/**
 * Sparse suggested action buttons under an assistant message.
 */

import { useCallback, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'

/**
 * @param {Object}   props
 * @param {Array}    props.actions
 * @param {boolean}  [props.isLatest]
 * @param {Function} [props.onAbilityAction] (action) => Promise|void
 * @param {boolean}  [props.disabled]
 */
export default function SuggestedActions( {
	actions,
	isLatest = false,
	onAbilityAction,
	disabled = false,
} ) {
	const [ busyId, setBusyId ] = useState( '' )
	const [ dismissed, setDismissed ] = useState( false )

	const list = Array.isArray( actions ) ? actions.filter( Boolean ).slice( 0, 2 ) : []

	const onClick = useCallback( async action => {
		if ( disabled || busyId || ! action ) {
			return
		}
		if ( action.type === 'link' && action.url ) {
			window.open( action.url, '_blank', 'noopener,noreferrer' )
			return
		}
		if ( action.type === 'ability' && typeof onAbilityAction === 'function' ) {
			setBusyId( action.id || action.name || 'ability' )
			try {
				await onAbilityAction( action )
				setDismissed( true )
			} catch ( _err ) {
				setBusyId( '' )
			}
		}
	}, [ busyId, disabled, onAbilityAction ] )

	if ( ! isLatest || dismissed || ! list.length ) {
		return null
	}

	return (
		<div className="ahentic-suggested-actions" role="group" aria-label={ __( 'Suggested actions', 'ahentic' ) }>
			{ list.map( action => {
				const key = action.id || action.label || action.name
				const isBusy = busyId && busyId === ( action.id || action.name || 'ability' )
				const className = action.type === 'ability'
					? 'ahentic-suggested-actions__btn ahentic-suggested-actions__btn--primary'
					: 'ahentic-suggested-actions__btn'
				return (
					<button
						key={ key }
						type="button"
						className={ className }
						disabled={ Boolean( disabled || busyId ) }
						onClick={ () => onClick( action ) }
					>
						{ isBusy ? __( 'Starting…', 'ahentic' ) : ( action.label || action.name ) }
					</button>
				)
			} ) }
		</div>
	)
}
