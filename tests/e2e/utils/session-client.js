/**
 * REST helpers for driving a real orchestrator run without the React sidebar.
 *
 * These hit production `ahentic/v1/sessions*` routes (message → poll →
 * approvals / browser-results / continue). Specs use them to characterize the
 * tool pipeline (HITL → browser → execute) that a future ToolRunner extract
 * must preserve — see tests/e2e/specs/orchestrator-pipeline.spec.js.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { seedAiResponses, resetAiResponses } = require( './ability-client' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )

const DEFAULT_PAGE_CONTEXT = {
	url: 'http://localhost:9400/wp-admin/',
	title: 'Dashboard',
	pathname: '/wp-admin/',
	search: '',
	isAdmin: true,
	is_block_editor: false,
}

/**
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {Object}                                                      [options]
 * @param {string}                                                      [options.mode]
 * @return {Promise<Object>} Created session REST payload.
 */
async function createSession( requestUtils, { mode = 'agent' } = {} ) {
	return requestUtils.rest( {
		path: '/ahentic/v1/sessions',
		method: 'POST',
		data: { mode },
	} )
}

/**
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {number|string}                                               sessionId
 * @return {Promise<Object>} Session REST payload.
 */
async function getSession( requestUtils, sessionId ) {
	return requestUtils.rest( {
		path: `/ahentic/v1/sessions/${ sessionId }`,
	} )
}

/**
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {number|string}                                               sessionId
 * @param {Object}                                                      body
 * @return {Promise<Object>} Session REST payload after enqueue.
 */
async function postMessage( requestUtils, sessionId, body ) {
	return requestUtils.rest( {
		path: `/ahentic/v1/sessions/${ sessionId }/messages`,
		method: 'POST',
		data: body,
	} )
}

/**
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {number|string}                                               sessionId
 * @param {string}                                                      decision
 * @return {Promise<Object>} Session REST payload.
 */
async function postApproval( requestUtils, sessionId, decision ) {
	return requestUtils.rest( {
		path: `/ahentic/v1/sessions/${ sessionId }/approvals`,
		method: 'POST',
		data: { decision },
	} )
}

/**
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {number|string}                                               sessionId
 * @param {Object}                                                      body
 * @return {Promise<Object>} Session REST payload.
 */
async function postBrowserResult( requestUtils, sessionId, body ) {
	return requestUtils.rest( {
		path: `/ahentic/v1/sessions/${ sessionId }/browser-results`,
		method: 'POST',
		data: body,
	} )
}

/**
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {number|string}                                               sessionId
 * @return {Promise<Object>} Session REST payload.
 */
async function continueSession( requestUtils, sessionId ) {
	return requestUtils.rest( {
		path: `/ahentic/v1/sessions/${ sessionId }/continue`,
		method: 'POST',
		data: {},
	} )
}

/**
 * Poll until `predicate(session)` is true. While status is `running`, nudge
 * via `/continue` so Playground runs that missed shutdown still advance
 * (same stall fallback the sidebar uses).
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {number|string}                                               sessionId
 * @param {(session: Object) => boolean}                                predicate
 * @param {Object}                                                      [options]
 * @param {number}                                                      [options.timeoutMs]
 * @param {number}                                                      [options.intervalMs]
 * @return {Promise<Object>} Matching session payload.
 */
async function waitForSession( requestUtils, sessionId, predicate, {
	timeoutMs = 45_000,
	intervalMs = 300,
} = {} ) {
	const deadline = Date.now() + timeoutMs
	let last = null

	while ( Date.now() < deadline ) {
		last = await getSession( requestUtils, sessionId )
		if ( predicate( last ) ) {
			return last
		}
		if ( last.status === 'running' ) {
			try {
				last = await continueSession( requestUtils, sessionId )
				if ( predicate( last ) ) {
					return last
				}
			} catch ( _err ) {
				// 409 / transient — keep polling.
			}
		}
		await new Promise( resolve => setTimeout( resolve, intervalMs ) )
	}

	const status = last?.status || 'unknown'
	const pending = last?.pendingTool?.name || null
	throw new Error(
		`Timed out waiting for session ${ sessionId } (last status=${ status }, pending=${ pending })`
	)
}

/**
 * Seed mocked model turns, post a user message, return the session id.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {Object}                                                      options
 * @param {Array<string>}                                               options.aiReplies     Raw mockReply() strings (or complete_chat shapes).
 * @param {string}                                                      [options.content]
 * @param {string}                                                      [options.mode]
 * @param {Object|null}                                                 [options.pageContext] Pass null to omit; default is a fresh admin context.
 * @return {Promise<{ session: Object, sessionId: number }>} Created session and its id.
 */
async function startRun( requestUtils, {
	aiReplies,
	content = 'Please proceed.',
	mode = 'agent',
	pageContext = DEFAULT_PAGE_CONTEXT,
} = {} ) {
	await resetAiResponses( requestUtils )
	await seedAiResponses( requestUtils, aiReplies )

	const session = await createSession( requestUtils, { mode } )
	const sessionId = session.id

	const body = { content, mode }
	if ( pageContext ) {
		body.pageContext = pageContext
	}

	await postMessage( requestUtils, sessionId, body )

	return { session, sessionId }
}

/**
 * Build a use_tools control-block turn.
 *
 * @param {string}               text         User-facing text.
 * @param {Array<Object|string>} toolsPlanned Tools for this think.
 * @param {Object}               [debugExtra] Extra debug fields (e.g. plan).
 * @return {string} mockReply wire text.
 */
function mockUseTools( text, toolsPlanned, debugExtra = {} ) {
	return mockReply( text, {
		intention: 'Running tools',
		thinking: 'Executing the planned abilities.',
		next: 'use_tools',
		tools_planned: toolsPlanned,
		...debugExtra,
	} )
}

/**
 * Conversation messages from a session REST payload (field is `messages`,
 * not `entries` — see Ahentic_Session_Repository::to_rest).
 *
 * @param {Object} session
 * @return {Array<Object>} Message entries from the session payload.
 */
function sessionMessages( session ) {
	if ( Array.isArray( session?.messages ) ) {
		return session.messages
	}
	if ( Array.isArray( session?.entries ) ) {
		return session.entries
	}
	return []
}

/**
 * Messages that are tool-role entries for an ability.
 *
 * @param {Object} session
 * @param {string} abilityName
 * @return {Array<Object>} Tool-role messages for the given ability.
 */
function toolEntriesFor( session, abilityName ) {
	return sessionMessages( session ).filter(
		entry => entry?.role === 'tool' && entry?.meta?.ability === abilityName
	)
}

module.exports = {
	DEFAULT_PAGE_CONTEXT,
	createSession,
	getSession,
	postMessage,
	postApproval,
	postBrowserResult,
	continueSession,
	waitForSession,
	startRun,
	mockUseTools,
	sessionMessages,
	toolEntriesFor,
	mockReply,
}
