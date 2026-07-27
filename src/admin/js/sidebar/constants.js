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
 * Create a new empty agent tab shell (messages live in memory only).
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
