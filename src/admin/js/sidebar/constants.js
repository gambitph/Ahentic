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
