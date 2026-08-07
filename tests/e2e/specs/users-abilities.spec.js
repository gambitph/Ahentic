/**
 * Track D — users: list / create / update / delete with role ceiling + reassign.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const { runAbility, seed } = require( '../utils/ability-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'ahentic/list-users', () => {
	test( 'lists users and includes email when operator can list_users', async ( {
		requestUtils,
	} ) => {
		const listed = await runAbility( requestUtils, 'ahentic/list-users', {
			number: 20,
		} )
		expect( listed.ok, JSON.stringify( listed ) ).toBe( true )
		expect( listed.data.count ).toBeGreaterThan( 0 )
		expect( listed.data.include_email ).toBe( true )
		expect( listed.data.users[ 0 ] ).toHaveProperty( 'id' )
		expect( listed.data.users[ 0 ] ).toHaveProperty( 'display_name' )
		expect( listed.data.users[ 0 ] ).toHaveProperty( 'roles' )
		expect( listed.data.users[ 0 ] ).toHaveProperty( 'registered' )
		expect( listed.data.users[ 0 ] ).toHaveProperty( 'post_count' )
		expect( listed.data.users[ 0 ] ).toHaveProperty( 'email' )
	} )
} )

test.describe( 'ahentic/create-user', () => {
	test( 'creates a subscriber and refuses administrator (role ceiling)', async ( {
		requestUtils,
	} ) => {
		const suffix = Date.now()
		const username = `ahentic_sub_${ suffix }`
		const email = `ahentic-sub-${ suffix }@example.com`

		const created = await runAbility( requestUtils, 'ahentic/create-user', {
			username,
			email,
			role: 'subscriber',
			display_name: `Sub ${ suffix }`,
		} )
		expect( created.ok, JSON.stringify( created ) ).toBe( true )
		expect( created.data.user.id ).toBeGreaterThan( 0 )
		expect( created.data.user.roles ).toContain( 'subscriber' )
		expect( created.data.user.username ).toBe( username )

		const escalate = await runAbility( requestUtils, 'ahentic/create-user', {
			username: `ahentic_admin_${ suffix }`,
			email: `ahentic-admin-${ suffix }@example.com`,
			role: 'administrator',
		} )
		expect( escalate.ok ).toBe( false )
		expect( escalate.error ).toBe( 'ahentic_role_ceiling' )
	} )
} )

test.describe( 'ahentic/update-user', () => {
	test( 'refuses self-edit and updates another user', async ( { requestUtils } ) => {
		const me = await requestUtils.rest( {
			path: '/wp/v2/users/me',
			params: { context: 'edit' },
		} )
		expect( me.id ).toBeGreaterThan( 0 )

		const selfEdit = await runAbility( requestUtils, 'ahentic/update-user', {
			user_id: me.id,
			display_name: 'Should Fail',
		} )
		expect( selfEdit.ok ).toBe( false )
		expect( selfEdit.error ).toBe( 'ahentic_user_self_edit' )

		const suffix = Date.now()
		const seeded = await seed( requestUtils, {
			users: [
				{
					user_login: `ahentic_upd_${ suffix }`,
					user_email: `ahentic-upd-${ suffix }@example.com`,
					user_pass: 'pass-upd-e2e',
					role: 'subscriber',
					display_name: `Prior ${ suffix }`,
				},
			],
		} )
		expect( seeded.ok ).toBe( true )
		const userId = seeded.created.users[ 0 ]

		const updated = await runAbility( requestUtils, 'ahentic/update-user', {
			user_id: userId,
			display_name: `Updated ${ suffix }`,
			role: 'contributor',
		} )
		expect( updated.ok, JSON.stringify( updated ) ).toBe( true )
		expect( updated.data.user.display_name ).toBe( `Updated ${ suffix }` )
		expect( updated.data.user.roles ).toContain( 'contributor' )

		const adminRole = await runAbility( requestUtils, 'ahentic/update-user', {
			user_id: userId,
			role: 'administrator',
		} )
		expect( adminRole.ok ).toBe( false )
		expect( adminRole.error ).toBe( 'ahentic_role_ceiling' )
	} )
} )

test.describe( 'ahentic/delete-user', () => {
	test( 'requires reassign_to and deletes with reassignment', async ( {
		requestUtils,
	} ) => {
		const suffix = Date.now()
		const seeded = await seed( requestUtils, {
			users: [
				{
					user_login: `ahentic_del_${ suffix }`,
					user_email: `ahentic-del-${ suffix }@example.com`,
					user_pass: 'pass-del-e2e',
					role: 'author',
					display_name: `Delete me ${ suffix }`,
				},
				{
					user_login: `ahentic_keep_${ suffix }`,
					user_email: `ahentic-keep-${ suffix }@example.com`,
					user_pass: 'pass-keep-e2e',
					role: 'author',
					display_name: `Keep me ${ suffix }`,
				},
			],
			posts: [
				{
					post_title: `Owned by delete target ${ suffix }`,
					post_content: 'Body',
					post_status: 'draft',
					post_type: 'post',
				},
			],
		} )
		expect( seeded.ok ).toBe( true )
		const deleteId = seeded.created.users[ 0 ]
		const keepId = seeded.created.users[ 1 ]
		const postId = seeded.created.posts[ 0 ]

		// Assign the seeded post to the user we will delete.
		await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			method: 'POST',
			data: { author: deleteId },
		} )

		// Schema requires reassign_to — omitted input fails before execute.
		const missing = await runAbility( requestUtils, 'ahentic/delete-user', {
			user_id: deleteId,
		} )
		expect( missing.ok ).toBe( false )
		expect( missing.error ).toBe( 'ability_invalid_input' )

		const zero = await runAbility( requestUtils, 'ahentic/delete-user', {
			user_id: deleteId,
			reassign_to: 0,
		} )
		expect( zero.ok ).toBe( false )
		expect( zero.error ).toBe( 'ahentic_reassign_required' )

		const same = await runAbility( requestUtils, 'ahentic/delete-user', {
			user_id: deleteId,
			reassign_to: deleteId,
		} )
		expect( same.ok ).toBe( false )
		expect( same.error ).toBe( 'ahentic_reassign_same_user' )

		const deleted = await runAbility( requestUtils, 'ahentic/delete-user', {
			user_id: deleteId,
			reassign_to: keepId,
		} )
		expect( deleted.ok, JSON.stringify( deleted ) ).toBe( true )
		expect( deleted.data.deleted.id ).toBe( deleteId )
		expect( deleted.data.reassign_to.id ).toBe( keepId )

		const post = await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			params: { context: 'edit' },
		} )
		expect( post.author ).toBe( keepId )

		const gone = await runAbility( requestUtils, 'ahentic/list-users', {
			search: `ahentic_del_${ suffix }`,
		} )
		expect( gone.ok ).toBe( true )
		expect( gone.data.users.some( ( u ) => u.id === deleteId ) ).toBe( false )
	} )
} )
