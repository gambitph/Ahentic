/**
 * @jest-environment node
 */

import {
	createEmptySessionRecord,
	getSessionRecord,
	patchSessionRecord,
	omitSessionRecord,
	remapSessionRecord,
	extractSessionMeta,
	isActiveRunStatus,
	isSessionPayloadStale,
	mergeServerMessagesWithPendingLocal,
	hasPendingLocalTurns,
	pendingLocalsConfirmedOnServer,
	mergeServerSessionIntoRecord,
	cancelIncompletePlanSteps,
	sessionFingerprint,
	sessionHasAssistantError,
} from './session-state'

describe( 'session-state record map', () => {
	it( 'createEmptySessionRecord returns idle defaults', () => {
		expect( createEmptySessionRecord() ).toEqual( {
			messages: [],
			status: 'idle',
			progress: null,
			pendingTool: null,
			plan: null,
			thought: null,
			trace: [],
			approving: '',
			pollWatch: false,
			tokensIn: 0,
			tokensOut: 0,
			tokensUsed: 0,
			contextUsage: null,
			jobResumable: false,
			lastErrorCode: '',
		} )
	} )

	it( 'getSessionRecord returns empty defaults for unknown ids without mutating', () => {
		const sessions = {}
		expect( getSessionRecord( sessions, '9' ).status ).toBe( 'idle' )
		expect( sessions ).toEqual( {} )
	} )

	it( 'patchSessionRecord merges a patch object for one id', () => {
		const next = patchSessionRecord( {}, '7', { status: 'running', pollWatch: true } )
		expect( next[ '7' ].status ).toBe( 'running' )
		expect( next[ '7' ].pollWatch ).toBe( true )
		expect( next[ '7' ].messages ).toEqual( [] )
	} )

	it( 'patchSessionRecord accepts a function updater', () => {
		const start = patchSessionRecord( {}, '7', { status: 'running' } )
		const next = patchSessionRecord( start, '7', record => ( {
			...record,
			status: 'idle',
			pollWatch: false,
		} ) )
		expect( next[ '7' ].status ).toBe( 'idle' )
		expect( next[ '7' ].pollWatch ).toBe( false )
	} )

	it( 'omitSessionRecord removes one id', () => {
		const start = patchSessionRecord( {}, '7', { status: 'running' } )
		expect( omitSessionRecord( start, '7' ) ).toEqual( {} )
	} )

	it( 'remapSessionRecord moves a record to a new id', () => {
		const start = patchSessionRecord( {}, 'tab_1', {
			messages: [ {
				id: 'm1', role: 'user', content: 'hi',
			} ],
			status: 'idle',
		} )
		const next = remapSessionRecord( start, 'tab_1', '42' )
		expect( next.tab_1 ).toBeUndefined()
		expect( next[ '42' ].messages ).toEqual( [
			{
				id: 'm1', role: 'user', content: 'hi',
			},
		] )
	} )

	it( 'remapSessionRecord can seed when from id is missing', () => {
		const next = remapSessionRecord( {}, 'tab_1', '42', {
			messages: [ {
				id: 's', role: 'assistant', content: 'ok',
			} ],
			status: 'idle',
		} )
		expect( next[ '42' ].messages[ 0 ].content ).toBe( 'ok' )
	} )
} )

describe( 'session-state freshness', () => {
	it( 'isActiveRunStatus covers running and pause states', () => {
		expect( isActiveRunStatus( 'running' ) ).toBe( true )
		expect( isActiveRunStatus( 'awaiting_human' ) ).toBe( true )
		expect( isActiveRunStatus( 'awaiting_browser' ) ).toBe( true )
		expect( isActiveRunStatus( 'idle' ) ).toBe( false )
	} )

	it( 'isSessionPayloadStale rejects older lastSeq', () => {
		const known = extractSessionMeta( {
			messages: [ {
				seq: 5, role: 'user', content: 'a',
			} ],
			status: 'running',
			stepCount: 1,
		} )
		const incoming = {
			messages: [ {
				seq: 4, role: 'user', content: 'a',
			} ],
			status: 'idle',
			stepCount: 0,
		}
		expect( isSessionPayloadStale( incoming, known ) ).toBe( true )
	} )

	it( 'isSessionPayloadStale rejects idle poll while known status is active', () => {
		const known = {
			...extractSessionMeta( {
				messages: [ {
					seq: 2, role: 'user', content: 'hi',
				} ],
				status: 'running',
				stepCount: 0,
			} ),
			status: 'running',
		}
		const incoming = {
			messages: [ {
				seq: 2, role: 'user', content: 'hi',
			} ],
			status: 'idle',
			stepCount: 0,
			trace: [],
		}
		expect( isSessionPayloadStale( incoming, known ) ).toBe( true )
	} )

	// Regression: second prompt floors meta with prior stepCount; new run resets
	// steps but grows the shared trace — that poll must apply (awaiting_browser).
	it( 'isSessionPayloadStale allows stepCount reset when trace advanced', () => {
		const known = {
			messageCount: 17,
			lastSeq: 17,
			stepCount: 6,
			traceLen: 128,
			modifiedAt: 1,
			progressAt: Date.now(),
			planAt: 1,
			status: 'running',
		}
		const incoming = {
			messages: Array.from( { length: 17 }, ( _, i ) => ( {
				seq: i + 1, role: 'user', content: 'x',
			} ) ),
			status: 'awaiting_browser',
			stepCount: 1,
			traceCount: 142,
			progress: {
				label: 'Waiting for this page to run: Updating block attributes…',
				updatedAt: '2026-08-07T10:50:15+00:00',
			},
		}
		expect( isSessionPayloadStale( incoming, known ) ).toBe( false )
	} )
} )

describe( 'session-state optimistic merge', () => {
	it( 'keeps trailing local pending user bubbles until the server mirrors them', () => {
		const merged = mergeServerMessagesWithPendingLocal(
			[ {
				id: '1', role: 'assistant', content: 'hello',
			} ],
			[
				{
					id: '1', role: 'assistant', content: 'hello',
				},
				{
					id: 'local_u_1', role: 'user', content: 'go',
				},
			],
			{ local_u_1: 'go' }
		)
		expect( merged ).toEqual( [
			{
				id: '1', role: 'assistant', content: 'hello',
			},
			{
				id: 'local_u_1', role: 'user', content: 'go',
			},
		] )
	} )

	it( 'drops locals already present as newest server user turns', () => {
		const merged = mergeServerMessagesWithPendingLocal(
			[
				{
					id: '1', role: 'assistant', content: 'hello',
				},
				{
					id: '2', role: 'user', content: 'go',
				},
			],
			[
				{
					id: '1', role: 'assistant', content: 'hello',
				},
				{
					id: 'local_u_1', role: 'user', content: 'go',
				},
			],
			{ local_u_1: 'go' }
		)
		expect( merged ).toEqual( [
			{
				id: '1', role: 'assistant', content: 'hello',
			},
			{
				id: '2', role: 'user', content: 'go',
			},
		] )
	} )

	it( 'hasPendingLocalTurns and pendingLocalsConfirmedOnServer agree on confirmation', () => {
		const pending = { a: { local_u_1: 'go' } }
		expect( hasPendingLocalTurns( pending, 'a' ) ).toBe( true )
		expect(
			pendingLocalsConfirmedOnServer(
				[ { role: 'user', content: 'go' } ],
				pending.a
			)
		).toBe( true )
		expect(
			pendingLocalsConfirmedOnServer(
				[ { role: 'assistant', content: 'nope' } ],
				pending.a
			)
		).toBe( false )
	} )
} )

describe( 'mergeServerSessionIntoRecord', () => {
	const mapEntries = entries => entries.map( entry => ( {
		id: String( entry.id || entry.seq || '' ),
		role: entry.role,
		content: entry.content,
	} ) )

	it( 'applies status, messages, plan, and clears pollWatch when idle', () => {
		const current = {
			...createEmptySessionRecord(),
			status: 'running',
			pollWatch: true,
			progress: {
				label: 'Working…', updatedAt: '', heartbeatAt: '', seenAt: 1,
			},
		}
		const next = mergeServerSessionIntoRecord(
			{
				id: 9,
				status: 'idle',
				messages: [ {
					id: '1', role: 'user', content: 'hi',
				} ],
				trace: [ { type: 'run_start' } ],
				plan: { steps: [ { id: 's1', status: 'completed' } ] },
				progress: { label: '', updatedAt: '' },
				pendingTool: null,
				thoughtProcess: { text: 'old' },
			},
			current,
			undefined,
			mapEntries
		)
		expect( next.status ).toBe( 'idle' )
		expect( next.pollWatch ).toBe( false )
		expect( next.messages[ 0 ].content ).toBe( 'hi' )
		expect( next.trace ).toEqual( [ { type: 'run_start' } ] )
		expect( next.plan.steps[ 0 ].id ).toBe( 's1' )
		expect( next.thought ).toBeNull()
		expect( next.progress ).toBeNull()
	} )

	it( 'keeps optimistic pending locals while merging messages', () => {
		const current = {
			...createEmptySessionRecord(),
			messages: [
				{
					id: 'local_u_1', role: 'user', content: 'go',
				},
			],
			status: 'running',
			pollWatch: true,
		}
		const next = mergeServerSessionIntoRecord(
			{
				id: 9,
				status: 'running',
				messages: [],
				trace: [],
			},
			current,
			{ local_u_1: 'go' },
			mapEntries
		)
		expect( next.messages ).toEqual( [
			{
				id: 'local_u_1', role: 'user', content: 'go',
			},
		] )
		expect( next.pollWatch ).toBe( true )
	} )
} )

describe( 'cancelIncompletePlanSteps', () => {
	it( 'marks non-terminal steps cancelled', () => {
		const plan = {
			steps: [
				{ id: '1', status: 'completed' },
				{ id: '2', status: 'pending' },
				{ id: '3', status: 'in_progress' },
			],
		}
		expect( cancelIncompletePlanSteps( plan ).steps.map( s => s.status ) ).toEqual( [
			'completed',
			'cancelled',
			'cancelled',
		] )
	} )

	it( 'returns the same plan reference when nothing changes', () => {
		const plan = { steps: [ { id: '1', status: 'completed' } ] }
		expect( cancelIncompletePlanSteps( plan ) ).toBe( plan )
	} )
} )

describe( 'sessionHasAssistantError', () => {
	it( 'detects the latest assistant error meta', () => {
		expect( sessionHasAssistantError( [
			{ role: 'user', content: 'go' },
			{
				role: 'assistant',
				content: 'fail',
				meta: { error: true },
			},
		] ) ).toBe( true )
		expect( sessionHasAssistantError( [
			{
				role: 'assistant',
				content: 'ok',
				meta: {},
			},
		] ) ).toBe( false )
	} )
} )

describe( 'mergeServerSessionIntoRecord plan settlement', () => {
	const mapEntries = entries => entries.map( entry => ( {
		id: entry.id,
		role: entry.role,
		content: entry.content || '',
		meta: entry.meta || {},
	} ) )

	it( 'cancels open plan steps when the latest assistant message is an error', () => {
		const next = mergeServerSessionIntoRecord(
			{
				id: 42,
				status: 'idle',
				messages: [
					{
						id: 'u1',
						role: 'user',
						content: 'do work',
					},
					{
						id: 'a1',
						role: 'assistant',
						content: 'timed out',
						meta: { error: true },
					},
				],
				plan: {
					title: 'Work',
					steps: [
						{
							id: '1',
							content: 'Done',
							status: 'completed',
						},
						{
							id: '2',
							content: 'Still going',
							status: 'in_progress',
						},
						{
							id: '3',
							content: 'Later',
							status: 'pending',
						},
					],
				},
				trace: [],
			},
			createEmptySessionRecord(),
			{},
			mapEntries
		)
		expect( next.status ).toBe( 'idle' )
		expect( next.plan.steps.map( s => s.status ) ).toEqual( [
			'completed',
			'cancelled',
			'cancelled',
		] )
	} )

	it( 'keeps open plan steps when the job is Continue-recoverable', () => {
		const next = mergeServerSessionIntoRecord(
			{
				id: 42,
				status: 'idle',
				jobResumable: true,
				messages: [
					{
						id: 'u1',
						role: 'user',
						content: 'write article',
					},
					{
						id: 'a1',
						role: 'assistant',
						content: 'timed out',
						meta: { error: true },
					},
				],
				plan: {
					title: 'Article',
					steps: [
						{
							id: '1',
							content: 'Research',
							status: 'completed',
						},
						{
							id: '2',
							content: 'Draft',
							status: 'in_progress',
						},
					],
				},
				trace: [],
			},
			createEmptySessionRecord(),
			{},
			mapEntries
		)
		expect( next.jobResumable ).toBe( true )
		expect( next.plan.steps.map( s => s.status ) ).toEqual( [
			'completed',
			'in_progress',
		] )
	} )

	it( 'keeps open plan steps while a resumed run is active despite a prior error assistant turn', () => {
		const next = mergeServerSessionIntoRecord(
			{
				id: 42,
				status: 'running',
				jobResumable: false,
				messages: [
					{
						id: 'u1',
						role: 'user',
						content: 'write article',
					},
					{
						id: 'a1',
						role: 'assistant',
						content: 'Sorry — I could not complete that request',
						meta: { error: true },
					},
				],
				plan: {
					title: 'Research and draft the jeepney article',
					steps: [
						{
							id: '1',
							content: 'Review recent articles',
							status: 'completed',
						},
						{
							id: '2',
							content: 'Draft and stage the article',
							status: 'in_progress',
						},
						{
							id: '3',
							content: 'Apply to the open page',
							status: 'pending',
						},
					],
				},
				trace: [],
			},
			createEmptySessionRecord(),
			{},
			mapEntries
		)
		expect( next.status ).toBe( 'running' )
		expect( next.jobResumable ).toBe( false )
		expect( next.plan.steps.map( s => s.status ) ).toEqual( [
			'completed',
			'in_progress',
			'pending',
		] )
	} )
} )

describe( 'sessionFingerprint', () => {
	it( 'is stable for the same payload shape', () => {
		const session = {
			modifiedAt: '2026-01-01T00:00:00Z',
			status: 'idle',
			stepCount: 2,
			messages: [ { id: '1', seq: 1 } ],
			progress: { label: 'x', updatedAt: 't' },
			plan: { updatedAt: 'p' },
			pendingTool: null,
		}
		expect( sessionFingerprint( session ) ).toBe( sessionFingerprint( { ...session } ) )
	} )

	// Regression: polls that only grow the debugger log (same progress label /
	// messages / stepCount) must still apply so live status can leave
	// "Planning next steps…" via resolveLiveStatusLabel(trace).
	it( 'changes when only traceCount grows during a running think', () => {
		const base = {
			modifiedAt: '2026-01-01T00:00:00Z',
			status: 'running',
			stepCount: 1,
			messages: [ { id: '1', seq: 1 } ],
			progress: { label: 'Planning next steps…', updatedAt: '2026-01-01T00:00:01Z' },
			plan: null,
			pendingTool: null,
			traceCount: 5,
		}
		expect( sessionFingerprint( base ) ).not.toBe(
			sessionFingerprint( { ...base, traceCount: 12 } )
		)
	} )
} )
