/**
 * Collect lightweight page context for the agent (URL / title / admin hints).
 *
 * @return {{ url: string, title: string, pathname: string, search: string, hash: string, isAdmin: boolean, bodyClass: string }}
 */
export function collectPageContext() {
	if ( typeof window === 'undefined' || ! window.location ) {
		return {
			url: '',
			title: '',
			pathname: '',
			search: '',
			hash: '',
			isAdmin: false,
			bodyClass: '',
		}
	}

	const pathname = window.location.pathname || ''
	const isAdmin = Boolean( window.ahentic?.isAdmin ) || /\/wp-admin(?:\/|$)/.test( pathname )
	const bodyClass = typeof document !== 'undefined' && document.body
		? String( document.body.className || '' ).slice( 0, 500 )
		: ''

	return {
		url: window.location.href || '',
		title: typeof document !== 'undefined' ? String( document.title || '' ) : '',
		pathname,
		search: window.location.search || '',
		hash: window.location.hash || '',
		isAdmin,
		bodyClass,
	}
}
