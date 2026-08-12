/**
 * Smoke-check docs-site build output.
 */

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = path.dirname( fileURLToPath( import.meta.url ) )
const dist = path.join( ROOT, 'dist' )
const htmlPath = path.join( dist, 'index.html' )
const cssPath = path.join( dist, 'styles.css' )
const iconPath = path.join( dist, 'ahentic-icon.svg' )

function assert( condition, message ) {
	if ( ! condition ) {
		throw new Error( message )
	}
}

assert( fs.existsSync( htmlPath ), 'dist/index.html missing - run npm run build first' )
assert( fs.existsSync( cssPath ), 'dist/styles.css missing' )
assert( fs.existsSync( iconPath ), 'dist/ahentic-icon.svg missing' )

const html = fs.readFileSync( htmlPath, 'utf8' )
const css = fs.readFileSync( cssPath, 'utf8' )

assert( html.includes( 'site-header__brand' ), 'expected header brand' )
assert( html.includes( 'site-header__name">Ahentic<' ), 'expected Ahentic wordmark in header' )
assert( html.includes( 'ahentic-icon.svg' ), 'expected icon reference' )
assert( html.includes( 'class="toc"' ), 'expected left TOC' )
assert( html.includes( 'id="what-is-ahentic"' ), 'expected h2 id for What is Ahentic' )
assert( html.includes( 'href="#before-you-start"' ), 'expected nav link to Before you start' )
assert( html.includes( 'docs.wpahentic.com' ) || html.includes( 'wpahentic.com' ), 'expected wpahentic.com branding' )
assert(
	! /(?<!wp)ahentic\.com/.test( html ),
	'built HTML must not reference ahentic.com (without wp prefix)'
)
assert( css.includes( '#5750f8' ) || css.includes( '5750f8' ), 'expected Ahentic primary in compiled CSS' )
assert( css.includes( '--spruce-' ), 'expected Spruce CSS custom properties' )
assert( css.includes( 'Plus Jakarta Sans' ), 'expected Plus Jakarta Sans for headings' )
assert( css.includes( 'system-ui' ), 'expected system-ui body stack' )
assert( html.includes( 'fonts.googleapis.com' ), 'expected Plus Jakarta Sans font link' )
assert( css.includes( '#f5f6fa' ) || css.includes( 'f5f6fa' ), 'expected soft page background' )
assert( css.includes( '.table-wrap' ) || css.includes( 'table-wrap' ), 'expected table wrap styles' )
assert( html.includes( 'table-wrap' ), 'expected tables wrapped for overflow' )

console.log( 'docs-site build smoke checks passed' )
