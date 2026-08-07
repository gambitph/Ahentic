/**
 * @jest-environment node
 */

import {
	prepareBlocksPayload,
	resolveTargetClientIds,
} from './editor-abilities'

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
