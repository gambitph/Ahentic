/**
 * Opaque block refs for the agent.
 *
 * Real Gutenberg clientIds stay in browser memory. The model only sees short
 * refs (b1, b2, …) from get-blocks / get-selection and passes those back.
 * Map is also session-backed (editor.refs) for refresh / Working memory PRD.
 */

let nextIndex = 1
/** @type {Map<string, string>} */
const clientIdToRef = new Map()
/** @type {Map<string, string>} */
const refToClientId = new Map()
/** @type {number} */
let boundPostId = 0

/**
 * Clear all refs (e.g. editor remount / miss). Prefer syncFromBlocks for normal use.
 */
export function resetBlockRefs() {
	nextIndex = 1
	clientIdToRef.clear()
	refToClientId.clear()
}

/**
 * @param {string} clientId
 * @return {string|null} Existing or newly assigned ref.
 */
export function refForClientId( clientId ) {
	if ( ! clientId ) {
		return null
	}
	const id = String( clientId )
	const existing = clientIdToRef.get( id )
	if ( existing ) {
		return existing
	}
	const ref = `b${ nextIndex++ }`
	clientIdToRef.set( id, ref )
	refToClientId.set( ref, id )
	return ref
}

/**
 * @param {string} ref
 * @return {string|null} The clientId for the ref, or null if unknown.
 */
export function clientIdForRef( ref ) {
	if ( ! ref ) {
		return null
	}
	return refToClientId.get( String( ref ).trim() ) || null
}

/**
 * Collect clientIds depth-first from live block objects.
 *
 * @param {Object[]} blocks
 * @return {string[]} Flattened clientIds in depth-first order.
 */
export function collectLiveClientIds( blocks ) {
	const ids = []
	const walk = list => {
		for ( const block of list || [] ) {
			if ( block?.clientId ) {
				ids.push( String( block.clientId ) )
			}
			if ( block?.innerBlocks?.length ) {
				walk( block.innerBlocks )
			}
		}
	}
	walk( blocks )
	return ids
}

/**
 * Drop refs whose clientIds are no longer in the editor; ensure live ones are mapped.
 *
 * @param {Object[]} blocks   Root (or subtree) blocks from the block editor.
 * @param {number}   [postId] Open document id for session binding.
 */
export function syncFromBlocks( blocks, postId = boundPostId ) {
	if ( postId && boundPostId && postId !== boundPostId ) {
		resetBlockRefs()
	}
	if ( postId ) {
		boundPostId = postId
	}
	const live = collectLiveClientIds( blocks )
	const liveSet = new Set( live )
	for ( const [ clientId, ref ] of [ ...clientIdToRef.entries() ] ) {
		if ( ! liveSet.has( clientId ) ) {
			clientIdToRef.delete( clientId )
			refToClientId.delete( ref )
		}
	}
	for ( const id of live ) {
		refForClientId( id )
	}
}

/**
 * Snapshot for session PATCH (editor.refs).
 *
 * @return {{ postId: number, nextIndex: number, map: Object<string, string> }} Snapshot of the ref registry.
 */
export function exportEditorRefs() {
	/** @type {Object<string, string>} */
	const map = {}
	for ( const [ ref, clientId ] of refToClientId.entries() ) {
		map[ ref ] = clientId
	}
	return {
		postId: boundPostId || 0,
		nextIndex,
		map,
	}
}

/**
 * Hydrate from session REST editorRefs.
 *
 * @param {Object|null} payload
 * @param {number}      [livePostId]
 */
export function hydrateEditorRefs( payload, livePostId = 0 ) {
	if ( ! payload || typeof payload !== 'object' || ! payload.map ) {
		return
	}
	const postId = payload.postId || payload.post_id || 0
	if ( livePostId && postId && livePostId !== postId ) {
		resetBlockRefs()
		boundPostId = livePostId
		return
	}
	resetBlockRefs()
	boundPostId = postId || livePostId || 0
	const map = payload.map || {}
	let max = 0
	for ( const [ ref, clientId ] of Object.entries( map ) ) {
		if ( ! /^b\d+$/.test( ref ) || ! clientId ) {
			continue
		}
		const n = parseInt( ref.slice( 1 ), 10 )
		if ( n > max ) {
			max = n
		}
		clientIdToRef.set( String( clientId ), ref )
		refToClientId.set( ref, String( clientId ) )
	}
	nextIndex = Math.max( payload.nextIndex || payload.next_index || 1, max + 1 )
}

/**
 * Wipe on miss (PRD): clear local + return export null so caller can clear session.
 */
export function wipeEditorRefs() {
	resetBlockRefs()
	boundPostId = 0
	return null
}

/**
 * @param {string|string[]|undefined|null} value
 * @return {string[]} Normalized, trimmed, non-empty tokens.
 */
function asTokenList( value ) {
	if ( Array.isArray( value ) ) {
		return value.map( String ).map( s => s.trim() ).filter( Boolean )
	}
	if ( typeof value === 'string' && value.trim() ) {
		return [ value.trim() ]
	}
	return []
}

/**
 * Resolve agent tokens (refs, or legacy raw clientIds) to live clientIds.
 *
 * @param {string|string[]|undefined|null}        value
 * @param {(id: string) => Object|null|undefined} [getBlock] Optional live lookup.
 * @return {{ clientIds: string[], missing: string[] }} Resolved live clientIds and unresolved tokens.
 */
export function resolveToClientIds( value, getBlock ) {
	const tokens = asTokenList( value )
	const clientIds = []
	const missing = []
	for ( const token of tokens ) {
		const fromRef = clientIdForRef( token )
		if ( fromRef ) {
			if ( ! getBlock || getBlock( fromRef ) ) {
				clientIds.push( fromRef )
				continue
			}
			missing.push( token )
			continue
		}
		// Legacy / accidental UUID still present in the editor.
		if ( getBlock?.( token ) ) {
			refForClientId( token )
			clientIds.push( token )
			continue
		}
		missing.push( token )
	}
	return { clientIds, missing }
}

/**
 * Map clientIds back to refs for ability result payloads.
 *
 * @param {string[]} clientIds
 * @return {string[]} Refs for the given clientIds.
 */
export function refsForClientIds( clientIds ) {
	return ( clientIds || [] )
		.map( id => refForClientId( id ) )
		.filter( Boolean )
}
