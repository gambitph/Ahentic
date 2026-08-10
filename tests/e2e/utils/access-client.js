/**
 * Browser helpers for Ahentic admin-bar / role-access e2e.
 *
 * Admin specs reuse the global `storageState` login. Editor / visitor specs
 * open a fresh browser context so cookies from the admin session are not shared.
 */
const path = require( 'path' )
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' )

const ADMIN_BAR_NODE = '#wp-admin-bar-ahentic-toggle'
const ADMIN_BAR_TOGGLE = `${ ADMIN_BAR_NODE } > .ab-item`
const AHENTIC_ROOT = '#ahentic-root'
const SIDEBAR_OPEN = 'aside.ahentic-sidebar.is-open'

/**
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<void>} Resolves when the shortcut has been pressed.
 */
async function pressAhenticShortcut( page ) {
	const meta = process.platform === 'darwin' ? 'Meta' : 'Control'
	await page.keyboard.press( `${ meta }+i` )
}

/**
 * @param {import('@playwright/test').Page} page
 * @return {{ bar: import('@playwright/test').Locator, root: import('@playwright/test').Locator, sidebar: import('@playwright/test').Locator }} Admin-bar, root, and open-sidebar locators.
 */
function ahenticLocators( page ) {
	return {
		bar: page.locator( ADMIN_BAR_NODE ),
		root: page.locator( AHENTIC_ROOT ),
		sidebar: page.locator( SIDEBAR_OPEN ),
	}
}

/**
 * Log in through wp-login.php in a fresh context (no admin storageState).
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {Object} user
 * @param {string} user.username
 * @param {string} user.password
 * @return {Promise<{ context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page }>} Fresh context and logged-in page.
 */
async function loginInFreshContext( browser, { username, password } ) {
	const context = await browser.newContext( {
		baseURL: process.env.WP_BASE_URL,
		storageState: undefined,
	} )
	const page = await context.newPage()
	await page.goto( '/wp-login.php' )
	await page.locator( '#user_login' ).fill( username )
	await page.locator( '#user_pass' ).fill( password )
	await page.locator( '#wp-submit' ).click()
	await page.waitForURL( url => ! String( url ).includes( 'wp-login.php' ), {
		timeout: 30_000,
	} )
	return { context, page }
}

/**
 * Storage state that opts out of WordPress Playground auto-login.
 *
 * Playground's `1-auto-login.php` logs cookie-less browsers in as admin unless
 * `playground_auto_login_already_happened` is set. A true anonymous visitor
 * session needs those guard cookies (and no WP auth cookies).
 *
 * @return {import('@playwright/test').BrowserContextOptions['storageState']} Storage state with Playground auto-login guard cookies.
 */
function anonymousPlaygroundStorageState() {
	const base = process.env.WP_BASE_URL || 'http://127.0.0.1:9400'
	const { hostname } = new URL( base )
	const expires = Math.floor( Date.now() / 1000 ) + 172800
	return {
		cookies: [
			{
				name: 'playground_auto_login_already_happened',
				value: '1',
				domain: hostname,
				path: '/',
				expires,
				httpOnly: false,
				secure: false,
				sameSite: 'Lax',
			},
			{
				name: 'playground_auto_login_already_logged_out',
				value: '1',
				domain: hostname,
				path: '/',
				expires,
				httpOnly: false,
				secure: false,
				sameSite: 'Lax',
			},
		],
		origins: [],
	}
}

/**
 * Anonymous visitor context (no WP auth cookies).
 *
 * @param {import('@playwright/test').Browser} browser
 * @return {Promise<{ context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page }>} Fresh anonymous context and page.
 */
async function visitorContext( browser ) {
	const context = await browser.newContext( {
		baseURL: process.env.WP_BASE_URL,
		storageState: anonymousPlaygroundStorageState(),
	} )
	const page = await context.newPage()
	return { context, page }
}

/**
 * Create (or reuse) an editor user for access specs.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils
 * @param {Object} [options]
 * @param {string} [options.username]
 * @param {string} [options.password]
 * @param {string} [options.email]
 * @return {Promise<{ id: number, username: string, password: string }>} Editor user credentials.
 */
async function ensureEditorUser( requestUtils, {
	username = 'ahentic_e2e_editor',
	password = 'editor-pass-e2e',
	email = 'ahentic-e2e-editor@example.com',
} = {} ) {
	const listed = await requestUtils.rest( {
		path: '/wp/v2/users',
		params: {
			search: username, context: 'edit', per_page: 20,
		},
	} )
	const existing = Array.isArray( listed )
		? listed.find( user => user.username === username )
		: null

	if ( existing?.id ) {
		await requestUtils.rest( {
			path: `/wp/v2/users/${ existing.id }`,
			method: 'POST',
			data: {
				password, roles: [ 'editor' ],
			},
		} )
		return {
			id: existing.id, username, password,
		}
	}

	const created = await requestUtils.createUser( {
		username,
		email,
		password,
		roles: [ 'editor' ],
	} )
	return {
		id: created.id, username, password,
	}
}

/**
 * Optional: write a storage-state file for an alternate user (unused by default).
 *
 * @param {Object} options
 * @param {string} options.username
 * @param {string} options.password
 * @param {string} options.storageStatePath
 * @return {Promise<import('@wordpress/e2e-test-utils-playwright').RequestUtils>} Request utils bound to the user storage state.
 */
async function setupUserStorageState( {
	username, password, storageStatePath,
} ) {
	const requestUtils = await RequestUtils.setup( {
		user: {
			username, password,
		},
		storageStatePath,
		baseURL: process.env.WP_BASE_URL,
	} )
	await requestUtils.setupRest()
	return requestUtils
}

module.exports = {
	ADMIN_BAR_NODE,
	ADMIN_BAR_TOGGLE,
	AHENTIC_ROOT,
	SIDEBAR_OPEN,
	ahenticLocators,
	pressAhenticShortcut,
	loginInFreshContext,
	visitorContext,
	ensureEditorUser,
	setupUserStorageState,
	editorStoragePath: path.join( __dirname, '../.auth/editor.json' ),
}
