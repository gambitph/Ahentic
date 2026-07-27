/**
 * Composer: mode select, context chip, attachment/mic affordances, textarea.
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
 * @param {Object}   props.context
 */
export default function Composer( {
	mode,
	onModeChange,
	onSubmit,
	focusSignal,
	shortcutLabel,
	context,
} ) {
	const [ value, setValue ] = useState( '' )
	const [ modeOpen, setModeOpen ] = useState( false )
	const textareaRef = useRef( null )
	const modeRef = useRef( null )

	useEffect( () => {
		if ( textareaRef.current ) {
			textareaRef.current.focus()
		}
	}, [ focusSignal ] )

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
		const trimmed = value.trim()
		if ( ! trimmed ) {
			return
		}
		onSubmit( trimmed )
		setValue( '' )
	}

	const wpLabel = context?.wpVersion ? `WP ${ context.wpVersion }` : 'WP'
	const phpLabel = context?.phpVersion
		? `PHP ${ String( context.phpVersion ).split( '.' ).slice( 0, 2 ).join( '.' ) }`
		: 'PHP'

	return (
		<div className="ahentic-composer">
			<div className="ahentic-composer__box">
				<textarea
					ref={ textareaRef }
					className="ahentic-composer__input"
					rows={ 3 }
					value={ value }
					placeholder="Plan, Build, / for skills, @ for context"
					aria-label="Ask Ahentic"
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
						<span className="ahentic-context-chip" title="Site runtime context">
							{ wpLabel }
							<span className="ahentic-context-chip__sep">·</span>
							{ phpLabel }
						</span>
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
