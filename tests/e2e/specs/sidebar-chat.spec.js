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
} )
