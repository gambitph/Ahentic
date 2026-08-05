/**
 * Playwright config for Ahentic's WordPress-backed e2e suite.
 *
 * Specs run against the environment defined by `.wp-env.tests.json`
 * (isolated site/DB, port 8889 via that file's "port" field) via
 * `npm run test:e2e`, never the `.wp-env.json` "development" environment a
 * contributor might have open locally.
 *
 * See tests/e2e/README.md for the harness this config wires up.
 */
const { defineConfig } = require( '@playwright/test' )

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8889'

module.exports = defineConfig( {
	testDir: './tests/e2e/specs',
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'html', { open: 'never' } ] ] : 'list',
	use: {
		baseURL,
		extraHTTPHeaders: {
			Accept: 'application/json',
		},
		trace: 'retain-on-failure',
	},
} )
