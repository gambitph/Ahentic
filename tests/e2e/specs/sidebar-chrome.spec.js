/**
 * Browser UI: chrome — debugger, resize, float snap-back, open entry points, Ask mode.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )
const { mockUseTools, waitForSession } = require( '../utils/session-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'Sidebar chrome', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'opens from the admin bar and via keyboard shortcut', async ( {
		ahenticSidebar,
		page,
	} ) => {
		await ahenticSidebar.openWithSession( { open: false } )
		await expect( ahenticSidebar.sidebar ).toHaveCount( 0 )
		await expect( page.locator( '#wp-admin-bar-ahentic-toggle' ) ).toBeVisible()

		await ahenticSidebar.openViaAdminBar()
		await expect( ahenticSidebar.sidebar ).toBeVisible()
		await expect( ahenticSidebar.composer ).toBeVisible()

		await ahenticSidebar.toggleViaShortcut()
		await expect( ahenticSidebar.sidebar ).toHaveCount( 0 )

		await ahenticSidebar.toggleViaShortcut()
		await expect( ahenticSidebar.sidebar ).toBeVisible()
	} )

	test( 'docked sidebar can be resized wider', async ( { ahenticSidebar } ) => {
		await ahenticSidebar.openWithSession( { width: 360 } )
		const before = await ahenticSidebar.panelWidth()
		await ahenticSidebar.resizeBy( 80 )
		const after = await ahenticSidebar.panelWidth()
		expect( after ).toBeGreaterThan( before + 40 )
	} )

	test( 'out-of-bounds floating sidebar snaps back on open', async ( {
		ahenticSidebar,
		page,
	} ) => {
		await ahenticSidebar.openWithSession( {
			open: false,
			placement: 'floating',
			width: 400,
			floatRect: {
				left: 5000,
				top: 5000,
				width: 400,
				height: 500,
			},
		} )

		await ahenticSidebar.openViaAdminBar()
		await expect( ahenticSidebar.sidebar ).toBeVisible()

		const box = await ahenticSidebar.sidebar.boundingBox()
		expect( box ).toBeTruthy()
		const viewport = page.viewportSize()
		expect( box.x ).toBeGreaterThanOrEqual( 0 )
		expect( box.y ).toBeGreaterThanOrEqual( 0 )
		expect( box.x + box.width ).toBeLessThanOrEqual( viewport.width + 1 )
		expect( box.y + box.height ).toBeLessThanOrEqual( viewport.height + 1 )
	} )

	test( 'renders WordPress-i18n chrome labels in the live sidebar', async ( {
		ahenticSidebar,
		page,
	} ) => {
		const evidenceDir = process.env.NO_MISTAKES_EVIDENCE_DIR
		await ahenticSidebar.openWithSession( {
			mode: 'agent',
		} )

		await expect( ahenticSidebar.composer ).toBeVisible()
		await expect( ahenticSidebar.composer ).toHaveAttribute( 'aria-label', 'Ask Ahentic' )
		await expect(
			ahenticSidebar.sidebar.getByPlaceholder( 'Plan, Build, / for skills, @ for context' )
		).toBeVisible()
		await expect( ahenticSidebar.sidebar.getByRole( 'button', { name: 'Select mode' } ) ).toContainText( 'Agent' )
		await expect( ahenticSidebar.tabs.first() ).toContainText( 'New Agent' )
		await expect( ahenticSidebar.sidebar.getByRole( 'button', { name: 'New agent', exact: true } ) ).toBeVisible()

		if ( evidenceDir ) {
			await ahenticSidebar.sidebar.screenshot( {
				path: `${ evidenceDir }/sidebar-i18n-chrome-agent.png`,
			} )
		}

		await ahenticSidebar.sidebar.getByRole( 'button', { name: 'Select mode' } ).click()
		await expect( ahenticSidebar.sidebar.getByRole( 'option', { name: 'Agent' } ) ).toBeVisible()
		await expect( ahenticSidebar.sidebar.getByRole( 'option', { name: 'Ask' } ) ).toBeVisible()

		if ( evidenceDir ) {
			await page.screenshot( {
				path: `${ evidenceDir }/sidebar-i18n-chrome-mode-menu.png`,
				fullPage: false,
			} )
		}

		await ahenticSidebar.sidebar.getByRole( 'option', { name: 'Ask' } ).click()
		await expect( ahenticSidebar.sidebar.getByRole( 'button', { name: 'Select mode' } ) ).toContainText( 'Ask' )

		if ( evidenceDir ) {
			await ahenticSidebar.sidebar.screenshot( {
				path: `${ evidenceDir }/sidebar-i18n-chrome-ask.png`,
			} )
		}
	} )

	test( 'Ask mode can be selected in the composer UI', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		const title = `E2E ask ui ${ Date.now() }`

		const session = await ahenticSidebar.openWithSession( { mode: 'agent' } )
		await expect( ahenticSidebar.sidebar.getByRole( 'button', { name: 'Select mode' } ) ).toContainText( 'Agent' )

		await ahenticSidebar.setComposerMode( 'ask' )
		await expect( ahenticSidebar.sidebar.getByRole( 'button', { name: 'Select mode' } ) ).toContainText( 'Ask' )

		await ahenticSidebar.seedAiResponses( [
			mockUseTools( 'Trying to create…', [
				{ name: 'ahentic/create-post', input: { title, post_type: 'post' } },
			] ),
			mockReply( 'Ask mode is read-only — switch to Agent to create posts.' ),
		] )
		await ahenticSidebar.sendMessage( `Create ${ title }` )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.hitlCard ).toHaveCount( 0 )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( /Ask mode is read-only/i, {
			timeout: 15_000,
		} )
	} )

	test( 'debugger shows the log, expands events, copies, downloads, and closes', async ( {
		ahenticSidebar,
		requestUtils,
		context,
		page,
	} ) => {
		await context.grantPermissions( [ 'clipboard-read', 'clipboard-write' ] )

		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Debugger seed reply.' ),
		] )
		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( 'Ping for debugger' )
		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Debugger seed reply.', {
			timeout: 15_000,
		} )

		await ahenticSidebar.openDebugger()
		await expect( ahenticSidebar.debuggerPanel ).toBeVisible()
		await expect( ahenticSidebar.debuggerPanel.locator( '.ahentic-debugger__event' ).first() ).toBeVisible( {
			timeout: 15_000,
		} )

		const firstEvent = ahenticSidebar.debuggerPanel.locator( '.ahentic-debugger__event' ).first()
		await firstEvent.locator( '.ahentic-debugger__event-head' ).click()
		await expect( firstEvent.locator( '.ahentic-debugger__event-body' ) ).toBeVisible()
		await firstEvent.locator( '.ahentic-debugger__event-head' ).click()
		await expect( firstEvent.locator( '.ahentic-debugger__event-body' ) ).toHaveCount( 0 )

		await ahenticSidebar.debuggerPanel.getByRole( 'button', { name: 'Copy debug log' } ).click()
		await expect( ahenticSidebar.debuggerPanel.getByRole( 'button', { name: 'Copied' } ) ).toBeVisible( {
			timeout: 5_000,
		} )
		const clip = await page.evaluate( () => navigator.clipboard.readText() )
		expect( clip.length ).toBeGreaterThan( 20 )

		const downloadPromise = page.waitForEvent( 'download' )
		await ahenticSidebar.debuggerPanel.getByRole( 'button', { name: 'Download debug log' } ).click()
		const download = await downloadPromise
		expect( download.suggestedFilename() ).toMatch( /ahentic-session-.*\.json/ )

		await ahenticSidebar.closeDebugger()
		await expect( ahenticSidebar.debuggerPanel ).toHaveCount( 0 )
		await expect( ahenticSidebar.composer ).toBeVisible()
	} )
} )
