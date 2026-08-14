/**
 * Track E — media reads + writes: list/get, update / featured / delete-quarantine / replace.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' )
const {
	runAbility, seed, inspectAttachment,
} = require( '../utils/ability-client' )
const { createSession } = require( '../utils/session-client' )

test.describe.configure( { mode: 'serial', timeout: 90_000 } )

test.describe( 'ahentic/list-media + get-media', () => {
	test( 'lists by search/mime and get returns detail; missing id is not-found', async ( {
		requestUtils,
	} ) => {
		const suffix = Date.now()
		const title = `List media ${ suffix }`

		const seeded = await seed( requestUtils, {
			attachments: [
				{
					title,
					alt_text: 'List media alt',
					filename: `list-media-${ suffix }.png`,
				},
			],
		} )
		expect( seeded.ok ).toBe( true )
		const attachmentId = seeded.created.attachments[ 0 ]
		expect( attachmentId ).toBeGreaterThan( 0 )

		const listed = await runAbility( requestUtils, 'ahentic/list-media', {
			search: title,
			mime_type: 'image',
			per_page: 10,
		} )
		expect( listed.ok, JSON.stringify( listed ) ).toBe( true )
		expect( listed.data.per_page ).toBeLessThanOrEqual( 50 )
		const hit = ( listed.data.items || [] ).find( item => item.id === attachmentId )
		expect( hit ).toBeTruthy()
		expect( hit.title ).toBe( title )
		expect( hit.alt ).toBe( 'List media alt' )
		expect( hit.mime_type ).toMatch( /^image\// )
		expect( hit.url ).toBeTruthy()

		const got = await runAbility( requestUtils, 'ahentic/get-media', {
			id: attachmentId,
		} )
		expect( got.ok, JSON.stringify( got ) ).toBe( true )
		expect( got.data.id ).toBe( attachmentId )
		expect( got.data.alt ).toBe( 'List media alt' )
		expect( got.data ).toHaveProperty( 'caption' )
		expect( got.data ).toHaveProperty( 'description' )
		expect( got.data ).toHaveProperty( 'media_details' )
		expect( got.data ).toHaveProperty( 'size_urls' )
		expect( got.data ).toHaveProperty( 'usage' )

		const missing = await runAbility( requestUtils, 'ahentic/get-media', {
			id: 999999999,
		} )
		expect( missing.ok ).toBe( false )
		expect( missing.error ).toBe( 'ahentic_attachment_not_found' )
	} )

	test( 'caps per_page at 50', async ( { requestUtils } ) => {
		const listed = await runAbility( requestUtils, 'ahentic/list-media', {
			mime_type: 'image',
			per_page: 500,
			page: 1,
		} )
		expect( listed.ok, JSON.stringify( listed ) ).toBe( true )
		expect( listed.data.per_page ).toBe( 50 )
	} )
} )

test.describe( 'ahentic/update-media', () => {
	test( 'writes alt/title/caption/description and undo restores prior', async ( {
		requestUtils,
	} ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const seeded = await seed( requestUtils, {
			attachments: [
				{
					title: 'Prior title',
					alt_text: 'Prior alt',
					caption: 'Prior caption',
					description: 'Prior description',
					filename: 'update-media-prior.png',
				},
			],
		} )
		expect( seeded.ok ).toBe( true )
		const attachmentId = seeded.created.attachments[ 0 ]
		expect( attachmentId ).toBeGreaterThan( 0 )

		const updated = await runAbility(
			requestUtils,
			'ahentic/update-media',
			{
				attachment_id: attachmentId,
				alt_text: 'New alt',
				title: 'New title',
				caption: 'New caption',
				description: 'New description',
			},
			{ sessionId }
		)
		expect( updated.ok, JSON.stringify( updated ) ).toBe( true )

		const after = await inspectAttachment( requestUtils, attachmentId )
		expect( after.ok ).toBe( true )
		expect( after.alt_text ).toBe( 'New alt' )
		expect( after.title ).toBe( 'New title' )
		expect( after.caption ).toBe( 'New caption' )
		expect( after.description ).toBe( 'New description' )

		const undo = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( undo.ok, JSON.stringify( undo ) ).toBe( true )
		expect( undo.data.undone ).toBe( 1 )

		const restored = await inspectAttachment( requestUtils, attachmentId )
		expect( restored.alt_text ).toBe( 'Prior alt' )
		expect( restored.title ).toBe( 'Prior title' )
		expect( restored.caption ).toBe( 'Prior caption' )
		expect( restored.description ).toBe( 'Prior description' )
	} )
} )

test.describe( 'ahentic/set-featured-image', () => {
	test( 'sets thumbnail and undo restores prior absence', async ( { requestUtils } ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const seeded = await seed( requestUtils, {
			posts: [
				{
					post_title: 'Featured target',
					post_content: 'Body',
					post_status: 'draft',
					post_type: 'post',
				},
			],
			attachments: [ { title: 'Featured img', filename: 'featured.png' } ],
		} )
		expect( seeded.ok ).toBe( true )
		const postId = seeded.created.posts[ 0 ]
		const attachmentId = seeded.created.attachments[ 0 ]

		const set = await runAbility(
			requestUtils,
			'ahentic/set-featured-image',
			{ post_id: postId, attachment_id: attachmentId },
			{ sessionId }
		)
		expect( set.ok, JSON.stringify( set ) ).toBe( true )
		expect( set.data.attachment_id ).toBe( attachmentId )

		const post = await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			params: { context: 'edit' },
		} )
		expect( post.featured_media ).toBe( attachmentId )

		const undo = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( undo.ok, JSON.stringify( undo ) ).toBe( true )
		expect( undo.data.undone ).toBe( 1 )

		const cleared = await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			params: { context: 'edit' },
		} )
		expect( cleared.featured_media ).toBe( 0 )
	} )

	test( 'undo restores a prior existing thumbnail', async ( { requestUtils } ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const seeded = await seed( requestUtils, {
			posts: [
				{
					post_title: 'Had featured',
					post_content: 'Body',
					post_status: 'draft',
					post_type: 'post',
				},
			],
			attachments: [
				{ title: 'Prior featured', filename: 'prior-feat.png' },
				{ title: 'New featured', filename: 'new-feat.png' },
			],
		} )
		const postId = seeded.created.posts[ 0 ]
		const priorId = seeded.created.attachments[ 0 ]
		const nextId = seeded.created.attachments[ 1 ]

		// Establish prior thumbnail outside Ahentic so undo only restores this write.
		await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			method: 'POST',
			data: { featured_media: priorId },
		} )

		const swapped = await runAbility(
			requestUtils,
			'ahentic/set-featured-image',
			{ post_id: postId, attachment_id: nextId },
			{ sessionId }
		)
		expect( swapped.ok, JSON.stringify( swapped ) ).toBe( true )

		const undo = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( undo.ok ).toBe( true )
		expect( undo.data.undone ).toBe( 1 )

		const post = await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			params: { context: 'edit' },
		} )
		expect( post.featured_media ).toBe( priorId )
	} )

	test( 'refuses when block editor is open for the same post', async ( { requestUtils } ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const seeded = await seed( requestUtils, {
			posts: [
				{
					post_title: 'Editor open',
					post_content: 'Body',
					post_status: 'draft',
					post_type: 'post',
				},
			],
			attachments: [ { title: 'Feat', filename: 'editor-feat.png' } ],
		} )
		const postId = seeded.created.posts[ 0 ]
		const attachmentId = seeded.created.attachments[ 0 ]

		await seed( requestUtils, {
			session_id: sessionId,
			page_context: {
				is_block_editor: true,
				post_id: postId,
				post_type: 'post',
			},
		} )

		const refused = await runAbility(
			requestUtils,
			'ahentic/set-featured-image',
			{ post_id: postId, attachment_id: attachmentId },
			{ sessionId }
		)
		expect( refused.ok ).toBe( false )
		expect( refused.error ).toBe( 'ahentic_use_browser_featured_image' )
	} )
} )

test.describe( 'ahentic/delete-media', () => {
	test( 'quarantines to trash when MEDIA_TRASH is unset; undo restores', async ( {
		requestUtils,
	} ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const seeded = await seed( requestUtils, {
			attachments: [ { title: 'Delete me', filename: 'delete-unset.png' } ],
		} )
		const attachmentId = seeded.created.attachments[ 0 ]

		const before = await inspectAttachment( requestUtils, attachmentId )
		expect( before.status ).toBe( 'inherit' )
		expect( before.file_exists ).toBe( true )

		const deleted = await runAbility(
			requestUtils,
			'ahentic/delete-media',
			{ attachment_id: attachmentId },
			{ sessionId }
		)
		expect( deleted.ok, JSON.stringify( deleted ) ).toBe( true )

		const after = await inspectAttachment( requestUtils, attachmentId )
		expect( after.status ).toBe( 'trash' )
		expect( after.file_exists ).toBe( true )

		const undo = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( undo.ok, JSON.stringify( undo ) ).toBe( true )
		expect( undo.data.undone ).toBe( 1 )

		const restored = await inspectAttachment( requestUtils, attachmentId )
		expect( restored.status ).toBe( 'inherit' )
		expect( restored.file_exists ).toBe( true )
	} )

	test( 'quarantines to trash when MEDIA_TRASH is explicitly false', async ( {
		requestUtils,
	} ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const seeded = await seed( requestUtils, {
			attachments: [ { title: 'Delete false', filename: 'delete-false.png' } ],
		} )
		const attachmentId = seeded.created.attachments[ 0 ]

		const deleted = await runAbility(
			requestUtils,
			'ahentic/delete-media',
			{ attachment_id: attachmentId },
			{ sessionId, defineMediaTrash: false }
		)
		expect( deleted.ok, JSON.stringify( deleted ) ).toBe( true )
		// Behaviour under MEDIA_TRASH=false: trash status, file still on disk
		// (wp_trash_post — never wp_delete_attachment force).
		expect( deleted.data.status ).toBe( 'trash' )
		expect( deleted.data.file_exists ).toBe( true )

		const after = await inspectAttachment( requestUtils, attachmentId )
		expect( after.status ).toBe( 'trash' )
		expect( after.file_exists ).toBe( true )
	} )
} )

test.describe( 'ahentic/restore-media', () => {
	test( 'untrashes a quarantined attachment; list-media status=trash finds it', async ( {
		requestUtils,
	} ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const seeded = await seed( requestUtils, {
			attachments: [ { title: 'Restore me', filename: 'restore-me.png' } ],
		} )
		const attachmentId = seeded.created.attachments[ 0 ]

		const deleted = await runAbility(
			requestUtils,
			'ahentic/delete-media',
			{ attachment_id: attachmentId },
			{ sessionId }
		)
		expect( deleted.ok, JSON.stringify( deleted ) ).toBe( true )

		const listedTrash = await runAbility( requestUtils, 'ahentic/list-media', {
			status: 'trash',
			search: 'Restore me',
		} )
		expect( listedTrash.ok, JSON.stringify( listedTrash ) ).toBe( true )
		const trashIds = ( listedTrash.data.items || [] ).map( item => item.id )
		expect( trashIds ).toContain( attachmentId )

		const restored = await runAbility(
			requestUtils,
			'ahentic/restore-media',
			{ attachment_id: attachmentId },
			{ sessionId }
		)
		expect( restored.ok, JSON.stringify( restored ) ).toBe( true )
		expect( restored.data.status ).not.toBe( 'trash' )

		const after = await inspectAttachment( requestUtils, attachmentId )
		expect( after.status ).toBe( 'inherit' )
		expect( after.file_exists ).toBe( true )
	} )

	test( 'idempotent when attachment is not trashed; undo re-quarantines', async ( {
		requestUtils,
	} ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const seeded = await seed( requestUtils, {
			attachments: [ { title: 'Restore undo', filename: 'restore-undo.png' } ],
		} )
		const attachmentId = seeded.created.attachments[ 0 ]

		await runAbility(
			requestUtils,
			'ahentic/delete-media',
			{ attachment_id: attachmentId },
			{ sessionId }
		)

		const restored = await runAbility(
			requestUtils,
			'ahentic/restore-media',
			{ attachment_id: attachmentId },
			{ sessionId }
		)
		expect( restored.ok, JSON.stringify( restored ) ).toBe( true )

		const noop = await runAbility(
			requestUtils,
			'ahentic/restore-media',
			{ attachment_id: attachmentId },
			{ sessionId }
		)
		expect( noop.ok, JSON.stringify( noop ) ).toBe( true )
		expect( noop.data.already_restored ).toBe( true )

		const undo = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( undo.ok, JSON.stringify( undo ) ).toBe( true )
		expect( undo.data.undone ).toBe( 1 )

		const afterUndo = await inspectAttachment( requestUtils, attachmentId )
		expect( afterUndo.status ).toBe( 'trash' )
	} )
} )

test.describe( 'ahentic/replace-media-file', () => {
	test( 'blocks private hosts via host_is_publicly_fetchable', async ( { requestUtils } ) => {
		const seeded = await seed( requestUtils, {
			attachments: [ { title: 'Replace SSRF', filename: 'ssrf.png' } ],
		} )
		const attachmentId = seeded.created.attachments[ 0 ]

		const blocked = await runAbility( requestUtils, 'ahentic/replace-media-file', {
			attachment_id: attachmentId,
			url: 'http://127.0.0.1/secret.png',
		} )
		expect( blocked.ok ).toBe( false )
		expect( blocked.error ).toBe( 'ahentic_upload_host_blocked' )
	} )

	test( 'replaces file in place with no undo snapshot', async ( { requestUtils } ) => {
		const session = await createSession( requestUtils )
		const sessionId = session.id || session.ID

		const seeded = await seed( requestUtils, {
			attachments: [ { title: 'Replace target', filename: 'replace-target.png' } ],
		} )
		const attachmentId = seeded.created.attachments[ 0 ]
		const before = await inspectAttachment( requestUtils, attachmentId )
		expect( before.file_exists ).toBe( true )
		const priorMd5 = before.file_md5
		expect( priorMd5 ).toBeTruthy()

		const generated = await runAbility(
			requestUtils,
			'ahentic/generate-image',
			{ prompt: '__e2e_blue__ replace source' },
			{ sessionId }
		)
		expect( generated.ok, JSON.stringify( generated ) ).toBe( true )
		const key = generated.data.artifact_key

		const replaced = await runAbility(
			requestUtils,
			'ahentic/replace-media-file',
			{ attachment_id: attachmentId, from_memory: key },
			{ sessionId }
		)
		expect( replaced.ok, JSON.stringify( replaced ) ).toBe( true )

		const after = await inspectAttachment( requestUtils, attachmentId )
		expect( after.file_exists ).toBe( true )
		expect( after.file_md5 ).toBeTruthy()
		expect( after.file_md5 ).not.toBe( priorMd5 )

		const undo = await runAbility(
			requestUtils,
			'ahentic/undo-last-actions',
			{ count: 1 },
			{ sessionId }
		)
		expect( undo.ok ).toBe( true )
		expect( undo.data.undone ).toBe( 0 )

		const still = await inspectAttachment( requestUtils, attachmentId )
		expect( still.file_md5 ).toBe( after.file_md5 )
	} )
} )

test.describe( 'audit-accessibility ↔ update-media alt loop', () => {
	test( 'update-media + block alt clears missing_alt from audit-accessibility', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const seededAtt = await seed( requestUtils, {
			attachments: [
				{
					title: 'Audit alt target',
					filename: 'audit-alt-loop.png',
					alt_text: '',
				},
			],
		} )
		expect( seededAtt.ok ).toBe( true )
		const attachmentId = seededAtt.created.attachments[ 0 ]

		const media = await requestUtils.rest( {
			path: `/wp/v2/media/${ attachmentId }`,
		} )
		const url = media.source_url || media.guid?.rendered
		expect( url ).toBeTruthy()

		const content = [
			`<!-- wp:image {"id":${ attachmentId },"sizeSlug":"full","linkDestination":"none"} -->`,
			`<figure class="wp-block-image size-full"><img src="${ url }" alt="" class="wp-image-${ attachmentId }"/></figure>`,
			'<!-- /wp:image -->',
		].join( '\n' )

		const seededPost = await seed( requestUtils, {
			posts: [
				{
					post_title: `Audit alt loop ${ Date.now() }`,
					post_status: 'draft',
					post_type: 'post',
					post_content: content,
				},
			],
		} )
		expect( seededPost.ok ).toBe( true )
		const postId = seededPost.created.posts[ 0 ]

		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` )

		await page.waitForFunction(
			() => Boolean( window.wp?.data?.select( 'core/block-editor' )?.getBlocks?.()?.length ),
			null,
			{ timeout: 60_000 }
		)
		await page.waitForFunction(
			() => typeof window.__ahenticE2E?.auditAccessibility === 'function',
			null,
			{ timeout: 60_000 }
		)

		const before = await page.evaluate( () => window.__ahenticE2E.auditAccessibility() )
		expect( before.ok, JSON.stringify( before ) ).toBe( true )
		const missingBefore = ( before.issues || [] ).filter( i => i.type === 'missing_alt' )
		expect( missingBefore.length ).toBeGreaterThan( 0 )
		expect( missingBefore[ 0 ].attachment_id ).toBe( attachmentId )
		expect( missingBefore[ 0 ].ref ).toBeTruthy()

		const altText = 'A red pixel used for the accessibility e2e loop'
		const updated = await runAbility( requestUtils, 'ahentic/update-media', {
			attachment_id: attachmentId,
			alt_text: altText,
		} )
		expect( updated.ok, JSON.stringify( updated ) ).toBe( true )
		expect( updated.data.alt_text ).toBe( altText )

		const inspected = await inspectAttachment( requestUtils, attachmentId )
		expect( inspected.alt_text ).toBe( altText )

		// Audit reads block attrs — library meta alone must not clear missing_alt.
		const mid = await page.evaluate( () => window.__ahenticE2E.auditAccessibility() )
		expect( ( mid.issues || [] ).filter( i => i.type === 'missing_alt' ).length ).toBeGreaterThan( 0 )

		// Agent closes the canvas gap the same way prompts recommend after describe-image.
		const patched = await page.evaluate( ( {
			ref, alt, url,
		} ) => window.__ahenticE2E.updateBlockAttributes( {
			ref,
			attributes: { mediaurl: url, alt },
		} ), {
			ref: missingBefore[ 0 ].ref, alt: altText, url,
		} )
		expect( patched.ok, JSON.stringify( patched ) ).toBe( true )
		expect( patched.attributes.alt ).toBe( altText )
		expect( patched.attributes.url ).toBe( url )
		expect( patched.attributes.mediaurl ).toBeUndefined()

		const after = await page.evaluate( () => window.__ahenticE2E.auditAccessibility() )
		expect( after.ok, JSON.stringify( after ) ).toBe( true )
		const missingAfter = ( after.issues || [] ).filter( i => i.type === 'missing_alt' )
		expect( missingAfter ).toHaveLength( 0 )
	} )
} )
