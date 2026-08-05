/**
 * Extends `@wordpress/e2e-test-utils-playwright`'s `test` (which already
 * provides `page`/`admin`/`requestUtils`/etc., see
 * tests/e2e/global-setup.js) with an `ahenticSidebar` fixture for
 * browser-driven specs. Import `test`/`expect` from here rather than
 * `@playwright/test` directly whenever a spec needs the sidebar.
 */
const base = require( '@wordpress/e2e-test-utils-playwright' )
const { AhenticSidebar } = require( './ahentic-sidebar' )

const test = base.test.extend( {
	ahenticSidebar: async ( { page, requestUtils }, use ) => {
		await use( new AhenticSidebar( { page, requestUtils } ) )
	},
} )

module.exports = { test, expect: base.expect }
