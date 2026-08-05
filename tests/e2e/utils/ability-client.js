/**
 * Thin client for the e2e-only `ahentic-e2e/v1/run-ability` REST route.
 *
 * That route (tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php) delegates
 * straight to `Ahentic_Abilities::execute()` — the same seam the
 * orchestrator's step worker calls — so specs can assert real ability
 * behaviour against a live wp-env WordPress without driving an LLM turn
 * through the sidebar chat loop.
 */
const fs = require( 'fs' )
const path = require( 'path' )

const AUTH_FILE = path.join( __dirname, '..', '.auth', 'admin.json' )

/**
 * Load the admin application-password credentials written by global-setup.js.
 *
 * @return {{username: string, password: string}} Credentials for Basic auth.
 */
function loadAdminAuth() {
	if ( ! fs.existsSync( AUTH_FILE ) ) {
		throw new Error(
			'No e2e admin credentials found at ' + AUTH_FILE + '. ' +
				'Run the suite via `npm run test:e2e` (or `npx playwright test`, which runs ' +
				'the global setup) rather than invoking a spec file in isolation.'
		)
	}
	return JSON.parse( fs.readFileSync( AUTH_FILE, 'utf8' ) )
}

/**
 * Build a `Basic` auth header value for the e2e admin user.
 *
 * @return {string} `Authorization` header value.
 */
function basicAuthHeader() {
	const { username, password } = loadAdminAuth()
	return 'Basic ' + Buffer.from( `${ username }:${ password }` ).toString( 'base64' )
}

/**
 * Run a single Ahentic ability as the e2e admin user.
 *
 * @param {import('@playwright/test').APIRequestContext} request Playwright request context (usually the `request` fixture).
 * @param {string}                                       name    Ability name, e.g. "ahentic/list-content".
 * @param {Object}                                       [input] Ability input.
 * @return {Promise<{ok: boolean, data?: *, error?: string, message?: string}>} Parsed run-ability response.
 */
async function runAbility( request, name, input = {} ) {
	const response = await request.post( '/wp-json/ahentic-e2e/v1/run-ability', {
		headers: { Authorization: basicAuthHeader() },
		data: { name, input },
	} )

	if ( ! response.ok() ) {
		throw new Error(
			`ahentic-e2e run-ability HTTP ${ response.status() } for "${ name }": ${ await response.text() }`
		)
	}

	return response.json()
}

module.exports = {
	runAbility, loadAdminAuth, basicAuthHeader,
}
