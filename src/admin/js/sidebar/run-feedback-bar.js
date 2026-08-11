/**
 * Run feedback Yes/No chrome after a prompt settles to idle.
 */

import { useCallback, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import {
	fileRunFeedbackReport,
	getFeedbackStatus,
	mintFeedbackSiteToken,
} from './api'

/**
 * Whether Run feedback Yes/No should show for this session snapshot.
 *
 * Show when idle after at least one user prompt; hide whenever not idle.
 *
 * @param {Object}  session
 * @param {boolean} [dismissed]
 * @return {boolean} True when the Yes/No chrome should render.
 */
export function shouldShowRunFeedback( session, dismissed = false ) {
	if ( dismissed ) {
		return false
	}
	if ( ! session || typeof session !== 'object' ) {
		return false
	}
	if ( session.status !== 'idle' ) {
		return false
	}
	const messages = Array.isArray( session.messages ) ? session.messages : []
	return messages.some( message => message?.role === 'user' )
}

/**
 * Load Turnstile script once; resolve with window.turnstile or reject.
 *
 * @param {string} siteKey
 * @return {Promise<Object>} turnstile API.
 */
function loadTurnstile( siteKey ) {
	if ( ! siteKey ) {
		return Promise.reject( new Error( __( 'Turnstile is not configured.', 'ahentic' ) ) )
	}
	if ( window.turnstile ) {
		return Promise.resolve( window.turnstile )
	}
	return new Promise( ( resolve, reject ) => {
		const existing = document.querySelector( 'script[data-ahentic-turnstile]' )
		if ( existing ) {
			existing.addEventListener( 'load', () => {
				if ( window.turnstile ) {
					resolve( window.turnstile )
				} else {
					reject( new Error( __( 'Turnstile failed to load.', 'ahentic' ) ) )
				}
			} )
			existing.addEventListener( 'error', () => {
				reject( new Error( __( 'Turnstile failed to load.', 'ahentic' ) ) )
			} )
			return
		}
		const script = document.createElement( 'script' )
		script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
		script.async = true
		script.dataset.ahenticTurnstile = '1'
		script.onload = () => {
			if ( window.turnstile ) {
				resolve( window.turnstile )
			} else {
				reject( new Error( __( 'Turnstile failed to load.', 'ahentic' ) ) )
			}
		}
		script.onerror = () => {
			reject( new Error( __( 'Turnstile failed to load.', 'ahentic' ) ) )
		}
		document.head.appendChild( script )
	} )
}

/**
 * Run Turnstile in a temporary container; returns the token.
 *
 * @param {string} siteKey
 * @return {Promise<string>} Turnstile response token.
 */
async function challengeTurnstile( siteKey ) {
	const turnstile = await loadTurnstile( siteKey )
	return new Promise( ( resolve, reject ) => {
		const host = document.createElement( 'div' )
		host.style.position = 'fixed'
		host.style.left = '-9999px'
		document.body.appendChild( host )
		let widgetId
		const cleanup = () => {
			try {
				if ( widgetId !== undefined && turnstile.remove ) {
					turnstile.remove( widgetId )
				}
			} catch ( _e ) {
				// ignore
			}
			host.remove()
		}
		try {
			widgetId = turnstile.render( host, {
				sitekey: siteKey,
				callback: token => {
					cleanup()
					resolve( token )
				},
				'error-callback': () => {
					cleanup()
					reject( new Error( __( 'Turnstile verification failed.', 'ahentic' ) ) )
				},
				'expired-callback': () => {
					cleanup()
					reject( new Error( __( 'Turnstile expired. Try again.', 'ahentic' ) ) )
				},
			} )
		} catch ( err ) {
			cleanup()
			reject( err instanceof Error ? err : new Error( String( err ) ) )
		}
	} )
}

/**
 * Ensure the site has a stored intake token (mint with Turnstile if needed).
 *
 * @return {Promise<Object>} Feedback status.
 */
export async function ensureFeedbackOptIn() {
	const status = await getFeedbackStatus()
	if ( status.hasToken ) {
		return status
	}
	// Playwright e2e: skip Cloudflare widget; intake is mocked in the mu-plugin.
	if ( window.__AHENTIC_E2E__ ) {
		return mintFeedbackSiteToken( 'e2e-turnstile-token' )
	}
	const siteKey = status.turnstileSiteKey || window.ahentic?.feedback?.turnstileSiteKey || ''
	const token = await challengeTurnstile( siteKey )
	return mintFeedbackSiteToken( token )
}

/**
 * @param {Object}        props
 * @param {string|number} props.sessionId
 * @param {Function}      props.onDismiss  Yes / after success dismiss.
 * @param {boolean}       [props.disabled]
 */
export default function RunFeedbackBar( {
	sessionId, onDismiss, disabled = false,
} ) {
	const [ phase, setPhase ] = useState( 'ask' ) // ask | working | done | error
	const [ error, setError ] = useState( '' )
	const [ resultUrl, setResultUrl ] = useState( '' )
	const [ busy, setBusy ] = useState( false )

	const onYes = useCallback( () => {
		if ( busy || disabled ) {
			return
		}
		onDismiss()
	}, [ busy, disabled, onDismiss ] )

	const onNo = useCallback( async () => {
		if ( busy || disabled ) {
			return
		}
		setBusy( true )
		setError( '' )
		setPhase( 'working' )
		try {
			await ensureFeedbackOptIn()
			const filed = await fileRunFeedbackReport( sessionId )
			setResultUrl( filed?.html_url || '' )
			setPhase( 'done' )
		} catch ( err ) {
			const code = err?.code || ''
			const message = err?.message || __( 'Could not file Run feedback.', 'ahentic' )
			if ( code === 'rate_limited' || err?.status === 429 ) {
				setError(
					__(
						'Feedback intake is rate-limited (new issues: about 1 per minute). Wait a moment and try again, or file as a duplicate if one already exists.',
						'ahentic'
					)
				)
				setPhase( 'error' )
			} else {
				setError( message )
				setPhase( 'error' )
			}
		} finally {
			setBusy( false )
		}
	}, [ busy, disabled, sessionId ] )

	if ( phase === 'done' ) {
		return (
			<div className="ahentic-run-feedback is-done" role="status">
				<span className="ahentic-run-feedback__text">
					{ __( 'Thanks — Run feedback was filed.', 'ahentic' ) }
					{ resultUrl ? (
						<>
							{ ' ' }
							<a
								className="ahentic-run-feedback__link"
								href={ resultUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'View on GitHub', 'ahentic' ) }
							</a>
						</>
					) : null }
				</span>
				<button
					type="button"
					className="ahentic-run-feedback__dismiss"
					onClick={ onDismiss }
				>
					{ __( 'Dismiss', 'ahentic' ) }
				</button>
			</div>
		)
	}

	return (
		<div
			className="ahentic-run-feedback"
			role="group"
			aria-label={ __( 'Run feedback', 'ahentic' ) }
		>
			<span className="ahentic-run-feedback__text">
				{ phase === 'working'
					? __( 'Drafting and filing Run feedback…', 'ahentic' )
					: __( 'Did this run go okay?', 'ahentic' ) }
			</span>
			{ phase !== 'working' ? (
				<div className="ahentic-run-feedback__actions">
					<button
						type="button"
						className="ahentic-run-feedback__yes"
						disabled={ busy || disabled }
						onClick={ onYes }
					>
						{ __( 'Yes', 'ahentic' ) }
					</button>
					<button
						type="button"
						className="ahentic-run-feedback__no"
						disabled={ busy || disabled }
						onClick={ onNo }
					>
						{ __( 'No', 'ahentic' ) }
					</button>
				</div>
			) : null }
			{ error ? (
				<span className="ahentic-run-feedback__error" role="alert">
					{ error }
				</span>
			) : null }
		</div>
	)
}
