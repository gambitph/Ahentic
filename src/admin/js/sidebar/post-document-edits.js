/**
 * Pure helpers for ahentic-browser/update-post-document.
 */

/* eslint-disable jsdoc/require-returns-description -- Compact helpers. */

const ALLOWED_KEYS = [ 'title', 'excerpt', 'slug' ]

/**
 * Plan editor-store edits for title / excerpt / slug.
 *
 * @param {Object} input Ability input.
 * @return {{ ok: true, edits: Object }|{ ok: false, error: string, message: string }}
 */
export function planPostDocumentEdits( input = {} ) {
	const edits = {}
	let sawKey = false

	for ( const key of ALLOWED_KEYS ) {
		if ( ! Object.prototype.hasOwnProperty.call( input, key ) ) {
			continue
		}
		sawKey = true
		const raw = input[ key ]
		if ( raw === undefined || raw === null ) {
			return {
				ok: false,
				error: 'invalid_' + key,
				message: key + ' cannot be null.',
			}
		}
		if ( typeof raw !== 'string' ) {
			return {
				ok: false,
				error: 'invalid_' + key,
				message: key + ' must be a string.',
			}
		}
		if ( key === 'excerpt' ) {
			edits.excerpt = raw
			continue
		}
		const trimmed = raw.trim()
		if ( ! trimmed ) {
			return {
				ok: false,
				error: 'invalid_' + key,
				message: key + ' cannot be empty.',
			}
		}
		edits[ key ] = trimmed
	}

	if ( ! sawKey ) {
		return {
			ok: false,
			error: 'missing_fields',
			message: 'Provide at least one of: title, excerpt, slug.',
		}
	}

	return { ok: true, edits }
}
