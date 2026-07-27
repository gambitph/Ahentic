/**
 * Push / release page content so the fixed sidebar does not overlap it.
 */

const OPEN_CLASS = 'ahentic-sidebar-is-open'
const MOBILE_CLASS = 'ahentic-sidebar-is-mobile'

/**
 * @param {Object}  options
 * @param {boolean} options.open
 * @param {number}  options.width
 * @param {boolean} options.isMobile
 */
export function syncPageInset( {
	open, width, isMobile,
} ) {
	const root = document.documentElement

	if ( open && ! isMobile ) {
		root.classList.add( OPEN_CLASS )
		root.style.setProperty( '--ahentic-sidebar-inset', `${ width }px` )
	} else {
		root.classList.remove( OPEN_CLASS )
		root.style.setProperty( '--ahentic-sidebar-inset', '0px' )
	}

	if ( isMobile ) {
		root.classList.add( MOBILE_CLASS )
	} else {
		root.classList.remove( MOBILE_CLASS )
	}
}

/**
 * Clean up inset styles (e.g. on unmount).
 */
export function clearPageInset() {
	const root = document.documentElement
	root.classList.remove( OPEN_CLASS, MOBILE_CLASS )
	root.style.removeProperty( '--ahentic-sidebar-inset' )
}
