/**
 * Shared content-placeholder heuristic (JS side of content-placeholder-rules.json).
 */

import rules from '../../../data/content-placeholder-rules.json'

/**
 * Strip tags and collapse whitespace (matches PHP Ahentic_Content_Placeholder::to_plain).
 *
 * @param {string} text Raw or HTML.
 * @return {string} Plain text.
 */
export function toPlainContent( text ) {
	return String( text || '' )
		.replace( /<[^>]+>/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim()
}

/**
 * Whether text looks like an LLM content stub rather than real prose.
 *
 * @param {string} text Raw or HTML content.
 * @return {boolean} True when the text matches a placeholder rule.
 */
export function looksLikeContentPlaceholder( text ) {
	const plain = toPlainContent( text )
	if ( ! plain ) {
		return false
	}

	const patterns = Array.isArray( rules.patterns ) ? rules.patterns : []
	for ( const entry of patterns ) {
		if ( ! entry || typeof entry.pattern !== 'string' ) {
			continue
		}
		const max = Number( entry.maxLength ) || 0
		if ( max > 0 && plain.length > max ) {
			continue
		}
		const flags = typeof entry.flags === 'string' ? entry.flags : ''
		let re
		try {
			re = new RegExp( entry.pattern, flags )
		} catch {
			continue
		}
		if ( re.test( plain ) ) {
			return true
		}
	}
	return false
}

export { rules as contentPlaceholderRules }
