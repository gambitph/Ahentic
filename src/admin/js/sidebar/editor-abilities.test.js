/**
 * @jest-environment node
 */

import {
	blockTextChars,
	measureEditorTextChars,
	plainTextCharsFromHtml,
	prepareBlocksPayload,
	resolveTargetClientIds,
} from './editor-abilities'

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
