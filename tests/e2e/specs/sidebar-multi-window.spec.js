/**
 * Browser UI: same session in two windows — controller vs viewer lock.
 *
 * Uses two pages in one Playwright context so runner-lock localStorage is shared
 * (same-origin multi-window behavior). Pipeline HITL itself stays REST-covered;
 * this asserts overlay, blocked send, and Stop from the viewer.
 */
/* eslint-disable camelcase -- Ability / REST I/O matches PHP schema snake_case. */
const { test, expect } = require( '../fixtures/test' )
const { mockReply } = require( '../fixtures/ahentic-sidebar' )
const { mockUseTools, waitForSession } = require( '../utils/session-client' )

test.describe.configure( { mode: 'serial', timeout: 120_000 } )

test.describe( 'Sidebar multi-window runner lock', () => {
	test.beforeEach( async ( { ahenticSidebar } ) => {
		await ahenticSidebar.resetAiResponses()
	} )

	test( 'second window becomes a viewer; Stop from viewer ends the run', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		const title = `E2E multi-window ${ Date.now() }`

		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Creating a draft…',
				[ {
					name: 'ahentic/create-post', input: {
						title, post_type: 'post', status: 'draft',
					},
				} ],
				{
					plan: {
						title: 'Create draft',
						steps: [
							{
								id: '1', content: 'Create the draft', status: 'in_progress',
							},
							{
								id: '2', content: 'Confirm', status: 'pending',
							},
						],
					},
				}
			),
			mockReply( 'Should not finish after viewer stop.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		let page2
		let viewer

		try {
			await ahenticSidebar.sendMessage( `Create a draft titled ${ title }` )

			await waitForSession(
				requestUtils,
				session.id,
				s => s.status === 'awaiting_human' && s.pendingTool?.name === 'ahentic/create-post'
			)

			await expect( ahenticSidebar.hitlCard ).toBeVisible( { timeout: 15_000 } )

			// Open after the run is live: idle peers do not poll, so they never become viewers.
			const second = await ahenticSidebar.openSecondWindow( session.id )
			page2 = second.page
			viewer = second.sidebar
			await expect( viewer.viewerOverlay ).toBeVisible( { timeout: 20_000 } )
			await expect( viewer.viewerOverlay ).toContainText( /active in another window/i )
			await expect( viewer.hitlCard ).toHaveCount( 0 )
			await expect( viewer.composer ).toBeDisabled()

			await viewer.viewerOverlay.getByRole( 'button', { name: /^Stop$/i } ).click()

			await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

			await expect( viewer.viewerOverlay ).toHaveCount( 0, { timeout: 15_000 } )
			await expect( ahenticSidebar.hitlCard ).toHaveCount( 0, { timeout: 15_000 } )
			await expect( ahenticSidebar.planEyebrow ).toContainText( /Plan stopped/i, {
				timeout: 15_000,
			} )
		} finally {
			if ( page2 ) {
				await page2.close()
			}
		}
	} )

	test( 'viewer still sees plan progress while the controller holds HITL', async ( {
		ahenticSidebar,
		requestUtils,
	} ) => {
		const title = `E2E viewer progress ${ Date.now() }`

		await ahenticSidebar.seedAiResponses( [
			mockUseTools(
				'Creating a draft…',
				[ {
					name: 'ahentic/create-post', input: {
						title, post_type: 'post', status: 'draft',
					},
				} ],
				{
					plan: {
						title: 'Create draft',
						steps: [
							{
								id: '1', content: 'Create the draft', status: 'in_progress',
							},
							{
								id: '2', content: 'Confirm', status: 'pending',
							},
						],
					},
				}
			),
			mockReply( 'Draft ready after allow.' ),
		] )

		const session = await ahenticSidebar.openWithSession()
		let page2
		let viewer

		try {
			await ahenticSidebar.sendMessage( `Create a draft titled ${ title }` )
			await waitForSession(
				requestUtils,
				session.id,
				s => s.status === 'awaiting_human'
			)

			const second = await ahenticSidebar.openSecondWindow( session.id )
			page2 = second.page
			viewer = second.sidebar
			await expect( viewer.viewerOverlay ).toBeVisible( { timeout: 20_000 } )
			await expect( viewer.planCard ).toBeVisible( { timeout: 15_000 } )
			await expect( viewer.planCard ).toContainText( 'Create the draft' )

			await ahenticSidebar.decideHitl( 'allow_once' )
			await waitForSession( requestUtils, session.id, s => s.status === 'idle' )

			await expect( viewer.viewerOverlay ).toHaveCount( 0, { timeout: 15_000 } )
			// Viewer may not poll through awaiting_human → idle; controller owns the drive.
			await expect( ahenticSidebar.message( 'assistant' ) ).toContainText( 'Draft ready after allow.', {
				timeout: 15_000,
			} )
		} finally {
			if ( page2 ) {
				await page2.close()
			}
		}
	} )
} )
