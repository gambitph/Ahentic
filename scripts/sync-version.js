const fs = require( 'fs' )
const https = require( 'https' )

// Allow PR builds to add a version suffix.
let versionSuffix = ''
if ( process.argv.length === 3 ) {
	versionSuffix = process.argv[ process.argv.length - 1 ]
}

// Function to fetch available WordPress versions
async function getAvailableWordPressVersions() {
	return new Promise( resolve => {
		https.get( 'https://api.wordpress.org/core/version-check/1.7/', res => {
			let data = ''

			res.on( 'data', chunk => {
				data += chunk
			} )

			res.on( 'end', () => {
				try {
					const response = JSON.parse( data )
					if ( response.offers && response.offers.length > 0 ) {
						const versions = response.offers.map( offer => offer.version )
						resolve( versions )
					} else {
						resolve( [ '6.8.2', '6.8.1', '6.8.0', '6.7.2', '6.7.1', '6.7.0', '6.6.2', '6.6.1', '6.6.0' ] )
					}
				} catch ( error ) {
					resolve( [ '6.8.2', '6.8.1', '6.8.0', '6.7.2', '6.7.1', '6.7.0', '6.6.2', '6.6.1', '6.6.0' ] )
				}
			} )
		} ).on( 'error', () => {
			resolve( [ '6.8.2', '6.8.1', '6.8.0', '6.7.2', '6.7.1', '6.7.0', '6.6.2', '6.6.1', '6.6.0' ] )
		} )
	} )
}

// Function to calculate minimum required version (2 minor versions behind, but ensure it exists)
function calculateMinVersion( latestVersion, availableVersions ) {
	const parts = latestVersion.split( '.' )
	if ( parts.length >= 2 ) {
		const major = parseInt( parts[ 0 ] )
		const minor = parseInt( parts[ 1 ] )

		const targetMinor = Math.max( 0, minor - 2 )
		const targetVersion = `${ major }.${ targetMinor }`

		const exactMatch = availableVersions.find( version => version.startsWith( targetVersion ) )
		if ( exactMatch ) {
			return exactMatch
		}

		const availableInMajor = availableVersions
			.filter( version => version.startsWith( `${ major }.` ) )
			.sort( ( a, b ) => {
				const aMinor = parseInt( a.split( '.' )[ 1 ] )
				const bMinor = parseInt( b.split( '.' )[ 1 ] )
				return bMinor - aMinor
			} )

		for ( const version of availableInMajor ) {
			const versionMinor = parseInt( version.split( '.' )[ 1 ] )
			if ( versionMinor <= targetMinor ) {
				return version
			}
		}

		return availableVersions[ availableVersions.length - 1 ]
	}
	return latestVersion
}

function compareWordPressVersions( a, b ) {
	const partsA = String( a ).trim().split( '.' ).map( n => parseInt( n, 10 ) || 0 )
	const partsB = String( b ).trim().split( '.' ).map( n => parseInt( n, 10 ) || 0 )
	const len = Math.max( partsA.length, partsB.length )
	for ( let i = 0; i < len; i++ ) {
		const numA = partsA[ i ] ?? 0
		const numB = partsB[ i ] ?? 0
		if ( numA !== numB ) {
			return numA - numB
		}
	}
	return 0
}

// Helper: Remove sub-version after x.y, e.g., '6.5.2' => '6.5'
function stripWpPatchVersion( version ) {
	const parts = version.split( '.' )
	return parts.length >= 2 ? `${ parts[ 0 ] }.${ parts[ 1 ] }` : version
}

async function syncVersions() {
	try {
		const ahenticPhp = fs.readFileSync( 'ahentic.php', 'utf8' )
		const versionMatch = ahenticPhp.match( /^\s*\*\s*Version:\s*([^\r\n]+)/m )

		if ( ! versionMatch ) {
			throw new Error( 'Could not find Version in ahentic.php' )
		}

		const basePluginVersion = versionMatch[ 1 ].trim()
		const pluginVersion = versionSuffix ? `${ basePluginVersion }-${ versionSuffix }` : basePluginVersion

		if ( versionSuffix ) {
			const updatedPluginContent = ahenticPhp.replace(
				/^(\s*\*\s*Version:\s*)([^\r\n]+)/m,
				`$1${ pluginVersion }`
			)
			fs.writeFileSync( 'ahentic.php', updatedPluginContent )
			// eslint-disable-next-line no-console
			console.log( `✅ ahentic.php version updated to ${ pluginVersion }` )
		}

		const packageJson = JSON.parse( fs.readFileSync( 'package.json', 'utf8' ) )

		if ( packageJson.version !== pluginVersion ) {
			// eslint-disable-next-line no-console
			console.log( `🔄 Updating package.json version from ${ packageJson.version } to ${ pluginVersion }` )

			packageJson.version = pluginVersion
			fs.writeFileSync( 'package.json', JSON.stringify( packageJson, null, '\t' ) + '\n' )

			// eslint-disable-next-line no-console
			console.log( '✅ package.json version updated successfully' )
		} else {
			// eslint-disable-next-line no-console
			console.log( `✅ package.json version already matches plugin version: ${ pluginVersion }` )
		}

		// eslint-disable-next-line no-console
		console.log( '🌐 Fetching available WordPress versions...' )
		const availableVersions = await getAvailableWordPressVersions()
		const latestWordPressVersionRaw = availableVersions[ 0 ]
		const minWordPressVersion = calculateMinVersion( latestWordPressVersionRaw, availableVersions )

		// Remove patch (z) version for fields in readme.txt
		const latestWordPressVersion = stripWpPatchVersion( latestWordPressVersionRaw )

		// eslint-disable-next-line no-console
		console.log( `📊 Latest WordPress version: ${ latestWordPressVersion }` )
		// eslint-disable-next-line no-console
		console.log( `📊 Available versions: ${ availableVersions.slice( 0, 5 ).join( ', ' ) }...` )
		// eslint-disable-next-line no-console
		console.log( `📊 Minimum required version: ${ minWordPressVersion }` )

		let readmeTxt = fs.readFileSync( 'readme.txt', 'utf8' )

		const stableTagMatch = readmeTxt.match( /^Stable tag:\s*([^\r\n]+)/m )
		if ( stableTagMatch ) {
			const currentStableTag = stableTagMatch[ 1 ].trim()

			if ( currentStableTag !== pluginVersion ) {
				// eslint-disable-next-line no-console
				console.log( `🔄 Updating readme.txt stable tag from ${ currentStableTag } to ${ pluginVersion }` )

				readmeTxt = readmeTxt.replace(
					/^Stable tag:\s*[^\r\n]+/m,
					`Stable tag: ${ pluginVersion }`
				)
				fs.writeFileSync( 'readme.txt', readmeTxt )

				// eslint-disable-next-line no-console
				console.log( '✅ readme.txt stable tag updated successfully' )
			} else {
				// eslint-disable-next-line no-console
				console.log( `✅ readme.txt stable tag already matches plugin version: ${ pluginVersion }` )
			}
		} else {
			// eslint-disable-next-line no-console
			console.log( '⚠️  Could not find "Stable tag:" in readme.txt' )
		}

		const testedUpToMatch = readmeTxt.match( /^Tested up to:\s*([^\r\n]+)/m )
		if ( testedUpToMatch ) {
			const currentTestedUpTo = testedUpToMatch[ 1 ].trim()
			// Compare using original (unstripped) version, but update with stripped version.
			const readmeIsAhead = compareWordPressVersions( currentTestedUpTo, latestWordPressVersion ) > 0

			if ( readmeIsAhead ) {
				// eslint-disable-next-line no-console
				console.log( `✅ readme.txt tested up to (${ currentTestedUpTo }) is newer than fetched latest (${ latestWordPressVersion }); leaving unchanged` )
			} else if ( currentTestedUpTo !== latestWordPressVersion ) {
				// eslint-disable-next-line no-console
				console.log( `🔄 Updating readme.txt tested up to from ${ currentTestedUpTo } to ${ latestWordPressVersion }` )

				readmeTxt = readmeTxt.replace(
					/^Tested up to:\s*[^\r\n]+/m,
					`Tested up to: ${ latestWordPressVersion }`
				)
				fs.writeFileSync( 'readme.txt', readmeTxt )

				// eslint-disable-next-line no-console
				console.log( '✅ readme.txt tested up to updated successfully' )
			} else {
				// eslint-disable-next-line no-console
				console.log( `✅ readme.txt tested up to already matches latest WordPress version: ${ latestWordPressVersion }` )
			}
		} else {
			// eslint-disable-next-line no-console
			console.log( '⚠️  Could not find "Tested up to:" in readme.txt' )
		}

		const requiresAtLeastMatch = readmeTxt.match( /^Requires at least:\s*([^\r\n]+)/m )
		if ( requiresAtLeastMatch ) {
			const currentRequiresAtLeast = requiresAtLeastMatch[ 1 ].trim()

			if ( currentRequiresAtLeast !== minWordPressVersion ) {
				// eslint-disable-next-line no-console
				console.log( `🔄 Updating readme.txt requires at least from ${ currentRequiresAtLeast } to ${ minWordPressVersion }` )

				readmeTxt = readmeTxt.replace(
					/^Requires at least:\s*[^\r\n]+/m,
					`Requires at least: ${ minWordPressVersion }`
				)
				fs.writeFileSync( 'readme.txt', readmeTxt )

				// eslint-disable-next-line no-console
				console.log( '✅ readme.txt requires at least updated successfully' )
			} else {
				// eslint-disable-next-line no-console
				console.log( `✅ readme.txt requires at least already matches calculated minimum version: ${ minWordPressVersion }` )
			}
		} else {
			// eslint-disable-next-line no-console
			console.log( '⚠️  Could not find "Requires at least:" in readme.txt' )
		}
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error( '❌ Error syncing versions:', error.message )
		process.exit( 1 )
	}
}

syncVersions()
