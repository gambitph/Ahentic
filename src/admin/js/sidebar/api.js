/**
 * Ahentic REST helpers for sessions / orchestrator.
 */

/**
 * @return {{ restUrl: string, restNonce: string }}
 */
function getRestConfig() {
	return {
		restUrl: window.ahentic?.restUrl || '',
		restNonce: window.ahentic?.restNonce || '',
	}
}

/**
 * @param {string} path Relative path under ahentic/v1.
 * @param {Object} [options] fetch options.
 * @return {Promise<any>}
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
 * @param {Object} [body]
 * @return {Promise<Object>}
 */
export function createSession( body = {} ) {
	return apiRequest( '/sessions', {
		method: 'POST',
		body: JSON.stringify( body ),
	} )
}

/**
 * @param {number|string} id
 * @return {Promise<Object>}
 */
export function getSession( id ) {
	return apiRequest( `/sessions/${ id }` )
}

/**
 * @param {number|string} id
 * @param {Object} body
 * @return {Promise<Object>}
 */
export function patchSession( id, body ) {
	return apiRequest( `/sessions/${ id }`, {
		method: 'PATCH',
		body: JSON.stringify( body ),
	} )
}

/**
 * @param {number|string} id
 * @param {{ content: string, mode?: string }} body
 * @return {Promise<Object>}
 */
export function postMessage( id, body ) {
	return apiRequest( `/sessions/${ id }/messages`, {
		method: 'POST',
		body: JSON.stringify( body ),
	} )
}

/**
 * @param {number|string} id
 * @return {Promise<Object>}
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
 * @return {Promise<Object>}
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
 * @param {number|string} id
 * @param {{ decision: string }} body
 * @return {Promise<Object>}
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
 * @param {Object} body Action payload.
 * @return {Promise<Object>}
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
 * @return {Array}
 */
export function mapEntriesToMessages( entries ) {
	if ( ! Array.isArray( entries ) ) {
		return []
	}
	return entries
		.filter( entry => entry && ( entry.role === 'user' || entry.role === 'assistant' || entry.role === 'event' ) )
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
 * @return {boolean}
 */
export function isSessionId( id ) {
	return /^\d+$/.test( String( id ) )
}
