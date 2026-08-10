/**
 * @jest-environment jsdom
 */

import { fillFields } from './visible-page'

describe( 'fillFields', () => {
	beforeEach( () => {
		document.body.innerHTML = `
			<div id="wpbody-content">
				<label for="blogname">Site Title</label>
				<input id="blogname" name="blogname" type="text" value="" />
				<label for="blogdescription">Tagline</label>
				<input id="blogdescription" name="blogdescription" type="text" value="" />
				<select id="date_format" name="date_format">
					<option value="F j, Y">F j, Y</option>
					<option value="Y-m-d">Y-m-d</option>
				</select>
				<label><input type="checkbox" name="blog_public" value="1" /> Search engine visibility</label>
				<label for="pass1">New Password</label>
				<input id="pass1" name="pass1" type="password" value="" />
				<label for="siteurl">WordPress Address</label>
				<input id="siteurl" name="siteurl" type="text" value="" />
				<input type="hidden" name="hidden_secret" value="x" />
				<input type="submit" name="submit" value="Save Changes" />
			</div>
			<div id="ahentic-root" class="ahentic">
				<input id="ah-internal" name="ah_internal" type="text" value="" />
			</div>
		`
		document.querySelectorAll( 'input, select, textarea, label' ).forEach( el => {
			el.getBoundingClientRect = () => ( {
				width: 120,
				height: 24,
				top: 0,
				left: 0,
				bottom: 24,
				right: 120,
			} )
		} )
	} )

	it( 'fills by name and fires input/change without submitting', () => {
		const blogname = document.getElementById( 'blogname' )
		const events = []
		blogname.addEventListener( 'input', () => events.push( 'input' ) )
		blogname.addEventListener( 'change', () => events.push( 'change' ) )

		const result = fillFields( {
			fields: [ { name: 'blogname', value: 'Acme' } ],
		} )

		expect( result.ok ).toBe( true )
		expect( blogname.value ).toBe( 'Acme' )
		expect( events ).toEqual( [ 'input', 'change' ] )
		expect( result.filled ).toHaveLength( 1 )
		expect( result.notes.join( ' ' ) ).toMatch( /does not submit/i )
	} )

	it( 'fills select by option text and checkbox by boolean', () => {
		const result = fillFields( {
			fields: [
				{ name: 'date_format', value: 'Y-m-d' },
				{ name: 'blog_public', value: true },
			],
		} )

		expect( result.ok ).toBe( true )
		expect( document.getElementById( 'date_format' ).value ).toBe( 'Y-m-d' )
		expect( document.querySelector( '[name="blog_public"]' ).checked ).toBe( true )
		expect( result.skipped ).toHaveLength( 0 )
	} )

	it( 'refuses hard-denied option keys without touching the control', () => {
		const siteurl = document.getElementById( 'siteurl' )
		siteurl.value = 'https://example.com'

		const result = fillFields( {
			fields: [
				{ name: 'blogname', value: 'Ok' },
				{ name: 'siteurl', value: 'https://evil.example' },
				{ name: 'users_can_register', value: true },
			],
		} )

		expect( result.ok ).toBe( true )
		expect( document.getElementById( 'blogname' ).value ).toBe( 'Ok' )
		expect( siteurl.value ).toBe( 'https://example.com' )
		expect( result.skipped.map( s => s.reason ) ).toEqual( [
			'option_denied',
			'option_denied',
		] )
	} )

	it( 'still fills password fields in the DOM (HITL is enforced server-side)', () => {
		const result = fillFields( {
			fields: [ { name: 'pass1', value: 'hunter2' } ],
		} )
		expect( result.ok ).toBe( true )
		expect( document.getElementById( 'pass1' ).value ).toBe( 'hunter2' )
	} )

	it( 'reports missing and skips Ahentic UI / unsupported types without aborting siblings', () => {
		const result = fillFields( {
			fields: [
				{ name: 'blogname', value: 'Ok' },
				{ name: 'missing_field', value: 'Nope' },
				{ name: 'ah_internal', value: 'Nope' },
				{ name: 'hidden_secret', value: 'Nope' },
			],
		} )

		expect( result.ok ).toBe( true )
		expect( document.getElementById( 'blogname' ).value ).toBe( 'Ok' )
		expect( result.filled ).toHaveLength( 1 )
		expect( result.skipped.map( s => s.reason ) ).toEqual( [
			'not_found',
			'not_found',
			'not_found',
		] )
	} )

	it( 'disambiguates duplicate names with label', () => {
		document.getElementById( 'wpbody-content' ).insertAdjacentHTML(
			'beforeend',
			`
			<label for="opt_a">Option A</label>
			<input id="opt_a" name="option" type="text" value="" />
			<label for="opt_b">Option B</label>
			<input id="opt_b" name="option" type="text" value="" />
			`
		)
		document.querySelectorAll( '#opt_a, #opt_b, label[for="opt_a"], label[for="opt_b"]' ).forEach( el => {
			el.getBoundingClientRect = () => ( {
				width: 120,
				height: 24,
				top: 0,
				left: 0,
				bottom: 24,
				right: 120,
			} )
		} )

		const ambiguous = fillFields( {
			fields: [ { name: 'option', value: 'x' } ],
		} )
		expect( ambiguous.ok ).toBe( false )
		expect( ambiguous.skipped[ 0 ].reason ).toBe( 'ambiguous' )

		const result = fillFields( {
			fields: [ {
				name: 'option', label: 'Option B', value: 'chosen',
			} ],
		} )
		expect( result.ok ).toBe( true )
		expect( document.getElementById( 'opt_b' ).value ).toBe( 'chosen' )
		expect( document.getElementById( 'opt_a' ).value ).toBe( '' )
	} )
} )
