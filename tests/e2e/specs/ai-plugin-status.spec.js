/**
 * REST-direct: AI plugin status payload + e2e flake harness.
 *
 * The sidebar recovers from localize-time `hasConnector: false` by calling
 * `GET /ahentic/v1/ai-plugin/status` (see sidebar.js `syncAiPluginStatus`).
 * These tests pin that endpoint and the `seed-ai-status-flake` counter the
 * browser recovery spec depends on.
 */
const { test, expect } = require( '../fixtures/test' )
const { seedAiStatusFlake, resetAiResponses } = require( '../utils/ability-client' )

test.describe( 'AI plugin status REST', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await resetAiResponses( requestUtils )
	} )

	test( 'GET /ai-plugin/status reports ready under the e2e override', async ( { requestUtils } ) => {
		const status = await requestUtils.rest( {
			path: '/ahentic/v1/ai-plugin/status',
		} )

		expect( status ).toMatchObject( {
			isReady: true,
			hasConnector: true,
			canGenerate: true,
		} )
		expect( status.connectorsUrl ).toBeTruthy()
	} )

	test( 'seed-ai-status-flake makes the next status builds report no connector', async ( { requestUtils } ) => {
		await seedAiStatusFlake( requestUtils, 2 )

		const first = await requestUtils.rest( {
			path: '/ahentic/v1/ai-plugin/status',
		} )
		expect( first ).toMatchObject( {
			isReady: true,
			hasConnector: false,
			canGenerate: false,
		} )

		const second = await requestUtils.rest( {
			path: '/ahentic/v1/ai-plugin/status',
		} )
		expect( second ).toMatchObject( {
			isReady: true,
			hasConnector: false,
			canGenerate: false,
		} )

		const third = await requestUtils.rest( {
			path: '/ahentic/v1/ai-plugin/status',
		} )
		expect( third ).toMatchObject( {
			isReady: true,
			hasConnector: true,
			canGenerate: true,
		} )
	} )

	test( 'reset clears a pending status flake', async ( { requestUtils } ) => {
		await seedAiStatusFlake( requestUtils, 5 )
		await resetAiResponses( requestUtils )

		const status = await requestUtils.rest( {
			path: '/ahentic/v1/ai-plugin/status',
		} )
		expect( status.hasConnector ).toBe( true )
		expect( status.canGenerate ).toBe( true )
	} )
} )
