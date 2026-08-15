/**
 * Run feedback Yes/No chrome after a prompt settles to idle.
 */

/* eslint-disable camelcase -- Feedback REST body matches PHP snake_case. */

import { useCallback, useState } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { submitRunFeedbackReport } from './api'
import { collectRunFeedbackSnapshot } from './run-feedback-snapshot'

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

/** Max length for optional user_note — keep in sync with Ahentic_Feedback_Intake::USER_NOTE_MAX_LENGTH. */
export const RUN_FEEDBACK_USER_NOTE_MAX = 1000

/**
 * Map a submit error to rate-limit copy when intake returns 429.
 *
 * @param {Object} err
 * @return {string} User-facing error copy.
 */
function fileErrorMessage( err ) {
	const code = err?.code || ''
	if ( code === 'rate_limited' || err?.status === 429 ) {
		return __(
			'Feedback intake is rate-limited (new issues: about 1 per minute). Wait a moment and try again, or file as a duplicate if one already exists.',
			'ahentic'
		)
	}
	return err?.message || __( 'Could not file Run feedback.', 'ahentic' )
}

/**
 * @param {Object}        props
 * @param {string|number} props.sessionId
 * @param {Function}      props.onDismiss  After success dismiss.
 * @param {boolean}       [props.disabled]
 */
export default function RunFeedbackBar( {
	sessionId, onDismiss, disabled = false,
} ) {
	const [ phase, setPhase ] = useState( 'ask' ) // ask | note | working | done | error
	const [ error, setError ] = useState( '' )
	const [ resultUrl, setResultUrl ] = useState( '' )
	const [ busy, setBusy ] = useState( false )
	const [ userNote, setUserNote ] = useState( '' )
	const [ filingKind, setFilingKind ] = useState( '' ) // success | failure

	const onYes = useCallback( async () => {
		if ( busy || disabled ) {
			return
		}
		setBusy( true )
		setError( '' )
		setFilingKind( 'success' )
		setPhase( 'working' )
		try {
			const filed = await submitRunFeedbackReport( sessionId, { kind: 'success' } )
			setResultUrl( filed?.html_url || '' )
			setPhase( 'done' )
		} catch ( err ) {
			setError( fileErrorMessage( err ) )
			setPhase( 'error' )
		} finally {
			setBusy( false )
		}
	}, [ busy, disabled, sessionId ] )

	const onNo = useCallback( () => {
		if ( busy || disabled ) {
			return
		}
		setError( '' )
		setFilingKind( 'failure' )
		setPhase( 'note' )
	}, [ busy, disabled ] )

	const onCancelNote = useCallback( () => {
		if ( busy ) {
			return
		}
		setUserNote( '' )
		setError( '' )
		setFilingKind( '' )
		setPhase( 'ask' )
	}, [ busy ] )

	const onSubmitNote = useCallback( async () => {
		if ( busy || disabled ) {
			return
		}
		setBusy( true )
		setError( '' )
		setFilingKind( 'failure' )
		setPhase( 'working' )
		try {
			const note = String( userNote || '' ).trim()
			const payload = {
				kind: 'failure',
				...collectRunFeedbackSnapshot(),
			}
			if ( note ) {
				payload.user_note = note
			}
			const filed = await submitRunFeedbackReport( sessionId, payload )
			setResultUrl( filed?.html_url || '' )
			setPhase( 'done' )
		} catch ( err ) {
			setError( fileErrorMessage( err ) )
			setPhase( 'error' )
		} finally {
			setBusy( false )
		}
	}, [ busy, disabled, sessionId, userNote ] )

	if ( phase === 'done' ) {
		return (
			<div className="ahentic-run-feedback is-done" role="status">
				<span className="ahentic-run-feedback__text">
					{ filingKind === 'success'
						? __( 'Thanks! This good run was filed.', 'ahentic' )
						: __( 'Thanks! Agent feedback was filed.', 'ahentic' ) }
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

	if ( phase === 'note' || ( phase === 'error' && filingKind === 'failure' ) ) {
		return (
			<div
				className="ahentic-run-feedback is-note"
				role="group"
				aria-label={ __( 'Run feedback', 'ahentic' ) }
			>
				<label className="ahentic-run-feedback__label" htmlFor="ahentic-run-feedback-note">
					{ __( 'What went wrong? (optional)', 'ahentic' ) }
				</label>
				<textarea
					id="ahentic-run-feedback-note"
					className="ahentic-run-feedback__note"
					value={ userNote }
					onChange={ event => setUserNote( event.target.value ) }
					rows={ 3 }
					maxLength={ RUN_FEEDBACK_USER_NOTE_MAX }
					disabled={ busy || disabled }
					placeholder={ __( 'Share a short note for the Ahentic team…', 'ahentic' ) }
				/>
				<div className="ahentic-run-feedback__actions">
					<button
						type="button"
						className="ahentic-run-feedback__submit"
						disabled={ busy || disabled }
						onClick={ onSubmitNote }
					>
						{ __( 'Send feedback', 'ahentic' ) }
					</button>
					<button
						type="button"
						className="ahentic-run-feedback__dismiss"
						disabled={ busy }
						onClick={ onCancelNote }
					>
						{ __( 'Cancel', 'ahentic' ) }
					</button>
				</div>
				{ error ? (
					<span className="ahentic-run-feedback__error" role="alert">
						{ error }
					</span>
				) : null }
			</div>
		)
	}

	return (
		<div
			className={ `ahentic-run-feedback${ phase === 'working' ? ' is-working' : '' }` }
			role="group"
			aria-label={ __( 'Run feedback', 'ahentic' ) }
		>
			{ phase === 'working' ? (
				<div className="ahentic-run-feedback__copy" role="status">
					<span className="ahentic-run-feedback__text">
						{ filingKind === 'success'
							? __( 'Sending this run…', 'ahentic' )
							: __(
								'Thanks! Drafting an anonymous report for the Ahentic team…',
								'ahentic'
							) }
					</span>
					<span className="ahentic-run-feedback__hint">
						{ __( 'Keep this window open.', 'ahentic' ) }
					</span>
				</div>
			) : (
				<span className="ahentic-run-feedback__text">
					{ __( 'Did this run go well?', 'ahentic' ) }
				</span>
			) }
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
			{ phase === 'error' && error ? (
				<span className="ahentic-run-feedback__error" role="alert">
					{ error }
				</span>
			) : null }
		</div>
	)
}
