/**
 * Playwright config for Ahentic's WordPress-backed e2e suite.
 *
 * WordPress itself is provided by `@wp-playground/cli` (WordPress Playground:
 * WASM PHP + SQLite) via the `webServer` option below — no Docker, MySQL, or
 * Apache required. Playwright starts it automatically before the suite runs
 * (and reuses an already-running instance locally, see `reuseExistingServer`).
 *
 * `WP_BASE_URL` must be set before `@wordpress/e2e-test-utils-playwright` is
 * first imported (its config reads `process.env.WP_BASE_URL` at module load
 * time), so it's assigned here, at the top of the config Node loads first.
 *
 * See tests/e2e/README.md for the harness this config wires up.
 */
const path = require( 'path' )
const { defineConfig } = require( '@playwright/test' )

const PORT = process.env.WP_PORT || '9400'
const baseURL = process.env.WP_BASE_URL || `http://127.0.0.1:${ PORT }`
process.env.WP_BASE_URL = baseURL
process.env.WP_USERNAME = process.env.WP_USERNAME || 'admin'
process.env.WP_PASSWORD = process.env.WP_PASSWORD || 'password'

const STORAGE_STATE_PATH = path.join( __dirname, 'tests/e2e/.auth/admin.json' )
process.env.STORAGE_STATE_PATH = STORAGE_STATE_PATH

const PLAYGROUND_BLUEPRINT = path.join( __dirname, 'tests/e2e/playground-blueprint.json' )

module.exports = defineConfig( {
	testDir: './tests/e2e/specs',
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'html', { open: 'never' } ] ] : 'list',
	webServer: {
		command: [
			'npx @wp-playground/cli server',
			'--mount=.:/wordpress/wp-content/plugins/ahentic',
			'--mount=./tests/e2e/mu-plugins:/wordpress/wp-content/mu-plugins',
			`--blueprint=${ PLAYGROUND_BLUEPRINT }`,
			'--php=8.2',
			`--port=${ PORT }`,
		].join( ' ' ),
		// Not `url`: Playground's own auto-login middleware 302-redirects every
		// cookie-less request (including this check's) back to itself, which a
		// plain redirect-following HTTP prober treats as "too many redirects" and
		// never resolves. `port` only waits for the TCP listener to accept
		// connections, sidestepping that entirely. Real spec traffic is
		// unaffected — Playwright's request/page contexts handle cookies (and
		// this one-time redirect) like a normal HTTP client/browser would.
		port: Number( PORT ),
		reuseExistingServer: ! process.env.CI,
		timeout: 180 * 1000,
		stdout: 'pipe',
		stderr: 'pipe',
	},
	use: {
		baseURL,
		storageState: STORAGE_STATE_PATH,
		extraHTTPHeaders: {
			Accept: 'application/json',
		},
		trace: 'retain-on-failure',
	},
	projects: [
		{
			// Abilities / harness that do not share the mocked-AI option queue.
			name: 'rest-direct',
			testMatch: /(?:content-and-plugins|media-abilities|settings-abilities|users-abilities|menus-abilities)\.spec\.js/,
		},
		{
			// Specs that seed `ahentic_e2e_ai_queue` or `ahentic_e2e_ai_status_false_remaining`
			// must not run in parallel — both are single WP options inside Playground.
			name: 'ai-orchestrator',
			testMatch: /(?:orchestrator-pipeline|job-resume|hitl-and-undo|sidebar-chat|connector-status-recovery|ai-plugin-status)\.spec\.js/,
			fullyParallel: false,
			workers: 1,
		},
	],
} )
