const defaultConfig = require( '@wordpress/scripts/config/webpack.config' )
const path = require( 'path' )

module.exports = {
	...defaultConfig,
	entry: {
		// Main Ahentic admin script.
		'admin/index': path.resolve( __dirname, './src/admin/js/index.js' ),
		'admin/index-styles': path.resolve( __dirname, './src/admin/css/index.css' ),
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve?.alias,
			'~ahentic': path.resolve( __dirname, 'src' ),
		},
	},
	output: {
		...defaultConfig.output,
		chunkFilename: 'chunks/[name]-[chunkhash:8].js',
	},
}
