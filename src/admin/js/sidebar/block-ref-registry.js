/**
 * Opaque block refs for the agent.
 *
 * Real Gutenberg clientIds stay in browser memory. The model only sees short
 * refs (b1, b2, …) from get-blocks / get-selection and passes those back.
 */

let nextIndex = 1
/** @type {Map<string, string>} */
const clientIdToRef = new Map()
/** @type {Map<string, string>} */
const refToClientId = new Map()

/**
 * Clear all refs (e.g. editor remount). Prefer syncFromBlocks for normal use.
 */
export function resetBlockRefs() {
	nextIndex = 1
	clientIdToRef.clear()
	refToClientId.clear()
}

/**
 * @param {string} clientId
 * @return {string|null}
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
 * @return {string|null}
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
 * @return {string[]}
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
 * @param {Object[]} blocks Root (or subtree) blocks from the block editor.
 */
export function syncFromBlocks( blocks ) {
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
 * @param {string|string[]|undefined|null} value
 * @return {string[]}
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
 * @param {string|string[]|undefined|null} value
 * @param {(id: string) => Object|null|undefined} [getBlock] Optional live lookup.
 * @return {{ clientIds: string[], missing: string[] }}
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
 * @return {string[]}
 */
export function refsForClientIds( clientIds ) {
	return ( clientIds || [] )
		.map( id => refForClientId( id ) )
		.filter( Boolean )
}
