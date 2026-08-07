/**
 * @jest-environment node
 */

import { planPostDocumentEdits } from './post-document-edits'

describe( 'planPostDocumentEdits', () => {
	it( 'accepts title, excerpt, and slug together', () => {
		expect(
			planPostDocumentEdits( {
				title: ' Hello ',
				excerpt: 'A summary',
				slug: ' hello-world ',
			} )
		).toEqual( {
			ok: true,
			edits: {
				title: 'Hello',
				excerpt: 'A summary',
				slug: 'hello-world',
			},
		} )
	} )

	it( 'allows clearing excerpt with an empty string', () => {
		expect( planPostDocumentEdits( { excerpt: '' } ) ).toEqual( {
			ok: true,
			edits: { excerpt: '' },
		} )
	} )

	it( 'rejects empty title', () => {
		const result = planPostDocumentEdits( { title: '   ' } )
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'invalid_title' )
	} )

	it( 'rejects empty slug', () => {
		const result = planPostDocumentEdits( { slug: '' } )
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'invalid_slug' )
	} )

	it( 'requires at least one allowlisted field', () => {
		const result = planPostDocumentEdits( { attachment_id: 1 } )
		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'missing_fields' )
	} )
} )
