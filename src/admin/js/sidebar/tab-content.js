/**
 * Empty state + message list for the active tab.
 */

import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { LoaderCircle } from 'lucide-react'
import AhenticLogo from './ahentic-logo'
import CapabilityRequestCard from './capability-request-card'
import HitlApprovalCard from './hitl-approval-card'
import SuggestedActions from './suggested-actions'
import { MessageBody } from './message-body'

/**
 * Suggested empty-state prompts (translated at call time).
 *
 * @return {string[]} Translated prompt strings.
 */
function getSuggestedPrompts() {
	return [
		__( 'Analyze my website and tell me what you would improve', 'ahentic' ),
		__( 'Explain what I\'m looking at in the screen and how to use it', 'ahentic' ),
		__( 'Find problems, errors, or opportunities on my website', 'ahentic' ),
		// __( 'Find unused plugins slowing the site down', 'ahentic' ),
		// __( 'Scan the media library for any unused images', 'ahentic' ),
		// __( 'Install an SEO plugin', 'ahentic' ),
	]
}

/**
 * Install & activate the WordPress AI plugin via Ahentic REST.
 *
 * @return {Promise<Object>} Install response payload from the REST API.
 */
async function installAiPlugin() {
	const restUrl = window.ahentic?.restUrl
	const restNonce = window.ahentic?.restNonce

	if ( ! restUrl || ! restNonce ) {
		throw new Error( __( 'Ahentic REST configuration is missing.', 'ahentic' ) )
	}

	const response = await fetch( `${ restUrl }/ai-plugin/install`, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': restNonce,
		},
	} )

	const payload = await response.json().catch( () => ( {} ) )

	if ( ! response.ok ) {
		const message = payload?.message ||
			payload?.data?.message ||
			__( 'Could not install the WordPress AI plugin.', 'ahentic' )
		throw new Error( message )
	}

	return payload
}

/**
 * Sidebar message list / empty state for the active tab.
 *
 * @param {Object}      props
 * @param {boolean}     props.aiReady
 * @param {boolean}     props.hasConnector
 * @param {Object}      props.aiPlugin
 * @param {Function}    props.onAiReady
 * @param {Function}    props.onHasConnector
 * @param {Array}       props.messages
 * @param {string}      props.sessionId
 * @param {Function}    props.onSuggestedPrompt
 * @param {boolean}     props.ready
 * @param {boolean}     props.loading
 * @param {boolean}     props.busy
 * @param {string}      props.progressLabel
 * @param {Object|null} props.pendingTool
 * @param {string}      props.sessionStatus
 * @param {Function}    props.onApproval
 * @param {Function}    props.onSuggestedAction
 */
export default function TabContent( {
	aiReady,
	hasConnector = false,
	aiPlugin,
	onAiReady,
	onHasConnector,
	messages,
	sessionId = '',
	onSuggestedPrompt,
	ready = true,
	loading = false,
	busy = false,
	progressLabel = '',
	pendingTool = null,
	sessionStatus = 'idle',
	onApproval,
	onSuggestedAction,
} ) {
	const [ installing, setInstalling ] = useState( false )
	const [ installError, setInstallError ] = useState( '' )
	const contentRef = useRef( null )
	const latestAssistantRef = useRef( null )
	const promptBeforeAssistantRef = useRef( null )
	const prevSessionIdRef = useRef( null )
	const skipSmoothScrollRef = useRef( false )
	const prevLastMessageKeyRef = useRef( '' )
	const lastMessage = messages[ messages.length - 1 ]
	const lastMessageKey = lastMessage
		? `${ lastMessage.id }:${ String( lastMessage.content || '' ).length }`
		: ''

	/**
	 * Offset of an element within the scrollable message container.
	 *
	 * @param {HTMLElement} container
	 * @param {HTMLElement} el
	 * @return {number}
	 */
	const offsetWithin = useCallback( ( container, el ) => {
		return el.getBoundingClientRect().top -
			container.getBoundingClientRect().top +
			container.scrollTop
	}, [] )

	/**
	 * Scroll the message list to the latest content.
	 *
	 * @param {'auto'|'smooth'} behavior Scroll behavior.
	 */
	const scrollToBottom = useCallback( ( behavior = 'auto' ) => {
		const el = contentRef.current
		if ( ! el ) {
			return
		}
		el.scrollTo( { top: el.scrollHeight, behavior } )
	}, [] )

	/**
	 * Scroll so a new AI reply is readable: prefer pinning the user prompt
	 * above it, but only when the reply start would still be on-screen.
	 *
	 * @param {'auto'|'smooth'} behavior Scroll behavior.
	 */
	const scrollToLatestReply = useCallback( ( behavior = 'smooth' ) => {
		const container = contentRef.current
		const assistantEl = latestAssistantRef.current
		if ( ! container || ! assistantEl ) {
			scrollToBottom( behavior )
			return
		}

		const padding = 8
		const viewportH = container.clientHeight
		const assistantTop = offsetWithin( container, assistantEl )
		const promptEl = promptBeforeAssistantRef.current

		if ( promptEl ) {
			const promptTop = offsetWithin( container, promptEl )
			const scrollIfPromptPinned = Math.max( 0, promptTop - padding )
			const assistantFromViewportTop = assistantTop - scrollIfPromptPinned
			// Keep the reply start in view (with a little room below the fold).
			const minVisible = 48
			if (
				assistantFromViewportTop >= 0 &&
				assistantFromViewportTop <= viewportH - minVisible
			) {
				container.scrollTo( { top: scrollIfPromptPinned, behavior } )
				return
			}
		}

		container.scrollTo( {
			top: Math.max( 0, assistantTop - padding ),
			behavior,
		} )
	}, [ offsetWithin, scrollToBottom ] )

	// Jump to the latest messages when opening/switching a session tab.
	useLayoutEffect( () => {
		if ( loading || ! messages.length ) {
			return
		}
		if ( prevSessionIdRef.current === sessionId ) {
			return
		}
		prevSessionIdRef.current = sessionId
		skipSmoothScrollRef.current = true
		prevLastMessageKeyRef.current = lastMessageKey
		scrollToBottom( 'auto' )
	}, [ sessionId, loading, messages.length, lastMessageKey, scrollToBottom ] )

	// Follow live status; on a new AI reply, reveal its start (not the very end).
	useEffect( () => {
		if ( loading || ! messages.length ) {
			return
		}
		if ( skipSmoothScrollRef.current ) {
			skipSmoothScrollRef.current = false
			prevLastMessageKeyRef.current = lastMessageKey
			return
		}
		if ( prevSessionIdRef.current !== sessionId ) {
			// Tab switch is handled by useLayoutEffect once content is ready.
			return
		}

		const keyChanged = prevLastMessageKeyRef.current !== lastMessageKey
		prevLastMessageKeyRef.current = lastMessageKey
		const isNewAssistantReply = keyChanged && lastMessage?.role === 'assistant'
		const followLiveActivity = Boolean( busy || pendingTool ) ||
			( keyChanged && lastMessage?.role === 'user' )

		const frame = window.requestAnimationFrame( () => {
			if ( isNewAssistantReply ) {
				scrollToLatestReply( 'smooth' )
				return
			}
			// Don't yank back to the bottom after a reply has already been revealed.
			if ( followLiveActivity ) {
				scrollToBottom( 'smooth' )
			}
		} )
		return () => window.cancelAnimationFrame( frame )
	}, [
		sessionId,
		loading,
		messages.length,
		lastMessageKey,
		lastMessage,
		busy,
		progressLabel,
		pendingTool,
		scrollToBottom,
		scrollToLatestReply,
	] )

	const canInstall = Boolean( aiPlugin?.canInstall )
	const pluginInstalled = Boolean( aiPlugin?.pluginInstalled )
	const pluginUrl = aiPlugin?.pluginUrl || 'https://wordpress.org/plugins/ai/'
	const connectorsUrl = aiPlugin?.connectorsUrl || ''
	const canGenerate = Boolean( aiReady && hasConnector )
	const actionLabel = pluginInstalled
		? __( 'Activate', 'ahentic' )
		: __( 'Install & Activate', 'ahentic' )
	const busyLabel = pluginInstalled
		? __( 'Activating…', 'ahentic' )
		: __( 'Installing…', 'ahentic' )

	const onInstallClick = useCallback( async () => {
		if ( installing ) {
			return
		}

		setInstallError( '' )
		setInstalling( true )

		try {
			const result = await installAiPlugin()

			if ( result?.needsReload || result?.isReady ) {
				window.location.reload()
				return
			}

			if ( result?.success ) {
				onAiReady?.( Boolean( result.isReady ) )
				onHasConnector?.( Boolean( result.hasConnector ) )
				if ( result.isReady && ! result.hasConnector ) {
					setInstalling( false )
					return
				}
				window.location.reload()
				return
			}

			throw new Error(
				result?.message || __( 'Could not install the WordPress AI plugin.', 'ahentic' )
			)
		} catch ( error ) {
			setInstallError(
				error?.message || __( 'Could not install the WordPress AI plugin.', 'ahentic' )
			)
			setInstalling( false )
		}
	}, [ installing, onAiReady, onHasConnector ] )

	if ( loading ) {
		return (
			<div
				className="ahentic-content ahentic-content--empty ahentic-content--loading"
				role="status"
				aria-live="polite"
				aria-busy="true"
				aria-label={ __( 'Loading session', 'ahentic' ) }
			>
				<LoaderCircle
					size={ 28 }
					className="ahentic-spin"
					aria-hidden="true"
				/>
			</div>
		)
	}

	if ( ! messages.length ) {
		const prompts = getSuggestedPrompts()
		const docsUrl = window.ahentic?.docsUrl || 'https://ahentic.com/docs'

		return (
			<div className="ahentic-content ahentic-content--empty">
				<div className="ahentic-empty">
					<div className="ahentic-empty__avatar" aria-hidden="true">
						<AhenticLogo size={ 28 } />
					</div>
					<p className="ahentic-empty__pitch">
						{ createInterpolateElement(
							__(
								'<strong>Ahentic</strong> is an intelligent AI agent that understands your WordPress site and works alongside you.',
								'ahentic'
							),
							{
								strong: <strong />,
							}
						) }
					</p>

					{ canGenerate ? (
						<div className="ahentic-empty__prompts">
							<div className="ahentic-empty__prompts-label">
								{ __( 'Suggestions', 'ahentic' ) }
							</div>
							{ prompts.map( prompt => (
								<button
									key={ prompt }
									type="button"
									className="ahentic-empty__prompt"
									disabled={ ! ready || busy }
									onClick={ () => onSuggestedPrompt( prompt ) }
								>
									{ prompt }
								</button>
							) ) }
						</div>
					) : ( ! aiReady ? (
						<div className="ahentic-empty__ai-notice" role="status">
							<p className="ahentic-empty__ai-notice-text">
								{ createInterpolateElement(
									__(
										'Ahentic needs the <a>WordPress AI</a> plugin installed and activated to continue.',
										'ahentic'
									),
									{
										// Content is injected by createInterpolateElement.
										/* eslint-disable-next-line jsx-a11y/anchor-has-content */
										a: <a href={ pluginUrl } target="_blank" rel="noopener noreferrer" />,
									}
								) }
							</p>

							{ canInstall ? (
								<button
									type="button"
									className="ahentic-empty__ai-install"
									onClick={ onInstallClick }
									disabled={ installing }
									aria-busy={ installing }
								>
									{ installing ? (
										<>
											<LoaderCircle
												size={ 16 }
												className="ahentic-spin"
												aria-hidden="true"
											/>
											{ busyLabel }
										</>
									) : (
										actionLabel
									) }
								</button>
							) : (
								<a
									className="ahentic-empty__ai-install ahentic-empty__ai-install--link"
									href={ pluginUrl }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __( 'Get WordPress AI', 'ahentic' ) }
								</a>
							) }

							{ installError ? (
								<p className="ahentic-empty__ai-error" role="alert">
									{ installError }
								</p>
							) : null }
						</div>
					) : (
						<div className="ahentic-empty__ai-notice" role="status">
							<p className="ahentic-empty__ai-notice-text">
								{ createInterpolateElement(
									__(
										'Add an AI connector so Ahentic can talk to a model. Open <strong>Settings → Connectors</strong> and connect a provider (for example OpenAI).',
										'ahentic'
									),
									{
										strong: <strong />,
									}
								) }
							</p>
							{ connectorsUrl ? (
								<a
									className="ahentic-empty__ai-install ahentic-empty__ai-install--link"
									href={ connectorsUrl }
								>
									{ __( 'Open Connectors', 'ahentic' ) }
								</a>
							) : null }
						</div>
					) ) }

					<a
						className="ahentic-empty__docs"
						href={ docsUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Guides and tutorials', 'ahentic' ) }
					</a>
				</div>
			</div>
		)
	}

	const lastAssistantIndex = messages.reduce(
		( last, m, i ) => ( m.role === 'assistant' ? i : last ),
		-1
	)
	let promptBeforeAssistantIndex = -1
	if ( lastAssistantIndex > 0 ) {
		for ( let i = lastAssistantIndex - 1; i >= 0; i-- ) {
			if ( messages[ i ].role === 'user' ) {
				promptBeforeAssistantIndex = i
				break
			}
		}
	}

	return (
		<div className="ahentic-content" ref={ contentRef }>
			<div className="ahentic-messages">
				{ messages.map( ( message, messageIndex ) => {
					const isLatestAssistant = message.role === 'assistant' && messageIndex === lastAssistantIndex
					const isPromptBeforeAssistant = messageIndex === promptBeforeAssistantIndex
					return (
						<div
							key={ message.id }
							ref={ isLatestAssistant
								? latestAssistantRef
								: ( isPromptBeforeAssistant ? promptBeforeAssistantRef : null )
							}
							className={ `ahentic-message ahentic-message--${ message.role }` }
						>
							<div className="ahentic-message__label">
								{ message.role === 'user'
									? __( 'You', 'ahentic' )
									: ( message.role === 'system'
										? __( 'System', 'ahentic' )
										: __( 'Ahentic', 'ahentic' )
									)
								}
							</div>
							<div className="ahentic-message__body">
								<MessageBody content={ message.content } role={ message.role } />
								{ message.role === 'assistant' && Array.isArray( message.meta?.capability_requests ) && message.meta.capability_requests.length
									? message.meta.capability_requests.map( ( req, index ) => (
										<CapabilityRequestCard
											key={ req?.ability || `cap_${ index }` }
											request={ req }
										/>
									) )
									: ( message.role === 'assistant' && message.meta?.capability_request
										? (
											<CapabilityRequestCard
												request={ message.meta.capability_request }
											/>
										)
										: null
									)
								}
								{ message.role === 'assistant' && Array.isArray( message.meta?.actions ) && message.meta.actions.length ? (
									<SuggestedActions
										actions={ message.meta.actions }
										isLatest={ isLatestAssistant && ! busy && ! pendingTool }
										onAbilityAction={ onSuggestedAction }
										disabled={ busy || Boolean( pendingTool ) }
									/>
								) : null }
							</div>
						</div>
					)
				} ) }

				{ sessionStatus === 'awaiting_human' && pendingTool && typeof onApproval === 'function' ? (
					<div className="ahentic-hitl-wrap">
						<HitlApprovalCard
							pendingTool={ pendingTool }
							onDecide={ onApproval }
						/>
					</div>
				) : null }

				{ busy && progressLabel && sessionStatus !== 'awaiting_human' ? (
					<div
						className="ahentic-live-status"
						role="status"
						aria-live="polite"
					>
						<span className="ahentic-live-status__text">
							{ progressLabel }
						</span>
					</div>
				) : null }
			</div>
		</div>
	)
}
