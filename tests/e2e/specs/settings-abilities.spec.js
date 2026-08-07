/**
 * Track C — settings discovery (Task 07) + update-theme-setting (Task 08)
 * + update-global-styles (Task 09).
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const { runAbility } = require( '../utils/ability-client' )
const { createSession } = require( '../utils/session-client' )

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

test.describe( 'ahentic/update-theme-setting', () => {
	test( 'rejects unknown ids and code-bearing custom_css', async ( { requestUtils } ) => {
		const missing = await runAbility( requestUtils, 'ahentic/update-theme-setting', {
			changes: [ { id: 'ahentic-invented-theme-mod', value: 'x' } ],
		} )
		expect( missing.ok ).toBe( false )
		expect( missing.error ).toBe( 'ahentic_setting_not_found' )

		const code = await runAbility( requestUtils, 'ahentic/update-theme-setting', {
			changes: [ { id: 'custom_css[twentytwentyfour]', value: 'body{}' } ],
		} )
		expect( code.ok ).toBe( false )
		expect( code.error ).toBe( 'ahentic_code_bearing_setting' )
	} )

	test( 'dry_run / write+undo when blogname is in the index', async ( { requestUtils } ) => {
		const got = await runAbility( requestUtils, 'ahentic/get-setting', {
			ids: [ 'blogname' ],
			raw: true,
		} )
		expect( got.ok, JSON.stringify( got ) ).toBe( true )

		const row = got.data.settings?.[ 0 ]
		if ( ! row?.ok ) {
			test.info().annotations.push( {
				type: 'note',
				description:
					'blogname not in Customizer index — write/undo path skipped.',
			} )
			return
		}

		const prior = row.value
		const nextTitle = `Ahentic E2E ${ Date.now() }`

		const dry = await runAbility( requestUtils, 'ahentic/update-theme-setting', {
			dry_run: true,
			changes: [ { id: 'blogname', value: nextTitle } ],
		} )
		expect( dry.ok, JSON.stringify( dry ) ).toBe( true )
		expect( dry.data.dry_run ).toBe( true )
		expect( dry.data.changes[ 0 ].prior ).toBe( prior )
		expect( dry.data.changes[ 0 ].next ).toBe( nextTitle )

		// Confirm dry_run did not persist.
		const afterDry = await runAbility( requestUtils, 'ahentic/get-setting', {
			ids: [ 'blogname' ],
			raw: true,
		} )
		expect( afterDry.ok ).toBe( true )
		expect( afterDry.data.settings[ 0 ].value ).toBe( prior )

		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const written = await runAbility(
			requestUtils,
			'ahentic/update-theme-setting',
			{ changes: [ { id: 'blogname', value: nextTitle } ] },
			{ sessionId }
		)
		expect( written.ok, JSON.stringify( written ) ).toBe( true )
		expect( written.data.dry_run ).toBe( false )
		expect( written.data.changes[ 0 ].next ).toBe( nextTitle )

		const live = await runAbility( requestUtils, 'ahentic/get-setting', {
			ids: [ 'blogname' ],
			raw: true,
		} )
		expect( live.data.settings[ 0 ].value ).toBe( nextTitle )

		const undo = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( undo.ok, JSON.stringify( undo ) ).toBe( true )
		expect( undo.data.undone ).toBe( 1 )

		const restored = await runAbility( requestUtils, 'ahentic/get-setting', {
			ids: [ 'blogname' ],
			raw: true,
		} )
		expect( restored.data.settings[ 0 ].value ).toBe( prior )
	} )
} )

test.describe( 'ahentic/update-global-styles', () => {
	test( 'refuses classic themes; dry_run / write+undo / css strip on block themes', async ( {
		requestUtils,
	} ) => {
		const context = await runAbility( requestUtils, 'ahentic/get-settings-context' )
		expect( context.ok, JSON.stringify( context ) ).toBe( true )

		if ( ! context.data.is_block_theme ) {
			const refused = await runAbility( requestUtils, 'ahentic/update-global-styles', {
				styles: { color: { background: '#ffffff' } },
			} )
			expect( refused.ok ).toBe( false )
			expect( refused.error ).toBe( 'ahentic_not_block_theme' )
			return
		}

		const cssOnly = await runAbility( requestUtils, 'ahentic/update-global-styles', {
			styles: { css: 'body{color:red}' },
		} )
		expect( cssOnly.ok ).toBe( false )
		expect( cssOnly.error ).toBe( 'ahentic_code_bearing_setting' )

		const marker = `#a${ Date.now().toString( 16 ).slice( -6 ) }`
		const dry = await runAbility( requestUtils, 'ahentic/update-global-styles', {
			dry_run: true,
			styles: {
				css: 'should-be-stripped',
				color: { background: marker },
			},
		} )
		expect( dry.ok, JSON.stringify( dry ) ).toBe( true )
		expect( dry.data.dry_run ).toBe( true )
		expect( dry.data.surface ).toBe( 'global_styles' )
		expect( dry.data.stripped_css ).toBe( true )
		expect( dry.data.next?.styles?.color?.background ).toBe( marker )
		expect( dry.data.next?.styles?.css ).toBeUndefined()

		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const written = await runAbility(
			requestUtils,
			'ahentic/update-global-styles',
			{
				styles: {
					css: 'should-be-stripped',
					color: { background: marker },
				},
			},
			{ sessionId }
		)
		expect( written.ok, JSON.stringify( written ) ).toBe( true )
		expect( written.data.dry_run ).toBe( false )
		expect( written.data.next?.styles?.color?.background ).toBe( marker )
		expect( written.data.next?.styles?.css ).toBeUndefined()
		expect( written.data.post_id ).toBeGreaterThan( 0 )

		const undo = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( undo.ok, JSON.stringify( undo ) ).toBe( true )
		expect( undo.data.undone ).toBe( 1 )
	} )
} )
