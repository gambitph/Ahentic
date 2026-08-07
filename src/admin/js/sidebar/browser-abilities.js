/**
 * Client-side handlers for browser-runtime abilities.
 */

/* eslint-disable camelcase -- Ability I/O matches PHP schema snake_case. */

import { collectPageContext } from './page-context'
import { collectVisiblePage } from './visible-page'
import {
	getEditorState,
	getBlocks,
	getSelection,
	getBlockType,
	listBlockTypes,
	focusBlock,
	updateBlockAttributes,
	replaceBlocks,
	setBlocks,
	insertBlocks,
	duplicateBlocks,
	deleteBlocks,
	moveBlocks,
	normalizeBlockStyles,
	restyleBlocksToPalette,
	convertBlocks,
	auditAccessibility,
	updatePostTitle,
	updatePostDocument,
	setFeaturedImage,
	savePost,
} from './editor-abilities'

const FETCH_TIMEOUT_MS = 12000
const FETCH_MAX_CHARS = 65536
const EXCERPT_MAX = 4000

/**
 * Run a pending browser ability and return a result payload.
 *
 * @param {{ name?: string, input?: Object }} pending
 * @return {Promise<{ result?: Object, error?: string }>} Ability result or error.
 */
export async function runBrowserAbility( pending ) {
	const name = pending?.name || ''
	const input = pending?.input && typeof pending.input === 'object' ? pending.input : {}

	try {
		switch ( name ) {
			case 'ahentic-browser/get-current-page':
				return { result: collectPageContext() }
			case 'ahentic-browser/get-visible-page':
				return { result: collectVisiblePage() }
			case 'ahentic-browser/get-editor-state':
				return { result: getEditorState() }
			case 'ahentic-browser/get-blocks':
				return { result: getBlocks( input ) }
			case 'ahentic-browser/get-selection':
				return { result: getSelection() }
			case 'ahentic-browser/get-block-type':
				return { result: getBlockType( input ) }
			case 'ahentic-browser/list-block-types':
				return { result: listBlockTypes( input ) }
			case 'ahentic-browser/focus-block':
				return { result: focusBlock( input ) }
			case 'ahentic-browser/update-block-attributes':
				return { result: updateBlockAttributes( input ) }
			case 'ahentic-browser/replace-blocks':
				return { result: replaceBlocks( input ) }
			case 'ahentic-browser/set-blocks':
				return { result: setBlocks( input ) }
			case 'ahentic-browser/insert-blocks':
				return { result: insertBlocks( input ) }
			case 'ahentic-browser/duplicate-blocks':
				return { result: duplicateBlocks( input ) }
			case 'ahentic-browser/delete-blocks':
				return { result: deleteBlocks( input ) }
			case 'ahentic-browser/move-blocks':
				return { result: moveBlocks( input ) }
			case 'ahentic-browser/normalize-block-styles':
				return { result: normalizeBlockStyles( input ) }
			case 'ahentic-browser/restyle-blocks-to-palette':
				return { result: restyleBlocksToPalette( input ) }
			case 'ahentic-browser/convert-blocks':
				return { result: convertBlocks( input ) }
			case 'ahentic-browser/audit-accessibility':
				return { result: auditAccessibility() }
			case 'ahentic-browser/update-post-title':
				return { result: updatePostTitle( input ) }
			case 'ahentic-browser/update-post-document':
				return { result: updatePostDocument( input ) }
			case 'ahentic-browser/set-featured-image':
				return { result: setFeaturedImage( input ) }
			case 'ahentic-browser/save-post':
				return { result: await savePost() }
			case 'ahentic/http-fetch':
				return { result: await fetchPageAsUser( input ) }
			default:
				return {
					error: `Unsupported browser ability: ${ name || 'unknown' }`,
				}
		}
	} catch ( error ) {
		return {
			error: error?.message || 'Browser ability failed.',
		}
	}
}

/**
 * Credentialed same-site fetch for http-fetch + as_user.
 *
 * @param {{ url?: string, as_user?: boolean }} input
 * @return {Promise<Object>} Fetch result payload.
 */
async function fetchPageAsUser( input ) {
	const url = typeof input.url === 'string' ? input.url.trim() : ''
	if ( ! url ) {
		return {
			ok: false,
			error: 'missing_url',
			message: 'A URL is required.',
			as_user: true,
			via: 'browser',
		}
	}

	let parsed
	try {
		parsed = new URL( url, window.location.href )
	} catch ( error ) {
		return {
			ok: false,
			error: 'invalid_url',
			url,
			message: 'That URL is not valid.',
			as_user: true,
			via: 'browser',
		}
	}

	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		return {
			ok: false,
			error: 'invalid_url',
			url: parsed.href,
			message: 'Only http and https URLs are allowed.',
			as_user: true,
			via: 'browser',
		}
	}

	if ( ! isSameSiteUrl( parsed ) ) {
		return {
			ok: false,
			error: 'as_user_same_site',
			url: parsed.href,
			same_site: false,
			as_user: true,
			via: 'browser',
			message: 'as_user is only allowed for URLs on this WordPress site.',
		}
	}

	const controller = typeof AbortController !== 'undefined' ? new AbortController() : null
	const timer = controller
		? window.setTimeout( () => controller.abort(), FETCH_TIMEOUT_MS )
		: 0

	const started = performance.now()
	try {
		const response = await fetch( parsed.href, {
			method: 'GET',
			credentials: 'include',
			redirect: 'follow',
			signal: controller ? controller.signal : undefined,
			headers: {
				Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			},
		} )

		const raw = await response.text()
		const durationMs = Math.round( performance.now() - started )
		const truncated = raw.length > FETCH_MAX_CHARS
		const body = truncated ? raw.slice( 0, FETCH_MAX_CHARS ) : raw
		const lower = body.toLowerCase()
		const looksLogin = ( lower.includes( 'name="log"' ) && lower.includes( 'name="pwd"' ) ) ||
			lower.includes( 'id="loginform"' ) ||
			lower.includes( '/wp-login.php' )
		const looksAdmin = lower.includes( 'id="wpadminbar"' ) ||
			lower.includes( 'id="wpbody"' ) ||
			lower.includes( 'class="wp-admin' )
		const successMarker = looksAdmin && ! looksLogin && response.status >= 200 &&
			response.status < 400 && body.length > 200

		return {
			ok: successMarker,
			url: parsed.href,
			final_url: response.url || parsed.href,
			status: response.status,
			duration_ms: durationMs,
			body_bytes: raw.length,
			truncated,
			excerpt: htmlToExcerpt( body ),
			same_site: true,
			as_user: true,
			auth_used: true,
			looks_like_login: looksLogin,
			looks_like_admin: looksAdmin,
			success_marker: successMarker,
			content_type: response.headers.get( 'content-type' ) || '',
			via: 'browser',
		}
	} catch ( error ) {
		const durationMs = Math.round( performance.now() - started )
		const aborted = error?.name === 'AbortError'
		return {
			ok: false,
			error: aborted ? 'timeout' : 'fetch_failed',
			url: parsed.href,
			same_site: true,
			as_user: true,
			via: 'browser',
			duration_ms: durationMs,
			timed_out: aborted,
			message: aborted
				? 'The request timed out.'
				: ( error?.message || 'Browser fetch failed.' ),
		}
	} finally {
		if ( timer ) {
			window.clearTimeout( timer )
		}
	}
}

/**
 * @param {URL} parsed
 * @return {boolean} True if the URL's hostname matches this site.
 */
function isSameSiteUrl( parsed ) {
	const hosts = new Set()
	const addHost = value => {
		if ( ! value || typeof value !== 'string' ) {
			return
		}
		try {
			hosts.add( new URL( value ).hostname.toLowerCase() )
		} catch ( error ) {
			// Ignore invalid localized URLs.
		}
	}

	addHost( window.ahentic?.homeUrl )
	addHost( window.ahentic?.siteUrl )
	hosts.add( window.location.hostname.toLowerCase() )

	return hosts.has( parsed.hostname.toLowerCase() )
}

/**
 * @param {string} html
 * @return {string} Plain-text excerpt, truncated to EXCERPT_MAX.
 */
function htmlToExcerpt( html ) {
	let text = String( html || '' )
	text = text.replace( /<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ' )
	text = text.replace( /<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ' )
	text = text.replace( /<[^>]+>/g, ' ' )
	text = text.replace( /\s+/g, ' ' ).trim()
	if ( text.length > EXCERPT_MAX ) {
		text = `${ text.slice( 0, EXCERPT_MAX - 1 ) }…`
	}
	return text
}
