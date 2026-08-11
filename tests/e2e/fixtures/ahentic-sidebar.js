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
 *
 * Multi-window specs open a second page in the same browser context so both
 * windows share the runner-lock localStorage key (real same-origin behavior).
 */
/* eslint-disable camelcase -- HITL decision keys match REST / orchestrator wire format. */
const { seedAiResponses, resetAiResponses } = require( '../utils/ability-client' )

const STORAGE_KEY = 'ahentic.sidebar.v1'
const ADMIN_BAR_TOGGLE = '#wp-admin-bar-ahentic-toggle > .ab-item'

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

/**
 * @param {Object} options
 * @param {string|number} options.sessionId
 * @param {string} [options.mode]
 * @param {boolean} [options.open]
 * @param {number} [options.width]
 * @param {string} [options.placement]
 * @param {Object|null} [options.floatRect]
 * @param {string} [options.title]
 * @param {Array<{id:string,title:string,createdAt?:number}>} [options.tabs]
 * @return {Object} `ahentic.sidebar.v1` payload.
 */
function buildSidebarStorage( {
	sessionId,
	mode = 'agent',
	open = true,
	width = 420,
	placement = 'right',
	floatRect = null,
	title = 'E2E',
	tabs,
} = {} ) {
	const id = String( sessionId )
	const tabList = tabs || [ {
		id, title, createdAt: Date.now(),
	} ]
	return {
		open,
		width,
		theme: 'dark',
		mode,
		placement,
		floatRect,
		tabs: tabList,
		activeTabId: id,
	}
}

/**
 * Wait for cold GET /sessions/{id} hydration (pathname or ?rest_route=).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|number} sessionId
 * @return {Promise<import('@playwright/test').Response>} Hydration response.
 */
function waitForSessionHydration( page, sessionId ) {
	const id = String( sessionId )
	// Exact /sessions/{id} — do not use includes() (matches /sessions/51 vs /sessions/510).
	const sessionGetRe = new RegExp( `(?:^|/)sessions/${ id }/?$` )
	return page.waitForResponse( response => {
		if ( response.request().method() !== 'GET' ) {
			return false
		}
		const url = new URL( response.url() )
		const restRoute = url.searchParams.get( 'rest_route' ) || ''
		return sessionGetRe.test( url.pathname ) || sessionGetRe.test( restRoute )
	} )
}

class AhenticSidebar {
	/**
	 * @param {Object}                                                      deps
	 * @param {import('@playwright/test').Page}                             deps.page         Playwright page fixture.
	 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} deps.requestUtils The `requestUtils` fixture.
	 * @param {import('@playwright/test').BrowserContext}                   [deps.context]    Browser context (multi-window).
	 */
	constructor( {
		page, requestUtils, context,
	} ) {
		this.page = page
		this.requestUtils = requestUtils
		this.context = context || page.context()
	}

	/**
	 * Bind this helper to another page (same session chrome helpers / locators).
	 *
	 * @param {import('@playwright/test').Page} page
	 * @return {AhenticSidebar} Sidebar helper for that page.
	 */
	forPage( page ) {
		return new AhenticSidebar( {
			page,
			requestUtils: this.requestUtils,
			context: this.context,
		} )
	}

	/**
	 * Seed sidebar localStorage and navigate. Does not create a session.
	 *
	 * @param {Object} options
	 * @param {string|number} options.sessionId
	 * @param {string} [options.path]
	 * @param {string} [options.mode]
	 * @param {boolean} [options.open]
	 * @param {number} [options.width]
	 * @param {string} [options.placement]
	 * @param {Object|null} [options.floatRect]
	 * @param {string} [options.title]
	 * @param {Array} [options.tabs]
	 * @param {boolean} [options.waitHydrated] Wait for GET session when open.
	 * @param {boolean} [options.clearRunnerLock] Clear runner lock in localStorage before hydrate.
	 * @return {Promise<void>}
	 */
	async gotoWithStorage( {
		sessionId,
		path = '/wp-admin/',
		mode = 'agent',
		open = true,
		width = 420,
		placement = 'right',
		floatRect = null,
		title = 'E2E',
		tabs,
		waitHydrated = open,
		clearRunnerLock = false,
	} = {} ) {
		const storage = buildSidebarStorage( {
			sessionId, mode, open, width, placement, floatRect, title, tabs,
		} )

		// Seed via evaluate + reload, not addInitScript. Init scripts re-run on every
		// navigation/reload and would overwrite tabs the UI wrote to localStorage
		// (breaks sidebar-persistence multi-tab refresh).
		await this.page.goto( path )
		await this.page.evaluate(
			( {
				storageKey, payload, runnerLockKey, clearLock,
			} ) => {
				window.localStorage.setItem( storageKey, JSON.stringify( payload ) )
				// Only when starting a fresh session — clearing on openSecondWindow
				// would wipe the controller's live claim.
				if ( clearLock ) {
					window.localStorage.removeItem( runnerLockKey )
				}
			},
			{
				storageKey: STORAGE_KEY,
				payload: storage,
				runnerLockKey: 'ahentic.session-runner.v1',
				clearLock: clearRunnerLock,
			}
		)

		const hydrated = waitHydrated
			? waitForSessionHydration( this.page, sessionId )
			: null

		await this.page.reload()

		if ( hydrated ) {
			await Promise.all( [
				hydrated,
				this.sidebar.waitFor( { state: 'visible' } ),
			] )
		}
	}

	/**
	 * Create a real session via REST, force the sidebar open on it via
	 * localStorage, then navigate there.
	 *
	 * @param {Object} [options]
	 * @param {string} [options.path] Admin page to open the sidebar on.
	 * @param {string} [options.mode] Session mode: 'agent' | 'ask'.
	 * @param {boolean} [options.open] Whether sidebar starts open.
	 * @param {number} [options.width]
	 * @param {string} [options.placement]
	 * @param {Object|null} [options.floatRect]
	 * @param {string} [options.title]
	 * @return {Promise<Object>} The created session record (REST shape).
	 */
	async openWithSession( {
		path = '/wp-admin/',
		mode = 'agent',
		open = true,
		width = 420,
		placement = 'right',
		floatRect = null,
		title = 'E2E',
	} = {} ) {
		const session = await this.requestUtils.rest( {
			path: '/ahentic/v1/sessions',
			method: 'POST',
			data: { mode },
		} )

		await this.gotoWithStorage( {
			sessionId: session.id,
			path,
			mode,
			open,
			width,
			placement,
			floatRect,
			title,
			waitHydrated: open,
			clearRunnerLock: true,
		} )

		return session
	}

	/**
	 * Open the same session in a second window (same context → shared runner lock).
	 *
	 * @param {string|number} sessionId
	 * @param {Object} [options]
	 * @param {string} [options.path]
	 * @param {string} [options.mode]
	 * @param {string} [options.title]
	 * @return {Promise<{ page: import('@playwright/test').Page, sidebar: AhenticSidebar }>} Second window helpers.
	 */
	async openSecondWindow( sessionId, {
		path = '/wp-admin/',
		mode = 'agent',
		title = 'E2E',
	} = {} ) {
		const page = await this.context.newPage()
		const sidebar = this.forPage( page )
		await sidebar.gotoWithStorage( {
			sessionId, path, mode, title, open: true,
		} )
		return { page, sidebar }
	}

	/** @return {import('@playwright/test').Locator} The open sidebar `<aside>`. */
	get sidebar() {
		return this.page.locator( 'aside.ahentic-sidebar.is-open' )
	}

	/** @return {import('@playwright/test').Locator} Sidebar root whether or not open. */
	get sidebarRoot() {
		return this.page.locator( 'aside.ahentic-sidebar' )
	}

	/** @return {import('@playwright/test').Locator} The chat composer textarea. */
	get composer() {
		return this.page.getByRole( 'textbox', { name: 'Ask Ahentic' } )
	}

	/** @return {import('@playwright/test').Locator} Composer Send (idle; right-most circle). */
	get sendButton() {
		return this.sidebar.locator( '.ahentic-composer__send' )
	}

	/** @return {import('@playwright/test').Locator} Composer Stop (replaces Send while a run is active). */
	get stopButton() {
		return this.sidebar.locator( '.ahentic-composer__stop' )
	}

	/** @return {import('@playwright/test').Locator} Plan card. */
	get planCard() {
		return this.sidebar.locator( '.ahentic-plan' )
	}

	/** @return {import('@playwright/test').Locator} Plan card eyebrow (Plan / Plan stopped / …). */
	get planEyebrow() {
		return this.planCard.locator( '.ahentic-plan__eyebrow' )
	}

	/** @return {import('@playwright/test').Locator} Live status row. */
	get liveStatus() {
		return this.sidebar.locator( '.ahentic-live-status' )
	}

	/** @return {import('@playwright/test').Locator} Run feedback Yes/No row. */
	get runFeedback() {
		return this.sidebar.locator( '.ahentic-run-feedback' )
	}

	/** @return {import('@playwright/test').Locator} Viewer overlay. */
	get viewerOverlay() {
		return this.sidebar.locator( '.ahentic-viewer-overlay' )
	}

	/** @return {import('@playwright/test').Locator} Docked resize handle. */
	get resizeHandle() {
		return this.sidebar.locator( '.ahentic-resize' )
	}

	/** @return {import('@playwright/test').Locator} Debugger panel. */
	get debuggerPanel() {
		return this.sidebar.getByRole( 'dialog', { name: 'Session debugger' } )
	}

	/** @return {import('@playwright/test').Locator} Agent tablist. */
	get tabList() {
		return this.sidebar.getByRole( 'tablist', { name: 'Agent conversations' } )
	}

	/** @return {import('@playwright/test').Locator} Tabs. */
	get tabs() {
		return this.tabList.getByRole( 'tab' )
	}

	/**
	 * Type a message into the composer and submit it.
	 *
	 * @param {string} text Message to send.
	 * @param {Object} [options]
	 * @param {'enter'|'button'} [options.via='enter'] Submit via Enter or the Send button.
	 * @return {Promise<void>}
	 */
	async sendMessage( text, { via = 'enter' } = {} ) {
		await this.composer.click()
		await this.composer.fill( text )
		if ( via === 'button' ) {
			await this.sendButton.click()
			return
		}
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
		// Prefer the body — the label is always "Ahentic"/"You" and pollutes toContainText.
		const locator = this.sidebar.locator( `.ahentic-message--${ role } .ahentic-message__body` )
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

	/**
	 * Switch composer mode via the Agent/Ask menu.
	 *
	 * @param {'agent'|'ask'} mode
	 * @return {Promise<void>}
	 */
	async setComposerMode( mode ) {
		const label = mode === 'ask' ? 'Ask' : 'Agent'
		await this.sidebar.getByRole( 'button', { name: 'Select mode' } ).click()
		await this.sidebar.getByRole( 'option', { name: label } ).click()
	}

	/**
	 * Open the debugger from the tab overflow menu.
	 *
	 * @return {Promise<void>}
	 */
	async openDebugger() {
		await this.sidebar.getByRole( 'button', { name: 'Tab actions' } ).click()
		await this.sidebar.getByRole( 'menuitemcheckbox', { name: /Debugger/i } ).click()
		await this.debuggerPanel.waitFor( { state: 'visible' } )
	}

	/**
	 * Close the debugger via its header close control.
	 *
	 * @return {Promise<void>}
	 */
	async closeDebugger() {
		await this.debuggerPanel.getByRole( 'button', { name: 'Close debugger' } ).click()
	}

	/**
	 * Click New agent (+).
	 *
	 * @return {Promise<void>}
	 */
	async newAgentTab() {
		// exact: avoid matching tab close "Close New Agent".
		await this.sidebar.getByRole( 'button', { name: 'New agent', exact: true } ).click()
	}

	/**
	 * Clear all tabs via the overflow menu.
	 *
	 * @return {Promise<void>}
	 */
	async clearAllTabs() {
		await this.sidebar.getByRole( 'button', { name: 'Tab actions' } ).click()
		await this.sidebar.getByRole( 'menuitem', { name: 'Clear all' } ).click()
	}

	/**
	 * Open the sidebar via the admin-bar toggle (sidebar must start closed).
	 *
	 * @return {Promise<void>}
	 */
	async openViaAdminBar() {
		await this.page.locator( ADMIN_BAR_TOGGLE ).click()
		await this.sidebar.waitFor( { state: 'visible' } )
	}

	/**
	 * Reload the page and wait for sidebar chrome to settle from localStorage.
	 *
	 * @param {Object} [options]
	 * @param {string|number} [options.sessionId] Session to wait for when open (defaults to active tab).
	 * @param {boolean} [options.expectOpen=true] Whether the sidebar should be open after reload.
	 * @return {Promise<void>}
	 */
	async reloadPreservingSidebar( { sessionId, expectOpen = true } = {} ) {
		let hydrateId = sessionId
		if ( expectOpen && ( hydrateId === undefined || hydrateId === null || hydrateId === '' ) ) {
			hydrateId = await this.page.evaluate( storageKey => {
				try {
					const raw = window.localStorage.getItem( storageKey )
					const parsed = raw ? JSON.parse( raw ) : null
					return parsed?.activeTabId || parsed?.tabs?.[ 0 ]?.id || null
				} catch ( _err ) {
					return null
				}
			}, STORAGE_KEY )
		}

		const hydrated = expectOpen && hydrateId
			? waitForSessionHydration( this.page, hydrateId )
			: null

		await this.page.reload()

		if ( expectOpen ) {
			await Promise.all( [
				hydrated || Promise.resolve(),
				this.sidebar.waitFor( { state: 'visible' } ),
			] )
			await this.composer.waitFor( { state: 'visible' } )
			return
		}

		await this.page.locator( '#ahentic-root' ).waitFor( { state: 'attached' } )
		await this.page.waitForFunction( () => {
			return ! document.querySelector( 'aside.ahentic-sidebar.is-open' )
		} )
	}

	/**
	 * Toggle via Cmd/Ctrl+I.
	 *
	 * @return {Promise<void>}
	 */
	async toggleViaShortcut() {
		const meta = process.platform === 'darwin' ? 'Meta' : 'Control'
		await this.page.keyboard.press( `${ meta }+i` )
	}

	/**
	 * Read the open sidebar panel width from its inline style.
	 *
	 * @return {Promise<number>} Width in CSS pixels.
	 */
	async panelWidth() {
		return this.sidebar.evaluate( el => {
			const style = el.getAttribute( 'style' ) || ''
			const match = style.match( /width:\s*([\d.]+)px/ )
			if ( match ) {
				return Number( match[ 1 ] )
			}
			return el.getBoundingClientRect().width
		} )
	}

	/**
	 * Drag the docked resize handle by a delta in CSS pixels.
	 *
	 * @param {number} deltaX Positive widens a right-docked sidebar (handle moves left).
	 * @return {Promise<void>}
	 */
	async resizeBy( deltaX ) {
		const handle = this.resizeHandle
		const box = await handle.boundingBox()
		if ( ! box ) {
			throw new Error( 'Resize handle not visible' )
		}
		const startX = box.x + ( box.width / 2 )
		const startY = box.y + ( box.height / 2 )
		// Right-docked: dragging the left edge left (negative client delta) increases width.
		await this.page.mouse.move( startX, startY )
		await this.page.mouse.down()
		await this.page.mouse.move( startX - deltaX, startY, { steps: 8 } )
		await this.page.mouse.up()
	}
}

module.exports = {
	AhenticSidebar,
	STORAGE_KEY,
	ADMIN_BAR_TOGGLE,
	mockReply,
	buildSidebarStorage,
}
