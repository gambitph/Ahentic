/**
 * Composer: mode select, attachment/mic affordances, textarea, send/stop.
 *
 * Paperclip + mic stay in the DOM but are visually hidden until later:
 * attach = free v3 (pro__premium_only/docs/future-prompt-file-attachments.md);
 * voice = Premium v3 (pro__premium_only/docs/future-prompt-voice-input.md). Grill before wiring.
 *
 * Right-most control: Send (idle) or Stop (active run) — never both (Cursor-style).
 */

import {
	useEffect, useRef, useState,
} from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import {
	ArrowUp, ChevronDown, Paperclip, Mic, Square,
} from 'lucide-react'
import { MODES } from './constants'
import ContextUsageControl from './context-usage'

/**
 * @param {string} code REST / usage limit error code.
 * @return {boolean} True if the error code is a token limit error.
 */
function isTokenLimitError( code ) {
	const codes = window.ahentic?.tokenLimitCodes || {}
	return code === codes.daily || code === codes.runaway
}

/**
 * @param {Object}      props
 * @param {string}      props.mode
 * @param {Function}    props.onModeChange
 * @param {Function}    props.onSubmit
 * @param {boolean}     props.focusSignal
 * @param {string}      props.shortcutLabel
 * @param {boolean}     [props.disabled]        Blocked (no AI / connector) — whole composer inert.
 * @param {boolean}     [props.inputDisabled]   Textarea/mode locked while a run is active.
 * @param {boolean}     [props.canStop]         Show stop (replaces send) for the active run.
 * @param {Function}    [props.onStop]
 * @param {boolean}     [props.stopping]
 * @param {string}      [props.disabledHint]
 * @param {string}      [props.connectorsUrl]
 * @param {string}      [props.blockedCtaLabel] Override for the blocked-state CTA label.
 * @param {string}      [props.placeholder]
 * @param {string}      [props.error]
 * @param {string}      [props.errorCode]       REST error code (e.g. token limit).
 * @param {string}      [props.settingsUrl]     Ahentic settings admin URL.
 * @param {Function}    [props.onClearError]
 * @param {Object|null} [props.contextUsage]    Soft context budget snapshot.
 * @param {number}      [props.tokensIn]
 * @param {number}      [props.tokensOut]
 * @param {number}      [props.tokensUsed]
 */
export default function Composer( {
	mode,
	onModeChange,
	onSubmit,
	focusSignal,
	shortcutLabel,
	disabled = false,
	inputDisabled = false,
	canStop = false,
	onStop,
	stopping = false,
	disabledHint = '',
	connectorsUrl = '',
	blockedCtaLabel = '',
	placeholder = 'Plan, Build, / for skills, @ for context',
	error = '',
	errorCode = '',
	settingsUrl = '',
	onClearError,
	contextUsage = null,
	tokensIn = 0,
	tokensOut = 0,
	tokensUsed = 0,
} ) {
	const [ value, setValue ] = useState( '' )
	const [ modeOpen, setModeOpen ] = useState( false )
	const textareaRef = useRef( null )
	const modeRef = useRef( null )
	const typingLocked = disabled || inputDisabled
	const canSend = ! typingLocked && value.trim().length > 0

	useEffect( () => {
		if ( typingLocked ) {
			return
		}
		if ( textareaRef.current ) {
			textareaRef.current.focus()
		}
	}, [ focusSignal, typingLocked ] )

	useEffect( () => {
		if ( ! modeOpen ) {
			return undefined
		}

		const onPointerDown = event => {
			if ( modeRef.current && ! modeRef.current.contains( event.target ) ) {
				setModeOpen( false )
			}
		}

		document.addEventListener( 'mousedown', onPointerDown )
		return () => document.removeEventListener( 'mousedown', onPointerDown )
	}, [ modeOpen ] )

	useEffect( () => {
		const el = textareaRef.current
		if ( ! el ) {
			return
		}
		el.style.height = 'auto'
		el.style.height = `${ Math.min( el.scrollHeight, 160 ) }px`
	}, [ value ] )

	const submit = async () => {
		if ( typingLocked ) {
			return
		}
		const trimmed = value.trim()
		if ( ! trimmed ) {
			return
		}
		// Clear immediately for snappy UX; restore if send rejects / returns false.
		setValue( '' )
		try {
			const ok = await Promise.resolve( onSubmit( trimmed ) )
			if ( ok === false ) {
				setValue( trimmed )
			}
		} catch {
			setValue( trimmed )
		}
	}

	return (
		<div className={ `ahentic-composer${ disabled ? ' is-disabled' : '' }` }>
			{ disabled && disabledHint ? (
				<div className="ahentic-composer__blocked" role="status">
					<p className="ahentic-composer__blocked-text">{ disabledHint }</p>
					{ connectorsUrl ? (
						<a
							className="ahentic-composer__blocked-cta"
							href={ connectorsUrl }
						>
							{ blockedCtaLabel || __( 'Open Connectors', 'ahentic' ) }
						</a>
					) : null }
				</div>
			) : null }

			{ error ? (
				<div className="ahentic-composer__error" role="alert">
					<p className="ahentic-composer__error-text">{ error }</p>
					{ settingsUrl && isTokenLimitError( errorCode ) ? (
						<a className="ahentic-composer__error-cta" href={ settingsUrl }>
							{ __( 'Open Settings', 'ahentic' ) }
						</a>
					) : null }
				</div>
			) : null }

			<div className="ahentic-composer__box">
				<textarea
					ref={ textareaRef }
					className="ahentic-composer__input"
					rows={ 1 }
					value={ value }
					placeholder={ placeholder }
					aria-label="Ask Ahentic"
					disabled={ typingLocked }
					onChange={ event => {
						setValue( event.target.value )
						if ( error && onClearError ) {
							onClearError()
						}
					} }
					onKeyDown={ event => {
						if ( event.key === 'Enter' && ! event.shiftKey ) {
							event.preventDefault()
							submit()
						}
					} }
				/>

				<div className="ahentic-composer__footer">
					<div className="ahentic-composer__footer-left">
						<div className="ahentic-mode" ref={ modeRef }>
							<button
								type="button"
								className="ahentic-mode__trigger"
								onClick={ () => setModeOpen( open => ! open ) }
								aria-haspopup="listbox"
								aria-expanded={ modeOpen }
								aria-label="Select mode"
								title="Mode"
								disabled={ typingLocked }
							>
								<span>{ mode === MODES.ASK ? 'Ask' : 'Agent' }</span>
								<ChevronDown size={ 12 } strokeWidth={ 2 } />
							</button>
							{ modeOpen && (
								<div className="ahentic-mode__menu" role="listbox">
									<button
										type="button"
										role="option"
										aria-selected={ mode === MODES.AGENT }
										className="ahentic-mode__option"
										onClick={ () => {
											onModeChange( MODES.AGENT )
											setModeOpen( false )
										} }
									>
										Agent
									</button>
									<button
										type="button"
										role="option"
										aria-selected={ mode === MODES.ASK }
										className="ahentic-mode__option"
										onClick={ () => {
											onModeChange( MODES.ASK )
											setModeOpen( false )
										} }
									>
										Ask
									</button>
								</div>
							) }
						</div>
					</div>

					<div className="ahentic-composer__footer-right">
						{ /* Deferred: attach = free v3; voice = Premium v3 — visually hidden; do not remove. */ }
						<button
							type="button"
							className="ahentic-icon-btn ahentic-composer__affordance--deferred"
							aria-label="Attach file"
							title="Attach"
							disabled
							hidden
						>
							<Paperclip size={ 14 } strokeWidth={ 1.75 } />
						</button>
						<button
							type="button"
							className="ahentic-icon-btn ahentic-composer__affordance--deferred"
							aria-label="Voice input"
							title="Voice"
							disabled
							hidden
						>
							<Mic size={ 14 } strokeWidth={ 1.75 } />
						</button>
						<ContextUsageControl
							contextUsage={ contextUsage }
							tokensIn={ tokensIn }
							tokensOut={ tokensOut }
							tokensUsed={ tokensUsed }
						/>
						{ canStop ? (
							<button
								type="button"
								className="ahentic-composer__circle-btn ahentic-composer__stop"
								aria-label={ __( 'Stop', 'ahentic' ) }
								title={ __( 'Stop', 'ahentic' ) }
								disabled={ stopping || typeof onStop !== 'function' }
								onClick={ () => {
									if ( typeof onStop === 'function' ) {
										onStop()
									}
								} }
							>
								<Square size={ 11 } fill="currentColor" strokeWidth={ 0 } aria-hidden="true" />
							</button>
						) : (
							<button
								type="button"
								className="ahentic-composer__circle-btn ahentic-composer__send"
								aria-label={ __( 'Send', 'ahentic' ) }
								title={ __( 'Send', 'ahentic' ) }
								disabled={ ! canSend }
								onClick={ () => {
									submit()
								} }
							>
								<ArrowUp size={ 16 } strokeWidth={ 2.25 } aria-hidden="true" />
							</button>
						) }
					</div>
				</div>
			</div>

			<div className="ahentic-composer__hint">
				<span>
					{ canStop
						? __( 'Stop ends the current run so you can send again', 'ahentic' )
						: 'Enter to send · Shift+Enter for newline' }
				</span>
				<span aria-hidden="true">{ shortcutLabel }</span>
			</div>
		</div>
	)
}
