/**
 * @jest-environment node
 */

import {
	assertBlocksApplied,
	blockTextChars,
	convertBlocks,
	enforceGetBlocksByteBudget,
	GET_BLOCKS_COMPACT_MAX_CHARS,
	getBlockType,
	getBlocks,
	measureEditorTextChars,
	plainTextCharsFromHtml,
	prepareBlocksPayload,
	resolveTargetClientIds,
	setBlocks,
	updateBlockAttributes,
} from './editor-abilities'
import { resetBlockRefs, syncFromBlocks } from './block-ref-registry'

/**
 * Mimic WP 7 RichTextData after createBlock / getBlocks (content is not a string).
 *
 * @param {string} html
 * @return {Object} RichText-like value with toHTMLString().
 */
function richTextData( html ) {
	return {
		toHTMLString: () => html,
		constructor: { name: 'RichTextData' },
	}
}

function mockCtx( {
	selected = [],
	blocksById = {},
	createBlock = ( name, attributes = {}, innerBlocks = [] ) => ( {
		name,
		attributes,
		innerBlocks,
		clientId: `cid_${ name }`,
	} ),
} = {} ) {
	return {
		ok: true,
		wp: {
			blocks: {
				createBlock,
			},
		},
		select: store => {
			if ( store !== 'core/block-editor' ) {
				return {}
			}
			return {
				getSelectedBlockClientIds: () => selected,
				getBlock: id => blocksById[ id ] || null,
			}
		},
		dispatch: () => ( {} ),
	}
}

describe( 'prepareBlocksPayload', () => {
	it( 'rejects placeholder stubs before normalize', () => {
		const result = prepareBlocksPayload(
			{ blocks: [ { name: 'core/paragraph', attributes: { content: '[full article]' } } ] },
			mockCtx()
		)
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'placeholder_content' )
	} )

	it( 'returns empty_blocks with the caller message', () => {
		const result = prepareBlocksPayload(
			{ blocks: [] },
			mockCtx(),
			{ emptyMessage: 'No blocks to insert.' }
		)
		expect( result ).toEqual( {
			ok: false,
			error: 'empty_blocks',
			message: 'No blocks to insert.',
		} )
	} )

	it( 'returns normalized blocks on success', () => {
		const result = prepareBlocksPayload(
			{
				blocks: [
					{ name: 'core/paragraph', attributes: { content: 'Hello' } },
				],
			},
			mockCtx()
		)
		expect( result.ok ).toBe( true )
		expect( result.blocks ).toHaveLength( 1 )
		expect( result.blocks[ 0 ].name ).toBe( 'core/paragraph' )
	} )
} )

describe( 'resolveTargetClientIds', () => {
	it( 'errors when no refs and no selection', () => {
		const result = resolveTargetClientIds(
			{},
			mockCtx(),
			{ missingMessage: 'Provide refs or select blocks.' }
		)
		expect( result ).toEqual( {
			ok: false,
			error: 'missing_refs',
			message: 'Provide refs or select blocks.',
		} )
	} )

	it( 'uses selection when allowSelection and no refs', () => {
		const result = resolveTargetClientIds(
			{},
			mockCtx( {
				selected: [ 'c1' ],
				blocksById: { c1: { name: 'core/paragraph' } },
			} )
		)
		expect( result ).toEqual( { ok: true, clientIds: [ 'c1' ] } )
	} )

	it( 'does not fall back to selection when allowSelection is false', () => {
		const result = resolveTargetClientIds(
			{},
			mockCtx( { selected: [ 'c1' ] } ),
			{
				allowSelection: false,
				missingMessage: 'refs is required.',
			}
		)
		expect( result ).toEqual( {
			ok: false,
			error: 'missing_refs',
			message: 'refs is required.',
		} )
	} )

	it( 'errors when requireExisting and selection ids are absent from the document', () => {
		const result = resolveTargetClientIds(
			{},
			mockCtx( { selected: [ 'gone' ], blocksById: {} } ),
			{ requireExisting: true }
		)
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'block_not_found' )
		expect( result.wiped ).toBe( true )
	} )
} )

describe( 'blockTextChars (set-blocks text_chars / Finish Gate)', () => {
	it( 'counts plain string content attributes', () => {
		const blocks = [
			{
				name: 'core/paragraph',
				attributes: { content: 'Hello world' },
				innerBlocks: [],
			},
		]
		expect( blockTextChars( blocks ) ).toBe( 'Hello world'.length )
	} )

	/**
	 * Session death spiral symptom: set-blocks inserts many named blocks but
	 * reports text_chars: 0 → Finish Gate verify_thin → agent rebuilds forever.
	 * On WP 7, createBlock stores rich-text attrs as RichTextData objects.
	 */
	it( 'counts RichTextData content the same as string HTML (user symptom: chars 0 after apply)', () => {
		const body = 'Metro Manila commuting is rarely a simple trip. '.repeat( 50 )
		expect( body.length ).toBeGreaterThan( 2000 )

		// Minimal repro: one live paragraph as getBlocks returns it after set-blocks.
		const chars = blockTextChars( [
			{
				name: 'core/paragraph',
				attributes: { content: richTextData( body ) },
				innerBlocks: [],
			},
		] )

		// Primary symptom: must not report 0 after a real long-form apply.
		expect( chars ).toBeGreaterThan( 2000 )
		expect( chars ).toBe( body.trim().length )
	} )

	it( 'counts nested list-item RichTextData (runtime set-blocks tree shape)', () => {
		const para = 'A reliable Metro Manila commute plans the full door-to-door journey. '
		const heading = 'Plan the complete journey'
		const item = 'Keep one backup route for delays.'
		const chars = blockTextChars( [
			{
				name: 'core/paragraph',
				attributes: { content: richTextData( para.repeat( 40 ) ) },
				innerBlocks: [],
			},
			{
				name: 'core/heading',
				attributes: { content: richTextData( heading ), level: 2 },
				innerBlocks: [],
			},
			{
				name: 'core/list',
				attributes: { ordered: false },
				innerBlocks: [
					{
						name: 'core/list-item',
						attributes: { content: richTextData( item ) },
						innerBlocks: [],
					},
				],
			},
		] )

		expect( chars ).toBeGreaterThan( 2000 )
		expect( chars ).toBe(
			para.repeat( 40 ).trim().length + heading.length + item.length
		)
	} )

	it( 'does not treat an empty RichTextData body as long-form', () => {
		expect(
			blockTextChars( [
				{
					name: 'core/paragraph',
					attributes: { content: richTextData( '' ) },
					innerBlocks: [],
				},
			] )
		).toBe( 0 )
	} )
} )

describe( 'updateBlockAttributes placeholder guard', () => {
	beforeEach( () => {
		resetBlockRefs()
		global.window = global.window || {}
	} )

	afterEach( () => {
		delete global.window.wp
	} )

	it( 'rejects meta-instruction content before applying attributes', () => {
		const blocksById = {
			cid_a: {
				clientId: 'cid_a',
				name: 'core/paragraph',
				attributes: {
					content: 'Metro Manila commuters face a different challenge every day.',
				},
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_a ]
		syncFromBlocks( root, 1 )

		let applied = null
		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: store => {
					if ( store === 'core/block-editor' ) {
						return {
							updateBlockAttributes: ( clientId, attributes ) => {
								applied = { clientId, attributes }
							},
						}
					}
					return {}
				},
			},
		}

		const result = updateBlockAttributes( {
			ref: 'b1',
			attributes: {
				content: 'HTML paragraph with a natural internal link to the Metro Manila commute planning article',
			},
		} )

		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'placeholder_content' )
		expect( applied ).toBeNull()
	} )
} )

describe( 'updateBlockAttributes live media remap', () => {
	beforeEach( () => {
		resetBlockRefs()
		global.window = global.window || {}
	} )

	afterEach( () => {
		delete global.window.wp
	} )

	/**
	 * @param {Record<string, Object>}                       blocksById
	 * @param {{ apply?: 'merge' | 'noop' | 'reformatCss' }} [opts]
	 */
	function mockLiveEditor( blocksById, opts = {} ) {
		const apply = opts.apply || 'merge'
		const root = opts.root || Object.values( blocksById )
		syncFromBlocks( root, 1 )
		let dispatched = null
		const parentOf = {}
		Object.values( blocksById ).forEach( block => {
			( block.innerBlocks || [] ).forEach( child => {
				parentOf[ child.clientId ] = block.clientId
			} )
		} )
		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getBlockParents: id => {
								const closestFirst = []
								let cur = parentOf[ id ]
								while ( cur ) {
									closestFirst.push( cur )
									cur = parentOf[ cur ]
								}
								return [ ...closestFirst ].reverse()
							},
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: store => {
					if ( store === 'core/block-editor' ) {
						return {
							updateBlockAttributes: ( clientId, attributes ) => {
								dispatched = { clientId, attributes }
								if ( apply === 'noop' ) {
									return
								}
								const block = blocksById[ clientId ]
								if ( ! block ) {
									return
								}
								const next = { ...attributes }
								if ( apply === 'reformatCss' && typeof next.inlineCssStyles === 'string' ) {
									next.inlineCssStyles = next.inlineCssStyles.replace(
										/url\(([^)]+)\)/,
										'url( $1 )'
									)
								}
								block.attributes = { ...block.attributes, ...next }
							},
						}
					}
					return {}
				},
			},
			blocks: {
				getBlockType: () => null,
			},
		}
		return {
			getDispatched: () => dispatched,
			getBlock: id => blocksById[ id ],
		}
	}

	it( 'writes remapped live keys and echoes them after a successful media patch', () => {
		const blocksById = {
			cid_img: {
				clientId: 'cid_img',
				name: 'greenshift-blocks/image',
				attributes: {
					mediaUrl: 'https://example.com/old.jpg',
					mediaId: 10,
					alt: 'Old field',
				},
				innerBlocks: [],
			},
		}
		const editor = mockLiveEditor( blocksById )

		const result = updateBlockAttributes( {
			ref: 'b1',
			attributes: {
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
		} )

		expect( result.ok ).toBe( true )
		expect( result.attributes ).toEqual( {
			mediaUrl: 'https://example.com/farm.png',
			mediaId: 1291,
			alt: 'Outdoor farm',
		} )
		expect( editor.getBlock( 'cid_img' ).attributes.mediaUrl ).toBe( 'https://example.com/farm.png' )
		expect( editor.getBlock( 'cid_img' ).attributes.mediaurl ).toBeUndefined()
		expect( editor.getDispatched().attributes.mediaurl ).toBeUndefined()
	} )

	it( 'returns attributes_not_applied when guessed media keys match nothing live', () => {
		const blocksById = {
			cid_p: {
				clientId: 'cid_p',
				name: 'core/paragraph',
				attributes: { content: 'Hello there friend.' },
				innerBlocks: [],
			},
		}
		const editor = mockLiveEditor( blocksById )

		const result = updateBlockAttributes( {
			ref: 'b1',
			attributes: {
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
		} )

		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'attributes_not_applied' )
		expect( result.ignored_keys ).toEqual( [ 'mediaurl', 'mediaid', 'alt' ] )
		expect( result.live_media ).toEqual( {} )
		expect( result.hint ).toBeTruthy()
		expect( editor.getDispatched() ).toBeNull()
		expect( editor.getBlock( 'cid_p' ).attributes.mediaurl ).toBeUndefined()
	} )

	it( 'still patches core/image url/alt/id when keys already match', () => {
		const blocksById = {
			cid_img: {
				clientId: 'cid_img',
				name: 'core/image',
				attributes: {
					url: 'https://example.com/old.jpg',
					alt: '',
					id: 5,
				},
				innerBlocks: [],
			},
		}
		mockLiveEditor( blocksById )

		const result = updateBlockAttributes( {
			ref: 'b1',
			attributes: {
				url: 'https://example.com/farm.png',
				alt: 'Outdoor farm',
				id: 1291,
			},
		} )

		expect( result.ok ).toBe( true )
		expect( result.attributes.url ).toBe( 'https://example.com/farm.png' )
		expect( result.attributes.alt ).toBe( 'Outdoor farm' )
		expect( result.attributes.id ).toBe( 1291 )
	} )

	it( 'deep-merges nested media objects and rewrites compiled CSS on the live block', () => {
		const oldUrl = 'https://example.com/old.jpg'
		const newUrl = 'https://example.com/farm.png'
		const blocksById = {
			cid_hero: {
				clientId: 'cid_hero',
				name: 'greenshift-blocks/container',
				attributes: {
					backgroundImage: {
						url: oldUrl, id: 10, alt: 'Old field', width: 1600,
					},
					inlineCssStyles: `.hero{background-image:url(${ oldUrl })}`,
				},
				innerBlocks: [],
			},
		}
		const editor = mockLiveEditor( blocksById )

		const result = updateBlockAttributes( {
			ref: 'b1',
			attributes: {
				mediaurl: newUrl,
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
		} )

		expect( result.ok ).toBe( true )
		expect( editor.getBlock( 'cid_hero' ).attributes.backgroundImage ).toEqual( {
			url: newUrl, id: 1291, alt: 'Outdoor farm', width: 1600,
		} )
		expect( editor.getBlock( 'cid_hero' ).attributes.inlineCssStyles ).toBe(
			`.hero{background-image:url(${ newUrl })}`
		)
		expect( editor.getBlock( 'cid_hero' ).attributes.mediaurl ).toBeUndefined()
	} )

	it( 'deep-merges Greenshift background.image arrays and keeps size/repeat', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/home-hero.webp'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const blocksById = {
			cid_hero: {
				clientId: 'cid_hero',
				name: 'greenshift-blocks/container',
				attributes: {
					background: {
						image: [ oldUrl, null ],
						size: [ 'cover' ],
						repeat: [ 'no-repeat' ],
					},
					id: 'gsbp-hero',
				},
				innerBlocks: [],
			},
		}
		const editor = mockLiveEditor( blocksById )

		const result = updateBlockAttributes( {
			ref: 'b1',
			attributes: {
				mediaurl: newUrl,
				mediaid: 1295,
			},
		} )

		expect( result.ok ).toBe( true )
		expect( editor.getBlock( 'cid_hero' ).attributes.background ).toEqual( {
			image: [ newUrl, null ],
			size: [ 'cover' ],
			repeat: [ 'no-repeat' ],
		} )
		expect( editor.getBlock( 'cid_hero' ).attributes.mediaurl ).toBeUndefined()
		expect( result.remapped.mediaurl ).toBe( 'background.image' )
	} )

	it( 'retargets a small nested image write onto the ancestor background', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/home-hero.webp'
		const overlayUrl = 'https://example.com/wp-content/uploads/home-hero-1.webp'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const imageBlock = {
			clientId: 'cid_img',
			name: 'greenshift-blocks/image',
			attributes: {
				mediaurl: overlayUrl,
				mediaid: 80,
				alt: '',
				originalWidth: 120,
				customWidth: [ 60 ],
				widthUnit: [ 'px' ],
				background: {},
			},
			innerBlocks: [],
		}
		const heroBlock = {
			clientId: 'cid_hero',
			name: 'greenshift-blocks/container',
			attributes: {
				background: {
					image: [ oldUrl ],
					size: [ 'cover' ],
					repeat: [ 'no-repeat' ],
				},
				id: 'gsbp-hero',
			},
			innerBlocks: [ imageBlock ],
		}
		const editor = mockLiveEditor( {
			cid_hero: heroBlock,
			cid_img: imageBlock,
		}, { root: [ heroBlock ] } )

		const result = updateBlockAttributes( {
			ref: 'b2',
			attributes: {
				mediaurl: newUrl,
				mediaid: 1295,
			},
		} )

		expect( result.ok ).toBe( true )
		expect( result.updated_refs ).toEqual( [ 'b1' ] )
		expect( result.retargeted ).toEqual( [ {
			from: 'b2',
			to: 'b1',
			reason: 'nested_overlay_ancestor_background',
		} ] )
		expect( editor.getBlock( 'cid_hero' ).attributes.background ).toEqual( {
			image: [ newUrl ],
			size: [ 'cover' ],
			repeat: [ 'no-repeat' ],
		} )
		expect( editor.getBlock( 'cid_img' ).attributes.mediaurl ).toBe( overlayUrl )
		expect( editor.getDispatched().clientId ).toBe( 'cid_hero' )
	} )

	it( 'does not retarget a large nested image onto a parent background', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/home-hero.webp'
		const photoUrl = 'https://example.com/wp-content/uploads/photo.webp'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const imageBlock = {
			clientId: 'cid_img',
			name: 'core/image',
			attributes: {
				url: photoUrl,
				alt: '',
				id: 80,
				width: 1200,
			},
			innerBlocks: [],
		}
		const heroBlock = {
			clientId: 'cid_hero',
			name: 'core/group',
			attributes: {
				style: {
					background: {
						backgroundImage: { url: oldUrl },
					},
				},
			},
			innerBlocks: [ imageBlock ],
		}
		const editor = mockLiveEditor( {
			cid_hero: heroBlock,
			cid_img: imageBlock,
		}, { root: [ heroBlock ] } )

		const result = updateBlockAttributes( {
			ref: 'b2',
			attributes: { url: newUrl },
		} )

		expect( result.ok ).toBe( true )
		expect( result.retargeted ).toBeUndefined()
		expect( editor.getBlock( 'cid_img' ).attributes.url ).toBe( newUrl )
		expect( editor.getBlock( 'cid_hero' ).attributes.style.background.backgroundImage.url ).toBe( oldUrl )
	} )

	it( 'retargets a small nested image onto a core/cover ancestor', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/cover.webp'
		const overlayUrl = 'https://example.com/wp-content/uploads/badge.webp'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const imageBlock = {
			clientId: 'cid_img',
			name: 'core/image',
			attributes: {
				url: overlayUrl,
				alt: '',
				id: 80,
				width: 120,
			},
			innerBlocks: [],
		}
		const heroBlock = {
			clientId: 'cid_hero',
			name: 'core/cover',
			attributes: {
				url: oldUrl,
				alt: 'Hero',
				id: 12,
			},
			innerBlocks: [ imageBlock ],
		}
		const editor = mockLiveEditor( {
			cid_hero: heroBlock,
			cid_img: imageBlock,
		}, { root: [ heroBlock ] } )

		const result = updateBlockAttributes( {
			ref: 'b2',
			attributes: { url: newUrl, id: 99 },
		} )

		expect( result.ok ).toBe( true )
		expect( result.updated_refs ).toEqual( [ 'b1' ] )
		expect( result.retargeted[ 0 ] ).toEqual( {
			from: 'b2',
			to: 'b1',
			reason: 'nested_overlay_ancestor_background',
		} )
		expect( editor.getBlock( 'cid_hero' ).attributes.url ).toBe( newUrl )
		expect( editor.getBlock( 'cid_img' ).attributes.url ).toBe( overlayUrl )
	} )

	it( 'retargets a small nested image onto a Stackable hero background', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/banner.webp'
		const overlayUrl = 'https://example.com/wp-content/uploads/icon.webp'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const imageBlock = {
			clientId: 'cid_img',
			name: 'stackable/image',
			attributes: {
				url: overlayUrl,
				alt: '',
				width: 80,
			},
			innerBlocks: [],
		}
		const heroBlock = {
			clientId: 'cid_hero',
			name: 'stackable/hero',
			attributes: {
				blockBackground: {
					image: { url: oldUrl },
					color: '#111111',
				},
			},
			innerBlocks: [ imageBlock ],
		}
		const editor = mockLiveEditor( {
			cid_hero: heroBlock,
			cid_img: imageBlock,
		}, { root: [ heroBlock ] } )

		const result = updateBlockAttributes( {
			ref: 'b2',
			attributes: { mediaurl: newUrl },
		} )

		expect( result.ok ).toBe( true )
		expect( result.updated_refs ).toEqual( [ 'b1' ] )
		expect( editor.getBlock( 'cid_hero' ).attributes.blockBackground.image.url ).toBe( newUrl )
		expect( editor.getBlock( 'cid_hero' ).attributes.blockBackground.color ).toBe( '#111111' )
		expect( editor.getBlock( 'cid_img' ).attributes.url ).toBe( overlayUrl )
	} )

	it( 'retargets a small nested image onto an unknown-library banner URL', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/banner.webp'
		const overlayUrl = 'https://example.com/wp-content/uploads/icon.webp'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const imageBlock = {
			clientId: 'cid_img',
			name: 'core/image',
			attributes: {
				url: overlayUrl,
				alt: '',
				width: 80,
			},
			innerBlocks: [],
		}
		const heroBlock = {
			clientId: 'cid_hero',
			name: 'acme/banner',
			attributes: {
				imageUrl: oldUrl,
			},
			innerBlocks: [ imageBlock ],
		}
		const editor = mockLiveEditor( {
			cid_hero: heroBlock,
			cid_img: imageBlock,
		}, { root: [ heroBlock ] } )

		const result = updateBlockAttributes( {
			ref: 'b2',
			attributes: { mediaurl: newUrl },
		} )

		expect( result.ok ).toBe( true )
		expect( result.updated_refs ).toEqual( [ 'b1' ] )
		expect( editor.getBlock( 'cid_hero' ).attributes.imageUrl ).toBe( newUrl )
		expect( editor.getBlock( 'cid_img' ).attributes.url ).toBe( overlayUrl )
	} )

	it( 'treats equal array attribute values as landed', () => {
		const blocksById = {
			cid_g: {
				clientId: 'cid_g',
				name: 'core/gallery',
				attributes: { ids: [ 1, 2 ] },
				innerBlocks: [],
			},
		}
		mockLiveEditor( blocksById )

		const result = updateBlockAttributes( {
			ref: 'b1',
			attributes: { ids: [ 3, 4 ] },
		} )

		expect( result.ok ).toBe( true )
		expect( result.attributes.ids ).toEqual( [ 3, 4 ] )
	} )

	it( 'accepts compiled CSS that still contains the new URL after store reformatting', () => {
		const oldUrl = 'https://example.com/old.jpg'
		const newUrl = 'https://example.com/farm.png'
		const blocksById = {
			cid_hero: {
				clientId: 'cid_hero',
				name: 'greenshift-blocks/container',
				attributes: {
					backgroundImage: {
						url: oldUrl, id: 10, width: 1600,
					},
					inlineCssStyles: `.hero{background-image:url(${ oldUrl })}`,
				},
				innerBlocks: [],
			},
		}
		mockLiveEditor( blocksById, { apply: 'reformatCss' } )

		const result = updateBlockAttributes( {
			ref: 'b1',
			attributes: { mediaurl: newUrl, mediaid: 1291 },
		} )

		expect( result.ok ).toBe( true )
		expect( blocksById.cid_hero.attributes.inlineCssStyles ).toContain( newUrl )
		expect( blocksById.cid_hero.attributes.backgroundImage.url ).toBe( newUrl )
	} )

	it( 'returns attributes_not_applied when the editor store does not keep the patch', () => {
		const blocksById = {
			cid_img: {
				clientId: 'cid_img',
				name: 'core/image',
				attributes: {
					url: 'https://example.com/old.jpg',
					alt: '',
					id: 5,
				},
				innerBlocks: [],
			},
		}
		mockLiveEditor( blocksById, { apply: 'noop' } )

		const result = updateBlockAttributes( {
			ref: 'b1',
			attributes: { url: 'https://example.com/farm.png' },
		} )

		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'attributes_not_applied' )
		expect( blocksById.cid_img.attributes.url ).toBe( 'https://example.com/old.jpg' )
	} )
} )

describe( 'getBlocks scoped refs', () => {
	beforeEach( () => {
		resetBlockRefs()
		global.window = global.window || {}
	} )

	afterEach( () => {
		delete global.window.wp
	} )

	it( 'returns only requested refs, not the full tree', () => {
		const blocksById = {
			cid_a: {
				clientId: 'cid_a',
				name: 'core/paragraph',
				attributes: { content: 'First paragraph about transit.' },
				innerBlocks: [],
			},
			cid_b: {
				clientId: 'cid_b',
				name: 'core/paragraph',
				attributes: { content: 'Second paragraph about traffic.' },
				innerBlocks: [],
			},
			cid_c: {
				clientId: 'cid_c',
				name: 'core/paragraph',
				attributes: { content: 'Third unused paragraph.' },
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_a, blocksById.cid_b, blocksById.cid_c ]
		syncFromBlocks( root, 1 )

		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: () => ( {} ),
			},
		}

		const result = getBlocks( { refs: [ 'b1', 'b3' ] } )
		expect( result.ok ).toBe( true )
		expect( result.scoped ).toBe( true )
		expect( result.count ).toBe( 2 )
		expect( result.blocks.map( b => b.ref ) ).toEqual( [ 'b1', 'b3' ] )
		expect( result.blocks[ 0 ].attributes.content ).toContain( 'transit' )
		expect( result.blocks[ 1 ].attributes.content ).toContain( 'unused' )
	} )

	it( 'compact full-document read includes HTML content for text blocks', () => {
		const html = 'Private cars <a href="https://example.com/x">cost more</a> than fares.'
		const blocksById = {
			cid_p: {
				clientId: 'cid_p',
				name: 'core/paragraph',
				attributes: { content: html, dropCap: false },
				innerBlocks: [],
			},
			cid_h: {
				clientId: 'cid_h',
				name: 'core/heading',
				attributes: { content: 'Why people choose a private car', level: 2 },
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_p, blocksById.cid_h ]
		syncFromBlocks( root, 1 )

		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: () => ( {} ),
			},
		}

		const result = getBlocks( {} )
		expect( result.ok ).toBe( true )
		expect( result.scoped ).toBeUndefined()
		expect( result.blocks ).toHaveLength( 2 )
		expect( result.blocks[ 0 ].preview ).toContain( 'Private cars' )
		expect( result.blocks[ 0 ].content_attr ).toBe( 'content' )
		expect( result.blocks[ 0 ].attributes.content ).toBe( html )
		expect( result.blocks[ 0 ].attributes.dropCap ).toBeUndefined()
		expect( result.blocks[ 1 ].attributes.content ).toBe( 'Why people choose a private car' )
		expect( result.blocks[ 1 ].attributes.level ).toBeUndefined()
	} )

	it( 'includes link essentials on compact third-party button blocks', () => {
		const blocksById = {
			cid_btn: {
				clientId: 'cid_btn',
				name: 'greenshift-blocks/buttonbox',
				attributes: {
					buttonContent: 'Shop Now',
					buttonUrl: 'http://ai.local/shop/',
					backBackgroundColor: '#000000b3',
					width: 100,
				},
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_btn ]
		syncFromBlocks( root, 1 )

		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: () => ( {} ),
			},
		}

		const result = getBlocks( {} )
		expect( result.ok ).toBe( true )
		expect( result.blocks[ 0 ].attributes.buttonContent ).toBe( 'Shop Now' )
		expect( result.blocks[ 0 ].attributes.buttonUrl ).toBe( 'http://ai.local/shop/' )
		expect( result.blocks[ 0 ].attributes.backBackgroundColor ).toBeUndefined()
	} )

	it( 'includes compact background.image on Greenshift container blocks', () => {
		const heroUrl = 'http://ai.local/wp-content/uploads/2025/08/home-hero-image-1.webp'
		const blocksById = {
			cid_box: {
				clientId: 'cid_box',
				name: 'greenshift-blocks/container',
				attributes: {
					background: {
						image: [ heroUrl ],
						size: [ 'cover' ],
						repeat: [ 'no-repeat' ],
						positionImage: [ { x: '0.50', y: '0.63' } ],
					},
					id: 'gsbp-hero',
					width: [ 100 ],
				},
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_box ]
		syncFromBlocks( root, 1 )

		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: () => ( {} ),
			},
		}

		const result = getBlocks( {} )
		expect( result.ok ).toBe( true )
		expect( result.blocks[ 0 ].attributes.background ).toEqual( {
			image: [ heroUrl ],
		} )
		expect( result.blocks[ 0 ].attributes.background.size ).toBeUndefined()
		expect( result.blocks[ 0 ].attributes.width ).toBeUndefined()
		expect( result.blocks[ 0 ].media_kind ).toBe( 'background' )
	} )

	it( 'marks core/cover compact media as a background canvas', () => {
		const heroUrl = 'http://ai.local/wp-content/uploads/2025/08/cover-hero.webp'
		const blocksById = {
			cid_cover: {
				clientId: 'cid_cover',
				name: 'core/cover',
				attributes: {
					url: heroUrl,
					alt: 'Cover hero',
					id: 88,
					dimRatio: 50,
				},
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_cover ]
		syncFromBlocks( root, 1 )

		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: () => ( {} ),
			},
		}

		const result = getBlocks( {} )
		expect( result.ok ).toBe( true )
		expect( result.blocks[ 0 ].media_kind ).toBe( 'background' )
		expect( result.blocks[ 0 ].attributes.url ).toBe( heroUrl )
	} )

	it( 'includes compact background media on core/group and Stackable heroes', () => {
		const groupUrl = 'http://ai.local/wp-content/uploads/2025/08/group-hero.webp'
		const stackUrl = 'http://ai.local/wp-content/uploads/2025/08/stack-hero.webp'
		const blocksById = {
			cid_group: {
				clientId: 'cid_group',
				name: 'core/group',
				attributes: {
					style: {
						color: { background: '#111111' },
						background: {
							backgroundImage: { url: groupUrl },
						},
					},
					layout: { type: 'constrained' },
				},
				innerBlocks: [],
			},
			cid_stack: {
				clientId: 'cid_stack',
				name: 'stackable/hero',
				attributes: {
					blockBackground: {
						image: { url: stackUrl },
						color: '#000000',
					},
					uniqueId: 'hero-1',
				},
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_group, blocksById.cid_stack ]
		syncFromBlocks( root, 1 )

		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: () => ( {} ),
			},
		}

		const result = getBlocks( {} )
		expect( result.ok ).toBe( true )
		expect( result.blocks[ 0 ].media_kind ).toBe( 'background' )
		expect( result.blocks[ 0 ].attributes.style ).toEqual( {
			background: {
				backgroundImage: { url: groupUrl },
			},
		} )
		expect( result.blocks[ 1 ].media_kind ).toBe( 'background' )
		expect( result.blocks[ 1 ].attributes.blockBackground ).toEqual( {
			image: { url: stackUrl },
		} )
	} )

	it( 'enforces a compact byte budget without mid-JSON clipping', () => {
		const fatKeys = {}
		for ( let i = 0; i < 40; i++ ) {
			fatKeys[ `designToken${ i }_${ 'x'.repeat( 20 ) }` ] = `value-${ i }`
		}
		const blocksById = {}
		const root = []
		for ( let n = 0; n < 30; n++ ) {
			const id = `cid_${ n }`
			blocksById[ id ] = {
				clientId: id,
				name: 'vendor/huge-row',
				attributes: {
					...fatKeys,
					buttonContent: `CTA ${ n }`,
					buttonUrl: `http://example.com/p/${ n }`,
				},
				innerBlocks: [],
			}
			root.push( blocksById[ id ] )
		}
		syncFromBlocks( root, 1 )

		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: () => ( {} ),
			},
		}

		const result = getBlocks( {} )
		expect( result.ok ).toBe( true )
		expect( JSON.stringify( result ).length ).toBeLessThanOrEqual( GET_BLOCKS_COMPACT_MAX_CHARS )
		expect( result.truncated ).toBe( true )
		expect( result.blocks[ 0 ].ref ).toMatch( /^b\d+$/ )
		expect( result.blocks[ 0 ].attributes.buttonContent ).toBeDefined()
		// attribute_keys are the first thing dropped under budget pressure.
		expect( result.blocks.every( b => ! b.attribute_keys ) ).toBe( true )
	} )

	it( 'enforceGetBlocksByteBudget keeps valid JSON under the cap', () => {
		const huge = {
			ok: true,
			count: 2,
			truncated: false,
			blocks: [
				{
					ref: 'b1',
					name: 'vendor/row',
					attribute_keys: Array.from( { length: 40 }, ( _, i ) => `k${ i }_${ 'y'.repeat( 50 ) }` ),
					attributes: { buttonContent: 'Go', buttonUrl: '/about/' },
					preview: 'Go',
				},
				{
					ref: 'b2',
					name: 'vendor/row',
					attribute_keys: Array.from( { length: 40 }, ( _, i ) => `k${ i }_${ 'z'.repeat( 50 ) }` ),
					innerBlocks: [],
				},
			],
		}
		// Force over budget with a tiny cap.
		const fitted = enforceGetBlocksByteBudget( huge, 800 )
		expect( JSON.stringify( fitted ).length ).toBeLessThanOrEqual( 800 )
		expect( fitted.truncated ).toBe( true )
		expect( fitted.blocks[ 0 ].ref ).toBe( 'b1' )
		expect( () => JSON.parse( JSON.stringify( fitted ) ) ).not.toThrow()
	} )

	it( 'enforceGetBlocksByteBudget keeps compact background media and media_kind', () => {
		const huge = {
			ok: true,
			count: 1,
			truncated: false,
			blocks: [ {
				ref: 'b1',
				name: 'acme/banner',
				media_kind: 'background',
				content_attr: 'content',
				preview: 'Hero',
				attribute_keys: Array.from( { length: 40 }, ( _, i ) => `k${ i }_${ 'y'.repeat( 80 ) }` ),
				attributes: {
					background: { image: [ 'https://example.com/hero.jpg' ] },
					content: 'z'.repeat( 2000 ),
				},
			} ],
		}
		const fitted = enforceGetBlocksByteBudget( huge, 800 )
		expect( fitted.blocks[ 0 ].media_kind ).toBe( 'background' )
		expect( fitted.blocks[ 0 ].attributes.background ).toEqual( {
			image: [ 'https://example.com/hero.jpg' ],
		} )
	} )

	it( 'errors when scoped refs are missing', () => {
		syncFromBlocks( [], 1 )
		global.window.wp = {
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => [],
							getBlock: () => null,
							getSelectedBlockClientIds: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: () => ( {} ),
			},
		}

		const result = getBlocks( { refs: [ 'b99' ] } )
		expect( result.ok ).toBe( false )
		expect( result.wiped ).toBe( true )
	} )
} )

/**
 * Soft-fail when the editor store never commits (e.g. backgrounded tab):
 * dispatch is called, applied payload still has the article text, but live
 * getBlocks() is unchanged — Ahentic must not report ok:true / fat text_chars.
 */
describe( 'setBlocks soft-fail when store does not commit', () => {
	beforeEach( () => {
		resetBlockRefs()
		global.window = global.window || {}
	} )

	afterEach( () => {
		delete global.window.wp
	} )

	it( 'returns ok:false when resetBlocks is a no-op (user: tab out → success, canvas empty)', () => {
		const article = 'Metro Manila commuting is rarely a simple trip. '.repeat( 50 )
		const liveRoot = []
		let resetCalled = false

		global.window.wp = {
			blocks: {
				createBlock: ( name, attributes = {}, innerBlocks = [] ) => ( {
					name,
					attributes,
					innerBlocks,
					clientId: `cid_${ name }_${ Math.random().toString( 36 ).slice( 2, 8 ) }`,
				} ),
			},
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => liveRoot,
							getBlock: () => null,
							getSelectedBlockClientIds: () => [],
							getBlockOrder: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return {
							getCurrentPostId: () => 1,
							getEditedPostContent: () => '',
						}
					}
					return {}
				},
				dispatch: store => {
					if ( store === 'core/block-editor' ) {
						return {
							// Simulate background-tab / throttled editor: call succeeds,
							// store never updates (user tabs away mid-write).
							resetBlocks: () => {
								resetCalled = true
							},
						}
					}
					return {}
				},
			},
		}

		const result = setBlocks( {
			blocks: [
				{
					name: 'core/paragraph',
					attributes: { content: article },
					innerBlocks: [],
				},
			],
		} )

		expect( resetCalled ).toBe( true )
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'write_not_applied' )
		expect( liveRoot ).toHaveLength( 0 )
	} )

	it( 'returns ok:true when resetBlocks commits the applied clientIds', () => {
		const article = 'A committed write lands in the live store.'
		let liveRoot = []

		global.window.wp = {
			blocks: {
				createBlock: ( name, attributes = {}, innerBlocks = [] ) => ( {
					name,
					attributes,
					innerBlocks,
					clientId: 'cid_committed',
				} ),
			},
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => liveRoot,
							getBlock: id => liveRoot.find( b => b.clientId === id ) || null,
							getSelectedBlockClientIds: () => [],
							getBlockOrder: () => liveRoot.map( b => b.clientId ),
						}
					}
					if ( store === 'core/editor' ) {
						return {
							getCurrentPostId: () => 1,
							getEditedPostContent: () => '',
						}
					}
					return {}
				},
				dispatch: store => {
					if ( store === 'core/block-editor' ) {
						return {
							resetBlocks: next => {
								liveRoot = next
							},
						}
					}
					return {}
				},
			},
		}

		const result = setBlocks( {
			blocks: [
				{
					name: 'core/paragraph',
					attributes: { content: article },
					innerBlocks: [],
				},
			],
		} )

		expect( result.ok ).toBe( true )
		expect( liveRoot ).toHaveLength( 1 )
		expect( result.text_chars ).toBe( article.length )
	} )
} )

describe( 'assertBlocksApplied', () => {
	it( 'fails when applied clientIds are absent from live', () => {
		const result = assertBlocksApplied(
			[ {
				clientId: 'a',
				name: 'core/paragraph',
				attributes: {},
				innerBlocks: [],
			} ],
			[]
		)
		expect( result ).toEqual( expect.objectContaining( {
			ok: false,
			error: 'write_not_applied',
		} ) )
	} )

	it( 'passes when applied clientIds are present (including nested)', () => {
		const applied = [
			{
				clientId: 'parent',
				name: 'core/group',
				attributes: {},
				innerBlocks: [
					{
						clientId: 'child',
						name: 'core/paragraph',
						attributes: {},
						innerBlocks: [],
					},
				],
			},
		]
		expect( assertBlocksApplied( applied, applied ).ok ).toBe( true )
	} )
} )

describe( 'measureEditorTextChars (fallbacks)', () => {
	it( 'prefers serialize when attr walk cannot see third-party copy', () => {
		const live = [
			{
				name: 'acme/card',
				attributes: { blurb: 'Third party stores copy in a custom attribute.' },
				innerBlocks: [],
			},
		]
		const html =
			'<!-- wp:acme/card --><p>' +
			'Third party stores copy in a custom attribute. '.repeat( 60 ) +
			'</p><!-- /wp:acme/card -->'

		const measured = measureEditorTextChars( {
			live,
			wp: {
				blocks: {
					serialize: () => html,
				},
			},
		} )

		expect( blockTextChars( live ) ).toBe( 0 )
		expect( measured.text_chars ).toBeGreaterThan( 2000 )
		expect( measured.text_chars_source ).toBe( 'serialize' )
	} )

	it( 'falls back to applied payload when live attrs are opaque', () => {
		const applied = [
			{
				name: 'core/paragraph',
				attributes: {
					content: 'Applied payload still has readable strings. '.repeat( 50 ),
				},
				innerBlocks: [],
			},
		]
		const live = [
			{
				name: 'acme/mystery',
				attributes: { payload: { nested: true } },
				innerBlocks: [],
			},
		]

		const measured = measureEditorTextChars( { live, applied } )
		expect( measured.text_chars ).toBeGreaterThan( 2000 )
		expect( measured.text_chars_source ).toBe( 'applied' )
	} )

	it( 'uses edited post content when serialize is unavailable', () => {
		const body = 'Edited post content carries the article text. '.repeat( 50 )
		const measured = measureEditorTextChars( {
			live: [
				{
					name: 'acme/x',
					attributes: {},
					innerBlocks: [],
				},
			],
			select: store => {
				if ( store !== 'core/editor' ) {
					return {}
				}
				return { getEditedPostContent: () => `<p>${ body }</p>` }
			},
		} )
		expect( measured.text_chars ).toBe( plainTextCharsFromHtml( `<p>${ body }</p>` ) )
		expect( measured.text_chars_source ).toBe( 'edited_post' )
	} )
} )

describe( 'convertBlocks target', () => {
	beforeEach( () => {
		resetBlockRefs()
		global.window = global.window || {}
	} )

	afterEach( () => {
		delete global.window.wp
	} )

	function installEditor( {
		blocksById,
		root,
		replaced = [],
		transforms = {},
		blockTypes = [],
	} ) {
		syncFromBlocks( root, 1 )
		global.window.wp = {
			blocks: {
				createBlock: ( name, attributes = {}, innerBlocks = [] ) => ( {
					name,
					attributes,
					innerBlocks,
					clientId: `cid_new_${ name }_${ Math.random().toString( 16 ).slice( 2, 8 ) }`,
				} ),
				getBlockType: name => blockTypes.find( t => t.name === name ) || null,
				getBlockTypes: () => blockTypes,
				getPossibleBlockTransformations: blocks => {
					const from = blocks?.[ 0 ]?.name
					return ( transforms[ from ] || [] ).map( name => ( { name } ) )
				},
				switchToBlockType: ( block, dest ) => {
					const allowed = transforms[ block.name ] || []
					if ( ! allowed.includes( dest ) ) {
						return null
					}
					return [ {
						clientId: `cid_switched_${ dest }`,
						name: dest,
						attributes: { ...( block.attributes || {} ), ported: true },
						innerBlocks: block.innerBlocks || [],
					} ]
				},
			},
			data: {
				select: store => {
					if ( store === 'core/block-editor' ) {
						return {
							getBlocks: () => root,
							getBlock: id => blocksById[ id ] || null,
							getSelectedBlockClientIds: () => [],
							getBlockParents: () => [],
						}
					}
					if ( store === 'core/editor' ) {
						return { getCurrentPostId: () => 1 }
					}
					return {}
				},
				dispatch: store => {
					if ( store !== 'core/block-editor' ) {
						return {}
					}
					return {
						replaceBlocks: ( ids, next ) => {
							replaced.push( { ids, next } )
						},
					}
				},
			},
		}
		return { replaced }
	}

	it( 'skips core blocks when target is core (default)', () => {
		const blocksById = {
			cid_a: {
				clientId: 'cid_a',
				name: 'core/paragraph',
				attributes: { content: 'Hello' },
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_a ]
		const { replaced } = installEditor( { blocksById, root } )

		const result = convertBlocks( { refs: [ 'b1' ] } )
		expect( result.ok ).toBe( true )
		expect( result.target ).toBe( 'core' )
		expect( result.converted_count ).toBe( 0 )
		expect( result.skipped[ 0 ].reason ).toBe( 'already_target' )
		expect( replaced ).toHaveLength( 0 )
	} )

	it( 'converts core paragraph to a plugin namespace via switchToBlockType', () => {
		const blocksById = {
			cid_a: {
				clientId: 'cid_a',
				name: 'core/paragraph',
				attributes: { content: 'Private cars remain useful.' },
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_a ]
		const { replaced } = installEditor( {
			blocksById,
			root,
			transforms: {
				'core/paragraph': [ 'stackable/text', 'core/heading' ],
			},
			blockTypes: [
				{ name: 'stackable/text', attributes: { text: { type: 'string' } } },
			],
		} )

		const result = convertBlocks( { refs: [ 'b1' ], target: 'stackable' } )
		expect( result.ok ).toBe( true )
		expect( result.target ).toBe( 'stackable' )
		expect( result.converted_count ).toBe( 1 )
		expect( result.converted[ 0 ] ).toMatchObject( {
			from: 'core/paragraph',
			to: 'stackable/text',
			method: 'switchToBlockType',
		} )
		expect( replaced ).toHaveLength( 1 )
		expect( replaced[ 0 ].next[ 0 ].name ).toBe( 'stackable/text' )
	} )

	it( 'honors an exact target block name', () => {
		const blocksById = {
			cid_a: {
				clientId: 'cid_a',
				name: 'core/paragraph',
				attributes: { content: 'Heading-worthy copy' },
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_a ]
		installEditor( {
			blocksById,
			root,
			transforms: {
				'core/paragraph': [ 'stackable/text', 'core/heading' ],
			},
		} )

		const result = convertBlocks( { refs: [ 'b1' ], target: 'core/heading' } )
		expect( result.converted_count ).toBe( 1 )
		expect( result.converted[ 0 ].to ).toBe( 'core/heading' )
	} )

	it( 'dry_run reports conversions without replacing blocks', () => {
		const blocksById = {
			cid_a: {
				clientId: 'cid_a',
				name: 'core/paragraph',
				attributes: { content: 'Dry run me' },
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_a ]
		const { replaced } = installEditor( {
			blocksById,
			root,
			transforms: { 'core/paragraph': [ 'stackable/text' ] },
		} )

		const result = convertBlocks( {
			refs: [ 'b1' ],
			target: 'stackable',
			dry_run: true,
		} )
		expect( result.dry_run ).toBe( true )
		expect( result.converted_count ).toBe( 1 )
		expect( replaced ).toHaveLength( 0 )
	} )

	it( 'falls back to heuristic createBlock when no transform exists', () => {
		const blocksById = {
			cid_a: {
				clientId: 'cid_a',
				name: 'core/paragraph',
				attributes: { content: 'Heuristic port' },
				innerBlocks: [],
			},
		}
		const root = [ blocksById.cid_a ]
		const { replaced } = installEditor( {
			blocksById,
			root,
			transforms: {},
			blockTypes: [
				{
					name: 'acme/text',
					attributes: {
						text: { type: 'string' },
						theme: { type: 'object' },
					},
				},
			],
		} )

		const result = convertBlocks( { refs: [ 'b1' ], target: 'acme' } )
		expect( result.converted_count ).toBe( 1 )
		expect( result.converted[ 0 ].method ).toBe( 'heuristic' )
		expect( result.converted[ 0 ].to ).toBe( 'acme/text' )
		expect( replaced[ 0 ].next[ 0 ].attributes.text ).toContain( 'Heuristic' )
	} )
} )

describe( 'getBlockType fields', () => {
	beforeEach( () => {
		global.window = global.window || {}
	} )

	afterEach( () => {
		delete global.window.wp
	} )

	it( 'returns slim convert schema without design attrs', () => {
		global.window.wp = {
			blocks: {
				getBlockType: () => ( {
					name: 'stackable/text',
					title: 'Text',
					category: 'stackable',
					description: 'Long description',
					attributes: {
						text: { type: 'string', source: 'html' },
						uniqueId: { type: 'string' },
						blockMargin: { type: 'object' },
						showText: { type: 'boolean', default: true },
					},
					supports: { spacing: true },
				} ),
				getBlockVariations: () => [ { name: 'default', title: 'Default' } ],
			},
		}

		const full = getBlockType( { name: 'stackable/text' } )
		expect( full.attributes.blockMargin ).toBeTruthy()
		expect( full.supports ).toBeTruthy()

		const slim = getBlockType( { name: 'stackable/text', fields: 'convert' } )
		expect( slim.fields ).toBe( 'convert' )
		expect( slim.attributes.text ).toBeTruthy()
		expect( slim.attributes.blockMargin ).toBeUndefined()
		expect( slim.attributes.uniqueId ).toBeUndefined()
		expect( slim.supports ).toBeUndefined()
		expect( slim.description ).toBe( '' )
	} )
} )
