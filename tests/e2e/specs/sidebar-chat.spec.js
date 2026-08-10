/**
 * Browser-driven spec: the sidebar chat loop end to end (real page, real
 * REST session, mocked AI text) — this is the module's proof that a user
 * typing into the composer actually results in an assistant bubble, not just
 * that the ability-dispatch seam works in isolation (see
 * content-and-plugins.spec.js for that REST-direct tier).
 *
 * `pre_ahentic_ai_status` is force-"ready" by the e2e mu-plugin (see
 * tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php) so the composer isn't
 * disabled for lack of a real AI plugin/connector — only the response text
 * is mocked, not the run itself.
 */
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )
const { mockUseTools, waitForSession } = require( '../utils/session-client' )

test.describe( 'Ahentic sidebar chat', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'sending a message renders the mocked assistant reply', async ( { ahenticSidebar } ) => {
		await ahenticSidebar.seedAiResponses( [ mockReply( 'Hello from the mocked assistant.' ) ] )
		await ahenticSidebar.openWithSession()

		await ahenticSidebar.sendMessage( 'Hi there, Ahentic.' )

		await expect( ahenticSidebar.message( 'user' ) ).toContainText( 'Hi there, Ahentic.' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Hello from the mocked assistant.', {
			timeout: 30 * 1000,
		} )
	} )

	test.describe( 'composer Send / Stop', () => {
		test( 'Send is disabled when empty and enabled with text', async ( { ahenticSidebar } ) => {
			await ahenticSidebar.openWithSession()

			await expect( ahenticSidebar.sendButton ).toBeVisible()
			await expect( ahenticSidebar.sendButton ).toBeDisabled()
			await expect( ahenticSidebar.stopButton ).toHaveCount( 0 )

			await ahenticSidebar.composer.fill( 'Draft a hello page' )
			await expect( ahenticSidebar.sendButton ).toBeEnabled()
		} )

		test( 'Send button submits a message', async ( { ahenticSidebar } ) => {
			await ahenticSidebar.seedAiResponses( [ mockReply( 'Sent via the circle button.' ) ] )
			await ahenticSidebar.openWithSession()

			await ahenticSidebar.sendMessage( 'Use the send button.', { via: 'button' } )

			await expect( ahenticSidebar.message( 'user' ) ).toContainText( 'Use the send button.' )
			await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Sent via the circle button.', {
				timeout: 30 * 1000,
			} )
			await expect( ahenticSidebar.sendButton ).toBeVisible( { timeout: 15 * 1000 } )
			await expect( ahenticSidebar.stopButton ).toHaveCount( 0 )
		} )

		test( 'Stop replaces Send while a run is awaiting HITL', async ( {
			ahenticSidebar,
			requestUtils,
		} ) => {
			const title = `E2E composer stop ${ Date.now() }`

			await ahenticSidebar.seedAiResponses( [
				mockUseTools(
					'Creating a draft…',
					[ {
						name: 'ahentic/create-post',
						input: {
							title, post_type: 'post', status: 'draft',
						},
					} ],
					{
						plan: {
							title: 'Create draft',
							steps: [
								{
									id: '1', content: 'Create the draft', status: 'in_progress',
								},
								{
									id: '2', content: 'Review the draft', status: 'pending',
								},
								{
									id: '3', content: 'Confirm', status: 'pending',
								},
							],
						},
					}
				),
				mockReply( 'Stopped swap covered.' ),
			] )

			const session = await ahenticSidebar.openWithSession()
			await ahenticSidebar.sendMessage( `Create a draft titled ${ title }`, { via: 'button' } )

			await waitForSession(
				requestUtils,
				session.id,
				s => s.status === 'awaiting_human' && s.pendingTool?.name === 'ahentic/create-post'
			)

			await expect( ahenticSidebar.hitlCard ).toBeVisible( { timeout: 15_000 } )
			await expect( ahenticSidebar.stopButton ).toBeVisible()
			await expect( ahenticSidebar.sendButton ).toHaveCount( 0 )

			await ahenticSidebar.decideHitl( 'deny' )
			await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

			await expect( ahenticSidebar.sendButton ).toBeVisible( { timeout: 15_000 } )
			await expect( ahenticSidebar.stopButton ).toHaveCount( 0 )
		} )

		test( 'attach and voice affordances stay hidden', async ( { ahenticSidebar } ) => {
			await ahenticSidebar.openWithSession()

			await expect( ahenticSidebar.sidebar.getByRole( 'button', { name: 'Attach file' } ) ).toBeHidden()
			await expect( ahenticSidebar.sidebar.getByRole( 'button', { name: 'Voice input' } ) ).toBeHidden()
		} )
	} )
} )
