/**
 * Shared timing / copy constants for session run drivers.
 */

import { __ } from '@wordpress/i18n'

export const POLL_MS = 650
/** Quiet queue nudge when the worker heartbeat goes quiet (not progress-label based). */
export const HEARTBEAT_STALL_MS = 8000
/** Show stuck recovery UI when heartbeat is this old while still running. */
export const HEARTBEAT_DEAD_MS = 45000
/** Recover stale awaiting_browser via continue (server timed fallback). */
export const BROWSER_STALL_MS = 45000

/** Shared copy when another window holds the active-runner claim. */
export const VIEWER_ACTIVE_ELSEWHERE = __( 'This agent is active in another window', 'ahentic' )
