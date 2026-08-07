/**
 * Session debugger panel — orchestrator / model trace for the active session.
 *
 * The polled session payload only carries recent trace envelopes (type/summary),
 * so this panel pulls the full log with event payloads from the diagnostics route
 * while it is open. That keeps verbose recording free for everyone who never
 * opens it, while a bug report still gets the complete log.
 */

import {
	useCallback, useEffect, useMemo, useRef, useState,
} from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import classnames from 'classnames'
import {
	Check, ChevronDown, ChevronRight, Copy, Download, X,
} from 'lucide-react'
import { getDiagnostics } from './api'

/** Refresh cadence for the full log while a run is active. */
const REFRESH_MS = 1500

/**
 * Build a paste-friendly debug log from the diagnostics bundle.
 *
 * @param {Object} bundle       Diagnostics response.
 * @param {Array}  fallback     Trace to use when diagnostics have not loaded.
 * @param {string} sessionTitle
 * @return {string} Pretty-printed JSON log.
 */
function formatLogForExport( bundle, fallback, sessionTitle ) {
	if ( bundle && Array.isArray( bundle.trace ) ) {
		return JSON.stringify( bundle, null, 2 )
	}
	return JSON.stringify(
		{
			session: sessionTitle || '',
			exportedAt: new Date().toISOString(),
			partial: true,
			eventCount: Array.isArray( fallback ) ? fallback.length : 0,
			trace: Array.isArray( fallback ) ? fallback : [],
		},
		null,
		2
	)
}

/**
 * One-line host summary so a maintainer can triage before reading events.
 *
 * @param {Object} bundle Diagnostics response.
 * @return {string} One-line environment summary.
 */
function describeEnvironment( bundle ) {
	const env = bundle?.environment
	if ( ! env ) {
		return ''
	}
	const parts = [
		env.plugin ? `Ahentic ${ env.plugin }` : '',
		env.wp ? `WP ${ env.wp }` : '',
		env.php ? `PHP ${ env.php }` : '',
		bundle?.session?.model || '',
		env.aiClient && env.aiClient !== 'none' ? `client: ${ env.aiClient }` : '',
	]
	return parts.filter( Boolean ).join( ' · ' )
}

/**
 * @param {Object}        props
 * @param {Array}         props.trace        Recent envelopes from the session poll.
 * @param {number|string} props.sessionId
 * @param {boolean}       props.isBusy       Whether the run is still active.
 * @param {Function}      props.onClose
 * @param {string}        props.sessionTitle
 */
export default function DebuggerPanel( {
	trace, sessionId, isBusy, onClose, sessionTitle,
} ) {
	const slim = useMemo( () => ( Array.isArray( trace ) ? trace : [] ), [ trace ] )
	const [ bundle, setBundle ] = useState( null )
	const [ loadError, setLoadError ] = useState( '' )
	const [ openIds, setOpenIds ] = useState( {} )
	const [ copied, setCopied ] = useState( false )
	const inFlightRef = useRef( false )

	const events = bundle && Array.isArray( bundle.trace ) ? bundle.trace : slim
	const envLine = describeEnvironment( bundle )

	useEffect( () => {
		if ( ! sessionId ) {
			return undefined
		}

		let cancelled = false

		const load = async () => {
			if ( inFlightRef.current ) {
				return
			}
			inFlightRef.current = true
			try {
				const next = await getDiagnostics( sessionId )
				if ( ! cancelled ) {
					setBundle( next )
					setLoadError( '' )
				}
			} catch ( error ) {
				if ( ! cancelled ) {
					setLoadError( error.message || __( 'Could not load the full log.', 'ahentic' ) )
				}
			} finally {
				inFlightRef.current = false
			}
		}

		load()

		// Only keep refreshing while there is something new to see.
		if ( ! isBusy ) {
			return () => {
				cancelled = true
			}
		}

		const timer = window.setInterval( load, REFRESH_MS )
		return () => {
			cancelled = true
			window.clearInterval( timer )
		}
	}, [ sessionId, isBusy ] )

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
		const text = formatLogForExport( bundle, slim, sessionTitle )
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
	}, [ bundle, slim, sessionTitle ] )

	// A full log is far too large to paste reliably, so offer it as a file.
	const downloadLog = useCallback( () => {
		const text = formatLogForExport( bundle, slim, sessionTitle )
		const stamp = new Date().toISOString().replace( /[:.]/g, '-' )
		const url = URL.createObjectURL(
			new Blob( [ text ], { type: 'application/json' } )
		)
		const link = document.createElement( 'a' )
		link.href = url
		link.download = `ahentic-session-${ sessionId || 'log' }-${ stamp }.json`
		document.body.appendChild( link )
		link.click()
		document.body.removeChild( link )
		URL.revokeObjectURL( url )
	}, [ bundle, slim, sessionTitle, sessionId ] )

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
						onClick={ downloadLog }
						disabled={ ! events.length }
						aria-label={ __( 'Download debug log', 'ahentic' ) }
						title={ __( 'Download debug log (JSON)', 'ahentic' ) }
					>
						<Download size={ 14 } strokeWidth={ 1.75 } />
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

			{ envLine || loadError ? (
				<div className="ahentic-debugger__meta">
					{ loadError ? (
						<span className="ahentic-debugger__meta-error">{ loadError }</span>
					) : (
						<span>
							{ [
								envLine,
								events.length ? sprintf(
									/* translators: %d: number of trace events */
									__( '%d events', 'ahentic' ),
									events.length
								) : '',
							].filter( Boolean ).join( ' · ' ) }
						</span>
					) }
				</div>
			) : null }

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
