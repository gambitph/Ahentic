/**
 * Playwright global setup: mints a fresh application password for the
 * wp-env e2e instance's default admin user, so specs can authenticate
 * against the REST API without a browser-driven login flow.
 *
 * Runs against the `cli` container of the **separate** environment defined
 * by `.wp-env.tests.json` (via wp-env's `--config` flag) — not the
 * `.wp-env.json` "development" instance a contributor might have open
 * locally. `@wordpress/env`'s single-config `testsEnvironment` sub-instance
 * feature is deprecated; `--config` with its own file is the current
 * recommended way to run an isolated environment (see the `@wordpress/env`
 * README's "Running parallel environments" section).
 */
const { execSync } = require( 'child_process' )
const fs = require( 'fs' )
const path = require( 'path' )

const AUTH_DIR = path.join( __dirname, '.auth' )
const AUTH_FILE = path.join( AUTH_DIR, 'admin.json' )
const WP_ENV_ADMIN_USER = 'admin'
const WP_ENV_TESTS_CONFIG = '.wp-env.tests.json'

module.exports = async function globalSetup() {
	const appPasswordName = `ahentic-e2e-${ Date.now() }`

	let output
	try {
		output = execSync(
			`npx wp-env run cli --config=${ WP_ENV_TESTS_CONFIG } wp user application-password create ${ WP_ENV_ADMIN_USER } ${ appPasswordName } --porcelain`,
			{ encoding: 'utf8' }
		)
	} catch ( error ) {
		throw new Error(
			'Could not create a WordPress application password for the e2e admin user via ' +
				`\`wp-env run cli --config=${ WP_ENV_TESTS_CONFIG }\`. ` +
				`Make sure \`npm run test:e2e\` (or \`wp-env start --config=${ WP_ENV_TESTS_CONFIG }\`) has finished ` +
				'successfully — Docker daemon running, ports free — before running the e2e suite.\n\n' +
				String( error )
		)
	}

	// `wp-env run` may print its own status lines before the command's actual
	// (porcelain) output, so take the last non-empty line rather than the
	// whole stdout blob.
	const lines = output.split( '\n' ).map( line => line.trim() ).filter( Boolean )
	const password = lines[ lines.length - 1 ]

	if ( ! password ) {
		throw new Error( '`wp user application-password create` returned no password.' )
	}

	fs.mkdirSync( AUTH_DIR, { recursive: true } )
	fs.writeFileSync(
		AUTH_FILE,
		JSON.stringify( { username: WP_ENV_ADMIN_USER, password }, null, '\t' ) + '\n'
	)
}
