/**
 * Empty state + mocked message list for the active tab.
 */

import { createInterpolateElement } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import AhenticLogo from './ahentic-logo'

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
	]
}

/**
 * @param {Object}   props
 * @param {Array}    props.messages
 * @param {Function} props.onSuggestedPrompt
 */
export default function TabContent( {
	messages, onSuggestedPrompt,
} ) {
	if ( ! messages.length ) {
		const prompts = getSuggestedPrompts()

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
					<div className="ahentic-empty__prompts">
						<div className="ahentic-empty__prompts-label">
							{ __( 'Suggestions', 'ahentic' ) }
						</div>
						{ prompts.map( prompt => (
							<button
								key={ prompt }
								type="button"
								className="ahentic-empty__prompt"
								onClick={ () => onSuggestedPrompt( prompt ) }
							>
								{ prompt }
							</button>
						) ) }
					</div>
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
								: __( 'Ahentic', 'ahentic' )
							}
						</div>
						<div className="ahentic-message__body">
							{ message.content }
						</div>
					</div>
				) ) }
			</div>
		</div>
	)
}
