/**
 * @jest-environment jsdom
 */

import { htmlPageSignals, htmlToExcerpt } from './browser-abilities'

describe( 'http-fetch page evidence (browser parity)', () => {
	it( 'keeps short excerpts intact', () => {
		expect( htmlToExcerpt( '<p>Call us at the front desk.</p>' ) ).toBe(
			'Call us at the front desk.'
		)
	} )

	it( 'keeps head and tail when truncated', () => {
		const head = `HEAD-START-${ 'A'.repeat( 2500 ) }`
		const mid = `MID-ONLY-MARKER-${ 'M'.repeat( 3000 ) }`
		const tail = 'FOOTER-MARKER-578-393-4937'
		const excerpt = htmlToExcerpt( `<div>${ head }${ mid }${ tail }</div>` )
		expect( excerpt.length ).toBeLessThanOrEqual( 4000 )
		expect( excerpt ).toContain( 'HEAD-START-' )
		expect( excerpt ).toContain( 'FOOTER-MARKER-578-393-4937' )
		expect( excerpt ).toContain( '…' )
		expect( excerpt ).not.toContain( 'MID-ONLY-MARKER-' )
	} )

	it( 'finds mailto, tel, and emails', () => {
		const html =
			'<footer><a href="mailto:hello@example.com">Email</a> ' +
			'<a href="tel:+15783934937">Call</a> Reach sales@example.org anytime.</footer>'
		const signals = htmlPageSignals( html )
		expect( signals.emails ).toEqual(
			expect.arrayContaining( [ 'hello@example.com', 'sales@example.org' ] )
		)
		expect( signals.mailto_links ).toEqual(
			expect.arrayContaining( [ 'mailto:hello@example.com' ] )
		)
		expect( signals.tel_links ).toEqual(
			expect.arrayContaining( [ 'tel:+15783934937' ] )
		)
	} )
} )
