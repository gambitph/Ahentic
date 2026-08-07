/**
 * localStorage helpers for sidebar chrome state.
 *
 * Persists: open, width, theme, tabs (session ids), activeTabId, mode, placement, floatRect.
 * Does NOT persist conversation message contents (those live on ahentic-session posts).
 *
 * Active-runner claims for multi-window safety use a separate key (`RUNNER_LOCK_KEY` in
 * `constants.js`) via `session-runner-lock.js` — do not merge them into the chrome blob.
 */

import {
	STORAGE_KEY,
	DEFAULT_WIDTH,
	DEFAULT_THEME,
	DEFAULT_PLACEMENT,
	MODES,
	createTab,
	MIN_WIDTH,
	MAX_WIDTH,
	normalizePlacement,
	isFloatingPlacement,
	clampFloatingRect,
	getDefaultFloatingRect,
} from './constants'

/**
 * @typedef {Object} FloatingRect
 * @property {number} left   Distance from the viewport's left edge, in pixels.
 * @property {number} top    Distance from the viewport's top edge, in pixels.
 * @property {number} width  Width in pixels.
 * @property {number} height Height in pixels.
 */

/**
 * @typedef {Object} SidebarPersistedState
 * @property {boolean}                                                 open        Whether the sidebar is open.
 * @property {number}                                                  width       Docked sidebar width in pixels.
 * @property {string}                                                  theme       Theme id (e.g. dark).
 * @property {string}                                                  mode        Composer mode (agent|ask).
 * @property {string}                                                  placement   Dock / float placement.
 * @property {FloatingRect|null}                                       floatRect   Last floating geometry.
 * @property {Array<{ id: string, title: string, createdAt: number }>} tabs        Open tabs.
 * @property {string}                                                  activeTabId Active tab id.
 */

/**
 * @param {unknown} value
 * @param {string}  placement
 * @param {number}  width
 * @return {FloatingRect|null} Normalized floating rect, or null when not floating.
 */
function normalizeFloatRect( value, placement, width ) {
	if ( ! isFloatingPlacement( placement ) ) {
		return value && typeof value === 'object' ? clampFloatingRect( /** @type {FloatingRect} */ ( value ) ) : null
	}
	if ( ! value || typeof value !== 'object' ) {
		return getDefaultFloatingRect( placement, width )
	}
	return clampFloatingRect( /** @type {FloatingRect} */ ( value ) )
}

/**
 * @return {SidebarPersistedState} Default persisted sidebar chrome state.
 */
export function getDefaultPersistedState() {
	const tab = createTab()
	return {
		open: false,
		width: DEFAULT_WIDTH,
		theme: DEFAULT_THEME,
		mode: MODES.AGENT,
		placement: DEFAULT_PLACEMENT,
		floatRect: null,
		tabs: [ tab ],
		activeTabId: tab.id,
	}
}

/**
 * Clamp sidebar width to allowed bounds.
 *
 * @param {number} width Desired width.
 * @return {number} Clamped width.
 */
export function clampWidth( width ) {
	const value = Number( width )
	if ( Number.isNaN( value ) ) {
		return DEFAULT_WIDTH
	}
	return Math.min( MAX_WIDTH, Math.max( MIN_WIDTH, Math.round( value ) ) )
}

/**
 * @return {SidebarPersistedState} State loaded from localStorage (or defaults).
 */
export function loadPersistedState() {
	const defaults = getDefaultPersistedState()

	try {
		const raw = window.localStorage.getItem( STORAGE_KEY )
		if ( ! raw ) {
			return defaults
		}

		const parsed = JSON.parse( raw )
		const tabs = Array.isArray( parsed.tabs ) && parsed.tabs.length
			? parsed.tabs.map( tab => ( {
				id: String( tab.id ),
				title: String( tab.title || 'New Agent' ),
				createdAt: Number( tab.createdAt ) || Date.now(),
			} ) )
			: defaults.tabs

		const activeTabId = tabs.some( tab => tab.id === parsed.activeTabId )
			? parsed.activeTabId
			: tabs[ 0 ].id

		const width = clampWidth( parsed.width ?? defaults.width )
		const placement = normalizePlacement( parsed.placement )

		return {
			open: Boolean( parsed.open ),
			width,
			theme: typeof parsed.theme === 'string' ? parsed.theme : defaults.theme,
			mode: parsed.mode === MODES.ASK ? MODES.ASK : MODES.AGENT,
			placement,
			floatRect: normalizeFloatRect( parsed.floatRect, placement, width ),
			tabs,
			activeTabId,
		}
	} catch ( error ) {
		return defaults
	}
}

/**
 * @param {Partial<SidebarPersistedState>} state Partial state to merge and save.
 */
export function savePersistedState( state ) {
	try {
		const current = loadPersistedState()
		const next = {
			...current,
			...state,
			width: clampWidth( state.width ?? current.width ),
			placement: normalizePlacement( state.placement ?? current.placement ),
			tabs: Array.isArray( state.tabs ) && state.tabs.length ? state.tabs : current.tabs,
		}

		if ( Object.prototype.hasOwnProperty.call( state, 'floatRect' ) ) {
			next.floatRect = state.floatRect
				? clampFloatingRect( state.floatRect )
				: null
		} else if ( next.floatRect ) {
			next.floatRect = clampFloatingRect( next.floatRect )
		}

		if ( ! next.tabs.some( tab => tab.id === next.activeTabId ) ) {
			next.activeTabId = next.tabs[ 0 ].id
		}

		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( next ) )
	} catch ( error ) {
		// Ignore quota / private mode failures.
	}
}
