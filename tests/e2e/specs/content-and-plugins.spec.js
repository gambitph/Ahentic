/**
 * Module spec: existing content/site/plugin abilities + Track B gaps.
 *
 * This is the harness "proof" spec — it exercises abilities against a real
 * WordPress instance (booted by `@wp-playground/cli`) via the e2e-only
 * `ahentic-e2e/v1/run-ability` route, without driving a real LLM turn. See
 * tests/e2e/README.md for the pattern.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const { request: apiRequest } = require( '@playwright/test' )
const { runAbility, seed } = require( '../utils/ability-client' )
const { createSession } = require( '../utils/session-client' )

test.describe( 'ahentic-e2e harness', () => {
	test( 'the e2e ability-runner mu-plugin is loaded', async ( { requestUtils } ) => {
		const body = await requestUtils.rest( { path: '/ahentic-e2e/v1/health' } )

		expect( body.abilities_loaded ).toBe( true )
	} )
} )

test.describe( 'ahentic/get-site-snapshot', () => {
	test( 'returns real site identity, not a stub', async ( { requestUtils, baseURL } ) => {
		const result = await runAbility( requestUtils, 'ahentic/get-site-snapshot' )

		expect( result.ok ).toBe( true )
		expect( result.data.home_url.replace( /\/$/, '' ) ).toBe( baseURL.replace( /\/$/, '' ) )
		expect( result.data.is_multisite ).toBe( false )
		expect( typeof result.data.wp_version ).toBe( 'string' )
		expect( Array.isArray( result.data.plugins ) ).toBe( true )
	} )
} )

test.describe( 'Ahentic_Abilities::execute() dispatch', () => {
	test( 'an unknown ability name fails with a stable error code', async ( { requestUtils } ) => {
		const result = await runAbility( requestUtils, 'ahentic/does-not-exist' )

		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'ahentic_ability_unknown' )
	} )

	test( 'run-ability is not reachable without an authenticated admin', async ( { baseURL } ) => {
		const anonymous = await apiRequest.newContext( { baseURL } )

		const response = await anonymous.post( '/wp-json/ahentic-e2e/v1/run-ability', {
			data: { name: 'ahentic/get-site-snapshot' },
		} )

		expect( response.ok() ).toBe( false )
		await anonymous.dispose()
	} )
} )

test.describe( 'ahentic/list-post-types', () => {
	test( 'returns agent-relevant types with counts and excludes internals', async ( { requestUtils } ) => {
		const result = await runAbility( requestUtils, 'ahentic/list-post-types' )

		expect( result.ok ).toBe( true )
		expect( Array.isArray( result.data.post_types ) ).toBe( true )
		const names = result.data.post_types.map( ( t ) => t.name )
		expect( names ).toContain( 'post' )
		expect( names ).toContain( 'page' )
		expect( names ).not.toContain( 'revision' )
		expect( names ).not.toContain( 'ahentic-session' )
		const post = result.data.post_types.find( ( t ) => t.name === 'post' )
		expect( post ).toMatchObject( {
			label: expect.any( String ),
			public: expect.any( Boolean ),
			show_in_rest: expect.any( Boolean ),
			count: expect.any( Number ),
		} )
	} )
} )

test.describe( 'ahentic/analyze-plugins', () => {
	test( 'flags inactive plugins without mutating the site', async ( { requestUtils } ) => {
		const result = await runAbility( requestUtils, 'ahentic/analyze-plugins' )

		expect( result.ok ).toBe( true )
		expect( result.data.summary ).toMatchObject( {
			inactive: expect.any( Number ),
			overlap: expect.any( Number ),
			has_available_update: expect.any( Number ),
		} )
		expect( Array.isArray( result.data.plugins ) ).toBe( true )
		for ( const plugin of result.data.plugins ) {
			expect( Array.isArray( plugin.flags ) ).toBe( true )
		}
	} )
} )

test.describe( 'ahentic/list-themes', () => {
	test( 'lists installed themes with active and block flags', async ( { requestUtils } ) => {
		const result = await runAbility( requestUtils, 'ahentic/list-themes' )

		expect( result.ok ).toBe( true )
		expect( result.data.count ).toBeGreaterThan( 0 )
		expect( Array.isArray( result.data.themes ) ).toBe( true )
		const active = result.data.themes.filter( ( t ) => t.is_active )
		expect( active ).toHaveLength( 1 )
		expect( active[ 0 ] ).toMatchObject( {
			stylesheet: expect.any( String ),
			is_block_theme: expect.any( Boolean ),
		} )
	} )
} )

test.describe( 'ahentic/replace-in-content', () => {
	test( 'dry_run previews matches without writing', async ( { requestUtils } ) => {
		await seed( requestUtils, {
			posts: [
				{
					post_title: 'Replace target AAA',
					post_content: 'Visit http://example.test/aaa for more.',
					post_status: 'publish',
				},
			],
		} )

		const preview = await runAbility( requestUtils, 'ahentic/replace-in-content', {
			find: 'http://example.test',
			replace: 'https://example.test',
			dry_run: true,
		} )

		expect( preview.ok ).toBe( true )
		expect( preview.data.dry_run ).toBe( true )
		expect( preview.data.post_count ).toBeGreaterThan( 0 )

		const get = await runAbility( requestUtils, 'ahentic/search-content', {
			query: 'http://example.test',
		} )
		expect( get.ok ).toBe( true )
		expect( get.data.count ).toBeGreaterThan( 0 )
	} )
} )

test.describe( 'ahentic/list-revisions + restore-revision', () => {
	test( 'lists revisions and rejects mismatched revision ids', async ( { requestUtils } ) => {
		const seeded = await seed( requestUtils, {
			posts: [
				{
					post_title: 'Revision parent',
					post_content: 'Version one',
					post_status: 'publish',
				},
			],
		} )
		const postId = seeded.created.posts[ 0 ]

		await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			method: 'POST',
			data: { content: 'Version two' },
		} )

		const list = await runAbility( requestUtils, 'ahentic/list-revisions', { post_id: postId } )
		expect( list.ok ).toBe( true )
		expect( list.data.post_id ).toBe( postId )

		const bad = await runAbility( requestUtils, 'ahentic/restore-revision', {
			post_id: postId,
			revision_id: 99999999,
		} )
		expect( bad.ok ).toBe( false )
		expect( bad.error ).toBe( 'ahentic_revision_mismatch' )
	} )
} )

test.describe( 'ahentic/describe-image + generate-image', () => {
	test( 'describe-image rejects both/neither inputs', async ( { requestUtils } ) => {
		const neither = await runAbility( requestUtils, 'ahentic/describe-image', {} )
		expect( neither.ok ).toBe( false )
		expect( neither.error ).toBe( 'ahentic_describe_image_input' )

		const both = await runAbility( requestUtils, 'ahentic/describe-image', {
			attachment_id: 1,
			url: 'https://example.com/x.png',
		} )
		expect( both.ok ).toBe( false )
		expect( both.error ).toBe( 'ahentic_describe_image_input' )
	} )

	test( 'describe-image accepts a remote url via mocked vision', async ( { requestUtils } ) => {
		const result = await runAbility( requestUtils, 'ahentic/describe-image', {
			url: 'https://example.com/photo.jpg',
		} )
		expect( result.ok ).toBe( true )
		expect( result.data.source ).toBe( 'url' )
		expect( result.data.alt_text_suggestion ).toBeTruthy()
	} )

	test( 'generate-image stages an image artifact for the session', async ( { requestUtils } ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID
		expect( sessionId, JSON.stringify( session ) ).toBeTruthy()

		const result = await runAbility(
			requestUtils,
			'ahentic/generate-image',
			{ prompt: 'A tiny blue square for e2e' },
			{ sessionId }
		)

		expect( result.ok ).toBe( true )
		expect( result.data.artifact_key ).toBeTruthy()
		expect( result.data.mime_type ).toBe( 'image/png' )
		expect( result.data.data_uri ).toBeUndefined()
	} )

	test( 'upload-media from_memory sideloads a generated image artifact', async ( { requestUtils } ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID
		expect( sessionId, JSON.stringify( session ) ).toBeTruthy()

		const generated = await runAbility(
			requestUtils,
			'ahentic/generate-image',
			{ prompt: 'A tiny blue square for upload e2e' },
			{ sessionId }
		)
		expect( generated.ok ).toBe( true )
		const key = generated.data.artifact_key
		expect( key ).toBeTruthy()

		const uploaded = await runAbility(
			requestUtils,
			'ahentic/upload-media',
			{ from_memory: key },
			{ sessionId }
		)
		expect( uploaded.ok, JSON.stringify( uploaded ) ).toBe( true )
		expect( uploaded.data.attachment_id ).toBeGreaterThan( 0 )
		expect( uploaded.data.url ).toBeTruthy()
	} )
} )

test.describe( 'ahentic-browser/set-featured-image', () => {
	test( 'PHP stub refuses server execute (browser runtime)', async ( { requestUtils } ) => {
		const result = await runAbility( requestUtils, 'ahentic-browser/set-featured-image', {
			attachment_id: 1,
		} )

		expect( result.ok ).toBe( false )
		expect( result.error ).toBe( 'ahentic_browser_runtime' )
	} )
} )
