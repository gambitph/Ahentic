/**
 * Mid-failure job resume (#3) — Session REST / continue seam.
 *
 * Covers the Testing Decisions from the resume-mid-failure spec:
 *   (1) Continue after error keeps goal + content_work + Plan
 *   (2) Composer "continue" does not replace the active goal
 *   (3) Forced from_memory apply failure does not finalize as success
 *   (4) A new user message after failure starts a fresh job
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const {
	startRun,
	waitForSession,
	postMessage,
	postBrowserResult,
	continueSession,
	getDiagnostics,
	getSession,
	mockUseTools,
	mockAiError,
	mockReply,
	toolEntriesFor,
	DEFAULT_PAGE_CONTEXT,
} = require( '../utils/session-client' )
const {
	resetAiResponses, seedAiResponses, seed,
} = require( '../utils/ability-client' )

const ARTICLE_GOAL = 'write a 1000 word article based on my previous posts'

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

/**
 * Drive a content-work run into fail_run via a mocked transport error after one tool.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils fixture.
 * @return {Promise<{ sessionId: number, failed: Object }>} Session id and failed-idle payload.
 */
async function runUntilResumableFailure( requestUtils ) {
	const { sessionId } = await startRun( requestUtils, {
		aiReplies: [
			mockUseTools(
				'Looking at recent posts…',
				[ { name: 'ahentic/get-site-snapshot', input: {} } ],
				{ plan: ARTICLE_PLAN }
			),
			mockAiError(),
		],
		content: ARTICLE_GOAL,
	} )

	const failed = await waitForSession(
		requestUtils,
		sessionId,
		s => s.status === 'idle' && Boolean( s.jobResumable )
	)

	return { sessionId, failed }
}

test.describe( 'Job resume mid-failure (Session REST)', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await resetAiResponses( requestUtils )
	} )

	test( 'Continue after mid-job error keeps goal, content_work, and Plan', async ( {
		requestUtils,
	} ) => {
		const { sessionId, failed } = await runUntilResumableFailure( requestUtils )

		expect( failed.jobResumable ).toBe( true )
		expect( failed.contentWork ).toBe( true )
		expect( failed.plan?.steps?.length ).toBeGreaterThanOrEqual( 2 )
		expect( failed.plan.steps.every( s => s.status === 'cancelled' ) ).toBe( false )

		const diag = await getDiagnostics( requestUtils, sessionId )
		expect( diag.state?.activeGoal ).toContain( '1000 word article' )
		expect( diag.state?.jobResumable ).toBe( true )

		await seedAiResponses( requestUtils, [
			mockReply( 'Resumed — finishing from the same job.' ),
		] )

		await continueSession( requestUtils, sessionId )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle' && ! s.jobResumable
		)

		expect( done.contentWork ).toBe( true )
		const after = await getDiagnostics( requestUtils, sessionId )
		expect( after.state?.activeGoal ).toContain( '1000 word article' )
		expect(
			( after.trace || [] ).some( e => e.type === 'run_resume' )
		).toBe( true )
	} )

	test( 'composer resume cue does not replace the active goal', async ( {
		requestUtils,
	} ) => {
		const { sessionId } = await runUntilResumableFailure( requestUtils )

		await seedAiResponses( requestUtils, [
			mockReply( 'Continuing the article draft.' ),
		] )

		await postMessage( requestUtils, sessionId, {
			content: 'continue',
			mode: 'agent',
			pageContext: DEFAULT_PAGE_CONTEXT,
		} )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle'
		)

		expect( done.contentWork ).toBe( true )
		const diag = await getDiagnostics( requestUtils, sessionId )
		expect( diag.state?.activeGoal ).toContain( '1000 word article' )
		expect( diag.state?.activeGoal ).not.toMatch( /^continue$/i )
		expect(
			( diag.trace || [] ).some( e => e.type === 'run_resume' )
		).toBe( true )
	} )

	test( 'forced from_memory apply failure during content work does not finalize as success', async ( {
		requestUtils,
	} ) => {
		const seeded = await seed( requestUtils, {
			posts: [
				{
					post_title: 'E2E resume draft',
					post_status: 'draft',
					post_type: 'page',
					post_content: '',
				},
			],
		} )
		const postId = seeded.created?.posts?.[ 0 ]
		expect( postId ).toBeTruthy()

		const editorContext = {
			...DEFAULT_PAGE_CONTEXT,
			url: `http://localhost:9400/wp-admin/post.php?post=${ postId }&action=edit`,
			pathname: '/wp-admin/post.php',
			search: `?post=${ postId }&action=edit`,
			is_block_editor: true,
			post_id: postId,
			postId,
		}

		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools(
					'Staging then placing the article…',
					[
						{
							name: 'ahentic/stage-artifact',
							input: {
								key: 'article_draft',
								kind: 'blocks',
								mode: 'replace',
								complete: true,
								payload: { blocks: '[Gutenberg block array]' },
							},
						},
						{
							name: 'ahentic-browser/update-post-document',
							input: {
								title: 'How to Reduce Commute Stress',
							},
						},
						{
							name: 'ahentic-browser/set-blocks',
							input: { from_memory: 'article_draft' },
						},
					],
					{ plan: ARTICLE_PLAN }
				),
				mockReply( 'The draft was missing — I will restage it next.' ),
			],
			content: ARTICLE_GOAL,
			pageContext: editorContext,
		} )

		const paused = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'awaiting_browser'
		)
		expect( paused.pendingTool?.name ).toBe( 'ahentic-browser/update-post-document' )
		expect( paused.contentWork ).toBe( true )

		await postBrowserResult( requestUtils, sessionId, {
			call_id: paused.pendingTool.call_id,
			result: {
				ok: true,
				title: 'How to Reduce Commute Stress',
				updated_fields: [ 'title' ],
				post_id: postId,
				post_type: 'page',
			},
		} )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle'
		)

		const setBlocks = toolEntriesFor( done, 'ahentic-browser/set-blocks' )
		expect( setBlocks.some( e => e.meta?.ok === false ) ).toBe( true )

		const diag = await getDiagnostics( requestUtils, sessionId )
		const types = ( diag.trace || [] ).map( e => e.type )
		expect( types ).toContain( 'forced_apply_retry' )

		const assistant = ( done.messages || [] ).filter( m => m.role === 'assistant' )
		expect(
			assistant.some( m => String( m.content || '' ).includes( 'restage' ) )
		).toBe( true )
	} )

	test( 'a new user message after failure starts a fresh job', async ( {
		requestUtils,
	} ) => {
		const { sessionId } = await runUntilResumableFailure( requestUtils )

		await seedAiResponses( requestUtils, [
			mockUseTools( 'Listing plugins…', [
				{ name: 'ahentic/list-plugins', input: {} },
			] ),
			mockReply( 'Here are your plugins.' ),
		] )

		await postMessage( requestUtils, sessionId, {
			content: 'List installed plugins instead',
			mode: 'agent',
			pageContext: DEFAULT_PAGE_CONTEXT,
		} )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle'
		)

		expect( done.jobResumable ).toBe( false )
		expect( done.contentWork ).toBe( false )

		const diag = await getDiagnostics( requestUtils, sessionId )
		expect( diag.state?.activeGoal ).toContain( 'List installed plugins' )
		expect( diag.state?.activeGoal ).not.toContain( '1000 word article' )

		const tools = toolEntriesFor( done, 'ahentic/list-plugins' )
		expect( tools.length ).toBeGreaterThanOrEqual( 1 )

		// Fresh run_start, not run_resume.
		const fresh = await getSession( requestUtils, sessionId )
		expect( fresh.jobResumable ).toBe( false )
		const resumeCount = ( diag.trace || [] ).filter( e => e.type === 'run_resume' ).length
		expect( resumeCount ).toBe( 0 )
	} )
} )
