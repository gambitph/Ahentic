/**
 * @jest-environment node
 */

import { resolveMovePlacement } from './move-placement'

function deps( overrides = {} ) {
	const orderByRoot = {
		'': [ 'h1', 'img', 'p1' ],
		col: [ 'a', 'b' ],
		...overrides.orderByRoot,
	}
	const rootById = {
		h1: '',
		img: '',
		p1: '',
		a: 'col',
		b: 'col',
		col: '',
		...overrides.rootById,
	}
	const refs = {
		h1: 'h1',
		img: 'img',
		p1: 'p1',
		a: 'a',
		b: 'b',
		col: 'col',
		...overrides.refs,
	}
	return {
		resolveRef: token => refs[ token ] || null,
		getRootClientId: id => ( rootById[ id ] !== undefined ? rootById[ id ] : '' ),
		getBlockOrder: root => orderByRoot[ root ] || [],
		defaultRootClientId: overrides.defaultRootClientId ?? '',
	}
}

describe( 'resolveMovePlacement', () => {
	it( 'places after_ref immediately after the anchor (document root)', () => {
		expect(
			resolveMovePlacement( { refs: [ 'img' ], after_ref: 'h1' }, deps() )
		).toEqual( {
			ok: true, index: 1, toRoot: '',
		} )
	} )

	it( 'places before_ref at the anchor index', () => {
		expect(
			resolveMovePlacement( { refs: [ 'img' ], before_ref: 'p1' }, deps() )
		).toEqual( {
			ok: true, index: 2, toRoot: '',
		} )
	} )

	it( 'resolves relative refs inside a parent', () => {
		expect(
			resolveMovePlacement( { refs: [ 'b' ], after_ref: 'a' }, deps() )
		).toEqual( {
			ok: true, index: 1, toRoot: 'col',
		} )
	} )

	it( 'keeps numeric index with optional root_ref', () => {
		expect(
			resolveMovePlacement( {
				refs: [ 'a' ], index: 0, root_ref: 'col',
			}, deps() )
		).toEqual( {
			ok: true, index: 0, toRoot: 'col',
		} )
	} )

	it( 'rejects before_ref and after_ref together', () => {
		const result = resolveMovePlacement(
			{
				refs: [ 'img' ], before_ref: 'h1', after_ref: 'p1',
			},
			deps()
		)
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'conflicting_relative_refs' )
	} )

	it( 'rejects mixing index with after_ref', () => {
		const result = resolveMovePlacement(
			{
				refs: [ 'img' ], index: 0, after_ref: 'h1',
			},
			deps()
		)
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'mixed_move_targeting' )
	} )

	it( 'rejects missing target', () => {
		const result = resolveMovePlacement( { refs: [ 'img' ] }, deps() )
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'missing_move_target' )
	} )

	it( 'reports missing relative ref', () => {
		const result = resolveMovePlacement(
			{ refs: [ 'img' ], after_ref: 'missing' },
			deps()
		)
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'missing_refs' )
		expect( result.missing ).toEqual( [ 'missing' ] )
	} )
} )
