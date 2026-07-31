/**
 * Empty state + message list for the active tab.
 */

import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { LoaderCircle } from 'lucide-react'
import AhenticLogo from './ahentic-logo'
import CapabilityRequestCard from './capability-request-card'
import HitlApprovalCard from './hitl-approval-card'
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
		__( 'Find unused plugins slowing the site down', 'ahentic' ),
		__( 'Scan the media library for any unused images', 'ahentic' ),
		__( 'Install an SEO plugin', 'ahentic' ),
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
 * @param {Object}      props
 * @param {boolean}     props.aiReady
 * @param {Object}      props.aiPlugin
 * @param {Function}    props.onAiReady
 * @param {Array}       props.messages
 * @param {Function}    props.onSuggestedPrompt
 * @param {boolean}     props.ready
 * @param {boolean}     props.loading
 * @param {boolean}     props.busy
 * @param {string}      props.progressLabel Live status under the latest message.
 * @param {Object|null} [props.pendingTool] HITL pending tool payload.
 * @param {Function}    [props.onApproval]  (decision: string) => Promise|void
 */
export default function TabContent( {
	aiReady,
	aiPlugin,
	onAiReady,
	messages,
	onSuggestedPrompt,
	ready = true,
	loading = false,
	busy = false,
	progressLabel = '',
	pendingTool = null,
	onApproval,
} ) {
	const [ installing, setInstalling ] = useState( false )
	const [ installError, setInstallError ] = useState( '' )
	const progressRef = useRef( null )

	useEffect( () => {
		if ( ! busy || ! progressLabel || ! progressRef.current ) {
			return
		}
		progressRef.current.scrollIntoView( { block: 'nearest', behavior: 'smooth' } )
	}, [ busy, progressLabel, messages.length ] )

	const canInstall = Boolean( aiPlugin?.canInstall )
	const pluginInstalled = Boolean( aiPlugin?.pluginInstalled )
	const pluginUrl = aiPlugin?.pluginUrl || 'https://wordpress.org/plugins/ai/'
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
				onAiReady?.( true )
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
	}, [ installing, onAiReady ] )

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

					{ aiReady ? (
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
					) : (
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
					) }

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

	return (
		<div className="ahentic-content">
			<div className="ahentic-messages">
				{ messages.map( message => (
					<div
						key={ message.id }
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
						</div>
					</div>
				) ) }

				{ pendingTool && typeof onApproval === 'function' ? (
					<div className="ahentic-hitl-wrap">
						<HitlApprovalCard
							pendingTool={ pendingTool }
							onDecide={ onApproval }
						/>
					</div>
				) : null }

				{ busy && progressLabel && ! pendingTool ? (
					<div
						ref={ progressRef }
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
