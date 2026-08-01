/**
 * Shared constants for the Ahentic sidebar.
 */

export const STORAGE_KEY = 'ahentic.sidebar.v1'

export const DEFAULT_WIDTH = 360
export const MIN_WIDTH = 300
export const MAX_WIDTH = 560
export const MOBILE_BREAKPOINT = 768

export const DEFAULT_THEME = 'dark'

export const MODES = {
	AGENT: 'agent',
	ASK: 'ask',
}

/**
 * Sidebar chrome placement (docked vs floating).
 * Distinct from composer `mode` (agent|ask).
 */
export const PLACEMENTS = {
	RIGHT: 'right',
	LEFT: 'left',
	FLOATING: 'floating',
	FLOATING_SMALL: 'floating-small',
}

export const DEFAULT_PLACEMENT = PLACEMENTS.RIGHT

export const FLOATING_GAP = 30
export const FLOATING_SMALL_HEIGHT = 600
export const MIN_FLOAT_HEIGHT = 320
export const MAX_FLOAT_HEIGHT = 1200

/**
 * @param {string} value
 * @return {boolean}
 */
export function isFloatingPlacement( value ) {
	return value === PLACEMENTS.FLOATING || value === PLACEMENTS.FLOATING_SMALL
}

/**
 * @param {string} value
 * @return {string} Normalized placement id.
 */
export function normalizePlacement( value ) {
	const allowed = Object.values( PLACEMENTS )
	return allowed.includes( value ) ? value : DEFAULT_PLACEMENT
}

/**
 * Default geometry for floating placements (viewport-relative).
 *
 * @param {string} placement
 * @param {number} [width]
 * @return {{ left: number, top: number, width: number, height: number }}
 */
export function getDefaultFloatingRect( placement, width = DEFAULT_WIDTH ) {
	const gap = FLOATING_GAP
	const vw = typeof window !== 'undefined' ? window.innerWidth : 1280
	const vh = typeof window !== 'undefined' ? window.innerHeight : 800
	const w = Math.min( MAX_WIDTH, Math.max( MIN_WIDTH, Math.round( width ) ) )

	if ( placement === PLACEMENTS.FLOATING_SMALL ) {
		const maxH = Math.max( MIN_FLOAT_HEIGHT, vh - ( gap * 2 ) )
		const h = Math.min( FLOATING_SMALL_HEIGHT, maxH )
		return {
			left: Math.max( gap, vw - gap - w ),
			top: Math.max( gap, vh - gap - h ),
			width: w,
			height: h,
		}
	}

	return {
		left: Math.max( gap, vw - gap - w ),
		top: gap,
		width: w,
		height: Math.max( MIN_FLOAT_HEIGHT, vh - ( gap * 2 ) ),
	}
}

/**
 * Keep a floating rect usable inside the viewport.
 *
 * @param {{ left: number, top: number, width: number, height: number }} rect
 * @return {{ left: number, top: number, width: number, height: number }}
 */
export function clampFloatingRect( rect ) {
	const vw = typeof window !== 'undefined' ? window.innerWidth : 1280
	const vh = typeof window !== 'undefined' ? window.innerHeight : 800
	const width = Math.min( MAX_WIDTH, Math.max( MIN_WIDTH, Math.round( Number( rect.width ) || DEFAULT_WIDTH ) ) )
	const height = Math.min(
		Math.min( MAX_FLOAT_HEIGHT, vh - 16 ),
		Math.max( MIN_FLOAT_HEIGHT, Math.round( Number( rect.height ) || MIN_FLOAT_HEIGHT ) )
	)
	const left = Math.min( Math.max( 0, Math.round( Number( rect.left ) || 0 ) ), Math.max( 0, vw - MIN_WIDTH ) )
	const top = Math.min( Math.max( 0, Math.round( Number( rect.top ) || 0 ) ), Math.max( 0, vh - 80 ) )

	return {
		left,
		top,
		width,
		height: Math.min( height, Math.max( MIN_FLOAT_HEIGHT, vh - top ) ),
	}
}

/**
 * Create a local tab shell (used before a server session exists).
 * Prefer createSession() via REST for real tabs — id becomes the session post ID.
 *
 * @return {{ id: string, title: string, createdAt: number }} New tab object.
 */
export function createTab() {
	return {
		id: `tab_${ Date.now() }_${ Math.random().toString( 36 ).slice( 2, 8 ) }`,
		title: 'New Agent',
		createdAt: Date.now(),
	}
}
