/**
 * Browser UI: plan card lifecycle + live status / mocked orchestrator loop chrome.
 *
 * REST covers plan persist / cancel_on_stop / jobResumable seams
 * (orchestrator-pipeline, job-resume). This file asserts the sidebar chrome.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )
const {
	mockUseTools,
	mockAiError,
	waitForSession,
} = require( '../utils/session-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

const THREE_STEP_PLAN = {
	title: 'Site check',
	steps: [
		{
			id: '1', content: 'Snapshot the site', status: 'in_progress',
		},
		{
			id: '2', content: 'Review recent posts', status: 'pending',
		},
		{
			id: '3', content: 'Summarize findings', status: 'pending',
		},
	],
}

const TWO_STEP_PLAN = {
	title: 'Short check',
	steps: [
		{
			id: '1', content: 'Snapshot the site', status: 'in_progress',
		},
		{
			id: '2', content: 'Summarize findings', status: 'pending',
		},
	],
}

test.describe( 'Sidebar plan card + live status', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'single readonly tool without a plan does not show the plan card', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockUseTools( 'Checking the site…', [
				{ name: 'ahentic/get-site-snapshot', input: {} },
			] ),
			mockReply( 'Here is a quick site snapshot.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( 'Give me a site snapshot.' )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'site snapshot', {
			timeout: 15_000,
		} )
		await expect( ahenticSidebar.planCard ).toHaveCount( 0 )
	} )

	test( 'two-step plan does not show the plan card', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Quick look…',
				[
					{ name: 'ahentic/get-site-snapshot', input: {} },
					{ name: 'ahentic/list-content', input: { post_type: 'post', per_page: 3 } },
				],
				{ plan: TWO_STEP_PLAN }
			),
			mockReply( 'Short plan stays off the card.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( 'Quick inspection.' )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'idle' && ( s.messages || [] ).some(
				m => m.role === 'assistant' && String( m.content || '' ).includes( 'Short plan stays off' )
			)
		)

		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Short plan stays off the card.' )
		await expect( ahenticSidebar.planCard ).toHaveCount( 0 )
	} )

	test( 'multi-step mocked loop shows live status then a plan and final reply', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Looking around…',
				[
					{ name: 'ahentic/get-site-snapshot', input: {} },
					{ name: 'ahentic/list-content', input: { post_type: 'post', per_page: 5 } },
				],
				{ plan: THREE_STEP_PLAN }
			),
			mockReply( 'Loop finished — here is the summary.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( 'Inspect the site and recent posts.' )

		await expect( ahenticSidebar.liveStatus ).toBeVisible( { timeout: 15_000 } )
		await expect( ahenticSidebar.liveStatus ).not.toHaveText( /^\s*$/ )

		await expect( ahenticSidebar.planCard ).toBeVisible( { timeout: 15_000 } )
		await expect( ahenticSidebar.planEyebrow ).toContainText( /Plan/i )
		await expect( ahenticSidebar.planCard ).toContainText( 'Snapshot the site' )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Loop finished', {
			timeout: 15_000,
		} )
		await expect( ahenticSidebar.planEyebrow ).toContainText( /Plan complete/i )
		await expect( ahenticSidebar.liveStatus ).toHaveCount( 0 )
	} )

	test( 'stopping a run marks the plan card as Plan stopped', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		const title = `E2E plan stop ${ Date.now() }`

		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Creating a draft…',
				[ {
					name: 'ahentic/create-post', input: {
						title, post_type: 'post', status: 'draft',
					},
				} ],
				{
					plan: {
						title: 'Create draft',
						steps: [
							{
								id: '1', content: 'Create the draft', status: 'in_progress',
							},
							{
								id: '2', content: 'Review the draft', status: 'pending',
							},
							{
								id: '3', content: 'Confirm', status: 'pending',
							},
						],
					},
				}
			),
			mockReply( 'Should not appear after stop.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( `Create a draft titled ${ title }` )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'awaiting_human' && s.pendingTool?.name === 'ahentic/create-post'
		)

		await expect( ahenticSidebar.planCard ).toBeVisible( { timeout: 15_000 } )
		await expect( ahenticSidebar.stopButton ).toBeVisible()
		await ahenticSidebar.stopButton.click()

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

		await expect( ahenticSidebar.planEyebrow ).toContainText( /Plan stopped/i, {
			timeout: 15_000,
		} )
		await expect( ahenticSidebar.planCard.locator( '.ahentic-plan__step.is-cancelled' ) ).toHaveCount( 3 )
	} )

	test( 're-prompting while idle clears the previous plan card', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Checking…',
				[
					{ name: 'ahentic/get-site-snapshot', input: {} },
					{ name: 'ahentic/list-content', input: { post_type: 'post', per_page: 3 } },
				],
				{ plan: THREE_STEP_PLAN }
			),
			mockReply( 'First pass done.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( 'First inspection.' )
		// Do not wait for idle first — a fresh session is already idle before send lands.
		await expect( ahenticSidebar.planCard ).toBeVisible( { timeout: 15_000 } )
		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'idle' && ( s.messages || [] ).some(
				m => m.role === 'assistant' && String( m.content || '' ).includes( 'First pass done' )
			)
		)
		await expect( ahenticSidebar.planEyebrow ).toContainText( /Plan complete/i )

		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Second reply with no plan.' ),
		] )
		await ahenticSidebar.sendMessage( 'Thanks — anything else?' )

		await expect( ahenticSidebar.planCard ).toHaveCount( 0 )
		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'idle' && ( s.messages || [] ).some(
				m => m.role === 'assistant' && String( m.content || '' ).includes( 'Second reply' )
			)
		)
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Second reply', {
			timeout: 15_000,
		} )
		await expect( ahenticSidebar.planCard ).toHaveCount( 0 )
	} )

	test( 'resumable failure shows Continue cue in the live status row', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Looking at recent posts…',
				[ { name: 'ahentic/get-site-snapshot', input: {} } ],
				{ plan: THREE_STEP_PLAN }
			),
			mockAiError(),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( 'write a 1000 word article based on my previous posts' )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'idle' && Boolean( s.jobResumable )
		)

		await expect( ahenticSidebar.liveStatus ).toBeVisible( { timeout: 15_000 } )
		await expect( ahenticSidebar.liveStatus ).toContainText( /can be continued/i )
		await expect( ahenticSidebar.liveStatus.getByRole( 'button', { name: 'Continue' } ) ).toBeVisible()

		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Resumed from the Continue cue.' ),
		] )
		await ahenticSidebar.liveStatus.getByRole( 'button', { name: 'Continue' } ).click()

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'idle' && ! s.jobResumable
		)
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Resumed from the Continue cue.', {
			timeout: 15_000,
		} )
	} )

	test( 'browser ability auto-resumes in the UI and shows the keep-tab hint', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		await ahenticSidebar.seedAiResponses( [
			mockUseTools( 'Reading the current admin page…', [
				{ name: 'ahentic-browser/get-current-page', input: {} },
			] ),
			mockReply( 'You are on the WordPress dashboard.' ),
		] )

		const session = await ahenticSidebar.openWithSession( {
			path: '/wp-admin/index.php',
		} )
		await ahenticSidebar.sendMessage( 'What screen am I on?' )

		await expect(
			ahenticSidebar.liveStatus.getByText( /Keep this tab visible/i )
		).toBeVisible( { timeout: 20_000 } )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'dashboard', {
			timeout: 20_000,
		} )
	} )
} )
