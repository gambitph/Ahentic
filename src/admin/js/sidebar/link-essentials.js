/**
 * Compact link / button fields for get-blocks (no include_attributes).
 *
 * Generic key heuristics so third-party button/CTA blocks still expose
 * url + label without a full attributes dump.
 */

const LINK_URL_KEY_RE = /^(url|href|linkurl|buttonurl|targeturl|permalink|link)$/i
const LINK_TEXT_KEY_RE = /^(buttoncontent|buttontext|textcontent|label|linktext|cta)$/i
const HTTP_OR_PATH_RE = /^(https?:\/\/|\/|#|[a-z0-9][a-z0-9+.-]*:)/i

/**
 * @param {unknown} value Candidate string.
 * @return {boolean} Whether value looks like a navigable URL or path.
 */
function looksLikeLinkUrl( value ) {
	if ( typeof value !== 'string' || ! value.trim() ) {
		return false
	}
	const s = value.trim()
	if ( s.length > 2000 ) {
		return false
	}
	return HTTP_OR_PATH_RE.test( s ) || s.startsWith( '?' )
}

/**
 * Pick a small bag of link/button attributes from a block's attrs.
 *
 * @param {Record<string, unknown>} rawAttrs Block attributes.
 * @return {Record<string, unknown>} Compact essentials (may be empty).
 */
export function pickLinkEssentialAttrs( rawAttrs ) {
	const attrs = rawAttrs && typeof rawAttrs === 'object' && ! Array.isArray( rawAttrs )
		? rawAttrs
		: {}
	const out = {}

	for ( const key of Object.keys( attrs ) ) {
		const val = attrs[ key ]
		if ( typeof val !== 'string' || ! val.trim() ) {
			continue
		}
		if ( LINK_URL_KEY_RE.test( key ) && looksLikeLinkUrl( val ) ) {
			out[ key ] = val.trim()
			continue
		}
		if ( LINK_TEXT_KEY_RE.test( key ) && val.trim().length <= 200 ) {
			out[ key ] = val.trim()
		}
	}

	return out
}
