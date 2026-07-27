#!/usr/bin/env node

/* eslint-disable no-console */
const fs = require( 'fs' )
const path = require( 'path' )

/**
 * Updates the AHENTIC_BUILD constant in ahentic.php
 *
 * @param {string} buildType - 'free' or 'premium'
 */
function updateBuildType( buildType ) {
	const actualPluginPath = path.resolve( __dirname, '../ahentic.php' )

	if ( ! fs.existsSync( actualPluginPath ) ) {
		console.warn( `⚠️  ${ actualPluginPath } not found, skipping build type update` )
		return false
	}

	let content = fs.readFileSync( actualPluginPath, 'utf8' )
	const originalContent = content
	content = content.replace(
		/defined\(\s*'AHENTIC_BUILD'\s*\)\s*\|\|\s*define\(\s*'AHENTIC_BUILD'\s*,\s*'[^']*'\s*\);/,
		`defined( 'AHENTIC_BUILD' ) || define( 'AHENTIC_BUILD', '${ buildType }' );`
	)

	if ( content !== originalContent ) {
		fs.writeFileSync( actualPluginPath, content )
		console.log( `✅ Updated AHENTIC_BUILD to '${ buildType }' in ${ actualPluginPath }` )
		return true
	}
	console.log( `ℹ️  AHENTIC_BUILD already set to '${ buildType }' in ${ actualPluginPath }` )
	return false
}

if ( require.main === module ) {
	const buildType = process.argv[ 2 ]

	if ( ! buildType || ! [ 'free', 'premium' ].includes( buildType ) ) {
		console.error( 'Usage: node update-build-type.js <free|premium>' )
		process.exit( 1 )
	}

	updateBuildType( buildType )
}

module.exports = { updateBuildType }
