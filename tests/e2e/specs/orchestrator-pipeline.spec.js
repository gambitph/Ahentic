/**
 * Characterization suite for the orchestrator tool pipeline.
 *
 * These specs lock behaviour a ToolRunner / HITL-policy / browser-resume
 * deepen must preserve:
 *   use_tools → (HITL?) → (browser?) → execute → think again → idle
 *
 * REST-direct only (no Chromium UI): real sessions, mocked LLM via the e2e
 * mu-plugin, production approvals / browser-results routes. Prefer this over
 * browser-driven specs for pipeline invariants (see docs/agents/testing.md).
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const {
	startRun,
	waitForSession,
	postApproval,
	postBrowserResult,
	postMessage,
	mockUseTools,
	mockReply,
	toolEntriesFor,
	sessionMessages,
	DEFAULT_PAGE_CONTEXT,
} = require( '../utils/session-client' )
const {
	resetAiResponses, runAbility, seedAiResponses,
} = require( '../utils/ability-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'Orchestrator tool pipeline (architecture characterization)', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await resetAiResponses( requestUtils )
	} )

	test( 'readonly use_tools runs without HITL and reaches idle with a tool entry', async ( {
		requestUtils,
	} ) => {
		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools( 'Checking the site…', [
					{ name: 'ahentic/get-site-snapshot', input: {} },
				] ),
				mockReply( 'Here is your site snapshot summary.' ),
			],
			content: 'What kind of site is this?',
		} )

		const session = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle'
		)

		expect( session.pendingTool ).toBeFalsy()
		const tools = toolEntriesFor( session, 'ahentic/get-site-snapshot' )
		expect( tools.length ).toBeGreaterThanOrEqual( 1 )
		expect( tools[ 0 ].meta?.ok ).not.toBe( false )

		const assistant = sessionMessages( session ).filter( e => e.role === 'assistant' )
		expect( assistant.some( e => String( e.content || '' ).includes( 'site snapshot' ) ) ).toBe( true )
	} )

	test( 'HITL write pauses as awaiting_human; allow_once executes then idles', async ( {
		requestUtils,
	} ) => {
		const title = `E2E HITL draft ${ Date.now() }`

		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
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
									id: '1', content: 'Create the draft post', status: 'in_progress',
								},
								{
									id: '2', content: 'Confirm creation', status: 'pending',
								},
								{
									id: '3', content: 'Confirm', status: 'pending',
								},
							],
						},
					}
				),
				mockReply( 'Draft created successfully.' ),
			],
			content: `Create a draft titled ${ title }`,
		} )

		const paused = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'awaiting_human'
		)

		expect( paused.pendingTool?.name ).toBe( 'ahentic/create-post' )
		expect( paused.pendingTool?.call_id ).toBeTruthy()
		expect( String( paused.pendingTool?.summary || '' ).length ).toBeGreaterThan( 0 )
		// Server writes must not claim browser runtime on the pending payload.
		expect( paused.pendingTool?.runtime ).not.toBe( 'browser' )

		await postApproval( requestUtils, sessionId, 'allow_once' )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle'
		)

		expect( done.pendingTool ).toBeFalsy()
		const tools = toolEntriesFor( done, 'ahentic/create-post' )
		expect( tools.length ).toBeGreaterThanOrEqual( 1 )
		expect( tools[ 0 ].meta?.ok ).not.toBe( false )

		const listed = await requestUtils.rest( {
			path: '/wp/v2/posts',
			params: { search: title, status: 'draft' },
		} )
		expect( Array.isArray( listed ) && listed.length ).toBeGreaterThanOrEqual( 1 )
	} )

	test( 'HITL deny skips the write and still reaches idle', async ( { requestUtils } ) => {
		const title = `E2E HITL skipped ${ Date.now() }`

		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools(
					'Creating a draft…',
					[ { name: 'ahentic/create-post', input: { title, post_type: 'post' } } ],
					{
						plan: {
							title: 'Create draft',
							steps: [
								{
									id: '1', content: 'Create the draft', status: 'in_progress',
								},
								{
									id: '2', content: 'Done', status: 'pending',
								},
								{
									id: '3', content: 'Confirm', status: 'pending',
								},
							],
						},
					}
				),
				mockReply( 'Okay, I skipped creating that draft.' ),
			],
			content: `Create a draft titled ${ title }`,
		} )

		await waitForSession( requestUtils, sessionId, s => s.status === 'awaiting_human' )
		await postApproval( requestUtils, sessionId, 'deny' )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle'
		)

		expect( done.pendingTool ).toBeFalsy()
		const okCreates = toolEntriesFor( done, 'ahentic/create-post' ).filter(
			e => e.meta?.ok === true
		)
		expect( okCreates ).toHaveLength( 0 )

		const listed = await requestUtils.rest( {
			path: '/wp/v2/posts',
			params: { search: title, status: 'draft' },
		} )
		expect( Array.isArray( listed ) ? listed.length : 0 ).toBe( 0 )
	} )

	test( 'allow_session preallows the same ability on a later turn (no second HITL pause)', async ( {
		requestUtils,
	} ) => {
		const title1 = `E2E session-allow A ${ Date.now() }`
		const title2 = `E2E session-allow B ${ Date.now() }`

		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools(
					'Creating first draft…',
					[ { name: 'ahentic/create-post', input: { title: title1, post_type: 'post' } } ],
					{
						plan: {
							title: 'Create drafts',
							steps: [
								{
									id: '1', content: 'Create first draft', status: 'in_progress',
								},
								{
									id: '2', content: 'Create second draft', status: 'pending',
								},
								{
									id: '3', content: 'Confirm', status: 'pending',
								},
							],
						},
					}
				),
				mockReply( 'First draft is ready.' ),
			],
			content: `Create a draft titled ${ title1 }`,
		} )

		await waitForSession( requestUtils, sessionId, s => s.status === 'awaiting_human' )
		await postApproval( requestUtils, sessionId, 'allow_session' )
		await waitForSession( requestUtils, sessionId, s => s.status === 'idle' )

		await seedAiResponses( requestUtils, [
			mockUseTools(
				'Creating second draft…',
				[ { name: 'ahentic/create-post', input: { title: title2, post_type: 'post' } } ],
				{
					plan: {
						title: 'Create second draft',
						steps: [
							{
								id: '1', content: 'Create the draft', status: 'in_progress',
							},
							{
								id: '2', content: 'Confirm', status: 'pending',
							},
							{
								id: '3', content: 'Confirm', status: 'pending',
							},
						],
					},
				}
			),
			mockReply( 'Second draft is ready.' ),
		] )

		await postMessage( requestUtils, sessionId, {
			content: `Create another draft titled ${ title2 }`,
			mode: 'agent',
			pageContext: DEFAULT_PAGE_CONTEXT,
		} )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle'
		)

		// Must not get stuck waiting for a second Allow after allow_session.
		expect( done.status ).toBe( 'idle' )
		expect( done.pendingTool ).toBeFalsy()

		const creates = toolEntriesFor( done, 'ahentic/create-post' ).filter(
			e => e.meta?.ok !== false
		)
		expect( creates.length ).toBeGreaterThanOrEqual( 2 )
	} )

	test( 'browser ability pauses as awaiting_browser; browser-results resumes the run', async ( {
		requestUtils,
	} ) => {
		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools( 'Reading the current admin page…', [
					{ name: 'ahentic-browser/get-current-page', input: {} },
				] ),
				mockReply( 'You are on the WordPress dashboard.' ),
			],
			content: 'What screen am I on?',
			pageContext: {
				...DEFAULT_PAGE_CONTEXT,
				url: 'http://localhost:9400/wp-admin/index.php',
				pathname: '/wp-admin/index.php',
			},
		} )

		const paused = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'awaiting_browser'
		)

		expect( paused.pendingTool?.name ).toBe( 'ahentic-browser/get-current-page' )
		expect( paused.pendingTool?.runtime ).toBe( 'browser' )
		expect( paused.pendingTool?.call_id ).toBeTruthy()

		await postBrowserResult( requestUtils, sessionId, {
			call_id: paused.pendingTool.call_id,
			result: {
				url: 'http://localhost:9400/wp-admin/index.php',
				pathname: '/wp-admin/index.php',
				title: 'Dashboard',
				isAdmin: true,
				is_block_editor: false,
			},
		} )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle'
		)

		expect( done.pendingTool ).toBeFalsy()
		const tools = toolEntriesFor( done, 'ahentic-browser/get-current-page' )
		expect( tools.length ).toBeGreaterThanOrEqual( 1 )
		expect( tools[ 0 ].meta?.browser ).toBe( true )
		expect( tools[ 0 ].meta?.ok ).not.toBe( false )
	} )

	test( 'Ask mode blocks a write tool without entering awaiting_human', async ( {
		requestUtils,
	} ) => {
		const title = `E2E ask-blocked ${ Date.now() }`

		const { sessionId } = await startRun( requestUtils, {
			mode: 'ask',
			aiReplies: [
				mockUseTools( 'Trying to create a draft…', [
					{ name: 'ahentic/create-post', input: { title, post_type: 'post' } },
				] ),
				mockReply( 'Ask mode is read-only — switch to Agent to create posts.' ),
			],
			content: `Create a draft titled ${ title }`,
		} )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle' || s.status === 'awaiting_human'
		)

		expect( done.status ).toBe( 'idle' )
		expect( done.pendingTool ).toBeFalsy()

		const toolEntries = toolEntriesFor( done, 'ahentic/create-post' )
		expect( toolEntries.length ).toBeGreaterThanOrEqual( 1 )
		expect( toolEntries[ 0 ].meta?.ok ).toBe( false )

		const listed = await requestUtils.rest( {
			path: '/wp/v2/posts',
			params: { search: title, status: 'draft' },
		} )
		expect( Array.isArray( listed ) ? listed.length : 0 ).toBe( 0 )
	} )

	test( 'approvals while not awaiting_human return a stable conflict', async ( {
		requestUtils,
	} ) => {
		const session = await requestUtils.rest( {
			path: '/ahentic/v1/sessions',
			method: 'POST',
			data: { mode: 'agent' },
		} )

		let status = 0
		let code = ''
		try {
			await requestUtils.rest( {
				path: `/ahentic/v1/sessions/${ session.id }/approvals`,
				method: 'POST',
				data: { decision: 'allow_once' },
			} )
		} catch ( err ) {
			status = err.status || err.data?.status || 0
			code = err.code || err.data?.code || ''
			if ( ! status && typeof err.message === 'string' && /\b4\d\d\b/.test( err.message ) ) {
				status = Number( ( err.message.match( /\b(4\d\d)\b/ ) || [] )[ 1 ] || 0 )
			}
		}

		expect( [ 400, 409 ] ).toContain( status )
		expect( String( code ) ).toMatch( /ahentic_no_pending|ahentic_not_awaiting/ )
	} )
} )

test.describe( 'Ability execute seam (dispatch invariants)', () => {
	test( 'create-post via run-ability creates a draft (execute path ToolRunner will call)', async ( {
		requestUtils,
	} ) => {
		const title = `E2E direct create ${ Date.now() }`

		const result = await runAbility( requestUtils, 'ahentic/create-post', {
			title,
			post_type: 'post',
			status: 'draft',
		} )

		expect( result.ok ).toBe( true )
		expect( result.data?.id || result.data?.post_id ).toBeTruthy()
	} )

	test( 'list-plugins readonly execute returns a plugin list', async ( { requestUtils } ) => {
		const result = await runAbility( requestUtils, 'ahentic/list-plugins', {} )

		expect( result.ok ).toBe( true )
		expect( Array.isArray( result.data?.plugins ) || Array.isArray( result.data ) ).toBe( true )
	} )
} )
