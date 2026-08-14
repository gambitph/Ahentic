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
export function isAttachmentId( value ) {
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

/**
 * First image-looking URL in a media essentials bag (string or nested object).
 *
 * @param {Record<string, unknown>} bag Essentials or patch.
 * @return {string} URL or empty.
 */
function firstMediaUrl( bag ) {
	if ( ! bag || typeof bag !== 'object' ) {
		return ''
	}
	for ( const key of Object.keys( bag ) ) {
		const val = bag[ key ]
		if ( typeof val === 'string' && looksLikeImageUrl( val ) ) {
			return val
		}
		if ( val && typeof val === 'object' && ! Array.isArray( val ) ) {
			const url = val.url ?? val.src ?? val.mediaUrl
			if ( typeof url === 'string' && url.trim() ) {
				return url
			}
		}
	}
	return ''
}

/**
 * Nested url/src/mediaUrl field name on an object attr.
 *
 * @param {Record<string, unknown>} obj Nested attr.
 * @return {string} Field name or empty.
 */
function nestedUrlField( obj ) {
	if ( ! obj || typeof obj !== 'object' || Array.isArray( obj ) ) {
		return ''
	}
	if ( 'url' in obj ) {
		return 'url'
	}
	if ( 'src' in obj ) {
		return 'src'
	}
	if ( 'mediaUrl' in obj ) {
		return 'mediaUrl'
	}
	return ''
}

/**
 * Nested id/mediaId field name on an object attr.
 *
 * @param {Record<string, unknown>} obj Nested attr.
 * @return {string} Field name or empty.
 */
function nestedIdField( obj ) {
	if ( ! obj || typeof obj !== 'object' || Array.isArray( obj ) ) {
		return ''
	}
	if ( isAttachmentId( obj.id ) ) {
		return 'id'
	}
	if ( isAttachmentId( obj.mediaId ) ) {
		return 'mediaId'
	}
	if ( isAttachmentId( obj.attachmentId ) ) {
		return 'attachmentId'
	}
	return ''
}

/**
 * HTTP(s) URLs a compiled style string is expected to keep after a media patch.
 *
 * @param {string} value CSS or style blob.
 * @return {string[]} URLs.
 */
function httpUrlsIn( value ) {
	return typeof value === 'string' ? ( value.match( /https?:\/\/[^\s"'()]+/g ) || [] ) : []
}

/**
 * Whether a live style string still contains every URL from the dispatched CSS.
 *
 * Gutenberg may reformat `url(...)` spacing without dropping the new file.
 *
 * @param {unknown} liveVal  Live attr after dispatch.
 * @param {unknown} expected Dispatched style string.
 * @return {boolean} Whether live CSS still contains the dispatched URLs.
 */
export function compiledStyleContainsUrls( liveVal, expected ) {
	if ( typeof liveVal !== 'string' || typeof expected !== 'string' ) {
		return false
	}
	if ( ! expected.includes( 'url(' ) ) {
		return false
	}
	const urls = httpUrlsIn( expected )
	return urls.length > 0 && urls.every( url => liveVal.includes( url ) )
}

/**
 * URL we may rewrite in sibling CSS (file extension optional).
 *
 * @param {unknown} value Candidate.
 * @return {boolean} Whether the URL may be rewritten in sibling CSS.
 */
function isRewritableMediaUrl( value ) {
	if ( looksLikeImageUrl( value ) ) {
		return true
	}
	return typeof value === 'string' && HTTP_URL_RE.test( value.trim() )
}

/**
 * Whether the requested key/value is a guessed media URL, attachment id, or alt.
 *
 * @param {string}  reqKey Requested attr name.
 * @param {unknown} reqVal Requested value.
 * @return {boolean} Whether this looks like a guessed media URL, id, or alt.
 */
function isMediaShapedRequest( reqKey, reqVal ) {
	if ( looksLikeImageUrl( reqVal ) ) {
		return true
	}
	if ( typeof reqVal === 'string' && HTTP_URL_RE.test( reqVal.trim() ) && STRONG_MEDIA_URL_KEY_RE.test( reqKey ) ) {
		return true
	}
	if ( isAttachmentId( reqVal ) && ID_KEY_RE.test( reqKey ) ) {
		return true
	}
	return typeof reqVal === 'string' && ALT_KEY_RE.test( reqKey )
}

/**
 * Whether a live attr already holds media (non-empty URL, nested image, id, alt).
 *
 * @param {string}  key Live attr name.
 * @param {unknown} val Live value.
 * @return {boolean} Whether the live attr already holds media.
 */
function liveAttrHoldsMedia( key, val ) {
	if ( isMediaUrlAttr( key, val ) || looksLikeImageUrl( val ) ) {
		return true
	}
	if ( objectHasImageUrl( val ) ) {
		return true
	}
	if ( isAttachmentId( val ) && ID_KEY_RE.test( key ) ) {
		return true
	}
	return typeof val === 'string' && val.trim() !== '' && ALT_KEY_RE.test( key )
}

/**
 * Use the live key as-is, or skip empty unused media keys so essentials can win.
 *
 * @param {Record<string, unknown>} live    Live attrs.
 * @param {string}                  liveKey Candidate live key.
 * @param {string}                  reqKey  Requested key.
 * @param {unknown}                 reqVal  Requested value.
 * @return {boolean} Whether to write this live key instead of remapping.
 */
function preferLiveKey( live, liveKey, reqKey, reqVal ) {
	if ( ! ( liveKey in live ) ) {
		return false
	}
	if ( ! isMediaShapedRequest( reqKey, reqVal ) ) {
		return true
	}
	return liveAttrHoldsMedia( liveKey, live[ liveKey ] )
}

/**
 * Merge a value into a patch, deep-merging object attrs so nested url/id/alt
 * updates do not wipe sibling fields.
 *
 * @param {Record<string, unknown>} patch     Accumulator.
 * @param {Record<string, unknown>} liveAttrs Live block attrs.
 * @param {string}                  key       Top-level attr key.
 * @param {unknown}                 value     Primitive or partial object.
 */
function mergeIntoPatch( patch, liveAttrs, key, value ) {
	const liveVal = liveAttrs[ key ]
	if (
		value && typeof value === 'object' && ! Array.isArray( value ) &&
		liveVal && typeof liveVal === 'object' && ! Array.isArray( liveVal )
	) {
		const prev = patch[ key ] && typeof patch[ key ] === 'object' && ! Array.isArray( patch[ key ] )
			? patch[ key ]
			: {}
		patch[ key ] = {
			...liveVal,
			...prev,
			...value,
		}
		return
	}
	patch[ key ] = value
}

/**
 * Map a guessed media URL/id/alt onto pickMediaEssentialAttrs paths.
 *
 * @param {Record<string, unknown>} essentials Compact media bag.
 * @param {string}                  reqKey     Requested key.
 * @param {unknown}                 reqVal     Requested value.
 * @return {{ key: string, partial: unknown, path: string }|null} Mapped path or null.
 */
function mapMediaValueOntoEssentials( essentials, reqKey, reqVal ) {
	const keys = Object.keys( essentials )
	if ( ! keys.length ) {
		return null
	}

	const wantsUrl = looksLikeImageUrl( reqVal ) ||
		( typeof reqVal === 'string' && HTTP_URL_RE.test( reqVal.trim() ) && URL_KEY_RE.test( reqKey ) )
	if ( wantsUrl && typeof reqVal === 'string' ) {
		for ( const key of keys ) {
			const val = essentials[ key ]
			if ( typeof val === 'string' && ( looksLikeImageUrl( val ) || URL_KEY_RE.test( key ) ) ) {
				return {
					key,
					partial: reqVal,
					path: key,
				}
			}
			const field = nestedUrlField( val )
			if ( field ) {
				return {
					key,
					partial: { [ field ]: reqVal },
					path: `${ key }.${ field }`,
				}
			}
		}
		return null
	}

	if ( isAttachmentId( reqVal ) && ID_KEY_RE.test( reqKey ) ) {
		for ( const key of keys ) {
			const val = essentials[ key ]
			if ( ID_KEY_RE.test( key ) && isAttachmentId( val ) ) {
				return {
					key,
					partial: reqVal,
					path: key,
				}
			}
			const idField = nestedIdField( val )
			if ( idField ) {
				return {
					key,
					partial: { [ idField ]: reqVal },
					path: `${ key }.${ idField }`,
				}
			}
		}
		return null
	}

	if ( typeof reqVal === 'string' && ALT_KEY_RE.test( reqKey ) ) {
		for ( const key of keys ) {
			const val = essentials[ key ]
			if ( ALT_KEY_RE.test( key ) && typeof val === 'string' ) {
				return {
					key,
					partial: reqVal,
					path: key,
				}
			}
			if ( val && typeof val === 'object' && ! Array.isArray( val ) && typeof val.alt === 'string' ) {
				return {
					key,
					partial: { alt: reqVal },
					path: `${ key }.alt`,
				}
			}
		}
		return null
	}

	return null
}

/**
 * Whether a string attr should get oldUrl → newUrl (CSS / media URL keys only).
 *
 * @param {string}  key    Attr name.
 * @param {unknown} value  Current value.
 * @param {string}  oldUrl URL to replace.
 * @return {boolean} Whether this string should be rewritten.
 */
function shouldRewriteUrlString( key, value, oldUrl ) {
	if ( typeof value !== 'string' || ! value.includes( oldUrl ) ) {
		return false
	}
	if ( SKIP_KEY_RE.test( key ) ) {
		return false
	}
	return value.includes( 'url(' ) || URL_KEY_RE.test( key )
}

/**
 * Rewrite oldUrl → newUrl in live string attrs (and one nested object level).
 *
 * @param {Record<string, unknown>} liveAttrs Live attrs.
 * @param {Record<string, unknown>} patch     Accumulator (mutated).
 * @param {string}                  oldUrl    Current media URL.
 * @param {string}                  newUrl    Replacement URL.
 */
function replaceOldUrlInStrings( liveAttrs, patch, oldUrl, newUrl ) {
	if ( ! oldUrl || oldUrl === newUrl || ! isRewritableMediaUrl( oldUrl ) ) {
		return
	}
	for ( const key of Object.keys( liveAttrs ) ) {
		if ( SKIP_KEY_RE.test( key ) ) {
			continue
		}
		const current = Object.prototype.hasOwnProperty.call( patch, key ) ? patch[ key ] : liveAttrs[ key ]
		if ( typeof current === 'string' ) {
			if ( shouldRewriteUrlString( key, current, oldUrl ) ) {
				patch[ key ] = current.split( oldUrl ).join( newUrl )
			}
			continue
		}
		if ( ! current || typeof current !== 'object' || Array.isArray( current ) ) {
			continue
		}
		const liveObj = liveAttrs[ key ] && typeof liveAttrs[ key ] === 'object' ? liveAttrs[ key ] : {}
		const next = { ...liveObj, ...current }
		let changed = false
		for ( const nestedKey of Object.keys( next ) ) {
			if ( shouldRewriteUrlString( nestedKey, next[ nestedKey ], oldUrl ) ) {
				next[ nestedKey ] = next[ nestedKey ].split( oldUrl ).join( newUrl )
				changed = true
			}
		}
		if ( changed ) {
			patch[ key ] = next
		}
	}
}

/**
 * Resolve a requested attribute patch against live block attributes.
 *
 * Remaps guessed media keys (mediaurl vs mediaUrl, nested {url,id}) onto the
 * live paths, and rewrites the old media URL in sibling string attrs (CSS).
 *
 * @param {Record<string, unknown>} liveAttrs Current block attributes.
 * @param {Record<string, unknown>} requested Agent-supplied patch.
 * @param {Object}                  [opts]    Optional blockName and schemaKeys.
 * @return {Object} Resolved patch plus remap/ignore notes.
 */
export function resolveAttributePatch( liveAttrs, requested, opts = {} ) {
	const live = liveAttrs && typeof liveAttrs === 'object' && ! Array.isArray( liveAttrs )
		? liveAttrs
		: {}
	const req = requested && typeof requested === 'object' && ! Array.isArray( requested )
		? requested
		: {}
	const blockName = typeof opts.blockName === 'string' ? opts.blockName : ''
	const schemaKeys = Array.isArray( opts.schemaKeys ) ? opts.schemaKeys.filter( k => typeof k === 'string' ) : []
	const schemaSet = new Set( schemaKeys )
	const liveLower = new Map( Object.keys( live ).map( key => [ key.toLowerCase(), key ] ) )
	const schemaLower = new Map( schemaKeys.map( key => [ key.toLowerCase(), key ] ) )
	const essentials = pickMediaEssentialAttrs( live, blockName )

	const patch = {}
	const remapped = {}
	const ignored = []

	for ( const reqKey of Object.keys( req ) ) {
		const reqVal = req[ reqKey ]
		if ( preferLiveKey( live, reqKey, reqKey, reqVal ) ) {
			mergeIntoPatch( patch, live, reqKey, reqVal )
			continue
		}
		const liveCase = liveLower.get( reqKey.toLowerCase() )
		if ( liveCase && preferLiveKey( live, liveCase, reqKey, reqVal ) ) {
			mergeIntoPatch( patch, live, liveCase, reqVal )
			remapped[ reqKey ] = liveCase
			continue
		}
		const mapped = mapMediaValueOntoEssentials( essentials, reqKey, reqVal )
		if ( mapped ) {
			mergeIntoPatch( patch, live, mapped.key, mapped.partial )
			remapped[ reqKey ] = mapped.path
			continue
		}
		const schemaHit = schemaSet.has( reqKey )
			? reqKey
			: schemaLower.get( reqKey.toLowerCase() )
		if ( schemaHit && ! isMediaShapedRequest( reqKey, reqVal ) ) {
			mergeIntoPatch( patch, live, schemaHit, reqVal )
			if ( schemaHit !== reqKey ) {
				remapped[ reqKey ] = schemaHit
			}
			continue
		}
		ignored.push( reqKey )
	}

	const oldUrl = firstMediaUrl( essentials )
	const newUrl = firstMediaUrl( patch ) || firstMediaUrl(
		Object.fromEntries(
			Object.entries( req ).filter( ( [ , val ] ) => looksLikeImageUrl( val ) )
		)
	)
	if ( oldUrl && newUrl ) {
		replaceOldUrlInStrings( live, patch, oldUrl, newUrl )
	}

	return {
		patch,
		remapped,
		ignored,
	}
}
