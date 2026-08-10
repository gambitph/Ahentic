/**
 * Browser UI: Ahentic top-bar affordance + role gating.
 *
 * Admins (`manage_options`) get the admin-bar toggle and sidebar on wp-admin
 * and the front end. Editors and anonymous visitors must not see the button,
 * mount root, or open Ahentic via Cmd/Ctrl+I.
 */
const { test, expect } = require( '../fixtures/test' )
const {
	ADMIN_BAR_NODE,
	AHENTIC_ROOT,
	ahenticLocators,
	pressAhenticShortcut,
	loginInFreshContext,
	visitorContext,
	ensureEditorUser,
} = require( '../utils/access-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

const ADMIN_PAGES = [
	'/wp-admin/',
	'/wp-admin/edit.php',
	'/wp-admin/plugins.php',
	'/wp-admin/options-general.php',
]

test.describe( 'Ahentic access: admin bar + roles', () => {
	/**
	 * @param {import('@playwright/test').Page} page
	 * @param {string} path
	 */
	async function gotoFresh( page, path ) {
		await page.addInitScript( () => {
			try {
				window.localStorage.removeItem( 'ahentic.sidebar.v1' )
			} catch ( _err ) {
				// Ignore private-mode / blocked storage.
			}
		} )
		await page.goto( path )
	}

	test( 'admin sees the Ahentic button on multiple admin pages and can open the sidebar', async ( {
		page,
		ahenticSidebar,
	} ) => {
		for ( const adminPath of ADMIN_PAGES ) {
			await gotoFresh( page, adminPath )
			await expect( page.locator( ADMIN_BAR_NODE ), `missing on ${ adminPath }` ).toBeVisible( {
				timeout: 15_000,
			} )
			await expect( page.locator( AHENTIC_ROOT ) ).toHaveCount( 1 )
			await expect( ahenticSidebar.sidebar ).toHaveCount( 0 )

			await ahenticSidebar.openViaAdminBar()
			await expect( ahenticSidebar.sidebar ).toBeVisible()
			await expect( ahenticSidebar.composer ).toBeVisible()

			await ahenticSidebar.toggleViaShortcut()
			await expect( ahenticSidebar.sidebar ).toHaveCount( 0 )
		}
	} )

	test( 'admin sees the Ahentic button on the front end and can open the sidebar', async ( {
		page,
		ahenticSidebar,
	} ) => {
		await gotoFresh( page, '/' )
		await expect( page.locator( ADMIN_BAR_NODE ) ).toBeVisible( { timeout: 15_000 } )
		await expect( page.locator( AHENTIC_ROOT ) ).toHaveCount( 1 )
		await expect( ahenticSidebar.sidebar ).toHaveCount( 0 )

		await ahenticSidebar.openViaAdminBar()
		await expect( ahenticSidebar.sidebar ).toBeVisible()
		await expect( ahenticSidebar.composer ).toBeVisible()

		await ahenticSidebar.toggleViaShortcut()
		await expect( ahenticSidebar.sidebar ).toHaveCount( 0 )

		await ahenticSidebar.toggleViaShortcut()
		await expect( ahenticSidebar.sidebar ).toBeVisible()
	} )

	test( 'editor does not see Ahentic on admin or front end, and shortcut does nothing', async ( {
		browser,
		requestUtils,
	} ) => {
		const editor = await ensureEditorUser( requestUtils )
		const { context, page } = await loginInFreshContext( browser, editor )

		try {
			await page.goto( '/wp-admin/' )
			const adminLoc = ahenticLocators( page )
			await expect( page.locator( '#wpadminbar' ) ).toBeVisible( { timeout: 15_000 } )
			await expect( adminLoc.bar ).toHaveCount( 0 )
			await expect( adminLoc.root ).toHaveCount( 0 )
			await pressAhenticShortcut( page )
			await expect( adminLoc.sidebar ).toHaveCount( 0 )
			await expect( adminLoc.root ).toHaveCount( 0 )

			await page.goto( '/' )
			const frontLoc = ahenticLocators( page )
			await expect( page.locator( '#wpadminbar' ) ).toBeVisible( { timeout: 15_000 } )
			await expect( frontLoc.bar ).toHaveCount( 0 )
			await expect( frontLoc.root ).toHaveCount( 0 )
			await pressAhenticShortcut( page )
			await expect( frontLoc.sidebar ).toHaveCount( 0 )
			await expect( frontLoc.root ).toHaveCount( 0 )
		} finally {
			await context.close()
		}
	} )

	test( 'site visitor does not see Ahentic, and shortcut does not open it', async ( {
		browser,
	} ) => {
		const { context, page } = await visitorContext( browser )

		try {
			await page.goto( '/' )
			const loc = ahenticLocators( page )
			await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 )
			await expect( loc.bar ).toHaveCount( 0 )
			await expect( loc.root ).toHaveCount( 0 )
			await pressAhenticShortcut( page )
			await expect( loc.sidebar ).toHaveCount( 0 )
			await expect( loc.root ).toHaveCount( 0 )
		} finally {
			await context.close()
		}
	} )
} )
