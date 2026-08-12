/**
 * Block editor helpers for ahentic-browser/* abilities.
 * Uses window.wp when the Gutenberg editor is open.
 *
 * Agent-facing I/O uses opaque refs (b1, b2, …). Real Gutenberg clientIds
 * stay in block-ref-registry memory and are resolved only in this module.
 */

/* eslint-disable camelcase -- Ability I/O matches PHP schema snake_case. */
/* eslint-disable jsdoc/require-returns-description, jsdoc/check-line-alignment -- Compact helpers. */

import { __, sprintf } from '@wordpress/i18n'
import {
	refForClientId,
	refsForClientIds,
	resolveToClientIds,
	syncFromBlocks,
	collectLiveClientIds,
	wipeEditorRefs,
} from './block-ref-registry'
import { pickMediaEssentialAttrs } from './media-essentials'
import { pickLinkEssentialAttrs } from './link-essentials'
import { resolveMovePlacement } from './move-placement'
import { planPostDocumentEdits } from './post-document-edits'
import { looksLikeContentPlaceholder } from './content-placeholder'

const MAX_BLOCKS_DEFAULT = 80
const MAX_BLOCKS_FULL_UNSCOPED_CAP = 8
const MAX_ATTR_CHARS = 2000
const MAX_TYPES_DEFAULT = 100
/** Keep compact get-blocks under the PHP trailing snapshot prompt cap (12k). */
export const GET_BLOCKS_COMPACT_MAX_CHARS = 10000
/** Scoped / include_attributes reads — still hard-capped for the prompt. */
export const GET_BLOCKS_FULL_MAX_CHARS = 14000

const STYLE_ATTR_KEYS = [
	'style',
	'backgroundColor',
	'textColor',
	'gradient',
	'fontSize',
	'fontFamily',
	'borderColor',
]

const CONTENT_ATTR_KEYS = [ 'content', 'text', 'title', 'caption', 'citation', 'label', 'value' ]

/**
 * @return {Object|null}
 */
function getWp() {
	return typeof window !== 'undefined' && window.wp ? window.wp : null
}

/**
 * Detect Gutenberg RichTextData / string-like rich-text store values.
 *
 * @param {mixed} value
 * @return {boolean}
 */
function isRichTextValue( value ) {
	if ( value === null || value === undefined ) {
		return false
	}
	if ( typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean' ) {
		return false
	}
	if ( Array.isArray( value ) ) {
		return false
	}
	if ( typeof value !== 'object' ) {
		return false
	}
	if ( typeof value.toHTMLString === 'function' ) {
		return true
	}
	const ctor = value.constructor && value.constructor.name
	if ( ctor === 'RichTextData' || ctor === 'RichTextValue' ) {
		return true
	}
	// String object / RichTextData extending String.
	if ( Object.prototype.toString.call( value ) === '[object String]' ) {
		return true
	}
	return false
}

/**
 * Convert a rich-text store value to an HTML string for the agent.
 *
 * @param {mixed} value
 * @return {string}
 */
function richTextToHtml( value ) {
	if ( typeof value === 'string' ) {
		return value
	}
	if ( ! value || typeof value !== 'object' ) {
		return ''
	}
	if ( typeof value.toHTMLString === 'function' ) {
		try {
			return String( value.toHTMLString() )
		} catch ( error ) {
			// Fall through.
		}
	}
	if ( typeof value.text === 'string' ) {
		return value.text
	}
	if ( Object.prototype.toString.call( value ) === '[object String]' ) {
		return String( value )
	}
	return ''
}

/**
 * Convert an HTML string into the attribute shape the block registry expects.
 *
 * @param {string} html
 * @param {Object} wp
 * @return {mixed}
 */
function htmlToRichTextAttr( html, wp ) {
	if ( typeof html !== 'string' ) {
		return html
	}
	const richText = wp?.richText
	const RichTextData = richText?.RichTextData
	if ( RichTextData?.fromHTMLString ) {
		try {
			return RichTextData.fromHTMLString( html )
		} catch ( error ) {
			// Fall through.
		}
	}
	if ( richText?.create && RichTextData?.fromRichTextValue ) {
		try {
			return RichTextData.fromRichTextValue( richText.create( { html } ) )
		} catch ( error ) {
			// Fall through.
		}
	}
	// Many WP versions still accept HTML strings for rich-text attrs.
	return html
}

/**
 * Whether a registered attribute definition is rich-text typed.
 *
 * @param {Object} def
 * @return {boolean}
 */
function isRichTextAttrDef( def ) {
	if ( ! def || typeof def !== 'object' ) {
		return false
	}
	return def.type === 'rich-text' || def.source === 'rich-text'
}

/**
 * Normalize string attributes to RichTextData when the block schema expects it.
 *
 * @param {string} blockName
 * @param {Object} attrs
 * @param {Object} wp
 * @return {Object}
 */
function normalizeAttributesForBlock( blockName, attrs, wp ) {
	if ( ! attrs || typeof attrs !== 'object' || Array.isArray( attrs ) ) {
		return attrs
	}
	const type = wp?.blocks?.getBlockType?.( blockName )
	const defs = type?.attributes || {}
	const out = { ...attrs }
	for ( const key of Object.keys( out ) ) {
		const val = out[ key ]
		if ( typeof val !== 'string' ) {
			continue
		}
		const def = defs[ key ]
		if ( isRichTextAttrDef( def ) ) {
			out[ key ] = htmlToRichTextAttr( val, wp )
			continue
		}
		// Heuristic when schema is missing: common content keys on core text blocks.
		if ( ! def && CONTENT_ATTR_KEYS.includes( key ) && String( blockName || '' ).startsWith( 'core/' ) ) {
			out[ key ] = htmlToRichTextAttr( val, wp )
		}
	}
	return out
}

/**
 * Plain-text preview from HTML (for agent matching).
 *
 * @param {string} html
 * @return {string}
 */
function htmlToPlainPreview( html ) {
	return String( html || '' )
		.replace( /<[^>]+>/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim()
		.slice( 0, 200 )
}

/**
 * @return {{ ok: false, error: string, message: string } | { ok: true, wp: Object, select: Function, dispatch: Function }}
 */
function requireEditor() {
	const wp = getWp()
	if ( ! wp?.data?.select || ! wp?.data?.dispatch ) {
		return {
			ok: false,
			error: 'editor_unavailable',
			message: __( 'WordPress data stores are not available on this page.', 'ahentic' ),
		}
	}

	const blockSelect = wp.data.select( 'core/block-editor' )
	const editorSelect = wp.data.select( 'core/editor' )
	if ( ! blockSelect?.getBlocks || ! editorSelect?.getCurrentPostId ) {
		return {
			ok: false,
			error: 'not_block_editor',
			message: __( 'Open a post or page in the block editor to use this ability.', 'ahentic' ),
		}
	}

	return {
		ok: true,
		wp,
		select: wp.data.select,
		dispatch: wp.data.dispatch,
	}
}

/**
 * @param {mixed} value
 * @param {number} depth
 * @return {mixed}
 */
function capValue( value, depth = 0 ) {
	if ( depth > 4 ) {
		return '[truncated]'
	}
	if ( value === null || value === undefined ) {
		return value
	}
	if ( isRichTextValue( value ) ) {
		return capValue( richTextToHtml( value ), depth )
	}
	if ( typeof value === 'string' ) {
		if ( value.length > MAX_ATTR_CHARS ) {
			return `${ value.slice( 0, MAX_ATTR_CHARS - 1 ) }…`
		}
		return value
	}
	if ( typeof value === 'number' || typeof value === 'boolean' ) {
		return value
	}
	if ( Array.isArray( value ) ) {
		return value.slice( 0, 40 ).map( item => capValue( item, depth + 1 ) )
	}
	if ( typeof value === 'object' ) {
		const out = {}
		let count = 0
		for ( const key of Object.keys( value ) ) {
			if ( count >= 40 ) {
				out._ahentic_truncated = true
				break
			}
			out[ key ] = capValue( value[ key ], depth + 1 )
			count += 1
		}
		return out
	}
	return String( value )
}

/**
 * Attach capped primary rich-text HTML onto a compact get-blocks node.
 *
 * @param {Object} node
 * @param {string} key
 * @param {string} html
 * @param {{ full?: boolean }} opts
 */
function attachCompactContentHtml( node, key, html, opts ) {
	if ( opts.full || ! html ) {
		return
	}
	if ( ! node.attributes ) {
		node.attributes = {}
	}
	node.attributes[ key ] = capValue( html )
}

/**
 * @param {Object} block
 * @param {{ remaining: number }} budget
 * @param {{ full?: boolean }} [opts]
 * @return {Object|null}
 */
function serializeBlockTree( block, budget, opts = {} ) {
	if ( ! block || budget.remaining <= 0 ) {
		return null
	}
	budget.remaining -= 1
	const rawAttrs = block.attributes || {}
	const node = {
		ref: refForClientId( block.clientId ),
		name: block.name,
	}
	// Full attribute dump is opt-in: third-party page-builder blocks (e.g. Greenshift
	// rows) carry huge design/attribute payloads that otherwise crowd real text
	// content out of the (size-capped) tool result before the agent ever sees it.
	if ( opts.full ) {
		const attributes = {}
		for ( const key of Object.keys( rawAttrs ) ) {
			const val = rawAttrs[ key ]
			attributes[ key ] = isRichTextValue( val )
				? capValue( richTextToHtml( val ) )
				: capValue( val )
		}
		node.attributes = attributes
	} else if ( Object.keys( rawAttrs ).length ) {
		node.attribute_keys = Object.keys( rawAttrs ).slice( 0, 40 )
		// Compact media identity/alt fields — alt-text and describe-image need
		// url/id without a second include_attributes pass. Known core maps plus
		// key/value heuristics for third-party image blocks.
		const picked = {
			...pickMediaEssentialAttrs( rawAttrs, block.name ),
			...pickLinkEssentialAttrs( rawAttrs ),
		}
		if ( Object.keys( picked ).length ) {
			const essentials = {}
			for ( const key of Object.keys( picked ) ) {
				essentials[ key ] = capValue( picked[ key ] )
			}
			node.attributes = essentials
		}
	}
	// Plain-text preview so the agent can match user phrases (e.g. "Get in touch").
	// Compact mode also ships capped HTML for the primary content attr so internal
	// links / light text edits can patch from one full-document get-blocks (no second
	// refs read just to recover content HTML).
	for ( const key of CONTENT_ATTR_KEYS ) {
		if ( ! ( key in rawAttrs ) ) {
			continue
		}
		const html = isRichTextValue( rawAttrs[ key ] )
			? richTextToHtml( rawAttrs[ key ] )
			: ( typeof rawAttrs[ key ] === 'string' ? rawAttrs[ key ] : '' )
		const preview = htmlToPlainPreview( html )
		if ( preview ) {
			node.preview = preview
			// Tells the agent which attribute key to patch via update-block-attributes
			// without needing a full attributes dump for a simple text edit.
			node.content_attr = key
			attachCompactContentHtml( node, key, html, opts )
			break
		}
	}
	// Fallback for third-party blocks that don't use any of the CONTENT_ATTR_KEYS
	// names (e.g. Greenshift's `textContent`): pick the longest phrase-like string
	// attribute (must contain a space, so short design tokens like "blocksy" or
	// "custom-0" are excluded) so the agent can still find/match this block by text.
	if ( ! node.preview ) {
		let bestPreview = ''
		let bestKey = ''
		let bestHtml = ''
		for ( const key of Object.keys( rawAttrs ) ) {
			const val = rawAttrs[ key ]
			const html = isRichTextValue( val )
				? richTextToHtml( val )
				: ( typeof val === 'string' ? val : '' )
			if ( ! html ) {
				continue
			}
			const preview = htmlToPlainPreview( html )
			if ( preview && preview.includes( ' ' ) && preview.length > bestPreview.length ) {
				bestPreview = preview
				bestKey = key
				bestHtml = html
			}
		}
		if ( bestPreview ) {
			node.preview = bestPreview
			node.content_attr = bestKey
			attachCompactContentHtml( node, bestKey, bestHtml, opts )
		}
	}
	const inner = Array.isArray( block.innerBlocks ) ? block.innerBlocks : []
	if ( inner.length ) {
		node.innerBlocks = []
		for ( const child of inner ) {
			const mapped = serializeBlockTree( child, budget, opts )
			if ( mapped ) {
				node.innerBlocks.push( mapped )
			}
			if ( budget.remaining <= 0 ) {
				node.innerBlocksTruncated = true
				break
			}
		}
	}
	return node
}

/**
 * Collect rich-text-ish strings from a raw blocks payload (pre-normalize).
 *
 * @param {*} raw
 * @return {string[]}
 */
function collectBlockTextSamples( raw ) {
	const out = []
	const visit = node => {
		if ( typeof node === 'string' ) {
			out.push( node )
			return
		}
		if ( Array.isArray( node ) ) {
			node.forEach( visit )
			return
		}
		if ( ! node || typeof node !== 'object' ) {
			return
		}
		const attrs = node.attributes && typeof node.attributes === 'object' ? node.attributes : {}
		for ( const key of [ 'content', 'text', 'caption', 'citation' ] ) {
			const val = attrs[ key ]
			if ( typeof val === 'string' ) {
				out.push( val )
			} else if ( isRichTextValue( val ) ) {
				// WP 7+ stores rich-text attrs as RichTextData after createBlock/getBlocks.
				// Counting only strings made set-blocks report text_chars: 0 → false thin loops.
				out.push( richTextToHtml( val ) )
			}
		}
		if ( Array.isArray( node.innerBlocks ) ) {
			node.innerBlocks.forEach( visit )
		}
	}
	visit( raw )
	return out
}

/**
 * Plain-text character count of a block tree, tags stripped.
 *
 * Mirrors the PHP side so one threshold governs both server and editor writes.
 * Exported for unit tests (Finish Gate text_chars arithmetic).
 *
 * @param {*} raw
 * @return {number}
 */
export function blockTextChars( raw ) {
	return collectBlockTextSamples( raw ).reduce(
		( total, sample ) => total + String( sample ).replace( /<[^>]*>/gu, '' ).trim().length,
		0
	)
}

/**
 * Strip tags/collapse whitespace for serialized HTML / post content.
 *
 * @param {string} html
 * @return {number}
 */
export function plainTextCharsFromHtml( html ) {
	return String( html || '' )
		.replace( /<[^>]*>/gu, ' ' )
		.replace( /\s+/gu, ' ' )
		.trim().length
}

/**
 * Best-effort document text size after an editor write.
 *
 * Prefer live attr walk (incl. RichTextData), then serialize()/edited post content
 * (third-party save() HTML), then the blocks just applied. Take the max so one
 * opaque block schema cannot force text_chars: 0 → false thin loops.
 *
 * @param {Object} args
 * @param {Array}  [args.live]     Blocks from getBlocks() after the write.
 * @param {Array}  [args.applied]  Blocks just inserted/replaced (pre-store shape).
 * @param {Object} [args.wp]       window.wp
 * @param {Function} [args.select] wp.data.select
 * @return {{ text_chars: number, text_chars_source: string }}
 */
export function measureEditorTextChars( {
	live = [],
	applied = [],
	wp = null,
	select = null,
} = {} ) {
	const candidates = []
	const push = ( source, chars ) => {
		const n = Math.max( 0, Number( chars ) || 0 )
		candidates.push( { source, chars: n } )
	}

	push( 'attrs', blockTextChars( live ) )
	if ( Array.isArray( applied ) && applied.length ) {
		push( 'applied', blockTextChars( applied ) )
	}

	try {
		if ( wp?.blocks?.serialize && Array.isArray( live ) && live.length ) {
			push( 'serialize', plainTextCharsFromHtml( wp.blocks.serialize( live ) ) )
		}
	} catch ( error ) {
		// ignore serialize failures (dynamic blocks, etc.)
	}

	try {
		const edited = select?.( 'core/editor' )?.getEditedPostContent?.()
		if ( typeof edited === 'string' && edited.length ) {
			push( 'edited_post', plainTextCharsFromHtml( edited ) )
		}
	} catch ( error ) {
		// ignore
	}

	let best = candidates[ 0 ] || { source: 'attrs', chars: 0 }
	for ( let i = 1; i < candidates.length; i++ ) {
		if ( candidates[ i ].chars > best.chars ) {
			best = candidates[ i ]
		}
	}
	return {
		text_chars: best.chars,
		text_chars_source: best.source,
	}
}

/**
 * Reject bracket/shorthand stubs in insert/replace payloads.
 *
 * @param {*} raw
 * @return {{ ok: false, error: string, message: string, hint: string }|null}
 */
function rejectPlaceholderBlocks( raw ) {
	const samples = collectBlockTextSamples( raw )
	if ( typeof raw === 'string' ) {
		samples.unshift( raw )
	}
	const stub = samples.find( sample => looksLikeContentPlaceholder( sample ) )
	if ( ! stub ) {
		return null
	}
	return {
		ok: false,
		error: 'placeholder_content',
		message: __( 'Blocks payload looks like a placeholder stub (e.g. [full article] or “expanded guide content”). Pass real Gutenberg block objects {name, attributes, innerBlocks} unless the user asked for placeholders.', 'ahentic' ),
		hint: __( 'Rewrite with actual heading/paragraph/list content. For long articles, use set-blocks or insert one section at a time.', 'ahentic' ),
	}
}

/**
 * Whether a string looks like serialized Gutenberg block markup.
 *
 * @param {string} raw
 * @return {boolean}
 */
function looksLikeSerializedBlocks( raw ) {
	return /<!--\s*wp:/u.test( String( raw || '' ) )
}

/**
 * Build live blocks via createBlock (preferred) or parse (serialized markup only).
 *
 * @param {Object|string|Array} raw
 * @param {Object} wp
 * @return {Array}
 */
function normalizeBlocksInput( raw, wp ) {
	if ( typeof raw === 'string' ) {
		if ( ! looksLikeSerializedBlocks( raw ) ) {
			throw new Error(
				__( 'Pass an array of block objects {name, attributes, innerBlocks}. Plain text is not accepted — use createBlock-shaped objects (or <!-- wp:… --> serialized markup).', 'ahentic' )
			)
		}
		if ( ! wp.blocks?.parse ) {
			throw new Error( __( 'wp.blocks.parse is not available.', 'ahentic' ) )
		}
		const parsed = wp.blocks.parse( raw )
		if ( ! parsed.length ) {
			throw new Error( __( 'Serialized markup produced no blocks.', 'ahentic' ) )
		}
		return parsed
	}
	if ( ! Array.isArray( raw ) ) {
		raw = raw ? [ raw ] : []
	}
	if ( ! wp.blocks?.createBlock ) {
		throw new Error( __( 'wp.blocks.createBlock is not available.', 'ahentic' ) )
	}

	const toBlock = item => {
		if ( ! item || typeof item !== 'object' ) {
			throw new Error( __( 'Each block must be an object with a name.', 'ahentic' ) )
		}
		const name = item.name
		if ( ! name || typeof name !== 'string' ) {
			throw new Error( __( 'Each block requires a name (e.g. core/paragraph).', 'ahentic' ) )
		}
		const attrs = item.attributes && typeof item.attributes === 'object' ? item.attributes : {}
		const normalizedAttrs = normalizeAttributesForBlock( name, attrs, wp )
		const inner = Array.isArray( item.innerBlocks )
			? item.innerBlocks.map( toBlock )
			: []
		return wp.blocks.createBlock( name, normalizedAttrs, inner )
	}

	return raw.map( toBlock )
}

/**
 * Collect ref tokens from common ability input aliases.
 *
 * @param {Object} input
 * @param {string[]} keys Preferred keys in order.
 * @return {string|string[]|undefined}
 */
function pickRefInput( input, keys ) {
	for ( const key of keys ) {
		if ( input[ key ] !== undefined && input[ key ] !== null && input[ key ] !== '' ) {
			return input[ key ]
		}
	}
	return undefined
}

/**
 * Resolve agent refs to live clientIds.
 *
 * @param {Object} input
 * @param {Function} select
 * @param {string[]} keys
 * @return {{ clientIds: string[], missing: string[] }}
 */
function resolveInputRefs( input, select, keys ) {
	const blockSelect = select( 'core/block-editor' )
	const getBlock = id => blockSelect.getBlock?.( id )
	const raw = pickRefInput( input, keys )
	if ( raw === undefined ) {
		return { clientIds: [], missing: [] }
	}
	return resolveToClientIds( raw, getBlock )
}

/**
 * Sync registry from the current document root.
 *
 * @param {Function} select
 */
function syncRegistryFromEditor( select ) {
	const blocks = select( 'core/block-editor' ).getBlocks?.() || []
	const postId = select( 'core/editor' )?.getCurrentPostId?.() || 0
	syncFromBlocks( blocks, postId )
}

/**
 * Collect clientIds from a block tree (depth-first).
 *
 * @param {Array} blocks
 * @param {string[]} [out]
 * @return {string[]}
 */
function collectClientIds( blocks, out = [] ) {
	if ( ! Array.isArray( blocks ) ) {
		return out
	}
	for ( const block of blocks ) {
		if ( block?.clientId ) {
			out.push( block.clientId )
		}
		if ( Array.isArray( block?.innerBlocks ) && block.innerBlocks.length ) {
			collectClientIds( block.innerBlocks, out )
		}
	}
	return out
}

/**
 * Confirm applied blocks actually landed in the live editor store.
 *
 * Backgrounded tabs can soft-fail: dispatch returns, applied payload still has
 * text, but getBlocks() never changed — without this check we report ok:true.
 *
 * @param {Array} applied Blocks just written (pre/post createBlock shape).
 * @param {Array} live    Current getBlocks() tree.
 * @return {{ ok: true } | { ok: false, error: string, message: string }}
 */
export function assertBlocksApplied( applied, live ) {
	const appliedList = Array.isArray( applied ) ? applied : []
	if ( ! appliedList.length ) {
		return { ok: true }
	}

	const appliedIds = collectClientIds( appliedList ).filter( Boolean )
	const liveIds = new Set( collectClientIds( Array.isArray( live ) ? live : [] ) )

	if ( appliedIds.length ) {
		const missing = appliedIds.filter( id => ! liveIds.has( id ) )
		if ( missing.length ) {
			return {
				ok: false,
				error: 'write_not_applied',
				message: __( 'The editor did not apply this write (canvas unchanged). Keep this WordPress tab visible and retry — background tabs can drop Gutenberg updates.', 'ahentic' ),
			}
		}
		return { ok: true }
	}

	// createBlock always assigns clientIds in practice; if somehow absent, require a non-empty live tree.
	if ( ! Array.isArray( live ) || live.length === 0 ) {
		return {
			ok: false,
			error: 'write_not_applied',
			message: __( 'The editor did not apply this write (canvas unchanged). Keep this WordPress tab visible and retry — background tabs can drop Gutenberg updates.', 'ahentic' ),
		}
	}
	return { ok: true }
}

/**
 * Stale-ref error payload.
 *
 * @param {string[]} missing
 * @return {Object}
 */
function missingRefsError( missing ) {
	wipeEditorRefs()
	return {
		ok: false,
		error: 'block_not_found',
		missing,
		wiped: true,
		message: __( 'One or more block refs were not found. The ref map was cleared — re-call get-blocks or get-selection and use the fresh ref strings (b1, b2, …) — do not invent refs or paste clientId hashes.', 'ahentic' ),
	}
}

/**
 * Resolve target block clientIds for mutate abilities (refs → selection fallback → errors).
 *
 * @param {Object} input
 * @param {{ select: Function }} ctx
 * @param {Object} [options]
 * @param {boolean} [options.allowSelection=true]
 * @param {string} [options.missingMessage]
 * @param {boolean} [options.requireExisting=false]
 * @return {{ ok: true, clientIds: string[] } | { ok: false, error: string, message?: string, missing?: string[], wiped?: boolean }}
 */
export function resolveTargetClientIds( input, ctx, {
	allowSelection = true,
	missingMessage = __( 'Provide refs or select blocks.', 'ahentic' ),
	requireExisting = false,
} = {} ) {
	const blockSelect = ctx.select( 'core/block-editor' )
	let { clientIds, missing } = resolveInputRefs( input, ctx.select, [
		'refs', 'ref', 'client_ids', 'clientIds', 'client_id', 'clientId',
	] )
	if ( ! clientIds.length && allowSelection ) {
		clientIds = blockSelect.getSelectedBlockClientIds?.() || []
	}
	if ( ! clientIds.length ) {
		return missing.length
			? missingRefsError( missing )
			: {
				ok: false,
				error: 'missing_refs',
				message: missingMessage,
			}
	}
	if ( missing.length ) {
		return missingRefsError( missing )
	}
	if ( requireExisting ) {
		const notFound = clientIds.filter( id => ! blockSelect.getBlock?.( id ) )
		if ( notFound.length ) {
			return missingRefsError( refsForClientIds( notFound ) )
		}
	}
	return { ok: true, clientIds }
}

/**
 * Validate and normalize a blocks payload for insert / replace / set.
 *
 * @param {Object} input
 * @param {{ wp: Object }} ctx
 * @param {Object} [options]
 * @param {string} [options.emptyMessage]
 * @return {{ ok: true, blocks: Array } | { ok: false, error: string, message?: string, hint?: string }}
 */
export function prepareBlocksPayload( input, ctx, {
	emptyMessage = __( 'Blocks cannot be empty.', 'ahentic' ),
} = {} ) {
	const stub = rejectPlaceholderBlocks( input.blocks )
	if ( stub ) {
		return stub
	}
	let blocks
	try {
		blocks = normalizeBlocksInput( input.blocks, ctx.wp )
	} catch ( error ) {
		return {
			ok: false,
			error: 'invalid_blocks',
			message: error?.message || __( 'Invalid blocks payload.', 'ahentic' ),
		}
	}
	if ( ! blocks.length ) {
		return {
			ok: false,
			error: 'empty_blocks',
			message: emptyMessage,
		}
	}
	const parsedStub = rejectPlaceholderBlocks(
		blocks.map( block => ( {
			name: block.name,
			attributes: block.attributes || {},
			innerBlocks: block.innerBlocks || [],
		} ) )
	)
	if ( parsedStub ) {
		return parsedStub
	}
	return { ok: true, blocks }
}

/**
 * Resolve target clientIds for scope helpers (refs in, clientIds out).
 *
 * @param {Object} input
 * @param {Function} select
 * @param {string} defaultScope
 * @return {string[]}
 */
function resolveScopeClientIds( input, select, defaultScope = 'selection' ) {
	const { clientIds: explicit } = resolveInputRefs( input, select, [
		'refs', 'ref', 'client_ids', 'clientIds', 'client_id', 'clientId',
	] )
	if ( explicit.length ) {
		return explicit
	}
	const blockSelect = select( 'core/block-editor' )
	const selected = blockSelect.getSelectedBlockClientIds?.() || []
	const scope = input.scope || ( selected.length ? 'selection' : defaultScope )
	if ( scope === 'selection' && selected.length ) {
		return selected
	}
	return collectLiveClientIds( blockSelect.getBlocks() || [] )
}

export function getEditorState() {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const editor = ctx.select( 'core/editor' )
	const blockEditor = ctx.select( 'core/block-editor' )
	const blocks = blockEditor.getBlocks?.() || []
	syncFromBlocks( blocks, editor.getCurrentPostId?.() || 0 )
	const selected = blockEditor.getSelectedBlockClientIds?.() || []
	const postType = editor.getCurrentPostType?.() ?? ''
	const postId = editor.getCurrentPostId?.() ?? null
	let templatePartId = ''
	if ( postType === 'wp_template_part' ) {
		if ( typeof postId === 'string' && postId.includes( '//' ) ) {
			templatePartId = postId
		} else {
			const slug = editor.getEditedPostAttribute?.( 'slug' ) || ''
			const theme = editor.getEditedPostAttribute?.( 'theme' ) || ''
			if ( theme && slug ) {
				templatePartId = `${ theme }//${ slug }`
			}
		}
	}
	return {
		ok: true,
		is_block_editor: true,
		post_id: postId,
		post_type: postType,
		title: editor.getEditedPostAttribute?.( 'title' ) ?? '',
		status: editor.getEditedPostAttribute?.( 'status' ) ?? '',
		is_dirty: Boolean( editor.isEditedPostDirty?.() ),
		is_saving: Boolean( editor.isSavingPost?.() ),
		is_new: Boolean( editor.isEditedPostNew?.() ),
		blocks_count: blocks.length,
		selected_refs: refsForClientIds( selected ),
		template_part_id: templatePartId,
	}
}

/**
 * Shrink a get-blocks payload until JSON fits maxChars (valid JSON, keep refs).
 *
 * Drop optional fields first (attribute_keys, then large attribute values),
 * then trim trailing blocks. Works for any block type — not vendor-specific.
 *
 * @param {Object} payload getBlocks result.
 * @param {number} maxChars Soft char budget for JSON.stringify(payload).
 * @return {Object}
 */
export function enforceGetBlocksByteBudget( payload, maxChars ) {
	if ( ! payload || typeof payload !== 'object' ) {
		return payload
	}
	const max = Math.max( 500, Number( maxChars ) || GET_BLOCKS_COMPACT_MAX_CHARS )
	let out = payload
	const size = () => JSON.stringify( out ).length
	if ( size() <= max ) {
		return out
	}

	out = {
		...out,
		truncated: true,
		note: out.note || __( 'Block tree capped by size; re-read with refs or root_ref for details.', 'ahentic' ),
		blocks: Array.isArray( out.blocks ) ? out.blocks.map( stripAttributeKeysDeep ) : out.blocks,
	}
	if ( size() <= max ) {
		return out
	}

	out = {
		...out,
		blocks: Array.isArray( out.blocks ) ? out.blocks.map( slimAttributesDeep ) : out.blocks,
	}
	if ( size() <= max ) {
		return out
	}

	while ( Array.isArray( out.blocks ) && out.blocks.length > 1 && size() > max ) {
		out = {
			...out,
			truncated: true,
			blocks: out.blocks.slice( 0, -1 ),
			count: out.blocks.length - 1,
			refs: ( out.blocks.slice( 0, -1 ) ).map( node => node && node.ref ).filter( Boolean ),
		}
	}

	if ( size() > max && Array.isArray( out.blocks ) && out.blocks.length === 1 ) {
		const only = slimAttributesDeep( stripAttributeKeysDeep( out.blocks[ 0 ] ) )
		out = {
			...out,
			truncated: true,
			blocks: [ {
				ref: only.ref,
				name: only.name,
				preview: only.preview,
				content_attr: only.content_attr,
				attributes: only.attributes,
			} ],
			count: 1,
			refs: only.ref ? [ only.ref ] : [],
		}
	}

	return out
}

/**
 * @param {Object|null} node
 * @return {Object|null}
 */
function stripAttributeKeysDeep( node ) {
	if ( ! node || typeof node !== 'object' ) {
		return node
	}
	const next = { ...node }
	delete next.attribute_keys
	if ( Array.isArray( next.innerBlocks ) ) {
		next.innerBlocks = next.innerBlocks.map( stripAttributeKeysDeep )
	}
	return next
}

/**
 * Keep only small essential attribute values (urls, short labels, ids).
 *
 * @param {Object|null} node
 * @return {Object|null}
 */
function slimAttributesDeep( node ) {
	if ( ! node || typeof node !== 'object' ) {
		return node
	}
	const next = { ...node }
	delete next.attribute_keys
	if ( next.attributes && typeof next.attributes === 'object' ) {
		const slim = {}
		for ( const key of Object.keys( next.attributes ) ) {
			const val = next.attributes[ key ]
			if ( typeof val === 'string' && val.length > 300 ) {
				continue
			}
			if ( val !== null && typeof val === 'object' ) {
				continue
			}
			slim[ key ] = val
		}
		next.attributes = slim
	}
	if ( Array.isArray( next.innerBlocks ) ) {
		next.innerBlocks = next.innerBlocks.map( slimAttributesDeep )
	}
	return next
}

export function getBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const blockSelect = ctx.select( 'core/block-editor' )
	syncRegistryFromEditor( ctx.select )

	// Scoped by refs: return only those blocks (not the whole document).
	const scopeRaw = pickRefInput( input, [ 'refs', 'ref', 'client_ids', 'clientIds', 'client_id', 'clientId' ] )
	if ( scopeRaw !== undefined ) {
		const resolved = resolveToClientIds( scopeRaw, id => blockSelect.getBlock?.( id ) )
		if ( resolved.missing.length || ! resolved.clientIds.length ) {
			return missingRefsError( resolved.missing.length ? resolved.missing : [ String( scopeRaw ) ] )
		}
		// Default attributes on for scoped reads so update-block-attributes can see exact HTML.
		const includeAttributes = ( input.include_attributes === undefined && input.includeAttributes === undefined )
			? true
			: Boolean( input.include_attributes || input.includeAttributes )
		const requestedMaxBlocks = Number( input.max_blocks || input.maxBlocks || MAX_BLOCKS_DEFAULT )
		const maxBlocks = Math.max( 1, Math.min( 200, requestedMaxBlocks ) )
		const budget = { remaining: maxBlocks }
		const tree = []
		let truncated = false
		for ( const clientId of resolved.clientIds ) {
			const block = blockSelect.getBlock?.( clientId )
			if ( ! block ) {
				continue
			}
			const node = serializeBlockTree( block, budget, { full: includeAttributes } )
			if ( node ) {
				tree.push( node )
			}
			if ( budget.remaining <= 0 ) {
				truncated = true
				break
			}
		}
		return enforceGetBlocksByteBudget( {
			ok: true,
			scoped: true,
			count: tree.length,
			truncated,
			blocks: tree,
			refs: tree.map( node => node.ref ).filter( Boolean ),
		}, GET_BLOCKS_FULL_MAX_CHARS )
	}

	let rootId = ''
	const rootToken = pickRefInput( input, [ 'root_ref', 'rootRef', 'root_client_id', 'rootClientId' ] )
	if ( rootToken ) {
		const resolved = resolveToClientIds( rootToken, id => blockSelect.getBlock?.( id ) )
		if ( resolved.missing.length || ! resolved.clientIds.length ) {
			return missingRefsError( resolved.missing.length ? resolved.missing : [ String( rootToken ) ] )
		}
		rootId = resolved.clientIds[ 0 ]
	}

	const includeAttributes = Boolean( input.include_attributes || input.includeAttributes )
	const requestedMaxBlocks = Number( input.max_blocks || input.maxBlocks || MAX_BLOCKS_DEFAULT )
	// Full attributes on an untargeted (no root_ref) call can still blow past the
	// prompt's tool-result size limit on pages with large/third-party block trees —
	// clamp hard so a mis-scoped include_attributes request can't reproduce that.
	// Pair include_attributes with root_ref to inspect one block's full attributes.
	const maxBlocksCap = ( includeAttributes && ! rootId ) ? MAX_BLOCKS_FULL_UNSCOPED_CAP : 200
	const maxBlocks = Math.max( 1, Math.min( maxBlocksCap, requestedMaxBlocks ) )
	const blocks = rootId
		? ( blockSelect.getBlocks?.( rootId ) || [] )
		: ( blockSelect.getBlocks?.() || [] )
	// Note: deliberately NOT re-syncing refs from just `blocks` here. syncFromBlocks()
	// treats whatever list it's given as "the whole live document" and drops refs for
	// any clientId not in it — since `blocks` is only the root's subtree when root_ref
	// is set, that previously wiped every other on-page block's ref on each drill-down
	// call, forcing them to be renumbered (and re-sent in full) on the next listing.
	// syncRegistryFromEditor() above already synced the full document; serializeBlockTree()
	// lazily assigns refs (via refForClientId) for anything it walks, so no extra sync is needed.

	const budget = { remaining: maxBlocks }
	const tree = []
	let truncated = false
	for ( const block of blocks ) {
		const node = serializeBlockTree( block, budget, { full: includeAttributes } )
		if ( node ) {
			tree.push( node )
		}
		if ( budget.remaining <= 0 ) {
			truncated = true
			break
		}
	}
	return enforceGetBlocksByteBudget( {
		ok: true,
		count: tree.length,
		truncated,
		blocks: tree,
		...( includeAttributes && ! rootId && truncated
			? {
				note: __( 'include_attributes without root_ref is capped to a few blocks to avoid an oversized result. Pass root_ref (from this or an earlier get-blocks/get-selection call) to see full attributes for one block/subtree in detail.', 'ahentic' ),
			}
			: {} ),
	}, includeAttributes ? GET_BLOCKS_FULL_MAX_CHARS : GET_BLOCKS_COMPACT_MAX_CHARS )
}

export function getSelection() {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	syncRegistryFromEditor( ctx.select )
	const blockSelect = ctx.select( 'core/block-editor' )
	const clientIds = blockSelect.getSelectedBlockClientIds?.() || []
	const blocks = clientIds
		.map( id => blockSelect.getBlock?.( id ) )
		.filter( Boolean )
		.map( block => serializeBlockTree( block, { remaining: 20 }, { full: true } ) )
		.filter( Boolean )

	let selectedText = ''
	try {
		const view = document.defaultView || window
		const sel = view.getSelection?.()
		if ( sel && String( sel ) ) {
			selectedText = String( sel ).slice( 0, 500 )
		}
	} catch ( error ) {
		// Ignore.
	}

	return {
		ok: true,
		refs: refsForClientIds( clientIds ),
		count: blocks.length,
		blocks,
		selected_text: selectedText,
		has_selection: clientIds.length > 0,
	}
}

/**
 * @param {string} hex
 * @return {[number, number, number]|null}
 */
function parseHex( hex ) {
	const raw = String( hex || '' ).replace( '#', '' ).trim()
	if ( ! /^(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test( raw ) ) {
		return null
	}
	const full = raw.length === 3
		? raw.split( '' ).map( c => c + c ).join( '' )
		: raw
	return [
		parseInt( full.slice( 0, 2 ), 16 ),
		parseInt( full.slice( 2, 4 ), 16 ),
		parseInt( full.slice( 4, 6 ), 16 ),
	]
}

/**
 * @param {[number, number, number]} a
 * @param {[number, number, number]} b
 * @return {number}
 */
function colorDistance( a, b ) {
	const dr = a[ 0 ] - b[ 0 ]
	const dg = a[ 1 ] - b[ 1 ]
	const db = a[ 2 ] - b[ 2 ]
	return ( dr * dr ) + ( dg * dg ) + ( db * db )
}

/**
 * @param {Array} colors
 * @return {Array<{ slug: string, color: string, rgb: number[] }>}
 */
function normalizePalette( colors ) {
	const out = []
	;( Array.isArray( colors ) ? colors : [] ).forEach( ( entry, index ) => {
		if ( typeof entry === 'string' ) {
			const rgb = parseHex( entry )
			if ( rgb ) {
				out.push( {
					slug: `color-${ index + 1 }`, color: entry.startsWith( '#' ) ? entry : `#${ entry }`, rgb,
				} )
			}
			return
		}
		if ( entry && typeof entry === 'object' ) {
			const color = entry.color || entry.hex || ''
			const rgb = parseHex( color )
			if ( rgb ) {
				out.push( {
					slug: String( entry.slug || `color-${ index + 1 }` ),
					color: color.startsWith( '#' ) ? color : `#${ color }`,
					rgb,
				} )
			}
		}
	} )
	return out
}

/**
 * @param {string} hex
 * @param {Array} palette
 * @return {{ slug: string, color: string }|null}
 */
function nearestPaletteColor( hex, palette ) {
	const rgb = parseHex( hex )
	if ( ! rgb || ! palette.length ) {
		return null
	}
	let best = null
	let bestDist = Infinity
	for ( const swatch of palette ) {
		const dist = colorDistance( rgb, swatch.rgb )
		if ( dist < bestDist ) {
			bestDist = dist
			best = swatch
		}
	}
	return best ? { slug: best.slug, color: best.color } : null
}

/**
 * Guess a core block name from a third-party name.
 *
 * @param {string} name
 * @return {string}
 */
function guessCoreTarget( name ) {
	const lower = String( name || '' ).toLowerCase()
	const leaf = lower.includes( '/' ) ? lower.split( '/' ).pop() : lower
	if ( /heading|title|headline/.test( leaf ) ) {
		return 'core/heading'
	}
	if ( /paragraph|text|rich-?text/.test( leaf ) ) {
		return 'core/paragraph'
	}
	if ( /image|img|figure/.test( leaf ) ) {
		return 'core/image'
	}
	if ( /button/.test( leaf ) ) {
		return 'core/buttons'
	}
	if ( /list/.test( leaf ) ) {
		return 'core/list'
	}
	if ( /quote|blockquote/.test( leaf ) ) {
		return 'core/quote'
	}
	if ( /column/.test( leaf ) ) {
		return 'core/columns'
	}
	if ( /spacer|divider|separator/.test( leaf ) ) {
		return 'core/separator'
	}
	if ( /video/.test( leaf ) ) {
		return 'core/video'
	}
	if ( /gallery/.test( leaf ) ) {
		return 'core/gallery'
	}
	return 'core/group'
}

/**
 * Pull human-readable text from attributes.
 *
 * @param {Object} attributes
 * @return {string}
 */
function extractTextFromAttributes( attributes ) {
	const attrs = attributes && typeof attributes === 'object' ? attributes : {}
	const preferred = [ 'content', 'title', 'text', 'heading', 'description', 'label', 'caption', 'value' ]
	for ( const key of preferred ) {
		if ( typeof attrs[ key ] === 'string' && attrs[ key ].trim() ) {
			return attrs[ key ]
		}
	}
	for ( const value of Object.values( attrs ) ) {
		if ( typeof value === 'string' && value.trim() && value.length < 2000 && /[a-zA-Z]/.test( value ) ) {
			return value
		}
	}
	return ''
}

/**
 * Soft leaf-name synonyms for picking among registered types (not plugin allowlists).
 */
const CONVERT_LEAF_SYNONYMS = {
	paragraph: [ 'text', 'paragraph', 'rich-text', 'richtext' ],
	text: [ 'paragraph', 'text', 'rich-text' ],
	heading: [ 'heading', 'title', 'headline' ],
	title: [ 'heading', 'title', 'headline' ],
	image: [ 'image', 'img', 'figure', 'photo' ],
	list: [ 'list', 'icon-list', 'bullets', 'checklist' ],
	'list-item': [ 'list-item', 'icon-list-item', 'item' ],
	button: [ 'button', 'btn' ],
	buttons: [ 'buttons', 'button-group' ],
	quote: [ 'quote', 'blockquote', 'testimonial' ],
	separator: [ 'separator', 'divider', 'spacer' ],
}

/**
 * @param {string} name
 * @return {string}
 */
function blockLeafName( name ) {
	const s = String( name || '' )
	return s.includes( '/' ) ? s.split( '/' ).pop() : s
}

/**
 * Parse convert-blocks target: "core", "stackable", or "stackable/heading".
 *
 * @param {string|undefined} raw
 * @return {{ kind: string, namespace: string, exact: string|null, label: string }}
 */
function parseConvertTarget( raw ) {
	const trimmed = String( raw || 'core' ).trim().toLowerCase()
	const value = trimmed || 'core'
	if ( value.includes( '/' ) ) {
		const namespace = value.split( '/' )[ 0 ] || 'core'
		return {
			kind: 'exact', namespace, exact: value, label: value,
		}
	}
	return {
		kind: 'namespace', namespace: value, exact: null, label: value,
	}
}

/**
 * @param {string} name
 * @param {{ exact: string|null, namespace: string }} target
 * @return {boolean}
 */
function nameMatchesConvertTarget( name, target ) {
	const n = String( name || '' )
	if ( target.exact ) {
		return n === target.exact
	}
	return n.startsWith( `${ target.namespace }/` )
}

/**
 * @param {string[]} names
 * @param {string} blockName
 * @param {{ requireAffinity?: boolean }} [opts]
 * @return {string|null}
 */
function pickAmongBlockNames( names, blockName, opts = {} ) {
	if ( ! names.length ) {
		return null
	}
	const leaf = blockLeafName( blockName )
	const exactLeaf = names.find( n => blockLeafName( n ) === leaf )
	if ( exactLeaf ) {
		return exactLeaf
	}
	const syns = CONVERT_LEAF_SYNONYMS[ leaf ] || [ leaf ]
	for ( const syn of syns ) {
		const hit = names.find( n => blockLeafName( n ) === syn )
		if ( hit ) {
			return hit
		}
	}
	if ( opts.requireAffinity ) {
		return null
	}
	return names[ 0 ]
}

/**
 * @param {Object} block
 * @param {Object} wp
 * @return {string[]}
 */
function possibleTransformNames( block, wp ) {
	const list = wp.blocks?.getPossibleBlockTransformations?.( [ block ] ) || []
	return list
		.map( entry => ( typeof entry === 'string' ? entry : entry?.name ) )
		.filter( Boolean )
}

/**
 * @param {Object} block
 * @param {Object} wp
 * @param {{ exact: string|null, namespace: string }} target
 * @return {string|null}
 */
function pickTransformDestination( block, wp, target ) {
	const names = possibleTransformNames( block, wp )
	if ( target.exact ) {
		return names.includes( target.exact ) ? target.exact : null
	}
	const inNs = names.filter( n => n.startsWith( `${ target.namespace }/` ) )
	return pickAmongBlockNames( inNs, block.name )
}

/**
 * @param {Object} wp
 * @param {string} namespace
 * @return {string[]}
 */
function registeredNamesInNamespace( wp, namespace ) {
	return ( wp.blocks?.getBlockTypes?.() || [] )
		.map( type => type.name )
		.filter( name => String( name ).startsWith( `${ namespace }/` ) )
}

/**
 * @param {Object} block
 * @param {Object} wp
 * @param {{ exact: string|null, namespace: string }} target
 * @return {string|null}
 */
function pickRegisteredDestination( block, wp, target ) {
	if ( target.exact ) {
		return wp.blocks?.getBlockType?.( target.exact ) ? target.exact : null
	}
	// Heuristic createBlock only when leaf names align — never invent paragraph→heading ports.
	return pickAmongBlockNames(
		registeredNamesInNamespace( wp, target.namespace ),
		block.name,
		{ requireAffinity: true }
	)
}

/**
 * @param {Object|null} type
 * @return {string|null}
 */
function firstContentAttrKey( type ) {
	const attrs = type?.attributes
	if ( ! attrs || typeof attrs !== 'object' ) {
		return null
	}
	for ( const key of CONTENT_ATTR_KEYS ) {
		if ( key in attrs ) {
			return key
		}
	}
	for ( const key of Object.keys( attrs ) ) {
		if ( isRichTextAttrDef( attrs[ key ] ) ) {
			return key
		}
	}
	return null
}

/**
 * Best-effort attribute port when no Gutenberg transform exists.
 *
 * @param {Object} fromBlock
 * @param {string} toName
 * @param {Object} wp
 * @return {Object}
 */
function buildHeuristicConvertAttributes( fromBlock, toName, wp ) {
	const type = wp.blocks?.getBlockType?.( toName )
	const fromAttrs = fromBlock.attributes || {}
	const out = {}
	if ( type?.attributes ) {
		for ( const key of Object.keys( type.attributes ) ) {
			if ( ! Object.prototype.hasOwnProperty.call( fromAttrs, key ) ) {
				continue
			}
			const val = fromAttrs[ key ]
			out[ key ] = isRichTextValue( val ) ? richTextToHtml( val ) : val
		}
	}
	const text = extractTextFromAttributes( fromAttrs )
	const contentKey = firstContentAttrKey( type )
	if ( text && contentKey && ( out[ contentKey ] === undefined || out[ contentKey ] === '' ) ) {
		const plain = text.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim()
		out[ contentKey ] = plain || text
	}
	if ( fromAttrs.level !== null && fromAttrs.level !== undefined && type?.attributes ) {
		if ( 'level' in type.attributes && out.level === undefined ) {
			out.level = fromAttrs.level
		}
		if ( 'textTag' in type.attributes && out.textTag === undefined ) {
			out.textTag = `h${ Math.min( 6, Math.max( 1, Number( fromAttrs.level ) || 2 ) ) }`
		}
	}
	const media = pickMediaEssentialAttrs( fromAttrs, fromBlock.name )
	if ( type?.attributes ) {
		const keys = Object.keys( type.attributes )
		if ( media.url ) {
			for ( const key of [ 'url', 'imageUrl', 'src', 'mediaUrl' ] ) {
				if ( keys.includes( key ) && out[ key ] === undefined ) {
					out[ key ] = media.url
				}
			}
		}
		if ( media.alt !== null && media.alt !== undefined && media.alt !== '' ) {
			for ( const key of [ 'alt', 'imageAlt' ] ) {
				if ( keys.includes( key ) && out[ key ] === undefined ) {
					out[ key ] = media.alt
				}
			}
		}
		if ( media.id !== null && media.id !== undefined ) {
			for ( const key of [ 'id', 'imageId', 'mediaId' ] ) {
				if ( keys.includes( key ) && out[ key ] === undefined ) {
					out[ key ] = media.id
				}
			}
		}
	}
	return out
}

/**
 * @param {Object} block
 * @param {Object} wp
 * @param {string} destName
 * @return {{ block: Object, method: string }|null}
 */
function switchBlockToType( block, wp, destName ) {
	if ( ! wp.blocks?.switchToBlockType ) {
		return null
	}
	try {
		const switched = wp.blocks.switchToBlockType( block, destName )
		if ( Array.isArray( switched ) && switched.length ) {
			return { block: switched[ 0 ], method: 'switchToBlockType' }
		}
		if ( switched && switched.name ) {
			return { block: switched, method: 'switchToBlockType' }
		}
	} catch ( error ) {
		// Fall through.
	}
	return null
}

/**
 * Convert one third-party block toward core (legacy heuristic path).
 *
 * @param {Object} block
 * @param {Object} wp
 * @return {{ block: Object, method: string }|null}
 */
function convertOneBlockTowardCore( block, wp ) {
	if ( ! block?.name ) {
		return null
	}
	if ( String( block.name ).startsWith( 'core/' ) ) {
		return null
	}

	const target = guessCoreTarget( block.name )
	const switched = switchBlockToType( block, wp, target )
	if ( switched ) {
		return switched
	}

	const text = extractTextFromAttributes( block.attributes )
	const plain = text.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim()

	if ( target === 'core/heading' ) {
		const level = Number( block.attributes?.level ) || 2
		return {
			block: wp.blocks.createBlock( 'core/heading', {
				content: plain || 'Heading',
				level: Math.min( 6, Math.max( 1, level ) ),
			} ),
			method: 'heuristic',
		}
	}
	if ( target === 'core/image' ) {
		return {
			block: wp.blocks.createBlock( 'core/image', {
				url: block.attributes?.url || block.attributes?.src || '',
				alt: block.attributes?.alt || '',
				caption: block.attributes?.caption || '',
			} ),
			method: 'heuristic',
		}
	}
	if ( target === 'core/buttons' ) {
		const label = plain || 'Button'
		const button = wp.blocks.createBlock( 'core/button', { text: label } )
		return {
			block: wp.blocks.createBlock( 'core/buttons', {}, [ button ] ),
			method: 'heuristic',
		}
	}
	if ( target === 'core/group' ) {
		const inner = plain
			? [ wp.blocks.createBlock( 'core/paragraph', { content: plain } ) ]
			: []
		return {
			block: wp.blocks.createBlock( 'core/group', {}, inner ),
			method: 'heuristic',
		}
	}

	return {
		block: wp.blocks.createBlock( target === 'core/paragraph' ? 'core/paragraph' : target, {
			content: plain,
		} ),
		method: 'heuristic',
	}
}

/**
 * Convert one block toward a namespace or exact block name.
 *
 * Prefers Gutenberg registered transforms; falls back to content heuristics.
 *
 * @param {Object} block
 * @param {Object} wp
 * @param {{ exact: string|null, namespace: string }} target
 * @return {{ block?: Object, method?: string, skip?: string, available?: string[] }}
 */
function convertOneBlockToward( block, wp, target ) {
	if ( ! block?.name ) {
		return { skip: 'invalid_block' }
	}
	if ( nameMatchesConvertTarget( block.name, target ) ) {
		return { skip: 'already_target' }
	}

	const transformDest = pickTransformDestination( block, wp, target )
	if ( transformDest ) {
		const switched = switchBlockToType( block, wp, transformDest )
		if ( switched ) {
			return switched
		}
	}

	// Legacy third-party → core when no exact target was requested.
	if ( target.namespace === 'core' && ! target.exact && ! String( block.name ).startsWith( 'core/' ) ) {
		const legacy = convertOneBlockTowardCore( block, wp )
		if ( legacy?.block ) {
			return legacy
		}
	}

	const dest = pickRegisteredDestination( block, wp, target )
	if ( ! dest || ! wp.blocks?.createBlock ) {
		return {
			skip: 'no_transform',
			available: possibleTransformNames( block, wp ).slice( 0, 20 ),
		}
	}

	try {
		const attrs = buildHeuristicConvertAttributes( block, dest, wp )
		const created = wp.blocks.createBlock( dest, attrs, block.innerBlocks || [] )
		return { block: created, method: 'heuristic' }
	} catch ( error ) {
		return {
			skip: 'no_mapping',
			available: possibleTransformNames( block, wp ).slice( 0, 20 ),
			error: error?.message || 'create_failed',
		}
	}
}

/**
 * Whether an attribute key is useful for convert/content discovery (slim schemas).
 *
 * @param {string} key
 * @param {Object} def
 * @return {boolean}
 */
function isConvertRelevantAttr( key, def ) {
	if ( CONTENT_ATTR_KEYS.includes( key ) ) {
		return true
	}
	if ( isRichTextAttrDef( def ) ) {
		return true
	}
	if ( /^(url|src|href|alt|id|caption|level|ordered|values|textTag|imageUrl|imageId|imageAlt|mediaUrl|mediaId)$/i.test( key ) ) {
		return true
	}
	const source = def?.source
	return source === 'html' || source === 'text' || source === 'rich-text' || source === 'attribute'
}

export function getBlockType( input = {} ) {
	const wp = getWp()
	let name = typeof input.name === 'string' ? input.name.trim() : ''
	const refToken = String(
		input.ref || input.client_id || input.clientId || ''
	).trim()

	// Convenience: resolve ref → block name (agents sometimes pass the wrong field).
	if ( ! name && refToken && wp?.data?.select ) {
		const select = wp.data.select
		const { clientIds } = resolveToClientIds( refToken, id => select( 'core/block-editor' )?.getBlock?.( id ) )
		const block = clientIds[ 0 ]
			? select( 'core/block-editor' )?.getBlock?.( clientIds[ 0 ] )
			: null
		if ( block?.name ) {
			name = block.name
		}
	}

	if ( ! name ) {
		return {
			ok: false,
			error: 'missing_name',
			message: __( 'A block name is required (e.g. core/heading). Prefer {name}. For core/* blocks, skip get-block-type and edit attributes directly.', 'ahentic' ),
		}
	}
	if ( ! wp?.blocks?.getBlockType ) {
		return {
			ok: false,
			error: 'blocks_api_unavailable',
			message: __( 'wp.blocks is not available on this page.', 'ahentic' ),
		}
	}
	const type = wp.blocks.getBlockType( name )
	if ( ! type ) {
		return {
			ok: false,
			error: 'unknown_block_type',
			message: sprintf(
				/* translators: %s: block type name */
				__( 'Block type “%s” is not registered.', 'ahentic' ),
				name
			),
			name,
		}
	}

	const fields = String( input.fields || 'full' ).trim().toLowerCase()
	const slim = fields === 'convert' || fields === 'content'
	const variations = slim ? [] : ( wp.blocks.getBlockVariations?.( name ) || [] )
	const attributeSchema = {}
	const richTextKeys = []
	const attrs = type.attributes || {}
	for ( const key of Object.keys( attrs ) ) {
		const def = attrs[ key ] || {}
		if ( slim && ! isConvertRelevantAttr( key, def ) ) {
			continue
		}
		const richText = isRichTextAttrDef( def )
		if ( richText ) {
			richTextKeys.push( key )
		}
		attributeSchema[ key ] = {
			type: def.type || null,
			default: slim ? undefined : capValue( def.default ),
			enum: def.enum || undefined,
			source: def.source || undefined,
			selector: slim ? undefined : ( def.selector || undefined ),
			attribute: def.attribute || undefined,
			rich_text: richText || undefined,
			hint: richText
				? __( 'Pass an HTML string; Ahentic converts to rich-text for the editor store.', 'ahentic' )
				: undefined,
		}
	}

	const isCore = String( name ).startsWith( 'core/' )

	return {
		ok: true,
		name: type.name,
		title: type.title || '',
		category: type.category || '',
		description: slim ? '' : ( type.description || '' ),
		parent: type.parent || null,
		ancestor: slim ? null : ( type.ancestor || null ),
		supports: slim ? undefined : capValue( type.supports || {} ),
		attributes: attributeSchema,
		rich_text_attributes: richTextKeys,
		fields: slim ? fields : 'full',
		hint: slim
			? __( 'Slim convert/content schema — content and media attrs only. Prefer ahentic-browser/convert-blocks with target for library conversion.', 'ahentic' )
			: ( isCore
				? __( 'Core block — prefer update-block-attributes / replace-blocks with known attrs; get-block-type is usually unnecessary.', 'ahentic' )
				: __( 'Study attributes/supports before patching third-party blocks. For library conversion prefer convert-blocks { target }.', 'ahentic' ) ),
		variations: variations.slice( 0, 20 ).map( variation => ( {
			name: variation.name,
			title: variation.title,
			isDefault: Boolean( variation.isDefault ),
		} ) ),
		example: slim ? null : ( type.example ? capValue( type.example ) : null ),
		is_dynamic: Boolean( type.save === null || type.save === undefined ),
	}
}

export function listBlockTypes( input = {} ) {
	const wp = getWp()
	if ( ! wp?.blocks?.getBlockTypes ) {
		return {
			ok: false,
			error: 'blocks_api_unavailable',
			message: __( 'wp.blocks is not available on this page.', 'ahentic' ),
		}
	}
	const namespace = typeof input.namespace === 'string' ? input.namespace.trim().replace( /\/$/, '' ) : ''
	const limit = Math.max( 1, Math.min( 300, Number( input.limit || MAX_TYPES_DEFAULT ) ) )
	let types = wp.blocks.getBlockTypes() || []
	if ( namespace ) {
		const prefix = `${ namespace }/`
		types = types.filter( type => String( type.name || '' ).startsWith( prefix ) || type.name === namespace )
	}
	const items = types.slice( 0, limit ).map( type => ( {
		name: type.name,
		title: type.title || '',
		category: type.category || '',
		parent: type.parent || null,
	} ) )
	return {
		ok: true,
		namespace: namespace || null,
		count: items.length,
		truncated: types.length > items.length,
		total_matching: types.length,
		types: items,
	}
}

export function focusBlock( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const { clientIds, missing } = resolveInputRefs( input, ctx.select, [
		'ref', 'client_id', 'clientId',
	] )
	if ( ! clientIds.length ) {
		return missing.length
			? missingRefsError( missing )
			: {
				ok: false, error: 'missing_ref', message: __( 'ref is required (from get-blocks / get-selection).', 'ahentic' ),
			}
	}
	const clientId = clientIds[ 0 ]
	const block = ctx.select( 'core/block-editor' ).getBlock?.( clientId )
	if ( ! block ) {
		return missingRefsError( [ String( pickRefInput( input, [ 'ref', 'client_id', 'clientId' ] ) || clientId ) ] )
	}
	ctx.dispatch( 'core/block-editor' ).selectBlock( clientId )
	try {
		const el = document.querySelector( `[data-block="${ clientId }"]` )
		el?.scrollIntoView?.( { behavior: 'smooth', block: 'center' } )
	} catch ( error ) {
		// Ignore scroll failures.
	}
	return {
		ok: true, ref: refForClientId( clientId ), name: block.name,
	}
}

export function updateBlockAttributes( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const { clientIds, missing: missingTokens } = resolveInputRefs( input, ctx.select, [
		'refs', 'ref', 'client_id', 'clientId', 'client_ids', 'clientIds',
	] )
	const attributes = input.attributes
	if ( ! clientIds.length ) {
		return missingTokens.length
			? missingRefsError( missingTokens )
			: {
				ok: false, error: 'missing_ref', message: __( 'ref (or refs) is required.', 'ahentic' ),
			}
	}
	if ( ! attributes || typeof attributes !== 'object' || Array.isArray( attributes ) ) {
		return {
			ok: false, error: 'missing_attributes', message: __( 'attributes object is required.', 'ahentic' ),
		}
	}
	for ( const key of [ 'content', 'text', 'caption', 'citation' ] ) {
		if ( typeof attributes[ key ] === 'string' && looksLikeContentPlaceholder( attributes[ key ] ) ) {
			return {
				ok: false,
				error: 'placeholder_content',
				message: __( 'Attribute looks like a placeholder stub. Pass the real text unless the user asked for placeholders.', 'ahentic' ),
				hint: __( 'Rewrite with the actual prose/HTML for this attribute.', 'ahentic' ),
			}
		}
	}
	const blockSelect = ctx.select( 'core/block-editor' )
	const dispatch = ctx.dispatch( 'core/block-editor' )
	const updated = []
	const missing = [ ...missingTokens ]
	for ( const clientId of clientIds ) {
		const block = blockSelect.getBlock?.( clientId )
		if ( ! block ) {
			missing.push( refForClientId( clientId ) || clientId )
			continue
		}
		const normalized = normalizeAttributesForBlock( block.name, attributes, ctx.wp )
		dispatch.updateBlockAttributes( clientId, normalized )
		updated.push( clientId )
	}
	return {
		ok: updated.length > 0,
		updated_refs: refsForClientIds( updated ),
		missing: missing.length ? missing : undefined,
		attributes: capValue( attributes ),
		...( missing.length
			? {
				message: __( 'One or more block refs were not found. Re-call get-blocks or get-selection and use the fresh refs.', 'ahentic' ),
			}
			: {} ),
	}
}

export function replaceBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const targets = resolveTargetClientIds( input, ctx, {
		missingMessage: __( 'Provide refs (from get-blocks) or select blocks.', 'ahentic' ),
		requireExisting: true,
	} )
	if ( ! targets.ok ) {
		return targets
	}
	const payload = prepareBlocksPayload( input, ctx, {
		emptyMessage: __( 'Replacement blocks cannot be empty.', 'ahentic' ),
	} )
	if ( ! payload.ok ) {
		return payload
	}
	const { clientIds } = targets
	const { blocks } = payload
	const blockSelect = ctx.select( 'core/block-editor' )
	const replacedRefs = refsForClientIds( clientIds )
	ctx.dispatch( 'core/block-editor' ).replaceBlocks( clientIds, blocks )
	syncRegistryFromEditor( ctx.select )
	const live = blockSelect.getBlocks?.() || []
	const appliedCheck = assertBlocksApplied( blocks, live )
	if ( ! appliedCheck.ok ) {
		return appliedCheck
	}
	const measured = measureEditorTextChars( {
		live,
		applied: blocks,
		wp: ctx.wp,
		select: ctx.select,
	} )
	return {
		ok: true,
		replaced_refs: replacedRefs,
		inserted_count: blocks.length,
		inserted_names: blocks.map( block => block.name ),
		inserted_refs: refsForClientIds( blocks.map( block => block.clientId ).filter( Boolean ) ),
		text_chars: measured.text_chars,
		text_chars_source: measured.text_chars_source,
	}
}

/**
 * Replace the entire document block tree (no target refs required).
 *
 * @param {Object} input
 * @return {Object}
 */
export function setBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const payload = prepareBlocksPayload( input, ctx )
	if ( ! payload.ok ) {
		return payload
	}
	const { blocks } = payload

	const blockSelect = ctx.select( 'core/block-editor' )
	const dispatch = ctx.dispatch( 'core/block-editor' )
	if ( typeof dispatch.resetBlocks === 'function' ) {
		dispatch.resetBlocks( blocks )
	} else {
		const existing = blockSelect.getBlocks?.() || []
		const existingIds = existing.map( block => block.clientId ).filter( Boolean )
		if ( existingIds.length ) {
			dispatch.replaceBlocks( existingIds, blocks )
		} else {
			dispatch.insertBlocks( blocks, 0 )
		}
	}
	syncRegistryFromEditor( ctx.select )
	const live = blockSelect.getBlocks?.() || []
	const appliedCheck = assertBlocksApplied( blocks, live )
	if ( ! appliedCheck.ok ) {
		return appliedCheck
	}
	const measured = measureEditorTextChars( {
		live,
		applied: blocks,
		wp: ctx.wp,
		select: ctx.select,
	} )
	return {
		ok: true,
		inserted_count: live.length,
		inserted_names: live.map( block => block.name ),
		inserted_refs: refsForClientIds( live.map( block => block.clientId ) ),
		text_chars: measured.text_chars,
		text_chars_source: measured.text_chars_source,
	}
}

export function insertBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const payload = prepareBlocksPayload( input, ctx, {
		emptyMessage: __( 'No blocks to insert.', 'ahentic' ),
	} )
	if ( ! payload.ok ) {
		return payload
	}
	const { blocks } = payload

	const blockSelect = ctx.select( 'core/block-editor' )
	const dispatch = ctx.dispatch( 'core/block-editor' )
	let afterId = ''
	const afterToken = pickRefInput( input, [ 'after_ref', 'afterRef', 'after_client_id', 'afterClientId' ] )
	if ( afterToken ) {
		const resolved = resolveToClientIds( afterToken, id => blockSelect.getBlock?.( id ) )
		if ( resolved.missing.length || ! resolved.clientIds.length ) {
			return missingRefsError( resolved.missing.length ? resolved.missing : [ String( afterToken ) ] )
		}
		afterId = resolved.clientIds[ 0 ]
	}

	let rootClientId = ''
	const rootToken = pickRefInput( input, [ 'root_ref', 'rootRef', 'root_client_id', 'rootClientId' ] )
	if ( rootToken ) {
		const resolved = resolveToClientIds( rootToken, id => blockSelect.getBlock?.( id ) )
		if ( resolved.missing.length || ! resolved.clientIds.length ) {
			return missingRefsError( resolved.missing.length ? resolved.missing : [ String( rootToken ) ] )
		}
		rootClientId = resolved.clientIds[ 0 ]
	}

	let index = input.index

	if ( afterId ) {
		const root = blockSelect.getBlockRootClientId?.( afterId )
		rootClientId = root || ''
		const order = blockSelect.getBlockOrder?.( rootClientId ) || []
		const afterIndex = order.indexOf( afterId )
		index = afterIndex >= 0 ? afterIndex + 1 : order.length
	} else if ( index === undefined || index === null || index === '' ) {
		const order = blockSelect.getBlockOrder?.( rootClientId || undefined ) || []
		index = order.length
	} else {
		index = Number( index )
	}

	dispatch.insertBlocks( blocks, index, rootClientId || undefined )
	syncRegistryFromEditor( ctx.select )
	const live = blockSelect.getBlocks?.() || []
	const appliedCheck = assertBlocksApplied( blocks, live )
	if ( ! appliedCheck.ok ) {
		return appliedCheck
	}
	const measured = measureEditorTextChars( {
		live,
		applied: blocks,
		wp: ctx.wp,
		select: ctx.select,
	} )
	return {
		ok: true,
		inserted_count: blocks.length,
		inserted_names: blocks.map( block => block.name ),
		inserted_refs: refsForClientIds( blocks.map( block => block.clientId ).filter( Boolean ) ),
		index,
		root_ref: rootClientId ? refForClientId( rootClientId ) : null,
		text_chars: measured.text_chars,
		text_chars_source: measured.text_chars_source,
	}
}

export function duplicateBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const targets = resolveTargetClientIds( input, ctx )
	if ( ! targets.ok ) {
		return targets
	}
	const { clientIds } = targets
	ctx.dispatch( 'core/block-editor' ).duplicateBlocks( clientIds )
	syncRegistryFromEditor( ctx.select )
	return { ok: true, duplicated_refs: refsForClientIds( clientIds ) }
}

export function deleteBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const targets = resolveTargetClientIds( input, ctx )
	if ( ! targets.ok ) {
		return targets
	}
	const { clientIds } = targets
	const blockSelect = ctx.select( 'core/block-editor' )
	const deletedRefs = refsForClientIds( clientIds )
	ctx.dispatch( 'core/block-editor' ).removeBlocks( clientIds )
	syncRegistryFromEditor( ctx.select )
	const live = blockSelect.getBlocks?.() || []
	const measured = measureEditorTextChars( {
		live,
		wp: ctx.wp,
		select: ctx.select,
	} )
	return {
		ok: true,
		deleted_refs: deletedRefs,
		deleted_count: deletedRefs.length,
		text_chars: measured.text_chars,
		text_chars_source: measured.text_chars_source,
	}
}

export function moveBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const targets = resolveTargetClientIds( input, ctx, {
		allowSelection: false,
		missingMessage: __( 'refs is required.', 'ahentic' ),
	} )
	if ( ! targets.ok ) {
		return targets
	}
	const { clientIds } = targets
	const blockSelect = ctx.select( 'core/block-editor' )
	const fromRoot = blockSelect.getBlockRootClientId?.( clientIds[ 0 ] ) || ''
	const placement = resolveMovePlacement( input, {
		resolveRef: token => {
			const resolved = resolveToClientIds( token, id => blockSelect.getBlock?.( id ) )
			if ( resolved.missing.length || ! resolved.clientIds.length ) {
				return null
			}
			return resolved.clientIds[ 0 ]
		},
		getRootClientId: id => blockSelect.getBlockRootClientId?.( id ) || '',
		getBlockOrder: root => blockSelect.getBlockOrder?.( root || undefined ) || [],
		defaultRootClientId: fromRoot,
	} )
	if ( ! placement.ok ) {
		if ( placement.error === 'missing_refs' && placement.missing?.length ) {
			return missingRefsError( placement.missing )
		}
		return {
			ok: false,
			error: placement.error,
			message: placement.message,
		}
	}
	const toRoot = placement.toRoot
	ctx.dispatch( 'core/block-editor' ).moveBlocksToPosition(
		clientIds,
		fromRoot || undefined,
		toRoot || undefined,
		placement.index
	)
	syncRegistryFromEditor( ctx.select )
	return {
		ok: true,
		moved_refs: refsForClientIds( clientIds ),
		index: placement.index,
		root_ref: toRoot ? refForClientId( toRoot ) : null,
	}
}

/**
 * @param {Object} attributes
 * @return {Object}
 */
function stripStyleAttributes( attributes ) {
	const next = { ...( attributes || {} ) }
	for ( const key of STYLE_ATTR_KEYS ) {
		if ( key in next ) {
			delete next[ key ]
		}
	}
	if ( next.className && typeof next.className === 'string' ) {
		next.className = next.className
			.split( /\s+/ )
			.filter( token => token && ! /^(has-|is-style-)/.test( token ) )
			.join( ' ' )
	}
	return next
}

export function normalizeBlockStyles( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const clientIds = resolveScopeClientIds( input, ctx.select, 'all' )
	const blockSelect = ctx.select( 'core/block-editor' )
	const dispatch = ctx.dispatch( 'core/block-editor' )
	let updated = 0
	for ( const clientId of clientIds ) {
		const block = blockSelect.getBlock?.( clientId )
		if ( ! block ) {
			continue
		}
		const before = block.attributes || {}
		const cleaned = stripStyleAttributes( before )
		const changed = STYLE_ATTR_KEYS.some( key => key in before ) ||
			( before.className || '' ) !== ( cleaned.className || '' )
		if ( ! changed ) {
			continue
		}
		if ( typeof dispatch.updateBlock === 'function' ) {
			dispatch.updateBlock( clientId, { attributes: cleaned } )
		} else {
			const patch = { ...cleaned }
			for ( const key of STYLE_ATTR_KEYS ) {
				if ( key in before && ! ( key in cleaned ) ) {
					patch[ key ] = undefined
				}
			}
			dispatch.updateBlockAttributes( clientId, patch )
		}
		updated += 1
	}
	return {
		ok: true, updated, refs: refsForClientIds( clientIds.slice( 0, 100 ) ),
	}
}

export function restyleBlocksToPalette( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const palette = normalizePalette( input.colors )
	if ( ! palette.length ) {
		return {
			ok: false, error: 'missing_colors', message: __( 'Provide colors: [{ slug, color }] or hex strings.', 'ahentic' ),
		}
	}
	const clientIds = resolveScopeClientIds( input, ctx.select, 'selection' )
	const blockSelect = ctx.select( 'core/block-editor' )
	const dispatch = ctx.dispatch( 'core/block-editor' )
	let updated = 0

	for ( const clientId of clientIds ) {
		const block = blockSelect.getBlock?.( clientId )
		if ( ! block ) {
			continue
		}
		const attrs = block.attributes || {}
		const patch = {}
		const style = attrs.style && typeof attrs.style === 'object' ? { ...attrs.style } : {}
		const colorStyle = style.color && typeof style.color === 'object' ? { ...style.color } : {}

		const mapHexField = ( hex, slugKey, styleKey ) => {
			const nearest = nearestPaletteColor( hex, palette )
			if ( ! nearest ) {
				return
			}
			patch[ slugKey ] = nearest.slug
			if ( styleKey ) {
				colorStyle[ styleKey ] = nearest.color
			}
		}

		if ( typeof attrs.textColor === 'string' && attrs.textColor.startsWith( '#' ) ) {
			mapHexField( attrs.textColor, 'textColor', 'text' )
		}
		if ( typeof attrs.backgroundColor === 'string' && attrs.backgroundColor.startsWith( '#' ) ) {
			mapHexField( attrs.backgroundColor, 'backgroundColor', 'background' )
		}
		if ( typeof colorStyle.text === 'string' && colorStyle.text.startsWith( '#' ) ) {
			mapHexField( colorStyle.text, 'textColor', 'text' )
		}
		if ( typeof colorStyle.background === 'string' && colorStyle.background.startsWith( '#' ) ) {
			mapHexField( colorStyle.background, 'backgroundColor', 'background' )
		}

		if ( Object.keys( colorStyle ).length ) {
			style.color = colorStyle
			patch.style = style
		}
		if ( Object.keys( patch ).length ) {
			dispatch.updateBlockAttributes( clientId, patch )
			updated += 1
		}
	}

	return {
		ok: true,
		updated,
		palette: palette.map( swatch => ( { slug: swatch.slug, color: swatch.color } ) ),
		refs: refsForClientIds( clientIds.slice( 0, 100 ) ),
	}
}

export function convertBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const target = parseConvertTarget( input.target )
	const dryRun = Boolean( input.dry_run || input.dryRun )
	const blockSelect = ctx.select( 'core/block-editor' )
	const dispatch = ctx.dispatch( 'core/block-editor' )
	let { clientIds } = resolveInputRefs( input, ctx.select, [
		'refs', 'ref', 'client_ids', 'clientIds', 'client_id', 'clientId',
	] )
	if ( ! clientIds.length ) {
		const selected = blockSelect.getSelectedBlockClientIds?.() || []
		if ( input.scope === 'all' || ! selected.length ) {
			clientIds = collectLiveClientIds( blockSelect.getBlocks() || [] ).filter( id => {
				const block = blockSelect.getBlock?.( id )
				return block && ! nameMatchesConvertTarget( block.name, target )
			} )
		} else {
			clientIds = selected
		}
	}

	const converted = []
	const skipped = []
	const failed = []

	// Convert deepest-first so parents are not invalidated mid-walk.
	const blocks = clientIds
		.map( id => blockSelect.getBlock?.( id ) )
		.filter( Boolean )
		.sort( ( a, b ) => {
			const da = ( blockSelect.getBlockParents?.( a.clientId ) || [] ).length
			const db = ( blockSelect.getBlockParents?.( b.clientId ) || [] ).length
			return db - da
		} )

	for ( const block of blocks ) {
		const ref = refForClientId( block.clientId )
		try {
			const result = convertOneBlockToward( block, ctx.wp, target )
			if ( result?.skip ) {
				const entry = {
					ref,
					name: block.name,
					reason: result.skip,
				}
				if ( result.available?.length ) {
					entry.available_transforms = result.available
				}
				skipped.push( entry )
				continue
			}
			if ( ! result?.block ) {
				skipped.push( {
					ref, name: block.name, reason: 'no_mapping',
				} )
				continue
			}
			if ( ! dryRun ) {
				dispatch.replaceBlocks( [ block.clientId ], [ result.block ] )
			}
			converted.push( {
				from: block.name,
				to: result.block.name,
				method: result.method,
				ref,
			} )
		} catch ( error ) {
			failed.push( {
				ref,
				name: block.name,
				reason: error?.message || 'convert_failed',
			} )
		}
	}

	if ( ! dryRun ) {
		syncRegistryFromEditor( ctx.select )
	}
	return {
		ok: true,
		target: target.label,
		dry_run: dryRun,
		converted_count: converted.length,
		skipped_count: skipped.length,
		failed_count: failed.length,
		converted,
		skipped: skipped.slice( 0, 50 ),
		failed: failed.slice( 0, 50 ),
	}
}

export function auditAccessibility() {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	syncRegistryFromEditor( ctx.select )
	const blocks = ctx.select( 'core/block-editor' ).getBlocks?.() || []
	const issues = []
	let lastHeadingLevel = 0

	const walk = ( list, path = [] ) => {
		for ( const block of list || [] ) {
			const name = block.name || ''
			const attrs = block.attributes || {}
			const here = [ ...path, name ]
			const ref = refForClientId( block.clientId )

			if ( name === 'core/heading' ) {
				const level = Number( attrs.level ) || 2
				const raw = isRichTextValue( attrs.content ) ? richTextToHtml( attrs.content ) : attrs.content
				const text = htmlToPlainPreview( typeof raw === 'string' ? raw : String( raw || '' ) )
				if ( ! text ) {
					issues.push( {
						type: 'empty_heading',
						ref,
						message: __( 'Heading block has no text.', 'ahentic' ),
						path: here,
					} )
				}
				if ( lastHeadingLevel && level > lastHeadingLevel + 1 ) {
					issues.push( {
						type: 'heading_skip',
						ref,
						message: sprintf(
							/* translators: %1$d: previous heading level, %2$d: current heading level */
							__( 'Heading level skips from h%1$d to h%2$d.', 'ahentic' ),
							lastHeadingLevel,
							level
						),
						path: here,
					} )
				}
				lastHeadingLevel = level
			}

			if ( name === 'core/image' ) {
				const alt = typeof attrs.alt === 'string' ? attrs.alt.trim() : ''
				if ( ! alt && ! attrs.url ) {
					// Ignore empty placeholder images.
				} else if ( ! alt ) {
					const attachmentId = Number( attrs.id )
					issues.push( {
						type: 'missing_alt',
						ref,
						attachment_id: Number.isFinite( attachmentId ) && attachmentId > 0 ? attachmentId : null,
						message: __( 'Image is missing alt text.', 'ahentic' ),
						path: here,
						hint: attachmentId > 0
							? __( 'Set library alt with ahentic/update-media, then ahentic-browser/update-block-attributes { alt } on this ref so the canvas matches.', 'ahentic' )
							: __( 'Set alt via ahentic-browser/update-block-attributes on this ref.', 'ahentic' ),
					} )
				}
			}

			if ( name === 'core/button' ) {
				const raw = isRichTextValue( attrs.text )
					? richTextToHtml( attrs.text )
					: ( attrs.text || attrs.content )
				const text = htmlToPlainPreview(
					isRichTextValue( raw ) ? richTextToHtml( raw ) : String( raw || '' )
				)
				if ( ! text ) {
					issues.push( {
						type: 'empty_button',
						ref,
						message: __( 'Button has no accessible label.', 'ahentic' ),
						path: here,
					} )
				}
			}

			// Third-party heading-like empty text.
			if ( ! name.startsWith( 'core/' ) && /heading|title/.test( name ) ) {
				const text = extractTextFromAttributes( attrs ).replace( /<[^>]+>/g, '' ).trim()
				if ( ! text ) {
					issues.push( {
						type: 'empty_heading_like',
						ref,
						message: sprintf(
							/* translators: %s: block name */
							__( 'Block %s looks like a heading but has no text.', 'ahentic' ),
							name
						),
						path: here,
					} )
				}
			}

			if ( block.innerBlocks?.length ) {
				walk( block.innerBlocks, here )
			}
		}
	}

	walk( blocks )

	return {
		ok: true,
		issue_count: issues.length,
		issues: issues.slice( 0, 100 ),
		truncated: issues.length > 100,
	}
}

/**
 * Update title / excerpt / slug on the open post (editor store only — does not save).
 *
 * @param {{ title?: string, excerpt?: string, slug?: string, post_id?: number }} input
 * @return {Object} Ability result.
 */
export function updatePostDocument( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}

	const editor = ctx.select( 'core/editor' )
	const currentPostId = editor.getCurrentPostId?.() ?? null

	if ( input.post_id !== undefined && input.post_id !== null && input.post_id !== '' ) {
		const expected = Number( input.post_id )
		const openId = Number( currentPostId )
		if ( ! Number.isFinite( expected ) || expected <= 0 ) {
			return {
				ok: false,
				error: 'invalid_post_id',
				message: __( 'post_id must be a positive integer when provided.', 'ahentic' ),
			}
		}
		if ( ! Number.isFinite( openId ) || openId !== expected ) {
			return {
				ok: false,
				error: 'post_mismatch',
				message: sprintf(
					/* translators: %1$s: open post id or "none", %2$d: expected post id */
					__( 'Open editor post (%1$s) does not match post_id %2$d. Use ahentic/update-post when the target post is not open in the block editor.', 'ahentic' ),
					currentPostId ?? 'none',
					expected
				),
				post_id: currentPostId,
				expected_post_id: expected,
			}
		}
	}

	const planned = planPostDocumentEdits( input )
	if ( ! planned.ok ) {
		return planned
	}

	const postType = editor.getCurrentPostType?.() || ''
	if ( postType === 'wp_template_part' && currentPostId !== null && currentPostId !== undefined ) {
		ctx.dispatch( 'core' ).editEntityRecord( 'postType', 'wp_template_part', currentPostId, planned.edits )
	} else {
		ctx.dispatch( 'core/editor' ).editPost( planned.edits )
	}

	const applied = {}
	for ( const key of Object.keys( planned.edits ) ) {
		const value = editor.getEditedPostAttribute?.( key )
		applied[ key ] = typeof value === 'string' ? value : planned.edits[ key ]
	}

	return {
		ok: true,
		...applied,
		updated_fields: Object.keys( planned.edits ),
		post_id: currentPostId,
		post_type: postType,
	}
}

/**
 * Thin alias of updatePostDocument for title-only edits (legacy ability name).
 *
 * @param {{ title?: string, post_id?: number }} input
 * @return {Object} Ability result.
 */
export function updatePostTitle( input = {} ) {
	return updatePostDocument( { title: input.title, post_id: input.post_id } )
}

/**
 * Set or clear the featured image on the open post (editor store only — does not save).
 *
 * @param {{ attachment_id?: number, post_id?: number }} input
 * @return {Object} Ability result.
 */
export function setFeaturedImage( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}

	const editor = ctx.select( 'core/editor' )
	const currentPostId = editor.getCurrentPostId?.() ?? null

	if ( input.post_id !== undefined && input.post_id !== null && input.post_id !== '' ) {
		const expected = Number( input.post_id )
		const openId = Number( currentPostId )
		if ( ! Number.isFinite( expected ) || expected <= 0 ) {
			return {
				ok: false,
				error: 'invalid_post_id',
				message: __( 'post_id must be a positive integer when provided.', 'ahentic' ),
			}
		}
		if ( ! Number.isFinite( openId ) || openId !== expected ) {
			return {
				ok: false,
				error: 'post_mismatch',
				message: sprintf(
					/* translators: %1$s: open post id or "none", %2$d: expected post id */
					__( 'Open editor post (%1$s) does not match post_id %2$d. Use ahentic/set-featured-image when the target post is not open in the block editor.', 'ahentic' ),
					currentPostId ?? 'none',
					expected
				),
				post_id: currentPostId,
				expected_post_id: expected,
			}
		}
	}

	if ( input.attachment_id === undefined || input.attachment_id === null || input.attachment_id === '' ) {
		return {
			ok: false,
			error: 'invalid_attachment_id',
			message: __( 'attachment_id is required (use 0 to clear the featured image).', 'ahentic' ),
		}
	}

	const attachmentId = Number( input.attachment_id )
	if ( ! Number.isFinite( attachmentId ) || attachmentId < 0 || ! Number.isInteger( attachmentId ) ) {
		return {
			ok: false,
			error: 'invalid_attachment_id',
			message: __( 'attachment_id must be a non-negative integer (0 clears the featured image).', 'ahentic' ),
		}
	}

	ctx.dispatch( 'core/editor' ).editPost( { featured_media: attachmentId } )

	const appliedRaw = editor.getEditedPostAttribute?.( 'featured_media' )
	const applied = appliedRaw === undefined || appliedRaw === null
		? attachmentId
		: Number( appliedRaw )

	return {
		ok: true,
		featured_media: Number.isFinite( applied ) ? applied : attachmentId,
		post_id: currentPostId,
		cleared: attachmentId === 0,
	}
}

/**
 * Normalize a list of term refs to positive integer IDs.
 *
 * @param {*} refs Term id list or single id.
 * @return {{ ok: true, ids: number[] } | { ok: false, error: string, message: string }}
 */
function normalizeTermIdList( refs ) {
	if ( refs === undefined || refs === null || refs === '' ) {
		return { ok: true, ids: [] }
	}
	const list = Array.isArray( refs ) ? refs : [ refs ]
	const ids = []
	for ( const ref of list ) {
		if ( ref === undefined || ref === null || ref === '' ) {
			continue
		}
		const id = Number( ref )
		if ( ! Number.isFinite( id ) || id <= 0 || ! Number.isInteger( id ) ) {
			return {
				ok: false,
				error: 'invalid_term_id',
				message: __( 'Term refs must be positive integer IDs when using ahentic-browser/set-post-terms (resolve names via list-terms/create-term first).', 'ahentic' ),
			}
		}
		ids.push( id )
	}
	return { ok: true, ids: [ ...new Set( ids ) ] }
}

/**
 * Set categories/tags/custom taxonomy terms on the open editor document.
 *
 * Replace-per-taxonomy: present key = full set; omit = unchanged; [] clears.
 * Prefer term IDs from list-terms / create-term.
 *
 * @param {{ categories?: number[], tags?: number[], tax_input?: Object, post_id?: number }} input
 * @return {Object} Ability result.
 */
export function setPostTerms( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}

	const editor = ctx.select( 'core/editor' )
	const currentPostId = editor.getCurrentPostId?.() ?? null

	if ( input.post_id !== undefined && input.post_id !== null && input.post_id !== '' ) {
		const expected = Number( input.post_id )
		const openId = Number( currentPostId )
		if ( ! Number.isFinite( expected ) || expected <= 0 ) {
			return {
				ok: false,
				error: 'invalid_post_id',
				message: __( 'post_id must be a positive integer when provided.', 'ahentic' ),
			}
		}
		if ( ! Number.isFinite( openId ) || openId !== expected ) {
			return {
				ok: false,
				error: 'post_mismatch',
				message: sprintf(
					/* translators: %1$s: open post id or "none", %2$d: expected post id */
					__( 'Open editor post (%1$s) does not match post_id %2$d. Use ahentic/update-post with categories/tags/tax_input when the target post is not open in the block editor.', 'ahentic' ),
					currentPostId ?? 'none',
					expected
				),
				post_id: currentPostId,
				expected_post_id: expected,
			}
		}
	}

	const edits = {}
	const applied = {}

	if ( Object.prototype.hasOwnProperty.call( input, 'categories' ) ) {
		const normalized = normalizeTermIdList( input.categories )
		if ( ! normalized.ok ) {
			return normalized
		}
		edits.categories = normalized.ids
		applied.category = normalized.ids
	}

	if ( Object.prototype.hasOwnProperty.call( input, 'tags' ) ) {
		const normalized = normalizeTermIdList( input.tags )
		if ( ! normalized.ok ) {
			return normalized
		}
		edits.tags = normalized.ids
		applied.post_tag = normalized.ids
	}

	if ( Object.prototype.hasOwnProperty.call( input, 'tax_input' ) ) {
		const taxInput = input.tax_input
		if ( taxInput === null || typeof taxInput !== 'object' || Array.isArray( taxInput ) ) {
			return {
				ok: false,
				error: 'invalid_tax_input',
				message: __( 'tax_input must be an object mapping taxonomy slug → list of term IDs.', 'ahentic' ),
			}
		}
		for ( const [ taxonomy, refs ] of Object.entries( taxInput ) ) {
			const key = String( taxonomy || '' ).trim()
			if ( ! key ) {
				continue
			}
			const normalized = normalizeTermIdList( refs )
			if ( ! normalized.ok ) {
				return normalized
			}
			// Editor store uses REST attribute names: categories / tags / custom rest_base.
			const attr = key === 'category' ? 'categories' : ( key === 'post_tag' ? 'tags' : key )
			edits[ attr ] = normalized.ids
			applied[ key ] = normalized.ids
		}
	}

	if ( Object.keys( edits ).length === 0 ) {
		return {
			ok: false,
			error: 'nothing_to_update',
			message: __( 'Provide at least one of: categories, tags, or tax_input.', 'ahentic' ),
		}
	}

	ctx.dispatch( 'core/editor' ).editPost( edits )

	return {
		ok: true,
		post_id: currentPostId,
		terms_applied: applied,
		edits,
	}
}

export async function savePost() {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const editor = ctx.select( 'core/editor' )
	const dispatch = ctx.dispatch( 'core/editor' )
	const postType = editor.getCurrentPostType?.() || ''
	const postId = editor.getCurrentPostId?.() ?? null
	if ( editor.isSavingPost?.() ) {
		return {
			ok: false, error: 'already_saving', message: __( 'A save is already in progress.', 'ahentic' ),
		}
	}
	try {
		if ( postType === 'wp_template_part' && postId !== null && postId !== undefined ) {
			await ctx.dispatch( 'core' ).saveEditedEntityRecord( 'postType', 'wp_template_part', postId )
		} else {
			await dispatch.savePost()
		}
	} catch ( error ) {
		return {
			ok: false,
			error: 'save_failed',
			message: error?.message || __( 'Failed to save the post.', 'ahentic' ),
		}
	}
	return {
		ok: true,
		post_id: editor.getCurrentPostId?.() ?? null,
		post_type: postType,
		is_dirty: Boolean( editor.isEditedPostDirty?.() ),
		status: editor.getEditedPostAttribute?.( 'status' ) ?? '',
	}
}
