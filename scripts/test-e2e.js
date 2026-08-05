#!/usr/bin/env node

/* eslint-disable no-console */
const { spawnSync } = require( 'child_process' )

const WP_ENV_TESTS_CONFIG = '.wp-env.tests.json'

/**
 * Runs a CLI command, streaming its output, and returns its exit code.
 *
 * @param {string}   command Executable to run.
 * @param {string[]} args    Arguments.
 * @return {number} Exit code.
 */
function run( command, args ) {
	const result = spawnSync( command, args, {
		stdio: 'inherit',
		shell: process.platform === 'win32',
	} )
	if ( result.error ) {
		console.error( result.error )
		return 1
	}
	return result.status === null ? 1 : result.status
}

/**
 * Whether an executable is resolvable on PATH.
 *
 * @param {string} command Executable name.
 * @return {boolean} Whether the command ran without an ENOENT.
 */
function commandExists( command ) {
	const result = spawnSync( command, [ '--version' ], { stdio: 'ignore' } )
	return ! ( result.error && result.error.code === 'ENOENT' )
}

function main() {
	if ( ! commandExists( 'docker' ) ) {
		console.error(
			'❌ The `docker` command was not found on your PATH.\n' +
				'   wp-env needs a Docker CLI + running daemon (Docker Desktop, OrbStack, Colima, etc.).\n' +
				'   Install one of those, make sure `docker --version` works in a fresh terminal, then re-run `npm run test:e2e`.'
		)
		process.exit( 1 )
	}

	console.log(
		'🐳 Starting wp-env (tests environment, --config=' + WP_ENV_TESTS_CONFIG + ') — ' +
			'leaves it running for the next run, use `npx wp-env stop --config=' + WP_ENV_TESTS_CONFIG + '` to shut it down...'
	)
	const startCode = run( 'npx', [ 'wp-env', 'start', `--config=${ WP_ENV_TESTS_CONFIG }` ] )
	if ( startCode !== 0 ) {
		console.error( '❌ wp-env failed to start. Is the Docker daemon actually running (not just installed)?' )
		process.exit( startCode )
	}

	console.log( '🎭 Running Playwright e2e suite against the wp-env tests environment...' )
	const passthroughArgs = process.argv.slice( 2 )
	const testCode = run( 'npx', [ 'playwright', 'test', ...passthroughArgs ] )

	process.exit( testCode )
}

main()
