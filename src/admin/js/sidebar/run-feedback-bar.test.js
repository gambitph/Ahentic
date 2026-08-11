/**
 * Pure helpers for Run feedback Yes/No visibility.
 */

import { shouldShowRunFeedback } from './run-feedback-bar'

describe( 'shouldShowRunFeedback', () => {
	it( 'shows when idle after a user prompt, hides when busy or dismissed', () => {
		const withPrompt = {
			status: 'idle',
			messages: [ { role: 'user', content: 'hi' } ],
		}
		expect( shouldShowRunFeedback( withPrompt ) ).toBe( true )
		expect( shouldShowRunFeedback( {
			status: 'idle',
			messages: [],
		} ) ).toBe( false )
		expect( shouldShowRunFeedback( {
			status: 'running',
			messages: [ { role: 'user', content: 'hi' } ],
		} ) ).toBe( false )
		expect( shouldShowRunFeedback( {
			status: 'awaiting_human',
			messages: [ { role: 'user', content: 'hi' } ],
		} ) ).toBe( false )
		expect( shouldShowRunFeedback( withPrompt, true ) ).toBe( false )
		expect( shouldShowRunFeedback( null ) ).toBe( false )
	} )
} )
