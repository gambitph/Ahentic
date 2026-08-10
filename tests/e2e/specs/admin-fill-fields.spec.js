/**
 * Browser-driven coverage for admin form-first fills.
 *
 * DOM fill via __ahenticE2E.fillFields on a real Settings screen, plus a
 * sidebar chat turn that fills without showing an HITL card.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { expect, test } = require( '../fixtures/test' )
const {
	waitForSession,
	mockUseTools,
	mockReply,
} = require( '../utils/session-client' )

test.describe.configure( { mode: 'serial', timeout: 120_000 } )

test.describe( 'Admin fill-fields (browser)', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'fillFields on General Settings updates blogname without submitting', async ( {
		ahenticSidebar,
		page,
	} ) => {
		await ahenticSidebar.openWithSession( {
			path: '/wp-admin/options-general.php',
		} )

		await page.waitForFunction(
			() => typeof window.__ahenticE2E?.fillFields === 'function',
			null,
			{ timeout: 30_000 }
		)

		const blogname = page.locator( '#blogname' )
		await expect( blogname ).toBeVisible()
		const before = await blogname.inputValue()
		const next = `Ahentic Fill ${ Date.now() }`

		const result = await page.evaluate( value => window.__ahenticE2E.fillFields( {
			fields: [ { name: 'blogname', value } ],
		} ), next )

		expect( result.ok ).toBe( true )
		expect( result.filled?.[ 0 ]?.name ).toBe( 'blogname' )
		await expect( blogname ).toHaveValue( next )
		expect( before ).not.toBe( next )

		// Form must remain dirty / unsaved — option in DB unchanged until Save.
		const denied = await page.evaluate( () => window.__ahenticE2E.fillFields( {
			fields: [ { name: 'siteurl', value: 'https://evil.example' } ],
		} ) )
		expect( denied.ok ).toBe( false )
		expect( denied.skipped?.[ 0 ]?.reason ).toBe( 'option_denied' )
	} )

	test( 'sidebar fill-fields for blogname reaches idle without an HITL card', async ( {
		ahenticSidebar,
		requestUtils,
		page,
	} ) => {
		const next = `Sidebar Fill ${ Date.now() }`

		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Filling the site title on this form…',
				[
					{
						name: 'ahentic-browser/fill-fields',
						input: {
							fields: [ { name: 'blogname', value: next } ],
						},
					},
				],
				{
					plan: {
						title: 'Update site title on form',
						steps: [
							{
								id: '1', content: 'Fill the Site Title field', status: 'in_progress',
							},
							{
								id: '2', content: 'Leave Save for the user', status: 'pending',
							},
							{
								id: '3', content: 'Confirm', status: 'pending',
							},
						],
					},
				}
			),
			mockReply( 'Updated the Site Title field. Click Save Changes when you are ready.' ),
		] )

		const session = await ahenticSidebar.openWithSession( {
			path: '/wp-admin/options-general.php',
		} )

		await ahenticSidebar.sendMessage( `Change the site title to ${ next } on this screen` )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'idle' || s.status === 'awaiting_human'
		)

		// Ordinary fill must not surface the HITL card.
		await expect( ahenticSidebar.hitlCard ).toBeHidden( { timeout: 5_000 } )

		const done = await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'idle'
		)
		expect( done.status ).toBe( 'idle' )

		await expect( page.locator( '#blogname' ) ).toHaveValue( next, { timeout: 15_000 } )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Save Changes', {
			timeout: 15_000,
		} )
	} )
} )
