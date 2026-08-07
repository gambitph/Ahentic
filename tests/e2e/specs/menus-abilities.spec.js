/**
 * Track H — classic menus: list / get / update (replace tree + locations).
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const { runAbility } = require( '../utils/ability-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'ahentic/list-menus + get-menu + update-menu', () => {
	test( 'creates a menu, lists it, replaces items, and loads the tree', async ( {
		requestUtils,
	} ) => {
		const suffix = Date.now()
		const menuName = `Ahentic Menu ${ suffix }`

		const created = await runAbility( requestUtils, 'ahentic/update-menu', {
			menu: menuName,
			items: [
				{
					title: 'Home',
					type: 'custom',
					url: '/',
					children: [
						{
							title: 'About',
							type: 'custom',
							url: '/about/',
						},
					],
				},
				{
					title: 'Blog',
					type: 'custom',
					url: '/blog/',
				},
			],
		} )
		expect( created.ok, JSON.stringify( created ) ).toBe( true )
		expect( created.data.created ).toBe( true )
		expect( created.data.menu.name ).toBe( menuName )
		expect( created.data.items_count.after ).toBe( 3 )
		expect( created.data.items ).toHaveLength( 2 )
		expect( created.data.items[ 0 ].children ).toHaveLength( 1 )

		const menuId = created.data.menu.id

		const listed = await runAbility( requestUtils, 'ahentic/list-menus' )
		expect( listed.ok, JSON.stringify( listed ) ).toBe( true )
		expect( listed.data.menus.some( m => m.id === menuId ) ).toBe( true )

		const items = await runAbility( requestUtils, 'ahentic/list-menu-items', {
			menu: menuId,
		} )
		expect( items.ok, JSON.stringify( items ) ).toBe( true )
		expect( items.data.count ).toBe( 3 )
		expect( items.data.items[ 0 ] ).toHaveProperty( 'title' )
		expect( items.data.items[ 0 ] ).toHaveProperty( 'type' )
		expect( items.data.items[ 0 ] ).toHaveProperty( 'parent' )

		const got = await runAbility( requestUtils, 'ahentic/get-menu', {
			menu: menuId,
		} )
		expect( got.ok, JSON.stringify( got ) ).toBe( true )
		expect( got.data.menu.id ).toBe( menuId )
		expect( got.data.items ).toHaveLength( 2 )
		expect( got.data.items[ 0 ].children[ 0 ].title ).toBe( 'About' )

		const replaced = await runAbility( requestUtils, 'ahentic/update-menu', {
			menu: menuId,
			items: [
				{
					title: 'Only',
					type: 'custom',
					url: '/only/',
				},
			],
		} )
		expect( replaced.ok, JSON.stringify( replaced ) ).toBe( true )
		expect( replaced.data.created ).toBe( false )
		expect( replaced.data.items_count ).toEqual( { before: 3, after: 1 } )
		expect( replaced.data.items ).toHaveLength( 1 )
		expect( replaced.data.items[ 0 ].title ).toBe( 'Only' )

		const missing = await runAbility( requestUtils, 'ahentic/get-menu', {
			menu: `missing-menu-${ suffix }`,
		} )
		expect( missing.ok ).toBe( false )
		expect( missing.error ).toBe( 'ahentic_menu_not_found' )
	} )
} )
