/**
 * Composer: mode select, attachment/mic affordances, textarea.
 */

import {
	useEffect, useRef, useState,
} from '@wordpress/element'
import {
	ChevronDown, Paperclip, Mic,
} from 'lucide-react'
import { MODES } from './constants'

/**
 * @param {Object}   props
 * @param {string}   props.mode
 * @param {Function} props.onModeChange
 * @param {Function} props.onSubmit
 * @param {boolean}  props.focusSignal
 * @param {string}   props.shortcutLabel
 * @param {boolean}  [props.disabled]
 * @param {string}   [props.disabledHint]
 * @param {string}   [props.connectorsUrl]
 */
export default function Composer( {
	mode,
	onModeChange,
	onSubmit,
	focusSignal,
	shortcutLabel,
	disabled = false,
	disabledHint = '',
	connectorsUrl = '',
} ) {
	const [ value, setValue ] = useState( '' )
	const [ modeOpen, setModeOpen ] = useState( false )
	const textareaRef = useRef( null )
	const modeRef = useRef( null )

	useEffect( () => {
		if ( disabled ) {
			return
		}
		if ( textareaRef.current ) {
			textareaRef.current.focus()
		}
	}, [ focusSignal, disabled ] )

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

	const submit = () => {
		if ( disabled ) {
			return
		}
		const trimmed = value.trim()
		if ( ! trimmed ) {
			return
		}
		onSubmit( trimmed )
		setValue( '' )
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
							Open Connectors
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
					placeholder="Plan, Build, / for skills, @ for context"
					aria-label="Ask Ahentic"
					disabled={ disabled }
					onChange={ event => setValue( event.target.value ) }
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
								disabled={ disabled }
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
						<button
							type="button"
							className="ahentic-icon-btn"
							aria-label="Attach file"
							title="Attach"
							disabled
						>
							<Paperclip size={ 14 } strokeWidth={ 1.75 } />
						</button>
						<button
							type="button"
							className="ahentic-icon-btn"
							aria-label="Voice input"
							title="Voice"
							disabled
						>
							<Mic size={ 14 } strokeWidth={ 1.75 } />
						</button>
					</div>
				</div>
			</div>

			<div className="ahentic-composer__hint">
				<span>Enter to send · Shift+Enter for newline</span>
				<span aria-hidden="true">{ shortcutLabel }</span>
			</div>
		</div>
	)
}
