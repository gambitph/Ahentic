/**
 * Block editor helpers for ahentic-browser/* abilities.
 * Uses window.wp when the Gutenberg editor is open.
 *
 * Agent-facing I/O uses opaque refs (b1, b2, …). Real Gutenberg clientIds
 * stay in block-ref-registry memory and are resolved only in this module.
 */

/* eslint-disable camelcase -- Ability I/O matches PHP schema snake_case. */
/* eslint-disable jsdoc/require-returns-description, jsdoc/check-line-alignment -- Compact helpers. */

import {
	refForClientId,
	refsForClientIds,
	resolveToClientIds,
	syncFromBlocks,
	collectLiveClientIds,
	wipeEditorRefs,
} from './block-ref-registry'

const MAX_BLOCKS_DEFAULT = 80
const MAX_ATTR_CHARS = 2000
const MAX_TYPES_DEFAULT = 100

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
			message: 'WordPress data stores are not available on this page.',
		}
	}

	const blockSelect = wp.data.select( 'core/block-editor' )
	const editorSelect = wp.data.select( 'core/editor' )
	if ( ! blockSelect?.getBlocks || ! editorSelect?.getCurrentPostId ) {
		return {
			ok: false,
			error: 'not_block_editor',
			message: 'Open a post or page in the block editor to use this ability.',
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
 * @param {Object} block
 * @param {{ remaining: number }} budget
 * @return {Object|null}
 */
function serializeBlockTree( block, budget ) {
	if ( ! block || budget.remaining <= 0 ) {
		return null
	}
	budget.remaining -= 1
	const rawAttrs = block.attributes || {}
	const attributes = {}
	for ( const key of Object.keys( rawAttrs ) ) {
		const val = rawAttrs[ key ]
		attributes[ key ] = isRichTextValue( val )
			? capValue( richTextToHtml( val ) )
			: capValue( val )
	}
	const node = {
		ref: refForClientId( block.clientId ),
		name: block.name,
		attributes,
	}
	// Plain-text preview so the agent can match user phrases (e.g. "Get in touch").
	for ( const key of CONTENT_ATTR_KEYS ) {
		if ( !( key in rawAttrs ) ) {
			continue
		}
		const html = isRichTextValue( rawAttrs[ key ] )
			? richTextToHtml( rawAttrs[ key ] )
			: ( typeof rawAttrs[ key ] === 'string' ? rawAttrs[ key ] : '' )
		const preview = htmlToPlainPreview( html )
		if ( preview ) {
			node.preview = preview
			break
		}
	}
	const inner = Array.isArray( block.innerBlocks ) ? block.innerBlocks : []
	if ( inner.length ) {
		node.innerBlocks = []
		for ( const child of inner ) {
			const mapped = serializeBlockTree( child, budget )
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
 * @param {Object|string|Array} raw
 * @param {Object} wp
 * @return {Array}
 */
/**
 * Whether text looks like an LLM content stub rather than real prose.
 *
 * @param {string} text
 * @return {boolean}
 */
function looksLikeContentPlaceholder( text ) {
	const plain = String( text || '' )
		.replace( /<[^>]+>/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim()
	if ( ! plain ) {
		return false
	}
	if ( /^\[[^[\]]{3,160}\]$/u.test( plain ) ) {
		return true
	}
	if ( /^(full|complete|expanded|entire|actual|the)\b.{0,100}\b(content|article|guide|blocks?|structure|markup|html|outline)\b\.?$/iu.test( plain ) ) {
		return true
	}
	if ( /^(placeholder|TODO|TBD|lorem ipsum)\b/iu.test( plain ) ) {
		return true
	}
	if ( plain.length <= 120 && /\b(block structure|gutenberg (article )?blocks|expanded guide)\b/iu.test( plain ) ) {
		return true
	}
	return false
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
			if ( typeof attrs[ key ] === 'string' ) {
				out.push( attrs[ key ] )
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
		message: 'Blocks payload looks like a placeholder stub (e.g. [full article] or “expanded guide content”). Pass real Gutenberg block objects {name, attributes, innerBlocks} unless the user asked for placeholders.',
		hint: 'Rewrite with actual heading/paragraph/list content. For long articles, use set-blocks or insert one section at a time.',
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
				'Pass an array of block objects {name, attributes, innerBlocks}. Plain text is not accepted — use createBlock-shaped objects (or <!-- wp:… --> serialized markup).'
			)
		}
		if ( ! wp.blocks?.parse ) {
			throw new Error( 'wp.blocks.parse is not available.' )
		}
		const parsed = wp.blocks.parse( raw )
		if ( ! parsed.length ) {
			throw new Error( 'Serialized markup produced no blocks.' )
		}
		return parsed
	}
	if ( ! Array.isArray( raw ) ) {
		raw = raw ? [ raw ] : []
	}
	if ( ! wp.blocks?.createBlock ) {
		throw new Error( 'wp.blocks.createBlock is not available.' )
	}

	const toBlock = item => {
		if ( ! item || typeof item !== 'object' ) {
			throw new Error( 'Each block must be an object with a name.' )
		}
		const name = item.name
		if ( ! name || typeof name !== 'string' ) {
			throw new Error( 'Each block requires a name (e.g. core/paragraph).' )
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
		message: 'One or more block refs were not found. The ref map was cleared — re-call get-blocks or get-selection and use the fresh ref strings (b1, b2, …) — do not invent refs or paste clientId hashes.',
	}
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
	return {
		ok: true,
		is_block_editor: true,
		post_id: editor.getCurrentPostId?.() ?? null,
		post_type: editor.getCurrentPostType?.() ?? '',
		title: editor.getEditedPostAttribute?.( 'title' ) ?? '',
		status: editor.getEditedPostAttribute?.( 'status' ) ?? '',
		is_dirty: Boolean( editor.isEditedPostDirty?.() ),
		is_saving: Boolean( editor.isSavingPost?.() ),
		is_new: Boolean( editor.isEditedPostNew?.() ),
		blocks_count: blocks.length,
		selected_refs: refsForClientIds( selected ),
	}
}

export function getBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const blockSelect = ctx.select( 'core/block-editor' )
	syncRegistryFromEditor( ctx.select )

	let rootId = ''
	const rootToken = pickRefInput( input, [ 'root_ref', 'rootRef', 'root_client_id', 'rootClientId' ] )
	if ( rootToken ) {
		const resolved = resolveToClientIds( rootToken, id => blockSelect.getBlock?.( id ) )
		if ( resolved.missing.length || ! resolved.clientIds.length ) {
			return missingRefsError( resolved.missing.length ? resolved.missing : [ String( rootToken ) ] )
		}
		rootId = resolved.clientIds[ 0 ]
	}

	const maxBlocks = Math.max( 1, Math.min( 200, Number( input.max_blocks || input.maxBlocks || MAX_BLOCKS_DEFAULT ) ) )
	const blocks = rootId
		? ( blockSelect.getBlocks?.( rootId ) || [] )
		: ( blockSelect.getBlocks?.() || [] )
	if ( rootId ) {
		syncFromBlocks( [
			...( blockSelect.getBlock?.( rootId ) ? [ blockSelect.getBlock( rootId ) ] : [] ),
		] )
		// Also ensure children are mapped.
		syncFromBlocks( blocks )
	}

	const budget = { remaining: maxBlocks }
	const tree = []
	let truncated = false
	for ( const block of blocks ) {
		const node = serializeBlockTree( block, budget )
		if ( node ) {
			tree.push( node )
		}
		if ( budget.remaining <= 0 ) {
			truncated = true
			break
		}
	}
	return {
		ok: true,
		count: tree.length,
		truncated,
		blocks: tree,
	}
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
		.map( block => serializeBlockTree( block, { remaining: 20 } ) )
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
 * Convert one block toward core.
 *
 * @param {Object} block
 * @param {Object} wp
 * @return {{ block: Object, method: string }|null}
 */
function convertOneBlock( block, wp ) {
	if ( ! block?.name ) {
		return null
	}
	if ( String( block.name ).startsWith( 'core/' ) ) {
		return null
	}

	const target = guessCoreTarget( block.name )
	if ( wp.blocks?.switchToBlockType ) {
		try {
			const switched = wp.blocks.switchToBlockType( block, target )
			if ( Array.isArray( switched ) && switched.length ) {
				return { block: switched[ 0 ], method: 'switchToBlockType' }
			}
			if ( switched && switched.name ) {
				return { block: switched, method: 'switchToBlockType' }
			}
		} catch ( error ) {
			// Fall through to heuristic create.
		}
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
			message: 'A block name is required (e.g. core/heading). Prefer {name}. For core/* blocks, skip get-block-type and edit attributes directly.',
		}
	}
	if ( ! wp?.blocks?.getBlockType ) {
		return {
			ok: false,
			error: 'blocks_api_unavailable',
			message: 'wp.blocks is not available on this page.',
		}
	}
	const type = wp.blocks.getBlockType( name )
	if ( ! type ) {
		return {
			ok: false,
			error: 'unknown_block_type',
			message: `Block type “${ name }” is not registered.`,
			name,
		}
	}

	const variations = wp.blocks.getBlockVariations?.( name ) || []
	const attributeSchema = {}
	const richTextKeys = []
	const attrs = type.attributes || {}
	for ( const key of Object.keys( attrs ) ) {
		const def = attrs[ key ] || {}
		const richText = isRichTextAttrDef( def )
		if ( richText ) {
			richTextKeys.push( key )
		}
		attributeSchema[ key ] = {
			type: def.type || null,
			default: capValue( def.default ),
			enum: def.enum || undefined,
			source: def.source || undefined,
			selector: def.selector || undefined,
			attribute: def.attribute || undefined,
			rich_text: richText || undefined,
			hint: richText
				? 'Pass an HTML string; Ahentic converts to rich-text for the editor store.'
				: undefined,
		}
	}

	const isCore = String( name ).startsWith( 'core/' )

	return {
		ok: true,
		name: type.name,
		title: type.title || '',
		category: type.category || '',
		description: type.description || '',
		parent: type.parent || null,
		ancestor: type.ancestor || null,
		supports: capValue( type.supports || {} ),
		attributes: attributeSchema,
		rich_text_attributes: richTextKeys,
		hint: isCore
			? 'Core block — prefer update-block-attributes / replace-blocks with known attrs; get-block-type is usually unnecessary.'
			: 'Study attributes/supports before patching third-party blocks.',
		variations: variations.slice( 0, 20 ).map( variation => ( {
			name: variation.name,
			title: variation.title,
			isDefault: Boolean( variation.isDefault ),
		} ) ),
		example: type.example ? capValue( type.example ) : null,
		is_dynamic: Boolean( type.save === null || type.save === undefined ),
	}
}

export function listBlockTypes( input = {} ) {
	const wp = getWp()
	if ( ! wp?.blocks?.getBlockTypes ) {
		return {
			ok: false,
			error: 'blocks_api_unavailable',
			message: 'wp.blocks is not available on this page.',
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
			: { ok: false, error: 'missing_ref', message: 'ref is required (from get-blocks / get-selection).' }
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
			: { ok: false, error: 'missing_ref', message: 'ref (or refs) is required.' }
	}
	if ( ! attributes || typeof attributes !== 'object' || Array.isArray( attributes ) ) {
		return {
			ok: false, error: 'missing_attributes', message: 'attributes object is required.',
		}
	}
	for ( const key of [ 'content', 'text', 'caption', 'citation' ] ) {
		if ( typeof attributes[ key ] === 'string' && looksLikeContentPlaceholder( attributes[ key ] ) ) {
			return {
				ok: false,
				error: 'placeholder_content',
				message: 'Attribute looks like a placeholder stub. Pass the real text unless the user asked for placeholders.',
				hint: 'Rewrite with the actual prose/HTML for this attribute.',
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
				message: 'One or more block refs were not found. Re-call get-blocks or get-selection and use the fresh refs.',
			}
			: {} ),
	}
}

export function replaceBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const blockSelect = ctx.select( 'core/block-editor' )
	let { clientIds, missing } = resolveInputRefs( input, ctx.select, [
		'refs', 'ref', 'client_ids', 'clientIds', 'client_id', 'clientId',
	] )
	if ( ! clientIds.length ) {
		clientIds = blockSelect.getSelectedBlockClientIds?.() || []
	}
	if ( ! clientIds.length ) {
		return missing.length
			? missingRefsError( missing )
			: { ok: false, error: 'missing_refs', message: 'Provide refs (from get-blocks) or select blocks.' }
	}
	if ( missing.length ) {
		return missingRefsError( missing )
	}
	const notFound = clientIds.filter( id => ! blockSelect.getBlock?.( id ) )
	if ( notFound.length ) {
		return missingRefsError( refsForClientIds( notFound ) )
	}
	const stub = rejectPlaceholderBlocks( input.blocks )
	if ( stub ) {
		return stub
	}
	let blocks
	try {
		blocks = normalizeBlocksInput( input.blocks, ctx.wp )
	} catch ( error ) {
		return {
			ok: false, error: 'invalid_blocks', message: error?.message || 'Invalid blocks payload.',
		}
	}
	if ( ! blocks.length ) {
		return {
			ok: false, error: 'empty_blocks', message: 'Replacement blocks cannot be empty.',
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
	const replacedRefs = refsForClientIds( clientIds )
	ctx.dispatch( 'core/block-editor' ).replaceBlocks( clientIds, blocks )
	syncRegistryFromEditor( ctx.select )
	return {
		ok: true,
		replaced_refs: replacedRefs,
		inserted_count: blocks.length,
		inserted_names: blocks.map( block => block.name ),
		inserted_refs: refsForClientIds( blocks.map( block => block.clientId ).filter( Boolean ) ),
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
	const stub = rejectPlaceholderBlocks( input.blocks )
	if ( stub ) {
		return stub
	}
	let blocks
	try {
		blocks = normalizeBlocksInput( input.blocks, ctx.wp )
	} catch ( error ) {
		return {
			ok: false, error: 'invalid_blocks', message: error?.message || 'Invalid blocks payload.',
		}
	}
	if ( ! blocks.length ) {
		return {
			ok: false, error: 'empty_blocks', message: 'Blocks cannot be empty.',
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
	return {
		ok: true,
		inserted_count: live.length,
		inserted_names: live.map( block => block.name ),
		inserted_refs: refsForClientIds( live.map( block => block.clientId ) ),
	}
}

export function insertBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const stub = rejectPlaceholderBlocks( input.blocks )
	if ( stub ) {
		return stub
	}
	let blocks
	try {
		blocks = normalizeBlocksInput( input.blocks, ctx.wp )
	} catch ( error ) {
		return {
			ok: false, error: 'invalid_blocks', message: error?.message || 'Invalid blocks payload.',
		}
	}
	if ( ! blocks.length ) {
		return {
			ok: false, error: 'empty_blocks', message: 'No blocks to insert.',
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
	return {
		ok: true,
		inserted_count: blocks.length,
		inserted_names: blocks.map( block => block.name ),
		inserted_refs: refsForClientIds( blocks.map( block => block.clientId ).filter( Boolean ) ),
		index,
		root_ref: rootClientId ? refForClientId( rootClientId ) : null,
	}
}

export function duplicateBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const blockSelect = ctx.select( 'core/block-editor' )
	let { clientIds, missing } = resolveInputRefs( input, ctx.select, [
		'refs', 'ref', 'client_ids', 'clientIds', 'client_id', 'clientId',
	] )
	if ( ! clientIds.length ) {
		clientIds = blockSelect.getSelectedBlockClientIds?.() || []
	}
	if ( ! clientIds.length ) {
		return missing.length
			? missingRefsError( missing )
			: { ok: false, error: 'missing_refs', message: 'Provide refs or select blocks.' }
	}
	if ( missing.length ) {
		return missingRefsError( missing )
	}
	ctx.dispatch( 'core/block-editor' ).duplicateBlocks( clientIds )
	syncRegistryFromEditor( ctx.select )
	return { ok: true, duplicated_refs: refsForClientIds( clientIds ) }
}

export function moveBlocks( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const { clientIds, missing } = resolveInputRefs( input, ctx.select, [
		'refs', 'ref', 'client_ids', 'clientIds', 'client_id', 'clientId',
	] )
	if ( ! clientIds.length ) {
		return missing.length
			? missingRefsError( missing )
			: { ok: false, error: 'missing_refs', message: 'refs is required.' }
	}
	if ( missing.length ) {
		return missingRefsError( missing )
	}
	if ( input.index === undefined || input.index === null || input.index === '' ) {
		return {
			ok: false, error: 'missing_index', message: 'index is required.',
		}
	}
	const blockSelect = ctx.select( 'core/block-editor' )
	const fromRoot = blockSelect.getBlockRootClientId?.( clientIds[ 0 ] ) || ''
	let toRoot = fromRoot
	const hasRoot = Object.prototype.hasOwnProperty.call( input, 'root_ref' ) ||
		Object.prototype.hasOwnProperty.call( input, 'rootRef' ) ||
		Object.prototype.hasOwnProperty.call( input, 'root_client_id' ) ||
		Object.prototype.hasOwnProperty.call( input, 'rootClientId' )
	if ( hasRoot ) {
		const rootToken = pickRefInput( input, [ 'root_ref', 'rootRef', 'root_client_id', 'rootClientId' ] )
		if ( rootToken === '' || rootToken === null || rootToken === undefined ) {
			toRoot = ''
		} else {
			const resolved = resolveToClientIds( rootToken, id => blockSelect.getBlock?.( id ) )
			if ( resolved.missing.length || ! resolved.clientIds.length ) {
				return missingRefsError( resolved.missing.length ? resolved.missing : [ String( rootToken ) ] )
			}
			toRoot = resolved.clientIds[ 0 ]
		}
	}
	ctx.dispatch( 'core/block-editor' ).moveBlocksToPosition(
		clientIds,
		fromRoot || undefined,
		toRoot || undefined,
		Number( input.index )
	)
	syncRegistryFromEditor( ctx.select )
	return {
		ok: true,
		moved_refs: refsForClientIds( clientIds ),
		index: Number( input.index ),
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
			ok: false, error: 'missing_colors', message: 'Provide colors: [{ slug, color }] or hex strings.',
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
				return block && ! String( block.name || '' ).startsWith( 'core/' )
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
		if ( String( block.name || '' ).startsWith( 'core/' ) ) {
			skipped.push( {
				ref, name: block.name, reason: 'already_core',
			} )
			continue
		}
		try {
			const result = convertOneBlock( block, ctx.wp )
			if ( ! result?.block ) {
				skipped.push( {
					ref, name: block.name, reason: 'no_mapping',
				} )
				continue
			}
			dispatch.replaceBlocks( [ block.clientId ], [ result.block ] )
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

	syncRegistryFromEditor( ctx.select )
	return {
		ok: true,
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
						message: 'Heading block has no text.',
						path: here,
					} )
				}
				if ( lastHeadingLevel && level > lastHeadingLevel + 1 ) {
					issues.push( {
						type: 'heading_skip',
						ref,
						message: `Heading level skips from h${ lastHeadingLevel } to h${ level }.`,
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
					issues.push( {
						type: 'missing_alt',
						ref,
						message: 'Image is missing alt text.',
						path: here,
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
						message: 'Button has no accessible label.',
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
						message: `Block ${ name } looks like a heading but has no text.`,
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

export function updatePostTitle( input = {} ) {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const title = typeof input.title === 'string' ? input.title.trim() : ''
	if ( ! title ) {
		return {
			ok: false, error: 'invalid_title', message: 'title cannot be empty.',
		}
	}
	ctx.dispatch( 'core/editor' ).editPost( { title } )

	// Report the stored title, not the input, so the result is proof of the applied state.
	const applied = ctx.select( 'core/editor' ).getEditedPostAttribute?.( 'title' )
	return {
		ok: true,
		title: typeof applied === 'string' ? applied : title,
		post_id: ctx.select( 'core/editor' ).getCurrentPostId?.() ?? null,
	}
}

export async function savePost() {
	const ctx = requireEditor()
	if ( ! ctx.ok ) {
		return ctx
	}
	const editor = ctx.select( 'core/editor' )
	const dispatch = ctx.dispatch( 'core/editor' )
	if ( editor.isSavingPost?.() ) {
		return {
			ok: false, error: 'already_saving', message: 'A save is already in progress.',
		}
	}
	try {
		await dispatch.savePost()
	} catch ( error ) {
		return {
			ok: false,
			error: 'save_failed',
			message: error?.message || 'Failed to save the post.',
		}
	}
	return {
		ok: true,
		post_id: editor.getCurrentPostId?.() ?? null,
		is_dirty: Boolean( editor.isEditedPostDirty?.() ),
		status: editor.getEditedPostAttribute?.( 'status' ) ?? '',
	}
}
