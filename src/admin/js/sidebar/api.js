/**
 * Ahentic REST helpers for sessions / orchestrator.
 */

/**
 * @return {{ restUrl: string, restNonce: string }} REST url + nonce from the localized config.
 */
function getRestConfig() {
	return {
		restUrl: window.ahentic?.restUrl || '',
		restNonce: window.ahentic?.restNonce || '',
	}
}

/**
 * @param {string} path      Relative path under ahentic/v1.
 * @param {Object} [options] fetch options.
 * @return {Promise<any>} Parsed JSON response payload.
 */
export async function apiRequest( path, options = {} ) {
	const { restUrl, restNonce } = getRestConfig()
	if ( ! restUrl || ! restNonce ) {
		throw new Error( 'Ahentic REST configuration is missing.' )
	}

	const response = await fetch( `${ restUrl }${ path }`, {
		credentials: 'same-origin',
		...options,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': restNonce,
			...( options.headers || {} ),
		},
	} )

	const payload = await response.json().catch( () => ( {} ) )

	if ( ! response.ok ) {
		const message = payload?.message ||
			payload?.data?.message ||
			`Request failed (${ response.status })`
		const error = new Error( message )
		error.code = payload?.code || payload?.data?.code
		error.status = response.status
		error.payload = payload
		throw error
	}

	return payload
}

/**
 * AI readiness / connector status (same payload as script localization).
 * Used once on sidebar mount to recover localize-time false negatives — not
 * for ongoing health checks (mid-session failures surface as chat errors).
 *
 * @return {Promise<Object>} Status payload (`isReady`, `hasConnector`, …).
 */
export function getAiPluginStatus() {
	return apiRequest( '/ai-plugin/status' )
}

/**
 * @param {Object} [body]
 * @return {Promise<Object>} Created session record.
 */
export function createSession( body = {} ) {
	return apiRequest( '/sessions', {
		method: 'POST',
		body: JSON.stringify( body ),
	} )
}

/**
 * @param {number|string} id
 * @return {Promise<Object>} Session record.
 */
export function getSession( id ) {
	return apiRequest( `/sessions/${ id }` )
}

/**
 * Full trace + host details for a bug report. Not part of the polled session
 * payload, so it is only fetched when the debugger is open or a log is exported.
 *
 * @param {number|string} id
 * @return {Promise<Object>} Diagnostics payload (trace + host details).
 */
export function getDiagnostics( id ) {
	return apiRequest( `/sessions/${ id }/diagnostics` )
}

/**
 * @param {number|string}                                           id
 * @param {{ title?: string, mode?: string, pageContext?: Object }} body
 * @return {Promise<Object>} Updated session record.
 */
export function patchSession( id, body ) {
	return apiRequest( `/sessions/${ id }`, {
		method: 'PATCH',
		body: JSON.stringify( body ),
	} )
}

/**
 * @param {number|string}                                            id
 * @param {{ content: string, mode?: string, pageContext?: Object }} body
 * @return {Promise<Object>} Updated session record with the new message.
 */
export function postMessage( id, body ) {
	return apiRequest( `/sessions/${ id }/messages`, {
		method: 'POST',
		body: JSON.stringify( body ),
	} )
}

/**
 * Post a browser ability result for a session awaiting_browser.
 *
 * @param {number|string}                                         id
 * @param {{ call_id?: string, result?: Object, error?: string }} body
 * @return {Promise<Object>} Updated session record.
 */
export function postBrowserResult( id, body ) {
	return apiRequest( `/sessions/${ id }/browser-results`, {
		method: 'POST',
		body: JSON.stringify( body ),
	} )
}

/**
 * @param {number|string} id
 * @return {Promise<Object>} Updated session record.
 */
export function cancelSession( id ) {
	return apiRequest( `/sessions/${ id }/cancel`, {
		method: 'POST',
		body: '{}',
	} )
}

/**
 * Kick a stalled run (Local / no cron fallback).
 *
 * @param {number|string} id
 * @return {Promise<Object>} Updated session record.
 */
export function continueSession( id ) {
	return apiRequest( `/sessions/${ id }/continue`, {
		method: 'POST',
		body: '{}',
	} )
}

/**
 * Post a HITL approval decision.
 *
 * @param {number|string}        id
 * @param {{ decision: string }} body
 * @return {Promise<Object>} Updated session record.
 */
export function postApproval( id, body ) {
	return apiRequest( `/sessions/${ id }/approvals`, {
		method: 'POST',
		body: JSON.stringify( body ),
	} )
}

/**
 * Start a suggested ability action (may pause for HITL).
 *
 * @param {number|string} id
 * @param {Object}        body Action payload.
 * @return {Promise<Object>} Updated session record.
 */
export function postSuggestedAction( id, body ) {
	return apiRequest( `/sessions/${ id }/actions`, {
		method: 'POST',
		body: JSON.stringify( body ),
	} )
}

/**
 * Map server entries to sidebar message objects.
 *
 * @param {Array} entries
 * @return {Array} Sidebar message objects.
 */
export function mapEntriesToMessages( entries ) {
	if ( ! Array.isArray( entries ) ) {
		return []
	}
	return entries
		.filter( entry => {
			if ( ! entry || ! ( entry.role === 'user' || entry.role === 'assistant' || entry.role === 'event' ) ) {
				return false
			}
			// Legacy thought_process entries — ephemeral UX uses thoughtProcess from REST now.
			if ( entry.meta?.thought_process || entry.meta?.intermediate ) {
				return false
			}
			return true
		} )
		.map( entry => ( {
			id: entry.id || `seq_${ entry.seq }`,
			role: entry.role === 'assistant' ? 'assistant' : ( entry.role === 'user' ? 'user' : 'system' ),
			content: entry.content || '',
			meta: entry.meta || {},
			seq: entry.seq,
		} ) )
}

/**
 * Whether a tab id looks like a server session id.
 *
 * @param {string|number} id
 * @return {boolean} True if the id is a server session id.
 */
export function isSessionId( id ) {
	return /^\d+$/.test( String( id ) )
}

/**
 * Run feedback intake status (consented / hasToken / Turnstile site key).
 *
 * @return {Promise<Object>} Status payload.
 */
export function getFeedbackStatus() {
	return apiRequest( '/feedback' )
}

/**
 * Fresh mint after Turnstile (token stays server-side).
 *
 * @param {string} turnstileToken Cloudflare Turnstile response.
 * @return {Promise<Object>} Updated feedback status.
 */
export function mintFeedbackSiteToken( turnstileToken ) {
	return apiRequest( '/feedback/site-tokens', {
		method: 'POST',
		// Intake / REST wire format uses snake_case.
		/* eslint-disable-next-line camelcase -- turnstile_token matches PHP / intake. */
		body: JSON.stringify( { turnstile_token: turnstileToken } ),
	} )
}

/**
 * Silent site-token refresh.
 *
 * @return {Promise<Object>} Updated feedback status.
 */
export function refreshFeedbackSiteToken() {
	return apiRequest( '/feedback/site-tokens/refresh', {
		method: 'POST',
		body: '{}',
	} )
}

/**
 * File a Run feedback report for a session via the PHP proxy.
 *
 * @param {number|string} id
 * @param {Object}        [body] Optional title/summary/duplicate_of overrides.
 * @return {Promise<{ action: string, number: number, html_url: string }>} Intake result.
 */
export function fileRunFeedbackReport( id, body = {} ) {
	return apiRequest( '/feedback/reports', {
		method: 'POST',
		body: JSON.stringify( {
			/* eslint-disable-next-line camelcase -- session_id matches PHP REST args. */
			session_id: Number( id ),
			...body,
		} ),
	} )
}
