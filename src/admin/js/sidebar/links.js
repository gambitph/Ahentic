/**
 * Shared helpers for deciding how sidebar-rendered links should open.
 *
 * Links that point back at the current site (relative paths, or absolute
 * URLs sharing the current origin) should navigate the current window
 * instead of spawning a new tab.
 */

/**
 * Whether a URL points at the current site's origin.
 *
 * Relative URLs (e.g. `/wp-admin/...`) are treated as same-origin.
 *
 * @param {string} url
 * @return {boolean}
 */
export function isSameOriginUrl( url ) {
	const value = String( url || '' ).trim()
	if ( ! value ) {
		return false
	}
	if ( value.startsWith( '/' ) && ! value.startsWith( '//' ) ) {
		return true
	}
	try {
		return new URL( value, window.location.href ).origin === window.location.origin
	} catch ( _err ) {
		return false
	}
}

/**
 * Open a URL, keeping same-site links in the current window/tab and only
 * opening a new tab for links that leave the site.
 *
 * @param {string} url
 */
export function openLink( url ) {
	if ( ! url ) {
		return
	}
	if ( isSameOriginUrl( url ) ) {
		window.location.href = url
		return
	}
	window.open( url, '_blank', 'noopener,noreferrer' )
}
