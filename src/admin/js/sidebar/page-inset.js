/**
 * Push / release page content so the docked sidebar does not overlap it.
 * Floating placements never push the page.
 */

import {
	PLACEMENTS,
	isFloatingPlacement,
	DEFAULT_PLACEMENT,
	normalizePlacement,
} from './constants'

const OPEN_CLASS = 'ahentic-sidebar-is-open'
const MOBILE_CLASS = 'ahentic-sidebar-is-mobile'
const PLACEMENT_CLASSES = {
	[ PLACEMENTS.RIGHT ]: 'ahentic-sidebar-placement-right',
	[ PLACEMENTS.LEFT ]: 'ahentic-sidebar-placement-left',
	[ PLACEMENTS.FLOATING ]: 'ahentic-sidebar-placement-floating',
	[ PLACEMENTS.FLOATING_SMALL ]: 'ahentic-sidebar-placement-floating-small',
}

/**
 * @param {Object}  options
 * @param {boolean} options.open
 * @param {number}  options.width
 * @param {boolean} options.isMobile
 * @param {string}  [options.placement]
 */
export function syncPageInset( {
	open, width, isMobile, placement = DEFAULT_PLACEMENT,
} ) {
	const root = document.documentElement
	const resolved = normalizePlacement( placement )
	const docked = open && ! isMobile && ! isFloatingPlacement( resolved )

	Object.values( PLACEMENT_CLASSES ).forEach( className => {
		root.classList.remove( className )
	} )
	root.classList.add( PLACEMENT_CLASSES[ resolved ] || PLACEMENT_CLASSES[ DEFAULT_PLACEMENT ] )

	if ( docked ) {
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
	root.classList.remove( OPEN_CLASS, MOBILE_CLASS, ...Object.values( PLACEMENT_CLASSES ) )
	root.style.removeProperty( '--ahentic-sidebar-inset' )
}
