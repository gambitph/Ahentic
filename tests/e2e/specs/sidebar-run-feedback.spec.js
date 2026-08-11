/**
 * Browser UI: Run feedback Yes/No after a prompt settles to idle.
 *
 * REST covers the intake proxy; this tier asserts the sidebar chrome.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )
const { waitForSession } = require( '../utils/session-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'Sidebar run feedback', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'idle after a prompt shows Run feedback Yes/No in the sidebar', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Here is a short answer.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await expect( ahenticSidebar.runFeedback ).toHaveCount( 0 )

		await ahenticSidebar.sendMessage( 'Say hello briefly' )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

		await expect( ahenticSidebar.runFeedback ).toBeVisible( { timeout: 15_000 } )
		await expect( ahenticSidebar.runFeedback ).toContainText( /Did this run go okay/i )
		await expect( ahenticSidebar.runFeedback.getByRole( 'button', { name: 'Yes' } ) ).toBeVisible()
		await expect( ahenticSidebar.runFeedback.getByRole( 'button', { name: 'No' } ) ).toBeVisible()
	} )

	test( 'hides Run feedback while a new run is busy, then shows again when idle', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockReply( 'First reply.' ),
			mockReply( 'Second reply.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( 'First question' )
		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.runFeedback ).toBeVisible( { timeout: 15_000 } )

		await ahenticSidebar.sendMessage( 'Second question' )
		await expect( ahenticSidebar.runFeedback ).toHaveCount( 0 )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'idle' && ( s.messages || [] ).some(
				m => m.role === 'assistant' && String( m.content || '' ).includes( 'Second reply' )
			)
		)
		await expect( ahenticSidebar.runFeedback ).toBeVisible( { timeout: 15_000 } )
	} )

	test( 'Yes dismisses the Run feedback indicator', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Dismissable reply.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( 'Please reply' )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

		await expect( ahenticSidebar.runFeedback ).toBeVisible( { timeout: 15_000 } )
		await ahenticSidebar.runFeedback.getByRole( 'button', { name: 'Yes' } ).click()
		await expect( ahenticSidebar.runFeedback ).toHaveCount( 0 )
	} )

	test( 'No files a mocked Run feedback report', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Reportable reply.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( 'Please reply for feedback' )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Reportable reply.', {
			timeout: 15_000,
		} )
		await expect( ahenticSidebar.runFeedback ).toBeVisible( { timeout: 15_000 } )

		// Summary draft for file_report — seed after the chat turn so the orchestrator
		// cannot consume it as a second model reply.
		await ahenticSidebar.seedAiResponses( [
			JSON.stringify( {
				title: 'E2E run feedback',
				summary: 'The run finished with a short reply.',
			} ),
		] )
		await ahenticSidebar.runFeedback.getByRole( 'button', { name: 'No' } ).click()

		await expect( ahenticSidebar.runFeedback ).toContainText( /Thanks|View on GitHub|filed/i, {
			timeout: 30_000,
		} )
	} )
} )
