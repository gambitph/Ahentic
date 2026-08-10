/**
 * Task 01 — non-preallowable HITL + settings snapshot / undo-last-actions.
 *
 * REST-direct coverage for Track A plumbing. Browser HITL card clickability
 * stays in the describe below (Allow once on create-post).
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )
const {
	waitForSession,
	mockUseTools,
	startRun,
	postApproval,
} = require( '../utils/session-client' )
const { runAbility, resetAiResponses } = require( '../utils/ability-client' )
const { createSession } = require( '../utils/session-client' )

const STUB = 'ahentic-e2e/stub-settings-write'

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'Task 01: settings snapshot + undo (REST)', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await resetAiResponses( requestUtils )
	} )

	test( 'stub write snapshots prior absence; undo deletes the option', async ( {
		requestUtils,
	} ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id

		const first = await runAbility(
			requestUtils,
			STUB,
			{ value: 'alpha' },
			{ sessionId }
		)
		expect( first.ok ).toBe( true )
		expect( first.data.prior_existed ).toBe( false )

		const undo = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( undo.ok ).toBe( true )
		expect( undo.data.undone ).toBe( 1 )

		const again = await runAbility(
			requestUtils,
			STUB,
			{ value: 'beta' },
			{ sessionId }
		)
		expect( again.ok ).toBe( true )
		// Undo deleted the option, so the next write again sees "did not exist".
		expect( again.data.prior_existed ).toBe( false )
	} )

	test( 'undo restores a prior value; empty undo is a no-op', async ( {
		requestUtils,
	} ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id

		await runAbility( requestUtils, STUB, { value: 'one' }, { sessionId } )
		await runAbility( requestUtils, STUB, { value: 'two' }, { sessionId } )

		const undo1 = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{},
			{ sessionId }
		)
		expect( undo1.ok ).toBe( true )
		expect( undo1.data.undone ).toBe( 1 )

		const third = await runAbility(
			requestUtils,
			STUB,
			{ value: 'three' },
			{ sessionId }
		)
		expect( third.ok ).toBe( true )
		expect( third.data.prior_existed ).toBe( true )

		// Drain remaining snapshots.
		await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 10 },
			{ sessionId }
		)

		const noop = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( noop.ok ).toBe( true )
		expect( noop.data.undone ).toBe( 0 )
		expect( String( noop.data.message || '' ) ).toMatch( /nothing to undo/i )
	} )

	test( 'allow_session / always_allow rejected for non-preallowable stub', async ( {
		requestUtils,
	} ) => {
		const { sessionId } = await startRun( requestUtils, {
			aiReplies: [
				mockUseTools(
					'Updating stub…',
					[ { name: STUB, input: { value: `hitl-${ Date.now() }` } } ],
					{
						plan: {
							title: 'Stub settings write',
							steps: [
								{
									id: '1', content: 'Write the stub option', status: 'in_progress',
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
				mockReply( 'Stub updated.' ),
			],
			content: 'Set the e2e stub setting',
		} )

		const paused = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'awaiting_human' && s.pendingTool?.name === STUB
		)

		expect( paused.pendingTool.non_preallowable ).toBe( true )

		let sessionStatus = 0
		let sessionCode = ''
		try {
			await postApproval( requestUtils, sessionId, 'allow_session' )
		} catch ( err ) {
			sessionStatus = err.status || err.data?.status || 0
			sessionCode = err.code || err.data?.code || ''
			if ( ! sessionStatus && typeof err.message === 'string' && /\b4\d\d\b/.test( err.message ) ) {
				sessionStatus = Number( ( err.message.match( /\b(4\d\d)\b/ ) || [] )[ 1 ] || 0 )
			}
		}
		expect( sessionStatus ).toBe( 400 )
		expect( String( sessionCode ) ).toMatch( /ahentic_hitl_not_preallowable/ )

		let alwaysStatus = 0
		let alwaysCode = ''
		try {
			await postApproval( requestUtils, sessionId, 'always_allow' )
		} catch ( err ) {
			alwaysStatus = err.status || err.data?.status || 0
			alwaysCode = err.code || err.data?.code || ''
			if ( ! alwaysStatus && typeof err.message === 'string' && /\b4\d\d\b/.test( err.message ) ) {
				alwaysStatus = Number( ( err.message.match( /\b(4\d\d)\b/ ) || [] )[ 1 ] || 0 )
			}
		}
		expect( alwaysStatus ).toBe( 400 )
		expect( String( alwaysCode ) ).toMatch( /ahentic_hitl_not_preallowable/ )

		// Still awaiting — allow once completes the run.
		const still = await waitForSession(
			requestUtils,
			sessionId,
			s => s.status === 'awaiting_human' && s.pendingTool?.name === STUB
		)
		expect( still.pendingTool.non_preallowable ).toBe( true )

		await postApproval( requestUtils, sessionId, 'allow_once' )
		await waitForSession( requestUtils, sessionId, s => s.status === 'idle' )
	} )
} )

test.describe( 'Sidebar HITL approval card', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'Allow once on the HITL card completes a create-post run', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		const title = `E2E UI HITL ${ Date.now() }`

		await ahenticSidebar.seedAiResponses( [
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
								id: '2', content: 'Review the draft', status: 'pending',
							},
							{
								id: '3', content: 'Confirm', status: 'pending',
							},
						],
					},
				}
			),
			mockReply( 'Draft created from the sidebar approval.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( `Create a draft titled ${ title }` )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'awaiting_human' && s.pendingTool?.name === 'ahentic/create-post'
		)

		await expect( ahenticSidebar.hitlCard ).toBeVisible( { timeout: 15_000 } )
		await expect( ahenticSidebar.hitlCard ).toContainText( 'ahentic/create-post' )

		await ahenticSidebar.decideHitl( 'allow_once' )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

		await expect( ahenticSidebar.hitlCard ).toBeHidden( { timeout: 15_000 } )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Draft created from the sidebar approval.', {
			timeout: 15_000,
		} )

		const listed = await requestUtils.rest( {
			path: '/wp/v2/posts',
			params: { search: title, status: 'draft' },
		} )
		expect( Array.isArray( listed ) && listed.length ).toBeGreaterThanOrEqual( 1 )
	} )

	test( 'Skip on the HITL card cancels the write and returns to idle', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		const title = `E2E UI HITL skip ${ Date.now() }`

		await ahenticSidebar.seedAiResponses( [
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
								id: '2', content: 'Review the draft', status: 'pending',
							},
							{
								id: '3', content: 'Confirm', status: 'pending',
							},
						],
					},
				}
			),
			mockReply( 'Skipped the draft as requested.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( `Create a draft titled ${ title }` )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'awaiting_human' && s.pendingTool?.name === 'ahentic/create-post'
		)

		await expect( ahenticSidebar.hitlCard ).toBeVisible( { timeout: 15_000 } )
		await expect( ahenticSidebar.hitlCard.getByRole( 'button', { name: /Allow for this chat/i } ) ).toBeVisible()
		// Re-seed the post-deny reply: reused Playground instances can leave a stale
		// ahentic_e2e_ai_queue entry that races the original second mock.
		await ahenticSidebar.seedAiResponses( [
			mockReply( 'Skipped the draft as requested.' ),
		] )
		await ahenticSidebar.decideHitl( 'deny' )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'idle' && ( s.messages || [] ).some(
				m => m.role === 'assistant' && String( m.content || '' ).includes( 'Skipped the draft' )
			)
		)
		await expect( ahenticSidebar.hitlCard ).toBeHidden( { timeout: 15_000 } )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Skipped the draft', {
			timeout: 15_000,
		} )

		const listed = await requestUtils.rest( {
			path: '/wp/v2/posts',
			params: { search: title, status: 'draft' },
		} )
		expect( Array.isArray( listed ) ? listed.length : 0 ).toBe( 0 )
	} )

	test( 'Allow for this chat preallows a later create-post without a second card', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		const title1 = `E2E UI HITL session A ${ Date.now() }`
		const title2 = `E2E UI HITL session B ${ Date.now() }`

		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Creating first draft…',
				[ { name: 'ahentic/create-post', input: { title: title1, post_type: 'post' } } ],
				{
					plan: {
						title: 'Create drafts',
						steps: [
							{
								id: '1', content: 'Create first', status: 'in_progress',
							},
							{
								id: '2', content: 'Create second', status: 'pending',
							},
							{
								id: '3', content: 'Confirm', status: 'pending',
							},
						],
					},
				}
			),
			mockReply( 'First draft allowed for this chat.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( `Create ${ title1 }` )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'awaiting_human' && s.pendingTool?.name === 'ahentic/create-post'
		)
		await expect( ahenticSidebar.hitlCard ).toBeVisible( { timeout: 15_000 } )
		await ahenticSidebar.decideHitl( 'allow_session' )
		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'First draft allowed', {
			timeout: 15_000,
		} )

		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Creating second draft…',
				[ { name: 'ahentic/create-post', input: { title: title2, post_type: 'post' } } ],
				{
					plan: {
						title: 'Create drafts',
						steps: [
							{
								id: '1', content: 'Create first', status: 'completed',
							},
							{
								id: '2', content: 'Create second', status: 'in_progress',
							},
							{
								id: '3', content: 'Confirm', status: 'pending',
							},
						],
					},
				}
			),
			mockReply( 'Second draft needed no approval.' ),
		] )
		await ahenticSidebar.sendMessage( `Create ${ title2 }` )

		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.hitlCard ).toHaveCount( 0 )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Second draft needed no approval.', {
			timeout: 15_000,
		} )
	} )

	test( 'non-preallowable HITL hides Allow for this chat', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		const suffix = Date.now()
		const username = `ahentic_hitl_${ suffix }`
		const email = `ahentic-hitl-${ suffix }@example.com`

		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Creating a subscriber…',
				[ {
					name: 'ahentic/create-user',
					input: {
						username, email, role: 'subscriber', display_name: `HITL ${ suffix }`,
					},
				} ],
				{
					plan: {
						title: 'Create user',
						steps: [
							{
								id: '1', content: 'Create the user', status: 'in_progress',
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
			mockReply( 'User created after Allow once.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		await ahenticSidebar.sendMessage( `Create subscriber ${ username }` )

		await waitForSession(
			requestUtils,
			session.id,
			s => s.status === 'awaiting_human' && s.pendingTool?.name === 'ahentic/create-user'
		)

		await expect( ahenticSidebar.hitlCard ).toBeVisible( { timeout: 15_000 } )
		await expect( ahenticSidebar.hitlCard ).toContainText( 'ahentic/create-user' )
		await expect( ahenticSidebar.hitlCard.getByRole( 'button', { name: /Allow once/i } ) ).toBeVisible()
		await expect( ahenticSidebar.hitlCard.getByRole( 'button', { name: /Allow for this chat/i } ) ).toHaveCount( 0 )
		await expect( ahenticSidebar.hitlCard.getByRole( 'button', { name: /Skip/i } ) ).toBeVisible()

		await ahenticSidebar.decideHitl( 'allow_once' )
		await waitForSession( requestUtils, session.id, s => s.status === 'idle' )
		await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'User created after Allow once.', {
			timeout: 15_000,
		} )
	} )
} )
