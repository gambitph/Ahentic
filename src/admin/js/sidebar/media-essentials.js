/**
 * Compact media fields for get-blocks (no include_attributes).
 *
 * Prefer known core key maps, then guess common url/alt/id key names, then
 * scan string/object values for image-looking URLs so third-party blocks still
 * work with describe-image / alt updates.
 */

/**
 * @type {Record<string, string[]>}
 */
const BLOCK_MEDIA_KEYS = {
	'core/image': [ 'url', 'alt', 'id' ],
	'core/cover': [ 'url', 'alt', 'id' ],
	'core/media-text': [ 'mediaUrl', 'mediaAlt', 'mediaId' ],
}

const URL_KEY_RE = /^(url|src|mediaurl|imageurl|backgroundurl|bgimage|bgurl|featuredimage|poster|backgroundimage)$/i
/** Keys that imply media even when the URL has no file extension. */
const STRONG_MEDIA_URL_KEY_RE = /^(mediaurl|imageurl|backgroundurl|bgimage|bgurl|featuredimage|poster|backgroundimage)$/i
const ALT_KEY_RE = /^(alt|mediaalt|alttext|imagealt)$/i
const ID_KEY_RE = /^(id|mediaid|attachmentid|imageid)$/i
const STRONG_MEDIA_ID_KEY_RE = /^(mediaid|attachmentid|imageid)$/i
const SKIP_KEY_RE = /^(href|link|canonical|permalink|targeturl)$/i

const IMAGE_URL_RE = /^(https?:\/\/|\/|data:image\/).+\.(jpe?g|png|gif|webp|avif|svg|heic)(\?|#|$)/i
const UPLOADS_URL_RE = /^(https?:\/\/|\/).*\/wp-content\/uploads\//i
const DATA_IMAGE_RE = /^data:image\//i
const HTTP_URL_RE = /^https?:\/\//i

/**
 * @param {unknown} value Candidate string.
 * @return {boolean} Whether value looks like an image URL.
 */
export function looksLikeImageUrl( value ) {
	if ( typeof value !== 'string' || ! value.trim() ) {
		return false
	}
	const s = value.trim()
	if ( DATA_IMAGE_RE.test( s ) ) {
		return true
	}
	if ( UPLOADS_URL_RE.test( s ) ) {
		return true
	}
	return IMAGE_URL_RE.test( s )
}

/**
 * @param {string}  key   Attribute key.
 * @param {unknown} value Attribute value.
 * @return {boolean} Whether this key/value is a usable media URL.
 */
function isMediaUrlAttr( key, value ) {
	if ( typeof value !== 'string' || ! value.trim() ) {
		return false
	}
	if ( looksLikeImageUrl( value ) ) {
		return true
	}
	// imageUrl / mediaUrl / poster etc. — accept any http(s) even without extension.
	return STRONG_MEDIA_URL_KEY_RE.test( key ) && HTTP_URL_RE.test( value.trim() )
}

/**
 * @param {unknown} value Candidate id.
 * @return {boolean} Whether value looks like a positive attachment id.
 */
function isAttachmentId( value ) {
	if ( typeof value === 'number' && Number.isFinite( value ) && value > 0 ) {
		return true
	}
	if ( typeof value === 'string' && /^\d+$/.test( value ) && Number( value ) > 0 ) {
		return true
	}
	return false
}

/**
 * @param {unknown} value Candidate object attr.
 * @return {boolean} Whether object has an image-looking url/src field.
 */
function objectHasImageUrl( value ) {
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return false
	}
	const url = value.url ?? value.src ?? value.mediaUrl
	if ( looksLikeImageUrl( url ) ) {
		return true
	}
	return typeof url === 'string' && HTTP_URL_RE.test( url.trim() )
}

/**
 * @param {Record<string, unknown>} out Essentials bag.
 * @return {boolean} Whether bag already has an image URL (string or nested).
 */
function bagHasMediaUrl( out ) {
	return Object.keys( out ).some( key => {
		const val = out[ key ]
		if ( isMediaUrlAttr( key, val ) || looksLikeImageUrl( val ) ) {
			return true
		}
		return objectHasImageUrl( val )
	} )
}

/**
 * Pick a small bag of media identity/alt attributes from a block's attrs.
 *
 * @param {Record<string, unknown>} rawAttrs    Block attributes.
 * @param {string}                  [blockName] Block name (e.g. core/image).
 * @return {Record<string, unknown>} Compact essentials (may be empty).
 */
export function pickMediaEssentialAttrs( rawAttrs, blockName = '' ) {
	const attrs = rawAttrs && typeof rawAttrs === 'object' && ! Array.isArray( rawAttrs )
		? rawAttrs
		: {}
	const out = {}

	const mapped = BLOCK_MEDIA_KEYS[ blockName ]
	if ( mapped ) {
		for ( const key of mapped ) {
			if ( key in attrs ) {
				out[ key ] = attrs[ key ]
			}
		}
		if ( Object.keys( out ).length ) {
			return out
		}
	}

	// Pass 1: URL-ish keys and image-looking values.
	for ( const key of Object.keys( attrs ) ) {
		if ( SKIP_KEY_RE.test( key ) ) {
			continue
		}
		const val = attrs[ key ]
		if ( URL_KEY_RE.test( key ) && isMediaUrlAttr( key, val ) ) {
			out[ key ] = val
			continue
		}
		if ( STRONG_MEDIA_ID_KEY_RE.test( key ) && isAttachmentId( val ) ) {
			out[ key ] = val
		}
	}

	if ( ! bagHasMediaUrl( out ) ) {
		for ( const key of Object.keys( attrs ) ) {
			if ( SKIP_KEY_RE.test( key ) || key in out ) {
				continue
			}
			const val = attrs[ key ]
			if ( looksLikeImageUrl( val ) ) {
				out[ key ] = val
				break
			}
			if ( objectHasImageUrl( val ) ) {
				out[ key ] = val
				break
			}
		}
	}

	const hasUrl = bagHasMediaUrl( out )
	const hasStrongId = Object.keys( out ).some( key => STRONG_MEDIA_ID_KEY_RE.test( key ) )
	if ( ! hasUrl && ! hasStrongId ) {
		return {}
	}

	// Pass 2: alt / generic id only once we know this looks like media.
	for ( const key of Object.keys( attrs ) ) {
		if ( key in out || SKIP_KEY_RE.test( key ) ) {
			continue
		}
		const val = attrs[ key ]
		if ( ALT_KEY_RE.test( key ) && typeof val === 'string' ) {
			out[ key ] = val
			continue
		}
		if ( hasUrl && ID_KEY_RE.test( key ) && isAttachmentId( val ) ) {
			out[ key ] = val
		}
	}

	return out
}
