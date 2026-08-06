/**
 * Browser-driven characterization: HITL card is clickable and Allow once
 * resumes the real orchestrator. Pipeline order itself is locked in
 * orchestrator-pipeline.spec.js (REST-direct); this only proves the React
 * HITL surface wires to those routes.
 *
 * Full Task 01 (non-preallowable + undo) coverage lands in this file later.
 */
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )
const { waitForSession, mockUseTools } = require( '../utils/session-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'Sidebar HITL approval card', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'Allow once on the HITL card completes a create-post run', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		const title = `E2E UI HITL ${ Date.now() }`

		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Creating a draft…',
				[ { name: 'ahentic/create-post', input: { title, post_type: 'post' } } ],
				{
					plan: {
						title: 'Create draft',
						steps: [
							{ id: '1', content: 'Create the draft', status: 'in_progress' },
							{ id: '2', content: 'Confirm', status: 'pending' },
						],
					},
				}
			),
			mockReply( 'Draft created from the sidebar approval.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( `Create a draft titled ${ title }` )

		// Wait on the same session status the REST pipeline asserts — the card
		// only mounts after the sidebar applies that poll payload.
		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'awaiting_human' && s.pendingTool?.name === 'ahentic/create-post'
		)

		await expect( ahenticSidebar.hitlCard ).toBeVisible( { timeout: 15_000 } )
		await expect( ahenticSidebar.hitlCard ).toContainText( 'ahentic/create-post' )

		await ahenticSidebar.decideHitl( 'allow_once' )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

		await expect( ahenticSidebar.hitlCard ).toBeHidden( { timeout: 15_000 } )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Draft created from the sidebar approval.', {
			timeout: 15_000,
		} )

		const listed = await requestUtils.rest( {
			path: '/wp/v2/posts',
			params: { search: title, status: 'draft' },
		} )
		expect( Array.isArray( listed ) && listed.length ).toBeGreaterThanOrEqual( 1 )
	} )
} )
