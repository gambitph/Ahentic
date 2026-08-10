/**
 * Browser convert-blocks: target namespaces / exact types via Gutenberg transforms.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const { seed } = require( '../utils/ability-client' )

test.describe.configure( { mode: 'serial', timeout: 120_000 } )

const FAKE_NS = 'ahentic-e2e'
const FAKE_TEXT = `${ FAKE_NS }/text`

/**
 * Register a temporary block with bidirectional transforms to core/paragraph.
 *
 * @param {import('@playwright/test').Page} page
 */
async function registerFakeTextBlock( page ) {
	await page.evaluate( ( { name } ) => {
		const {
			registerBlockType, createBlock, getBlockType, unregisterBlockType,
		} = window.wp.blocks
		if ( getBlockType( name ) ) {
			unregisterBlockType( name )
		}
		registerBlockType( name, {
			apiVersion: 3,
			title: 'Ahentic E2E Text',
			category: 'text',
			icon: 'editor-paragraph',
			attributes: {
				text: {
					type: 'string',
					source: 'html',
					selector: 'p',
					default: '',
				},
			},
			supports: {
				className: false,
			},
			transforms: {
				from: [
					{
						type: 'block',
						blocks: [ 'core/paragraph' ],
						transform: attributes => createBlock( name, {
							text: attributes.content || '',
						} ),
					},
				],
				to: [
					{
						type: 'block',
						blocks: [ 'core/paragraph' ],
						transform: attributes => createBlock( 'core/paragraph', {
							content: attributes.text || '',
						} ),
					},
				],
			},
			edit: () => null,
			save: ( { attributes } ) => {
				const el = window.wp.element.createElement
				return el( 'p', null, attributes.text || '' )
			},
		} )
	}, { name: FAKE_TEXT } )
}

test.describe( 'ahentic-browser/convert-blocks target', () => {
	test( 'converts core paragraph to a plugin namespace and back via transforms', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const body = 'Private cars remain useful in Metro Manila traffic.'
		const content = [
			'<!-- wp:paragraph -->',
			`<p>${ body }</p>`,
			'<!-- /wp:paragraph -->',
			'<!-- wp:heading {"level":2} -->',
			'<h2 class="wp-block-heading">Keep this heading</h2>',
			'<!-- /wp:heading -->',
		].join( '\n' )

		const seeded = await seed( requestUtils, {
			posts: [
				{
					post_title: `Convert blocks ${ Date.now() }`,
					post_status: 'draft',
					post_type: 'post',
					post_content: content,
				},
			],
		} )
		expect( seeded.ok ).toBe( true )
		const postId = seeded.created.posts[ 0 ]

		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` )

		await page.waitForFunction(
			() => Boolean( window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.()?.length ),
			null,
			{ timeout: 60_000 }
		)
		await page.waitForFunction(
			() => typeof window.__ahenticE2E?.convertBlocks === 'function',
			null,
			{ timeout: 60_000 }
		)

		await registerFakeTextBlock( page )

		const dry = await page.evaluate( ( { ns } ) => window.__ahenticE2E.convertBlocks( {
			target: ns,
			scope: 'all',
			dry_run: true,
		} ), { ns: FAKE_NS } )
		expect( dry.ok, JSON.stringify( dry ) ).toBe( true )
		expect( dry.dry_run ).toBe( true )
		expect( dry.converted_count ).toBeGreaterThanOrEqual( 1 )
		expect( dry.converted.some( row => row.from === 'core/paragraph' && row.to === FAKE_TEXT ) ).toBe( true )

		const namesBefore = await page.evaluate( () => (
			window.wp.data.select( 'core/block-editor' ).getBlocks().map( b => b.name )
		) )
		expect( namesBefore ).toContain( 'core/paragraph' )

		const toPlugin = await page.evaluate( ( { ns } ) => window.__ahenticE2E.convertBlocks( {
			target: ns,
			scope: 'all',
		} ), { ns: FAKE_NS } )
		expect( toPlugin.ok, JSON.stringify( toPlugin ) ).toBe( true )
		expect( toPlugin.target ).toBe( FAKE_NS )
		expect( toPlugin.converted_count ).toBeGreaterThanOrEqual( 1 )
		expect( toPlugin.converted[ 0 ].method ).toBe( 'switchToBlockType' )

		const afterPlugin = await page.evaluate( () => {
			const blocks = window.wp.data.select( 'core/block-editor' ).getBlocks()
			return blocks.map( b => ( {
				name: b.name,
				text: b.attributes?.text || b.attributes?.content || '',
			} ) )
		} )
		const fake = afterPlugin.find( b => b.name === FAKE_TEXT )
		expect( fake ).toBeTruthy()
		expect( String( fake.text ) ).toContain( 'Private cars' )
		expect( afterPlugin.some( b => b.name === 'core/heading' ) ).toBe( true )

		const back = await page.evaluate( () => window.__ahenticE2E.convertBlocks( {
			target: 'core',
			scope: 'all',
		} ) )
		expect( back.ok, JSON.stringify( back ) ).toBe( true )
		expect( back.converted_count ).toBeGreaterThanOrEqual( 1 )

		const afterCore = await page.evaluate( () => {
			const blocks = window.wp.data.select( 'core/block-editor' ).getBlocks()
			return blocks.map( b => ( {
				name: b.name,
				content: b.attributes?.content || '',
			} ) )
		} )
		expect( afterCore.some( b => b.name === FAKE_TEXT ) ).toBe( false )
		const paragraph = afterCore.find( b => b.name === 'core/paragraph' )
		expect( paragraph ).toBeTruthy()
		expect( String( paragraph.content ) ).toContain( 'Private cars' )
	} )

	test( 'exact target converts paragraph to heading; slim get-block-type omits design noise', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const content = [
			'<!-- wp:paragraph -->',
			'<p>Turn this into a heading.</p>',
			'<!-- /wp:paragraph -->',
		].join( '\n' )

		const seeded = await seed( requestUtils, {
			posts: [
				{
					post_title: `Convert exact ${ Date.now() }`,
					post_status: 'draft',
					post_type: 'post',
					post_content: content,
				},
			],
		} )
		expect( seeded.ok ).toBe( true )
		const postId = seeded.created.posts[ 0 ]

		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` )
		await page.waitForFunction(
			() => typeof window.__ahenticE2E?.convertBlocks === 'function',
			null,
			{ timeout: 60_000 }
		)

		const converted = await page.evaluate( () => window.__ahenticE2E.convertBlocks( {
			target: 'core/heading',
			scope: 'all',
		} ) )
		expect( converted.ok, JSON.stringify( converted ) ).toBe( true )
		expect( converted.converted_count ).toBe( 1 )
		expect( converted.converted[ 0 ].to ).toBe( 'core/heading' )

		const names = await page.evaluate( () => (
			window.wp.data.select( 'core/block-editor' ).getBlocks().map( b => b.name )
		) )
		expect( names ).toEqual( [ 'core/heading' ] )

		const slim = await page.evaluate( () => window.__ahenticE2E.getBlockType( {
			name: 'core/heading',
			fields: 'convert',
		} ) )
		expect( slim.ok ).toBe( true )
		expect( slim.fields ).toBe( 'convert' )
		expect( slim.attributes.content || slim.attributes.level ).toBeTruthy()
		expect( slim.supports ).toBeUndefined()
	} )
} )
