/**
 * Empty state + mocked message list for the active tab.
 */

import { Bot } from 'lucide-react'
import { SUGGESTED_PROMPTS } from './constants'

/**
 * @param {Object}   props
 * @param {Array}    props.messages
 * @param {Function} props.onSuggestedPrompt
 */
export default function TabContent( {
	messages, onSuggestedPrompt,
} ) {
	if ( ! messages.length ) {
		return (
			<div className="ahentic-content ahentic-content--empty">
				<div className="ahentic-empty">
					<div className="ahentic-empty__avatar" aria-hidden="true">
						<Bot size={ 28 } strokeWidth={ 1.5 } />
					</div>
					<p className="ahentic-empty__pitch">
						An intelligent AI agent that understands your WordPress site and works alongside you.
					</p>
					<div className="ahentic-empty__prompts">
						{ SUGGESTED_PROMPTS.map( prompt => (
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
							{ message.role === 'user' ? 'You' : 'Ahentic' }
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
