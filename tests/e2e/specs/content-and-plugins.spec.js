/**
 * Module spec: existing content/site/plugin abilities.
 *
 * This is the harness "proof" spec — it exercises abilities that already
 * ship today (`ahentic/get-site-snapshot`) against a real WordPress instance
 * (booted by `@wp-playground/cli`, see playwright.config.js) via the
 * e2e-only `ahentic-e2e/v1/run-ability` route, without driving a real LLM
 * turn. See tests/e2e/README.md for the pattern to follow when adding
 * coverage for new abilities (mvp-abilities tasks 02-06).
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const { request: apiRequest } = require( '@playwright/test' )
const { runAbility } = require( '../utils/ability-client' )

test.describe( 'ahentic-e2e harness', () => {
	test( 'the e2e ability-runner mu-plugin is loaded', async ( { requestUtils } ) => {
		const body = await requestUtils.rest( { path: '/ahentic-e2e/v1/health' } )

		expect( body.abilities_loaded ).toBe( true )
	} )
} )

test.describe( 'ahentic/get-site-snapshot', () => {
	test( 'returns real site identity, not a stub', async ( { requestUtils, baseURL } ) => {
		const result = await runAbility( requestUtils, 'ahentic/get-site-snapshot' )

		expect( result.ok ).toBe( true )
		expect( result.data.home_url.replace( /\/$/, '' ) ).toBe( baseURL.replace( /\/$/, '' ) )
		expect( result.data.is_multisite ).toBe( false )
		expect( typeof result.data.wp_version ).toBe( 'string' )
		expect( Array.isArray( result.data.plugins ) ).toBe( true )
	} )
} )

test.describe( 'Ahentic_Abilities::execute() dispatch', () => {
	test( 'an unknown ability name fails with a stable error code', async ( { requestUtils } ) => {
		const result = await runAbility( requestUtils, 'ahentic/does-not-exist' )

		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'ahentic_ability_unknown' )
	} )

	test( 'run-ability is not reachable without an authenticated admin', async ( { baseURL } ) => {
		const anonymous = await apiRequest.newContext( { baseURL } )

		const response = await anonymous.post( '/wp-json/ahentic-e2e/v1/run-ability', {
			data: { name: 'ahentic/get-site-snapshot' },
		} )

		expect( response.ok() ).toBe( false )
		await anonymous.dispose()
	} )
} )
