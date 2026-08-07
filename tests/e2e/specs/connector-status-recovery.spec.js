/**
 * Regression: localize-time `hasConnector: false` with a live GET that is
 * green must not leave the "Add an AI connector" UI stuck (verified
 * production failure: boot false, live true).
 *
 * Harness: `seed-ai-status-flake` (tests/e2e/mu-plugins) makes the next N
 * `build_status_payload()` calls report no connector; the sidebar must
 * fetch `GET /ai-plugin/status` once on mount and recover (no further
 * open/focus re-probes — soft-false must not re-lock a green composer).
 */
const { test, expect } = require( '../fixtures/test' )
const { seedAiStatusFlake } = require( '../utils/ability-client' )

/**
 * @param {string} url
 * @return {boolean} Whether the URL is a GET for the AI plugin status endpoint.
 */
function isAiPluginStatusGet( url ) {
	try {
		const parsed = new URL( url )
		if ( parsed.pathname.includes( '/ai-plugin/status' ) ) {
			return true
		}
		const restRoute = parsed.searchParams.get( 'rest_route' ) || ''
		return restRoute.includes( '/ai-plugin/status' )
	} catch {
		return false
	}
}

test.describe( 'Ahentic AI connector status recovery', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'mount re-fetches status and clears a localize false-negative', async ( {
		ahenticSidebar,
		requestUtils,
		page,
	} ) => {
		// Localize consumes the first false; mount GET(s) must see true.
		await seedAiStatusFlake( requestUtils, 1 )

		const statusResponsePromise = page.waitForResponse( response => (
			response.request().method() === 'GET' &&
			response.ok() &&
			isAiPluginStatusGet( response.url() )
		) )

		await ahenticSidebar.openWithSession()

		const statusResponse = await statusResponsePromise
		const live = await statusResponse.json()
		expect( live.hasConnector ).toBe( true )
		expect( live.canGenerate ).toBe( true )

		const missingConnector = page.getByText( 'Add an AI connector so Ahentic can talk to a model' )
		await expect( missingConnector ).toHaveCount( 0, { timeout: 15 * 1000 } )
		await expect( ahenticSidebar.composer ).toBeEnabled( { timeout: 15 * 1000 } )

		const bootAfterSync = await page.evaluate( () => ( {
			hasConnector: Boolean( window.ahentic?.aiPlugin?.hasConnector ),
			canGenerate: Boolean( window.ahentic?.aiPlugin?.canGenerate ),
		} ) )
		expect( bootAfterSync.hasConnector ).toBe( true )
		expect( bootAfterSync.canGenerate ).toBe( true )
	} )

	test( 'keeps the missing-connector notice when live status stays false', async ( {
		ahenticSidebar,
		requestUtils,
		page,
	} ) => {
		// Enough falses for localize + mount re-fetch(es) so recovery cannot succeed.
		await seedAiStatusFlake( requestUtils, 20 )
		await ahenticSidebar.openWithSession()

		const missingConnector = page.getByText( 'Add an AI connector so Ahentic can talk to a model' )
		await expect( missingConnector ).toBeVisible( { timeout: 15 * 1000 } )
		await expect( ahenticSidebar.composer ).toBeDisabled()
	} )
} )
