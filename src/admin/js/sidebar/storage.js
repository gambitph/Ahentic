/**
 * localStorage helpers for sidebar chrome state.
 *
 * Persists: open, width, theme, tabs, activeTabId, mode.
 * Does NOT persist conversation message contents (those belong in the DB later).
 */

import {
	STORAGE_KEY,
	DEFAULT_WIDTH,
	DEFAULT_THEME,
	MODES,
	createTab,
	MIN_WIDTH,
	MAX_WIDTH,
} from './constants'

/**
 * @typedef {Object} SidebarPersistedState
 * @property {boolean}                                                 open        Whether the sidebar is open.
 * @property {number}                                                  width       Sidebar width in pixels.
 * @property {string}                                                  theme       Theme id (e.g. dark).
 * @property {string}                                                  mode        Composer mode (agent|ask).
 * @property {Array<{ id: string, title: string, createdAt: number }>} tabs        Open tabs.
 * @property {string}                                                  activeTabId Active tab id.
 */

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

		return {
			open: Boolean( parsed.open ),
			width: clampWidth( parsed.width ?? defaults.width ),
			theme: typeof parsed.theme === 'string' ? parsed.theme : defaults.theme,
			mode: parsed.mode === MODES.ASK ? MODES.ASK : MODES.AGENT,
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
			tabs: Array.isArray( state.tabs ) && state.tabs.length ? state.tabs : current.tabs,
		}

		if ( ! next.tabs.some( tab => tab.id === next.activeTabId ) ) {
			next.activeTabId = next.tabs[ 0 ].id
		}

		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( next ) )
	} catch ( error ) {
		// Ignore quota / private mode failures.
	}
}
