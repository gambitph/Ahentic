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

	test( 'fill-fields ordinary option skips HITL and pauses as awaiting_browser', async ( {
		requestUtils,
	} ) => {
		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools(
					'Updating the site title on this form…',
					[
						{
							name: 'ahentic-browser/fill-fields',
							input: {
								fields: [ { name: 'blogname', value: 'Acme E2E' } ],
							},
						},
					],
					{
						plan: {
							title: 'Update site title on form',
							steps: [
								{
									id: '1', content: 'Inspect the open settings form', status: 'completed',
								},
								{
									id: '2', content: 'Fill the Site Title field', status: 'in_progress',
								},
								{
									id: '3', content: 'Leave Save for the user', status: 'pending',
								},
							],
						},
					}
				),
				mockReply( 'Filled the Site Title field — click Save Changes when ready.' ),
			],
			content: 'Change the site title to Acme E2E on this screen',
			pageContext: {
				...DEFAULT_PAGE_CONTEXT,
				url: 'http://localhost:9400/wp-admin/options-general.php',
				pathname: '/wp-admin/options-general.php',
				title: 'General Settings',
			},
		} )

		const paused = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'awaiting_browser' || s.status === 'awaiting_human'
		)

		expect( paused.status ).toBe( 'awaiting_browser' )
		expect( paused.pendingTool?.name ).toBe( 'ahentic-browser/fill-fields' )
		expect( paused.pendingTool?.runtime ).toBe( 'browser' )

		await postBrowserResult( requestUtils, sessionId, {
			call_id: paused.pendingTool.call_id,
			result: {
				ok: true,
				filled: [ { name: 'blogname', value: 'Acme E2E' } ],
				skipped: [],
				notes: [ 'Does not submit the form — user must click Save/Update.' ],
			},
		} )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle'
		)
		expect( done.pendingTool ).toBeFalsy()
		const tools = toolEntriesFor( done, 'ahentic-browser/fill-fields' )
		expect( tools.length ).toBeGreaterThanOrEqual( 1 )
		expect( tools[ 0 ].meta?.ok ).not.toBe( false )
	} )

	test( 'fill-fields password target pauses as awaiting_human first', async ( {
		requestUtils,
	} ) => {
		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools(
					'Setting a password field…',
					[
						{
							name: 'ahentic-browser/fill-fields',
							input: {
								fields: [ { name: 'pass1', value: 'hunter2' } ],
							},
						},
					],
					{
						plan: {
							title: 'Fill password field',
							steps: [
								{
									id: '1', content: 'Request approval', status: 'in_progress',
								},
								{
									id: '2', content: 'Fill the password field', status: 'pending',
								},
								{
									id: '3', content: 'Leave Save for the user', status: 'pending',
								},
							],
						},
					}
				),
				mockReply( 'Password field filled after your approval.' ),
			],
			content: 'Fill the new password field',
			pageContext: {
				...DEFAULT_PAGE_CONTEXT,
				url: 'http://localhost:9400/wp-admin/profile.php',
				pathname: '/wp-admin/profile.php',
			},
		} )

		const paused = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'awaiting_human'
		)

		expect( paused.pendingTool?.name ).toBe( 'ahentic-browser/fill-fields' )
		expect( paused.pendingTool?.non_preallowable ).toBe( true )

		await postApproval( requestUtils, sessionId, 'allow_once' )

		const browserPaused = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'awaiting_browser' || s.status === 'idle'
		)

		if ( browserPaused.status === 'awaiting_browser' ) {
			await postBrowserResult( requestUtils, sessionId, {
				call_id: browserPaused.pendingTool.call_id,
				result: {
					ok: true,
					filled: [ { name: 'pass1', value: '••••••' } ],
					skipped: [],
				},
			} )
			await waitForSession( requestUtils, sessionId, s => s.status === 'idle' )
		}
	} )

	test( 'fill-fields hard-denied option fails without HITL or browser pause', async ( {
		requestUtils,
	} ) => {
		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools(
					'Trying to change the site URL…',
					[
						{
							name: 'ahentic-browser/fill-fields',
							input: {
								fields: [ { name: 'siteurl', value: 'https://evil.example' } ],
							},
						},
					],
					{
						plan: {
							title: 'Change site URL',
							steps: [
								{
									id: '1', content: 'Attempt fill', status: 'in_progress',
								},
								{
									id: '2', content: 'Handle refusal', status: 'pending',
								},
								{
									id: '3', content: 'Explain to user', status: 'pending',
								},
							],
						},
					}
				),
				mockReply( 'That option is hard-denied and cannot be filled.' ),
			],
			content: 'Change the WordPress Address (URL)',
			pageContext: {
				...DEFAULT_PAGE_CONTEXT,
				url: 'http://localhost:9400/wp-admin/options-general.php',
				pathname: '/wp-admin/options-general.php',
			},
		} )

		const done = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'idle' || s.status === 'awaiting_human' || s.status === 'awaiting_browser'
		)

		expect( done.status ).toBe( 'idle' )
		expect( done.pendingTool ).toBeFalsy()
		const tools = toolEntriesFor( done, 'ahentic-browser/fill-fields' )
		expect( tools.length ).toBeGreaterThanOrEqual( 1 )
		expect( tools[ 0 ].meta?.ok ).toBe( false )
		const body = String( tools[ 0 ].content || '' )
		expect( body ).toMatch( /ahentic_option_denied|siteurl|hard-denied/i )
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
