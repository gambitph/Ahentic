/**
 * Pure helpers for ahentic-browser/move-blocks placement targeting.
 */

/* eslint-disable jsdoc/require-returns-description, jsdoc/check-line-alignment -- Compact helpers. */

/**
 * @param {Object} input Ability input.
 * @param {string[]} keys Candidate keys.
 * @return {string}
 */
function pickRefToken( input, keys ) {
	for ( const key of keys ) {
		if ( ! Object.prototype.hasOwnProperty.call( input, key ) ) {
			continue
		}
		const value = input[ key ]
		if ( value === undefined || value === null || value === '' ) {
			continue
		}
		return String( value )
	}
	return ''
}

/**
 * @param {Object} input Ability input.
 * @return {boolean}
 */
function hasIndex( input ) {
	return input.index !== undefined && input.index !== null && input.index !== ''
}

/**
 * @param {Object} input Ability input.
 * @return {boolean}
 */
function hasRootKey( input ) {
	return Object.prototype.hasOwnProperty.call( input, 'root_ref' ) ||
		Object.prototype.hasOwnProperty.call( input, 'rootRef' ) ||
		Object.prototype.hasOwnProperty.call( input, 'root_client_id' ) ||
		Object.prototype.hasOwnProperty.call( input, 'rootClientId' )
}

/**
 * Resolve move placement from index or before_ref / after_ref.
 *
 * @param {Object} input Ability input.
 * @param {{
 *   resolveRef: (token: string) => string|null,
 *   getRootClientId: (clientId: string) => string,
 *   getBlockOrder: (rootClientId: string) => string[],
 *   defaultRootClientId?: string,
 * }} deps Store accessors (injectable for tests).
 * @return {{ ok: true, index: number, toRoot: string }|{ ok: false, error: string, message: string, missing?: string[] }}
 */
export function resolveMovePlacement( input = {}, deps ) {
	const beforeToken = pickRefToken( input, [ 'before_ref', 'beforeRef', 'before_client_id', 'beforeClientId' ] )
	const afterToken = pickRefToken( input, [ 'after_ref', 'afterRef', 'after_client_id', 'afterClientId' ] )
	const indexProvided = hasIndex( input )
	const rootProvided = hasRootKey( input )

	if ( beforeToken && afterToken ) {
		return {
			ok: false,
			error: 'conflicting_relative_refs',
			message: 'Provide before_ref or after_ref, not both.',
		}
	}

	if ( ( beforeToken || afterToken ) && indexProvided ) {
		return {
			ok: false,
			error: 'mixed_move_targeting',
			message: 'Use either index (with optional root_ref) or before_ref/after_ref — not both.',
		}
	}

	if ( ( beforeToken || afterToken ) && rootProvided ) {
		return {
			ok: false,
			error: 'mixed_move_targeting',
			message: 'before_ref/after_ref imply the parent of the anchor; do not also pass root_ref.',
		}
	}

	if ( ! beforeToken && ! afterToken && ! indexProvided ) {
		return {
			ok: false,
			error: 'missing_move_target',
			message: 'Provide index, before_ref, or after_ref.',
		}
	}

	if ( beforeToken || afterToken ) {
		const token = beforeToken || afterToken
		const anchorId = deps.resolveRef( token )
		if ( ! anchorId ) {
			return {
				ok: false,
				error: 'missing_refs',
				message: 'One or more block refs were not found.',
				missing: [ token ],
			}
		}
		const toRoot = deps.getRootClientId( anchorId ) || ''
		const order = deps.getBlockOrder( toRoot ) || []
		const anchorIndex = order.indexOf( anchorId )
		if ( beforeToken ) {
			return {
				ok: true,
				index: anchorIndex >= 0 ? anchorIndex : 0,
				toRoot,
			}
		}
		return {
			ok: true,
			index: anchorIndex >= 0 ? anchorIndex + 1 : order.length,
			toRoot,
		}
	}

	let toRoot = deps.defaultRootClientId || ''
	if ( rootProvided ) {
		const rootToken = pickRefToken( input, [ 'root_ref', 'rootRef', 'root_client_id', 'rootClientId' ] )
		if ( rootToken === '' ) {
			toRoot = ''
		} else {
			const rootId = deps.resolveRef( rootToken )
			if ( ! rootId ) {
				return {
					ok: false,
					error: 'missing_refs',
					message: 'One or more block refs were not found.',
					missing: [ rootToken ],
				}
			}
			toRoot = rootId
		}
	}

	return {
		ok: true,
		index: Number( input.index ),
		toRoot,
	}
}
