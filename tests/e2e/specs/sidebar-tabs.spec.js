/**
 * Browser UI: multi-tab agent strip (new / switch / close / clear all).
 */
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )
const { waitForSession } = require( '../utils/session-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'Sidebar agent tabs', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'New agent adds a tab; switching preserves each transcript', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Reply in tab one.' ),
		] )
		const first = await ahenticSidebar.openWithSession( { title: 'Tab One' } )
		await ahenticSidebar.sendMessage( 'Hello from tab one' )
		await waitForSession( requestUtils, first.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Reply in tab one.', {
			timeout: 15_000,
		} )

		await expect( ahenticSidebar.tabs ).toHaveCount( 1 )
		await ahenticSidebar.newAgentTab()
		await expect( ahenticSidebar.tabs ).toHaveCount( 2 )

		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Reply in tab two.' ),
		] )
		await ahenticSidebar.sendMessage( 'Hello from tab two' )

		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Reply in tab two.', {
			timeout: 20_000,
		} )

		await ahenticSidebar.tabs.first().click()
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Reply in tab one.', {
			timeout: 15_000,
		} )

		await ahenticSidebar.tabs.last().click()
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Reply in tab two.', {
			timeout: 15_000,
		} )
	} )

	test( 'closing a tab removes it; closing the last tab leaves one session', async ( {
		ahenticSidebar,
	} ) => {
		await ahenticSidebar.openWithSession( { title: 'Keep' } )
		await ahenticSidebar.newAgentTab()
		await expect( ahenticSidebar.tabs ).toHaveCount( 2 )

		await ahenticSidebar.tabs.last().getByRole( 'button', { name: /Close/i } ).click()
		await expect( ahenticSidebar.tabs ).toHaveCount( 1 )

		await ahenticSidebar.tabs.first().getByRole( 'button', { name: /Close/i } ).click()
		await expect( ahenticSidebar.tabs ).toHaveCount( 1 )
		await expect( ahenticSidebar.composer ).toBeVisible()
	} )

	test( 'Clear all resets to a single fresh tab', async ( { ahenticSidebar } ) => {
		await ahenticSidebar.openWithSession( { title: 'A' } )
		await ahenticSidebar.newAgentTab()
		await ahenticSidebar.newAgentTab()
		await expect( ahenticSidebar.tabs ).toHaveCount( 3 )

		await ahenticSidebar.clearAllTabs()
		await expect( ahenticSidebar.tabs ).toHaveCount( 1 )
		await expect( ahenticSidebar.composer ).toBeVisible()
	} )

	test( 'first user message updates the tab title', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Noted.' ),
		] )
		const session = await ahenticSidebar.openWithSession( { title: 'New Agent' } )
		await ahenticSidebar.sendMessage( 'Rename this tab with a long prompt about widgets' )
		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

		await expect( ahenticSidebar.tabs.first() ).not.toContainText( 'New Agent', {
			timeout: 15_000,
		} )
		await expect( ahenticSidebar.tabs.first() ).toContainText( /Rename this tab/i )
	} )
} )
