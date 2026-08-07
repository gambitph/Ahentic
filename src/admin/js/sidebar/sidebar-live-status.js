/**
 * Live-status row labels for an active session (presentation helpers).
 */

import { __, sprintf } from '@wordpress/i18n'
import { progressLabelForAbility } from './progress-label'

/** Generic phase placeholders — prefer real debugger step summaries instead. */
const GENERIC_PROGRESS_LABELS = new Set( [
	'Planning next steps…',
	'Reviewing results…',
	'Starting…',
	'Finishing…',
	'Thinking…',
	'Ahentic is thinking…',
	// HITL wait label must not stick as live status after Allow/Skip.
	'Waiting for your approval…',
] )

/** Debugger-only llm_thinking summaries — never surface in the live-status row. */
const HIDDEN_LIVE_STATUS_LABELS = new Set( [
	'Model thinking',
	'Thinking block not provided by model',
] )

/**
 * Age of a heartbeat ISO timestamp in ms, or null when unknown.
 *
 * @param {string} heartbeatAt ISO timestamp.
 * @return {number|null} Age in milliseconds, or null when unknown.
 */
export function heartbeatAgeMs( heartbeatAt ) {
	if ( ! heartbeatAt || typeof heartbeatAt !== 'string' ) {
		return null
	}
	const t = Date.parse( heartbeatAt )
	if ( Number.isNaN( t ) ) {
		return null
	}
	return Math.max( 0, Date.now() - t )
}

/**
 * Live status text: prefer a real step label (tool / intention), matching the debugger.
 *
 * @param {string}      progressLabel   Server progress.label.
 * @param {Array}       trace           Session trace events.
 * @param {boolean}     isBusy          Whether the session is actively working.
 * @param {Object|null} pendingTool     HITL pending tool, if any.
 * @param {string}      [sessionStatus] Session status.
 * @return {string} Label for the live-status row.
 */
export function resolveLiveStatusLabel( progressLabel, trace, isBusy, pendingTool, sessionStatus = '' ) {
	if ( ! isBusy ) {
		return ''
	}

	if ( sessionStatus === 'awaiting_human' && pendingTool ) {
		const summary = pendingTool.summary || pendingTool.name || ''
		return summary
			? sprintf(
				/* translators: %s: action summary */
				__( 'Waiting for your approval: %s', 'ahentic' ),
				summary
			)
			: __( 'Waiting for your approval…', 'ahentic' )
	}

	if ( sessionStatus === 'awaiting_browser' && pendingTool ) {
		const summary = pendingTool.summary || progressLabelForAbility( pendingTool.name || '' )
		return sprintf(
			/* translators: %s: action summary */
			__( 'Waiting for this page to run: %s', 'ahentic' ),
			summary
		)
	}

	const label = typeof progressLabel === 'string' ? progressLabel.trim() : ''
	if (
		label &&
		! GENERIC_PROGRESS_LABELS.has( label ) &&
		! HIDDEN_LIVE_STATUS_LABELS.has( label )
	) {
		return label
	}

	// Waiting for approval uses its own card; still surface the waiting label if present.
	if ( pendingTool ) {
		return label || ''
	}

	const events = Array.isArray( trace ) ? trace : []
	// Only use trace from the current run — older run_start boundaries leak prior intentions.
	let runStart = 0
	for ( let i = events.length - 1; i >= 0; i-- ) {
		if ( events[ i ]?.type === 'run_start' ) {
			runStart = i
			break
		}
	}

	for ( let i = events.length - 1; i >= runStart; i-- ) {
		const event = events[ i ]
		const summary = typeof event?.summary === 'string' ? event.summary.trim() : ''
		if ( ! summary ) {
			continue
		}
		if ( event.type === 'tool_executed' ) {
			return summary
		}
		// Prefer intention/thinking labels; skip missing-debug diagnostics (debugger only).
		if (
			event.type === 'llm_thinking' &&
			! HIDDEN_LIVE_STATUS_LABELS.has( summary ) &&
			! event?.data?.missing
		) {
			return summary
		}
		if (
			event.type === 'progress' &&
			! GENERIC_PROGRESS_LABELS.has( summary ) &&
			! HIDDEN_LIVE_STATUS_LABELS.has( summary )
		) {
			return summary
		}
	}

	if (
		label &&
		! GENERIC_PROGRESS_LABELS.has( label ) &&
		! HIDDEN_LIVE_STATUS_LABELS.has( label )
	) {
		return label
	}
	return 'Planning next steps…'
}
