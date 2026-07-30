/**
 * Session debugger panel — orchestrator / model trace for the active session.
 */

import { useCallback, useEffect, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import classnames from 'classnames'
import { Check, ChevronDown, ChevronRight, Copy, X } from 'lucide-react'

/**
 * Build a paste-friendly debug log from the session trace.
 *
 * @param {Array}  events
 * @param {string} sessionTitle
 * @return {string}
 */
function formatTraceForCopy( events, sessionTitle ) {
	const payload = {
		session: sessionTitle || '',
		exportedAt: new Date().toISOString(),
		eventCount: Array.isArray( events ) ? events.length : 0,
		trace: Array.isArray( events ) ? events : [],
	}
	return JSON.stringify( payload, null, 2 )
}

/**
 * @param {Object}   props
 * @param {Array}    props.trace
 * @param {Function} props.onClose
 * @param {string}   props.sessionTitle
 */
export default function DebuggerPanel( {
	trace, onClose, sessionTitle,
} ) {
	const events = Array.isArray( trace ) ? trace : []
	const [ openIds, setOpenIds ] = useState( {} )
	const [ copied, setCopied ] = useState( false )

	useEffect( () => {
		if ( ! copied ) {
			return undefined
		}
		const timer = window.setTimeout( () => setCopied( false ), 1800 )
		return () => window.clearTimeout( timer )
	}, [ copied ] )

	const toggle = id => {
		setOpenIds( current => ( {
			...current,
			[ id ]: ! current[ id ],
		} ) )
	}

	const copyLog = useCallback( async () => {
		const text = formatTraceForCopy( events, sessionTitle )
		try {
			if ( navigator.clipboard?.writeText ) {
				await navigator.clipboard.writeText( text )
			} else {
				const area = document.createElement( 'textarea' )
				area.value = text
				area.setAttribute( 'readonly', '' )
				area.style.position = 'fixed'
				area.style.left = '-9999px'
				document.body.appendChild( area )
				area.select()
				document.execCommand( 'copy' )
				document.body.removeChild( area )
			}
			setCopied( true )
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			window.alert( __( 'Could not copy the debug log.', 'ahentic' ) )
		}
	}, [ events, sessionTitle ] )

	return (
		<div className="ahentic-debugger" role="dialog" aria-label={ __( 'Session debugger', 'ahentic' ) }>
			<div className="ahentic-debugger__header">
				<div className="ahentic-debugger__title">
					<span>{ __( 'Debugger', 'ahentic' ) }</span>
					{ sessionTitle ? (
						<span className="ahentic-debugger__session">{ sessionTitle }</span>
					) : null }
				</div>
				<div className="ahentic-debugger__actions">
					<button
						type="button"
						className={ classnames( 'ahentic-icon-btn', {
							'is-active': copied,
						} ) }
						onClick={ copyLog }
						disabled={ ! events.length }
						aria-label={ copied
							? __( 'Copied', 'ahentic' )
							: __( 'Copy debug log', 'ahentic' )
						}
						title={ copied
							? __( 'Copied', 'ahentic' )
							: __( 'Copy debug log', 'ahentic' )
						}
					>
						{ copied ? (
							<Check size={ 14 } strokeWidth={ 1.75 } />
						) : (
							<Copy size={ 14 } strokeWidth={ 1.75 } />
						) }
					</button>
					<button
						type="button"
						className="ahentic-icon-btn"
						onClick={ onClose }
						aria-label={ __( 'Close debugger', 'ahentic' ) }
						title={ __( 'Close', 'ahentic' ) }
					>
						<X size={ 14 } strokeWidth={ 1.75 } />
					</button>
				</div>
			</div>

			<div className="ahentic-debugger__body">
				{ ! events.length ? (
					<p className="ahentic-debugger__empty">
						{ __( 'No trace yet — send a message to start a run.', 'ahentic' ) }
					</p>
				) : (
					<ol className="ahentic-debugger__list">
						{ events.map( event => {
							const expanded = Boolean( openIds[ event.id ] )
							const data = event.data && typeof event.data === 'object' ? event.data : {}
							const Chevron = expanded ? ChevronDown : ChevronRight

							return (
								<li
									key={ event.id }
									className={ classnames( 'ahentic-debugger__event', `is-${ event.type || 'unknown' }` ) }
								>
									<button
										type="button"
										className="ahentic-debugger__event-head"
										onClick={ () => toggle( event.id ) }
										aria-expanded={ expanded }
									>
										<Chevron size={ 12 } strokeWidth={ 2 } aria-hidden="true" />
										<span className="ahentic-debugger__type">{ event.type }</span>
										{ event.step ? (
											<span className="ahentic-debugger__step">#{ event.step }</span>
										) : null }
										<span className="ahentic-debugger__summary">
											{ event.summary || '—' }
										</span>
									</button>

									{ expanded ? (
										<div className="ahentic-debugger__event-body">
											{ data.intention ? (
												<div className="ahentic-debugger__field">
													<span className="ahentic-debugger__label">{ __( 'Intention', 'ahentic' ) }</span>
													<p>{ data.intention }</p>
												</div>
											) : null }
											{ data.thinking ? (
												<div className="ahentic-debugger__field">
													<span className="ahentic-debugger__label">{ __( 'Thinking', 'ahentic' ) }</span>
													<p>{ data.thinking }</p>
												</div>
											) : null }
											{ Array.isArray( data.tools_planned ) && data.tools_planned.length ? (
												<div className="ahentic-debugger__field">
													<span className="ahentic-debugger__label">{ __( 'Tools planned', 'ahentic' ) }</span>
													<ul>
														{ data.tools_planned.map( tool => (
															<li key={ typeof tool === 'string' ? tool : JSON.stringify( tool ) }>
																<code>{ typeof tool === 'string' ? tool : JSON.stringify( tool ) }</code>
															</li>
														) ) }
													</ul>
												</div>
											) : null }
											{ data.next ? (
												<div className="ahentic-debugger__field">
													<span className="ahentic-debugger__label">{ __( 'Next', 'ahentic' ) }</span>
													<p><code>{ data.next }</code></p>
												</div>
											) : null }
											{ data.missing ? (
												<p className="ahentic-debugger__muted">
													{ __( 'Model did not return an AHENTIC_DEBUG block.', 'ahentic' ) }
												</p>
											) : null }
											<pre className="ahentic-debugger__json">
												{ JSON.stringify( event, null, 2 ) }
											</pre>
										</div>
									) : null }
								</li>
							)
						} ) }
					</ol>
				) }
			</div>
		</div>
	)
}
