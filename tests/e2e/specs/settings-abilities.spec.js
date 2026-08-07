/**
 * Track C — settings discovery (Task 07): context, list-settings, get-setting.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const { runAbility } = require( '../utils/ability-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'ahentic/get-settings-context', () => {
	test( 'reports active theme surfaces without requiring list filters', async ( {
		requestUtils,
	} ) => {
		const result = await runAbility( requestUtils, 'ahentic/get-settings-context' )

		expect( result.ok, JSON.stringify( result ) ).toBe( true )
		expect( result.data ).toMatchObject( {
			ok: true,
			stylesheet: expect.any( String ),
			is_block_theme: expect.any( Boolean ),
			surfaces: expect.any( Array ),
			routing_hint: expect.any( String ),
		} )
		expect( result.data.surfaces.length ).toBeGreaterThan( 0 )

		if ( result.data.is_block_theme ) {
			expect( result.data.surfaces ).toEqual(
				expect.arrayContaining( [ 'global_styles', 'template_parts' ] )
			)
			expect( result.data.surfaces ).not.toContain( 'theme_settings' )
		} else {
			expect( result.data.surfaces ).toContain( 'theme_settings' )
			expect( result.data.routing_hint ).toMatch( /list-settings/ )
		}
	} )
} )

test.describe( 'ahentic/list-settings', () => {
	test( 'refuses an unfiltered registry dump', async ( { requestUtils } ) => {
		const result = await runAbility( requestUtils, 'ahentic/list-settings', {} )

		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'ahentic_settings_unfiltered' )
		expect( String( result.message || '' ).toLowerCase() ).toMatch( /filter|query|section|prefix/ )
	} )

	test( 'returns a filtered page for classic themes (or empty note for block)', async ( {
		requestUtils,
	} ) => {
		const context = await runAbility( requestUtils, 'ahentic/get-settings-context' )
		expect( context.ok ).toBe( true )

		const result = await runAbility( requestUtils, 'ahentic/list-settings', {
			query: 'title',
			limit: 10,
		} )

		expect( result.ok, JSON.stringify( result ) ).toBe( true )
		expect( result.data.ok ).toBe( true )
		expect( Array.isArray( result.data.settings ) ).toBe( true )
		expect( result.data.limit ).toBeLessThanOrEqual( 50 )

		if ( context.data.is_block_theme ) {
			expect( result.data.count ).toBe( 0 )
			expect( result.data.message || '' ).toMatch( /block theme/i )
			return
		}

		// Classic: at least core blogname / site title usually matches "title".
		for ( const row of result.data.settings ) {
			expect( row.id ).toBeTruthy()
			expect( String( row.id ) ).not.toMatch( /^custom_css/ )
			expect( String( row.control_type || '' ) ).not.toMatch( /Code_Editor/i )
		}
	} )
} )

test.describe( 'ahentic/get-setting', () => {
	test( 'reads a known setting when present in the index', async ( { requestUtils } ) => {
		const context = await runAbility( requestUtils, 'ahentic/get-settings-context' )
		expect( context.ok ).toBe( true )

		if ( context.data.is_block_theme ) {
			test.info().annotations.push( {
				type: 'note',
				description: 'Active theme is block — get-setting index is classic Customizer only.',
			} )
		}

		const listed = await runAbility( requestUtils, 'ahentic/list-settings', {
			query: 'blogname',
			limit: 5,
		} )
		expect( listed.ok, JSON.stringify( listed ) ).toBe( true )

		if ( ! listed.data.settings?.length ) {
			// Block theme or minimal Customizer — still assert missing-id path.
			const missing = await runAbility( requestUtils, 'ahentic/get-setting', {
				ids: [ 'ahentic-definitely-missing-setting' ],
			} )
			expect( missing.ok, JSON.stringify( missing ) ).toBe( true )
			expect( missing.data.settings[ 0 ].ok ).toBe( false )
			expect( missing.data.settings[ 0 ].error ).toBe( 'ahentic_setting_not_found' )
			return
		}

		const id = listed.data.settings[ 0 ].id
		const got = await runAbility( requestUtils, 'ahentic/get-setting', { ids: [ id ] } )
		expect( got.ok, JSON.stringify( got ) ).toBe( true )
		expect( got.data.settings ).toHaveLength( 1 )
		expect( got.data.settings[ 0 ].ok ).toBe( true )
		expect( got.data.settings[ 0 ].id ).toBe( id )
		expect(
			got.data.settings[ 0 ].summarized === true ||
				Object.prototype.hasOwnProperty.call( got.data.settings[ 0 ], 'value' )
		).toBe( true )
	} )
} )
