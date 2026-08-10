/**
 * Browser UI: sidebar open/tabs/content survive a full page refresh.
 *
 * Chrome (`open`) + tab strip live in `ahentic.sidebar.v1` localStorage;
 * message bodies hydrate from the session REST route after reload.
 */
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )
const { waitForSession } = require( '../utils/session-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'Sidebar refresh persistence', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'open sidebar with one session keeps the transcript after refresh', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Persisted reply after refresh.' ),
		] )
		const session = await ahenticSidebar.openWithSession( { title: 'Persist One' } )
		await ahenticSidebar.sendMessage( 'Remember this chat' )
		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.message( 'user' ) ).toContainText( 'Remember this chat' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Persisted reply after refresh.', {
			timeout: 15_000,
		} )

		await ahenticSidebar.reloadPreservingSidebar( { sessionId: session.id, expectOpen: true } )

		await expect( ahenticSidebar.sidebar ).toBeVisible()
		await expect( ahenticSidebar.tabs ).toHaveCount( 1 )
		await expect( ahenticSidebar.message( 'user' ) ).toContainText( 'Remember this chat', {
			timeout: 15_000,
		} )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Persisted reply after refresh.' )
	} )

	test( 'two agent tabs both survive refresh with their transcripts', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockReply( 'First tab reply.' ),
		] )
		const first = await ahenticSidebar.openWithSession( { title: 'Tab A' } )
		await ahenticSidebar.sendMessage( 'Hello from tab A' )
		await waitForSession( requestUtils, first.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'First tab reply.', {
			timeout: 15_000,
		} )

		await ahenticSidebar.newAgentTab()
		await expect( ahenticSidebar.tabs ).toHaveCount( 2 )

		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Second tab reply.' ),
		] )
		await ahenticSidebar.sendMessage( 'Hello from tab B' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Second tab reply.', {
			timeout: 20_000,
		} )

		await ahenticSidebar.reloadPreservingSidebar( { expectOpen: true } )

		await expect( ahenticSidebar.sidebar ).toBeVisible()
		await expect( ahenticSidebar.tabs ).toHaveCount( 2 )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Second tab reply.', {
			timeout: 15_000,
		} )

		await ahenticSidebar.tabs.first().click()
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'First tab reply.', {
			timeout: 15_000,
		} )
		await expect( ahenticSidebar.message( 'user' ) ).toContainText( 'Hello from tab A' )

		await ahenticSidebar.tabs.last().click()
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Second tab reply.', {
			timeout: 15_000,
		} )
		await expect( ahenticSidebar.message( 'user' ) ).toContainText( 'Hello from tab B' )
	} )

	test( 'sidebar that is open stays open after refresh', async ( { ahenticSidebar } ) => {
		await ahenticSidebar.openWithSession( { open: true } )
		await expect( ahenticSidebar.sidebar ).toBeVisible()

		await ahenticSidebar.reloadPreservingSidebar( { expectOpen: true } )

		await expect( ahenticSidebar.sidebar ).toBeVisible()
		await expect( ahenticSidebar.composer ).toBeVisible()
	} )

	test( 'sidebar that is closed stays closed after refresh', async ( {
		ahenticSidebar,
		page,
	} ) => {
		await ahenticSidebar.openWithSession( { open: true } )
		await expect( ahenticSidebar.sidebar ).toBeVisible()

		await ahenticSidebar.toggleViaShortcut()
		await expect( ahenticSidebar.sidebar ).toHaveCount( 0 )

		await ahenticSidebar.reloadPreservingSidebar( { expectOpen: false } )

		await expect( ahenticSidebar.sidebar ).toHaveCount( 0 )
		await expect( page.locator( '#wp-admin-bar-ahentic-toggle' ) ).toBeVisible()
		await expect( page.locator( '#ahentic-root' ) ).toHaveCount( 1 )
	} )
} )
