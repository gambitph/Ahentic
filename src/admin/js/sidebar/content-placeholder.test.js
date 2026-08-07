/**
 * @jest-environment node
 */

import {
	looksLikeContentPlaceholder,
	contentPlaceholderRules,
} from './content-placeholder'

describe( 'content-placeholder (shared rules)', () => {
	it( 'loads patterns and samples from the shared JSON', () => {
		expect( Array.isArray( contentPlaceholderRules.patterns ) ).toBe( true )
		expect( contentPlaceholderRules.patterns.length ).toBeGreaterThan( 0 )
		expect( contentPlaceholderRules.samples.placeholder.length ).toBeGreaterThan( 0 )
		expect( contentPlaceholderRules.samples.real.length ).toBeGreaterThan( 0 )
	} )

	it( 'flags every shared placeholder sample', () => {
		contentPlaceholderRules.samples.placeholder.forEach( sample => {
			expect( looksLikeContentPlaceholder( sample ) ).toBe( true )
		} )
	} )

	it( 'accepts every shared real sample', () => {
		contentPlaceholderRules.samples.real.forEach( sample => {
			expect( looksLikeContentPlaceholder( sample ) ).toBe( false )
		} )
	} )
} )
