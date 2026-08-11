/**
 * Run feedback intake proxy (REST-direct) — mint + report against mocked intake.
 */
/* eslint-disable camelcase -- REST body keys match PHP / intake wire format. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const {
	startRun,
	waitForSession,
	mockUseTools,
	mockAiError,
} = require( '../utils/session-client' )
const { resetAiResponses, seedAiResponses } = require( '../utils/ability-client' )

const ARTICLE_PLAN = {
	title: 'Draft next article',
	steps: [
		{
			id: '1', content: 'Review recent posts', status: 'in_progress',
		},
		{
			id: '2', content: 'Draft and stage the article', status: 'pending',
		},
	],
}

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'Run feedback intake proxy (REST)', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await resetAiResponses( requestUtils )
	} )

	test( 'GET /feedback reports status without a site token', async ( { requestUtils } ) => {
		const status = await requestUtils.rest( {
			path: '/ahentic/v1/feedback',
		} )
		expect( status ).toMatchObject( {
			hasToken: expect.any( Boolean ),
			consented: expect.any( Boolean ),
			intakeBase: expect.stringContaining( 'feedback' ),
		} )
		expect( status ).not.toHaveProperty( 'site_token' )
		expect( status ).not.toHaveProperty( 'siteToken' )
		expect( status ).not.toHaveProperty( 'turnstileSiteKey' )
	} )

	test( 'mint + report files a mocked issue for a resumable session', async ( {
		requestUtils,
	} ) => {
		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools(
					'Looking at recent posts…',
					[ { name: 'ahentic/get-site-snapshot', input: {} } ],
					{ plan: ARTICLE_PLAN }
				),
				mockAiError(),
			],
			content: 'write a long article based on my previous posts',
		} )

		await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle' && Boolean( s.jobResumable )
		)

		const minted = await requestUtils.rest( {
			path: '/ahentic/v1/feedback/site-tokens',
			method: 'POST',
			data: {},
		} )
		expect( minted.hasToken ).toBe( true )
		expect( minted ).not.toHaveProperty( 'site_token' )
		expect( minted ).not.toHaveProperty( 'turnstileSiteKey' )

		await seedAiResponses( requestUtils, [
			JSON.stringify( {
				title: 'E2E unsure run',
				summary: 'The run failed while gathering posts for the article.',
			} ),
		] )

		const filed = await requestUtils.rest( {
			path: '/ahentic/v1/feedback/reports',
			method: 'POST',
			data: {
				session_id: Number( sessionId ),
				user_note: 'It edited the wrong page.',
			},
		} )

		expect( filed ).toMatchObject( {
			action: 'created',
			number: 4242,
			html_url: expect.stringContaining( 'github.com/gambitph/Ahentic/issues/4242' ),
		} )
	} )
} )
