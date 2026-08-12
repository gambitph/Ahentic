/**
 * Shared constants for the Ahentic sidebar.
 */

import { __ } from '@wordpress/i18n'

export const STORAGE_KEY = 'ahentic.sidebar.v1'

/**
 * Default session/tab title (keep in sync with Ahentic_Session_Repository).
 *
 * @return {string} Translated default title.
 */
export function defaultAgentTitle() {
	return __( 'New Agent', 'ahentic' )
}

/** Per-session active-runner claims (multi-window); not chrome state. */
export const RUNNER_LOCK_KEY = 'ahentic.session-runner.v1'

export const DEFAULT_WIDTH = 360
export const MIN_WIDTH = 300
export const MAX_WIDTH = 960
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
 * @return {boolean} True if the placement floats over the page.
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
 * Resolve viewport size for floating geometry helpers.
 *
 * @param {{ width?: number, height?: number }|undefined} viewport Optional override (tests).
 * @return {{ width: number, height: number }} Viewport dimensions in pixels.
 */
function resolveFloatingViewport( viewport ) {
	return {
		width: viewport?.width ?? ( typeof window !== 'undefined' ? window.innerWidth : 1280 ),
		height: viewport?.height ?? ( typeof window !== 'undefined' ? window.innerHeight : 800 ),
	}
}

/**
 * Default geometry for floating placements (viewport-relative).
 *
 * @param {string}                                             placement
 * @param {number}                                             [width]
 * @param {{ viewport?: { width?: number, height?: number } }} [options]
 * @return {{ left: number, top: number, width: number, height: number }} Default rect for the placement.
 */
export function getDefaultFloatingRect( placement, width = DEFAULT_WIDTH, options = {} ) {
	const gap = FLOATING_GAP
	const { width: vw, height: vh } = resolveFloatingViewport( options.viewport )
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
 * @param {{ viewport?: { width?: number, height?: number } }}           [options]
 * @return {{ left: number, top: number, width: number, height: number }} Rect clamped to the viewport.
 */
export function clampFloatingRect( rect, options = {} ) {
	const { width: vw, height: vh } = resolveFloatingViewport( options.viewport )
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
 * On open: restore unusable size and nudge a floating rect fully inside the
 * viewport using floating margins. Prefer minimal position changes.
 *
 * @param {{ left?: number, top?: number, width?: number, height?: number }|null} rect
 * @param {string}                                                                placement Floating placement id.
 * @param {{ viewport?: { width?: number, height?: number } }}                    [options]
 * @return {{ left: number, top: number, width: number, height: number }} Recovered rect.
 */
export function recoverFloatingRectOnOpen( rect, placement, options = {} ) {
	const { width: vw, height: vh } = resolveFloatingViewport( options.viewport )
	const gap = FLOATING_GAP
	const source = rect && typeof rect === 'object' ? rect : {}

	let width = Math.round( Number( source.width ) )
	if ( ! Number.isFinite( width ) || width <= 0 || width < MIN_WIDTH ) {
		width = DEFAULT_WIDTH
	}
	width = Math.min( MAX_WIDTH, width )

	let height = Math.round( Number( source.height ) )
	if ( ! Number.isFinite( height ) || height <= 0 || height < MIN_FLOAT_HEIGHT ) {
		height = getDefaultFloatingRect( placement, width, options ).height
	}
	height = Math.min( MAX_FLOAT_HEIGHT, height )

	// Fit inside the viewport with floating margins (may shrink after a browser resize).
	const maxWidth = Math.max( MIN_WIDTH, vw - ( gap * 2 ) )
	const maxHeight = Math.max( MIN_FLOAT_HEIGHT, vh - ( gap * 2 ) )
	width = Math.min( width, maxWidth )
	height = Math.min( height, maxHeight )

	let left = Math.round( Number( source.left ) )
	let top = Math.round( Number( source.top ) )
	if ( ! Number.isFinite( left ) ) {
		left = gap
	}
	if ( ! Number.isFinite( top ) ) {
		top = gap
	}

	const maxLeft = Math.max( gap, vw - gap - width )
	const maxTop = Math.max( gap, vh - gap - height )
	left = Math.min( Math.max( gap, left ), maxLeft )
	top = Math.min( Math.max( gap, top ), maxTop )

	return {
		left,
		top,
		width,
		height,
	}
}

/**
 * Create a local tab shell (used before a server session exists).
 * Prefer createSession() via REST for real tabs — id becomes the session post ID.
 *
 * @return {{ id: string, title: string, createdAt: number, autoTitle: boolean }} New tab object.
 */
export function createTab() {
	return {
		id: `tab_${ Date.now() }_${ Math.random().toString( 36 ).slice( 2, 8 ) }`,
		title: defaultAgentTitle(),
		createdAt: Date.now(),
		autoTitle: true,
	}
}

/**
 * Whether a tab still allows auto-renaming of its title.
 *
 * @param {{ autoTitle?: boolean }|null|undefined} tab
 * @return {boolean} True when the title may still be auto-renamed.
 */
export function tabAllowsAutoTitle( tab ) {
	return tab?.autoTitle !== false
}

/**
 * Title to send when creating a session from a local tab.
 *
 * @param {{ title?: string, autoTitle?: boolean }|null|undefined} tab
 * @return {string|undefined} Custom title, or undefined for the server default.
 */
export function createSessionTitleFromTab( tab ) {
	if ( ! tab || tabAllowsAutoTitle( tab ) ) {
		return undefined
	}
	const title = typeof tab.title === 'string' ? tab.title.trim() : ''
	return title || undefined
}

/**
 * Build a chrome tab from a session REST payload.
 *
 * @param {Object}                                              session
 * @param {{ createdAt?: number, title?: string, status?: string, autoTitle?: boolean }} [fallback]
 * @return {{ id: string, title: string, createdAt: number, status: string, autoTitle: boolean }} Tab.
 */
export function tabFromSession( session, fallback = {} ) {
	const autoTitle = typeof session?.autoTitle === 'boolean'
		? session.autoTitle
		: ( fallback.autoTitle !== false )

	return {
		id: String( session.id ),
		title: session.title || fallback.title || defaultAgentTitle(),
		createdAt: fallback.createdAt || Date.now(),
		status: session.status || fallback.status || 'idle',
		autoTitle,
	}
}
