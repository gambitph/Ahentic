/**
 * AhenticSidebar: a small Playwright fixture wrapping the React sidebar's
 * known DOM/localStorage contract (see src/admin/js/sidebar/), so browser-
 * driven specs read like "open a session, send a message, assert a reply"
 * rather than re-deriving selectors and REST calls every time.
 *
 * Session creation goes through the real `POST /ahentic/v1/sessions` route
 * (not a UI "New agent" click) — one less flaky UI step, and it's the same
 * seam a "resume an existing chat" page load exercises anyway. The sidebar
 * hydrates whatever tab is in `localStorage` on mount (see
 * src/admin/js/sidebar/storage.js's `ahentic.sidebar.v1` key), so seeding
 * that + navigating is sufficient to land on an already-open session with no
 * keyboard shortcut or admin-bar click needed.
 */
/* eslint-disable camelcase -- HITL decision keys match REST / orchestrator wire format. */
const { seedAiResponses, resetAiResponses } = require( '../utils/ability-client' )

const STORAGE_KEY = 'ahentic.sidebar.v1'

/**
 * Build a mocked model turn in the wire format the orchestrator's parser
 * expects (see src/orchestrator/control-block.md) — a leading
 * `<<<AHENTIC_DEBUG {…} AHENTIC_DEBUG>>>` control block followed by the
 * user-facing reply. A plain string with no control block is NOT a valid
 * turn: the orchestrator retries (MAX_DEBUG_ATTEMPTS), drains the mocked
 * queue without producing the intended reply, then falls through to a real
 * (unconfigured) provider on the next attempt.
 *
 * @param {string} text             User-facing reply text.
 * @param {Object} [debugOverrides] Merged into the default `next: "reply"` debug block.
 * @return {string} Full raw model text, ready to seed via `seedAiResponses()`.
 */
function mockReply( text, debugOverrides = {} ) {
	const debug = {
		intention: 'Replying',
		thinking: 'Mocked e2e response.',
		plan: null,
		next: 'reply',
		...debugOverrides,
	}

	return `<<<AHENTIC_DEBUG\n${ JSON.stringify( debug ) }\nAHENTIC_DEBUG>>>\n\n${ text }`
}

class AhenticSidebar {
	/**
	 * @param {Object}                                                      deps
	 * @param {import('@playwright/test').Page}                             deps.page         Playwright page fixture.
	 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} deps.requestUtils The `requestUtils` fixture.
	 */
	constructor( { page, requestUtils } ) {
		this.page = page
		this.requestUtils = requestUtils
	}

	/**
	 * Create a real session via REST, force the sidebar open on it via
	 * localStorage, then navigate there.
	 *
	 * @param {Object} [options]
	 * @param {string} [options.path] Admin page to open the sidebar on.
	 * @param {string} [options.mode] Session mode: 'agent' | 'ask'.
	 * @return {Promise<Object>} The created session record (REST shape).
	 */
	async openWithSession( { path = '/wp-admin/', mode = 'agent' } = {} ) {
		const session = await this.requestUtils.rest( {
			path: '/ahentic/v1/sessions',
			method: 'POST',
			data: { mode },
		} )

		await this.page.addInitScript(
			( {
				sessionId, sessionMode, storageKey,
			} ) => {
				window.localStorage.setItem( storageKey, JSON.stringify( {
					open: true,
					width: 420,
					theme: 'dark',
					mode: sessionMode,
					placement: 'right',
					floatRect: null,
					tabs: [ {
						id: String( sessionId ), title: 'E2E', createdAt: Date.now(),
					} ],
					activeTabId: String( sessionId ),
				} ) )
			},
			{
				sessionId: session.id, sessionMode: mode, storageKey: STORAGE_KEY,
			}
		)

		// The sidebar's "cold" GET /sessions/{id} (mount-time hydration) has to
		// resolve before it will accept a send — see sidebar.js's `sendMessage`
		// (`hydratedRef` guard). Race the wait against navigation itself rather
		// than starting it after `goto()` settles, or the request can fire (and
		// this promise miss it) before we start listening.
		//
		// Match the route in pathname (/wp-json/…) *or* in ?rest_route=… —
		// Playground defaults to plain permalinks, so rest_url() is often
		// index.php?rest_route=/ahentic/v1/sessions/{id} (pathname is /index.php).
		const sessionPath = `/sessions/${ session.id }`
		const hydrated = this.page.waitForResponse( response => {
			if ( response.request().method() !== 'GET' ) {
				return false
			}
			const url = new URL( response.url() )
			if ( url.pathname.includes( sessionPath ) ) {
				return true
			}
			const restRoute = url.searchParams.get( 'rest_route' ) || ''
			return restRoute.includes( sessionPath )
		} )

		await this.page.goto( path )
		await Promise.all( [ hydrated, this.sidebar.waitFor( { state: 'visible' } ) ] )

		return session
	}

	/** @return {import('@playwright/test').Locator} The open sidebar `<aside>`. */
	get sidebar() {
		return this.page.locator( 'aside.ahentic-sidebar.is-open' )
	}

	/** @return {import('@playwright/test').Locator} The chat composer textarea. */
	get composer() {
		return this.page.getByRole( 'textbox', { name: 'Ask Ahentic' } )
	}

	/**
	 * Type a message into the composer and submit it (Enter — there is no
	 * send button, see src/admin/js/sidebar/composer.js).
	 *
	 * @param {string} text Message to send.
	 * @return {Promise<void>}
	 */
	async sendMessage( text ) {
		await this.composer.click()
		await this.composer.fill( text )
		await this.composer.press( 'Enter' )
	}

	/**
	 * Locate a chat bubble by role.
	 *
	 * @param {'user'|'assistant'|'system'} role  Message role.
	 * @param {number}                      [nth] Index among that role's bubbles; defaults to the last (most recent).
	 * @return {import('@playwright/test').Locator} Locator for that chat bubble.
	 */
	message( role, nth = -1 ) {
		const locator = this.page.locator( `.ahentic-message--${ role }` )
		return nth === -1 ? locator.last() : locator.nth( nth )
	}

	/**
	 * Queue canned AI responses the mocked `Ahentic_AI::complete_chat()` will
	 * pop from (see tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php).
	 *
	 * @param {Array<Object|string>} responses Ordered canned responses.
	 * @return {Promise<{ok: boolean, queued: number}>} Confirmation of how many responses are queued.
	 */
	seedAiResponses( responses ) {
		return seedAiResponses( this.requestUtils, responses )
	}

	/**
	 * Clear any queued-but-unconsumed mocked AI responses.
	 *
	 * @return {Promise<{ok: boolean}>} Confirmation.
	 */
	resetAiResponses() {
		return resetAiResponses( this.requestUtils )
	}

	/** @return {import('@playwright/test').Locator} The HITL approval card. */
	get hitlCard() {
		return this.page.getByRole( 'group', { name: 'Approve action' } )
	}

	/**
	 * Click a HITL decision on the visible approval card.
	 *
	 * @param {'allow_once'|'allow_session'|'deny'} decision
	 * @return {Promise<void>}
	 */
	async decideHitl( decision ) {
		const labels = {
			allow_once: /Allow once/i,
			allow_session: /Allow for this chat/i,
			deny: /Skip/i,
		}
		const label = labels[ decision ]
		if ( ! label ) {
			throw new Error( `Unknown HITL decision: ${ decision }` )
		}
		await this.hitlCard.getByRole( 'button', { name: label } ).click()
	}
}

module.exports = {
	AhenticSidebar, STORAGE_KEY, mockReply,
}
