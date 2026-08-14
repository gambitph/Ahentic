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

const URL_KEY_RE = /^(url|src|mediaurl|imageurl|backgroundurl|bgimage|bgimg|bgurl|featuredimage|poster|backgroundimage)$/i
/** Keys that imply media even when the URL has no file extension. */
const STRONG_MEDIA_URL_KEY_RE = /^(mediaurl|imageurl|backgroundurl|bgimage|bgimg|bgurl|featuredimage|poster|backgroundimage)$/i
const ALT_KEY_RE = /^(alt|mediaalt|alttext|imagealt)$/i
const ID_KEY_RE = /^(id|mediaid|attachmentid|imageid|bgimgid|bgimageid)$/i
const STRONG_MEDIA_ID_KEY_RE = /^(mediaid|attachmentid|imageid|bgimgid|bgimageid)$/i
const SKIP_KEY_RE = /^(href|link|canonical|permalink|targeturl)$/i

const IMAGE_URL_RE = /^(https?:\/\/|\/|data:image\/).+\.(jpe?g|png|gif|webp|avif|svg|heic)(\?|#|$)/i
const UPLOADS_URL_RE = /^(https?:\/\/|\/).*\/wp-content\/uploads\//i
const DATA_IMAGE_RE = /^data:image\//i
const HTTP_URL_RE = /^https?:\/\//i
/** Nested design objects that often hold a CSS/block background image. */
const BACKGROUND_NEST_KEY_RE = /^(background|backgrounds|backgroundimage|blockbackground|overlay|style|bg)$/i
/** Top-level keys whose media paints a box/cover, not an inline img. */
const BACKGROUND_KIND_KEY_RE = /background|blockbackground|^(bg|bgimg|bgimage|bgurl|overlay)$/i
/** Block names that are inline media, not a painted container/hero. */
const INLINE_MEDIA_BLOCK_RE = /\/(image|img|video|audio|gallery|media-text)$/i
const OVERLAY_PX_MAX = 400

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
 * @return {boolean} Whether object has an image-looking url/src/image field.
 */
function objectHasImageUrl( value ) {
	return Boolean( urlFromUnknown( value ) )
}

/**
 * First image-looking URL in a string, URL array, or {url|src|mediaUrl|image} object.
 *
 * @param {unknown} value   Candidate.
 * @param {number}  [depth] Recursion depth.
 * @return {string} URL or empty.
 */
function urlFromUnknown( value, depth = 0 ) {
	if ( depth > 4 ) {
		return ''
	}
	if ( typeof value === 'string' ) {
		const s = value.trim()
		if ( looksLikeImageUrl( s ) ) {
			return s
		}
		return ''
	}
	if ( Array.isArray( value ) ) {
		for ( let i = 0; i < value.length; i++ ) {
			const found = urlFromUnknown( value[ i ], depth + 1 )
			if ( found ) {
				return found
			}
		}
		return ''
	}
	if ( ! value || typeof value !== 'object' ) {
		return ''
	}
	const direct = value.url ?? value.src ?? value.mediaUrl
	if ( typeof direct === 'string' && direct.trim() ) {
		const s = direct.trim()
		if ( looksLikeImageUrl( s ) || HTTP_URL_RE.test( s ) ) {
			return s
		}
	}
	const fromImage = urlFromUnknown( value.image, depth + 1 )
	if ( fromImage ) {
		return fromImage
	}
	for ( const key of Object.keys( value ) ) {
		if ( ! BACKGROUND_NEST_KEY_RE.test( key ) ) {
			continue
		}
		const found = urlFromUnknown( value[ key ], depth + 1 )
		if ( found ) {
			return found
		}
	}
	return ''
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
		const keys = Object.keys( attrs )
		const ordered = [
			...keys.filter( key => BACKGROUND_KIND_KEY_RE.test( key ) ),
			...keys.filter( key => ! BACKGROUND_KIND_KEY_RE.test( key ) ),
		]
		for ( const key of ordered ) {
			if ( SKIP_KEY_RE.test( key ) || key in out ) {
				continue
			}
			const val = attrs[ key ]
			if ( looksLikeImageUrl( val ) ) {
				out[ key ] = val
				break
			}
			if ( objectHasImageUrl( val ) ) {
				out[ key ] = compactMediaObject( val )
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
 * Keep only the image URL payload from a nested design object.
 *
 * @param {Record<string, unknown>} val     Nested attr.
 * @param {number}                  [depth] Recursion depth.
 * @return {Record<string, unknown>} Compact object for essentials / remap.
 */
function compactMediaObject( val, depth = 0 ) {
	if ( ! val || typeof val !== 'object' || Array.isArray( val ) || depth > 4 ) {
		return val
	}
	if ( Array.isArray( val.image ) || typeof val.image === 'string' ) {
		return { image: val.image }
	}
	if ( val.image && typeof val.image === 'object' ) {
		const compacted = compactMediaObject( val.image, depth + 1 )
		if ( objectHasImageUrl( compacted ) ) {
			return { image: compacted }
		}
	}
	const field = nestedUrlField( val )
	if ( field ) {
		const out = { [ field ]: val[ field ] }
		if ( isAttachmentId( val.id ) ) {
			out.id = val.id
		} else if ( isAttachmentId( val.mediaId ) ) {
			out.mediaId = val.mediaId
		}
		if ( typeof val.alt === 'string' ) {
			out.alt = val.alt
		}
		return out
	}
	for ( const key of Object.keys( val ) ) {
		if ( ! BACKGROUND_NEST_KEY_RE.test( key ) ) {
			continue
		}
		const nested = val[ key ]
		if ( looksLikeImageUrl( nested ) ) {
			return { [ key ]: nested }
		}
		if ( objectHasImageUrl( nested ) ) {
			return {
				[ key ]: typeof nested === 'object' && nested && ! Array.isArray( nested )
					? compactMediaObject( nested, depth + 1 )
					: nested,
			}
		}
	}
	return val
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
		const found = urlFromUnknown( bag[ key ] )
		if ( found ) {
			return found
		}
	}
	return ''
}

/**
 * Compact media kind for get-blocks: background vs inline image.
 *
 * @param {Record<string, unknown>} essentials  Picked media bag.
 * @param {string}                  [blockName] Block name (cover is a canvas).
 * @return {string} 'background', 'image', or empty.
 */
export function mediaKindFromEssentials( essentials, blockName = '' ) {
	if ( ! essentials || typeof essentials !== 'object' ) {
		return ''
	}
	if ( ! Object.keys( essentials ).length ) {
		return ''
	}
	if ( /\/cover$/i.test( blockName ) ) {
		return 'background'
	}
	for ( const key of Object.keys( essentials ) ) {
		if ( BACKGROUND_KIND_KEY_RE.test( key ) ) {
			return 'background'
		}
		if ( key === 'style' && objectHasImageUrl( essentials[ key ] ) ) {
			return 'background'
		}
		if ( BACKGROUND_NEST_KEY_RE.test( key ) && objectHasImageUrl( essentials[ key ] ) ) {
			return 'background'
		}
	}
	if ( INLINE_MEDIA_BLOCK_RE.test( blockName ) ) {
		return 'image'
	}
	return 'background'
}

/**
 * Whether live attrs look like a small nested overlay, not a page hero.
 *
 * @param {Record<string, unknown>} attrs Live block attributes.
 * @return {boolean} Whether this looks like a tiny overlay image.
 */
export function looksLikeSmallOverlay( attrs ) {
	if ( ! attrs || typeof attrs !== 'object' ) {
		return false
	}
	const orig = Number( attrs.originalWidth )
	if ( Number.isFinite( orig ) && orig > 0 ) {
		return orig < OVERLAY_PX_MAX
	}
	const unit = Array.isArray( attrs.widthUnit ) ? attrs.widthUnit[ 0 ] : attrs.widthUnit
	if ( unit && unit !== 'px' ) {
		return false
	}
	const custom = Array.isArray( attrs.customWidth )
		? Number( attrs.customWidth[ 0 ] )
		: Number( attrs.customWidth )
	if ( Number.isFinite( custom ) && custom > 0 ) {
		return custom < OVERLAY_PX_MAX
	}
	if ( typeof attrs.width === 'number' && attrs.width > 0 ) {
		return attrs.width < OVERLAY_PX_MAX
	}
	return false
}

/**
 * Cover blocks and any essentials bag whose media lives on a background field.
 *
 * @param {string}                  blockName  Block name.
 * @param {Record<string, unknown>} essentials Picked media bag.
 * @return {boolean} Whether this block is a canvas background surface.
 */
export function isCanvasBackgroundSurface( blockName, essentials ) {
	if ( /\/cover$/i.test( blockName ) ) {
		return true
	}
	return mediaKindFromEssentials( essentials, blockName ) === 'background'
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
 * Map a new URL onto a nested `{ image: url | url[] }` object.
 *
 * @param {unknown} val    Nested attr.
 * @param {string}  reqVal Replacement URL.
 * @return {Record<string, unknown>|null} Partial object or null.
 */
function mapUrlOntoImageField( val, reqVal ) {
	if ( ! val || typeof val !== 'object' || Array.isArray( val ) ) {
		return null
	}
	if ( typeof val.image === 'string' && urlFromUnknown( val.image ) ) {
		return { image: reqVal }
	}
	if ( Array.isArray( val.image ) && urlFromUnknown( val.image ) ) {
		let replaced = false
		const next = val.image.map( item => {
			if ( typeof item === 'string' && urlFromUnknown( item ) ) {
				replaced = true
				return reqVal
			}
			return item
		} )
		if ( ! replaced && next.length ) {
			next[ 0 ] = reqVal
		}
		return { image: next }
	}
	return null
}

/**
 * Map a new URL onto a nested media object (url field, image array, or background).
 *
 * @param {unknown} val     Nested attr.
 * @param {string}  reqVal  Replacement URL.
 * @param {string}  prefix  Path prefix for remapped notes.
 * @param {number}  [depth] Recursion depth.
 * @return {{ partial: unknown, path: string }|null} Mapped partial or null.
 */
function mapUrlOntoValue( val, reqVal, prefix, depth = 0 ) {
	if ( depth > 4 ) {
		return null
	}
	const field = nestedUrlField( val )
	if ( field ) {
		return {
			partial: { [ field ]: reqVal },
			path: `${ prefix }.${ field }`,
		}
	}
	const imagePartial = mapUrlOntoImageField( val, reqVal )
	if ( imagePartial ) {
		return {
			partial: imagePartial,
			path: `${ prefix }.image`,
		}
	}
	if ( ! val || typeof val !== 'object' || Array.isArray( val ) ) {
		return null
	}
	for ( const nestedKey of Object.keys( val ) ) {
		if ( ! BACKGROUND_NEST_KEY_RE.test( nestedKey ) && nestedKey !== 'image' ) {
			continue
		}
		const inner = mapUrlOntoValue( val[ nestedKey ], reqVal, `${ prefix }.${ nestedKey }`, depth + 1 )
		if ( inner ) {
			return {
				partial: { [ nestedKey ]: inner.partial },
				path: inner.path,
			}
		}
	}
	return null
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
 * Map a new attachment id onto a nested media object.
 *
 * @param {unknown} val     Nested attr.
 * @param {unknown} reqVal  Replacement id.
 * @param {string}  prefix  Path prefix for remapped notes.
 * @param {number}  [depth] Recursion depth.
 * @return {{ partial: unknown, path: string }|null} Mapped partial or null.
 */
function mapIdOntoValue( val, reqVal, prefix, depth = 0 ) {
	if ( depth > 4 || ! val || typeof val !== 'object' || Array.isArray( val ) ) {
		return null
	}
	const field = nestedIdField( val )
	if ( field ) {
		return {
			partial: { [ field ]: reqVal },
			path: `${ prefix }.${ field }`,
		}
	}
	for ( const nestedKey of Object.keys( val ) ) {
		if ( ! BACKGROUND_NEST_KEY_RE.test( nestedKey ) && nestedKey !== 'image' ) {
			continue
		}
		const inner = mapIdOntoValue( val[ nestedKey ], reqVal, `${ prefix }.${ nestedKey }`, depth + 1 )
		if ( inner ) {
			return {
				partial: { [ nestedKey ]: inner.partial },
				path: inner.path,
			}
		}
	}
	return null
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
		patch[ key ] = deepMergeObjects( liveVal, deepMergeObjects( prev, value ) )
		return
	}
	patch[ key ] = value
}

/**
 * Deep-merge objects; arrays and primitives from `value` replace.
 *
 * @param {unknown} liveVal Live value.
 * @param {unknown} value   Incoming value.
 * @return {unknown} Merged value.
 */
function deepMergeObjects( liveVal, value ) {
	if (
		value && typeof value === 'object' && ! Array.isArray( value ) &&
		liveVal && typeof liveVal === 'object' && ! Array.isArray( liveVal )
	) {
		const out = { ...liveVal }
		for ( const nestedKey of Object.keys( value ) ) {
			out[ nestedKey ] = deepMergeObjects( liveVal[ nestedKey ], value[ nestedKey ] )
		}
		return out
	}
	return value
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
			const nested = mapUrlOntoValue( val, reqVal, key )
			if ( nested ) {
				return {
					key,
					partial: nested.partial,
					path: nested.path,
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
			const nestedId = mapIdOntoValue( val, reqVal, key )
			if ( nestedId ) {
				return {
					key,
					partial: nestedId.partial,
					path: nestedId.path,
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
 * @param {unknown[]} arr    Live array.
 * @param {string}    oldUrl URL to replace.
 * @param {string}    newUrl Replacement URL.
 * @return {unknown[]|null} Rewritten array or null.
 */
function rewriteUrlInArray( arr, oldUrl, newUrl ) {
	let changed = false
	const next = arr.map( item => {
		if ( typeof item === 'string' && item.includes( oldUrl ) ) {
			changed = true
			return item.split( oldUrl ).join( newUrl )
		}
		return item
	} )
	return changed ? next : null
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
		if ( Array.isArray( current ) ) {
			const rewritten = rewriteUrlInArray( current, oldUrl, newUrl )
			if ( rewritten ) {
				patch[ key ] = rewritten
			}
			continue
		}
		if ( ! current || typeof current !== 'object' ) {
			continue
		}
		const liveObj = liveAttrs[ key ] && typeof liveAttrs[ key ] === 'object' ? liveAttrs[ key ] : {}
		const next = { ...liveObj, ...current }
		let changed = false
		for ( const nestedKey of Object.keys( next ) ) {
			if ( shouldRewriteUrlString( nestedKey, next[ nestedKey ], oldUrl ) ) {
				next[ nestedKey ] = next[ nestedKey ].split( oldUrl ).join( newUrl )
				changed = true
				continue
			}
			if ( Array.isArray( next[ nestedKey ] ) ) {
				const rewritten = rewriteUrlInArray( next[ nestedKey ], oldUrl, newUrl )
				if ( rewritten ) {
					next[ nestedKey ] = rewritten
					changed = true
				}
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
