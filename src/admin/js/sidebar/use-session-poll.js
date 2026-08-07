/**
 * Poll running / awaiting_browser sessions and nudge stalled workers.
 */

import { useEffect } from '@wordpress/element'
import { continueSession, getSession } from './api'
import { getSessionRecord } from './session-state'
import { heartbeatAgeMs } from './sidebar-live-status'
import {
	POLL_MS,
	HEARTBEAT_STALL_MS,
	BROWSER_STALL_MS,
} from './session-run-constants'

/**
 * @param {Object}   options
 * @param {string}   options.runningSessionKey Comma-joined session ids to poll.
 * @param {Object}   options.sessionsByIdRef
 * @param {Function} options.applySession
 * @param {Object}   options.runnerLock
 */
export function useSessionPoll( {
	runningSessionKey,
	sessionsByIdRef,
	applySession,
	runnerLock,
} ) {
	useEffect( () => {
		if ( ! runningSessionKey ) {
			return undefined
		}

		const ids = runningSessionKey.split( ',' ).filter( Boolean )
		let cancelled = false
		const continueInFlight = new Set()

		const apply = session => {
			const id = session?.id !== undefined && session?.id !== null ? String( session.id ) : ''
			// While approval POST is in flight, ignore stale awaiting_human polls.
			if ( id && getSessionRecord( sessionsByIdRef.current, id ).approving && session.status === 'awaiting_human' ) {
				return
			}
			applySession( session )
		}

		/**
		 * Quiet queue recovery when heartbeat is stale — not progress-label based.
		 * @param {Object} session Session REST payload.
		 */
		const needsHeartbeatNudge = session => {
			if ( session?.status !== 'running' ) {
				return false
			}
			const age = heartbeatAgeMs( session.heartbeatAt || '' )
			if ( age === null ) {
				return false
			}
			return age >= HEARTBEAT_STALL_MS
		}

		/**
		 * Timed browser recovery (server falls back or errors clearly).
		 * @param {Object} session Session REST payload.
		 */
		const needsBrowserNudge = session => {
			if ( session?.status !== 'awaiting_browser' ) {
				return false
			}
			const paused = session.browserPausedAt || session.progress?.updatedAt || ''
			const age = heartbeatAgeMs( paused )
			if ( age === null ) {
				return false
			}
			return age >= BROWSER_STALL_MS
		}

		const pollOne = async id => {
			try {
				const session = await getSession( id )
				if ( cancelled ) {
					return
				}
				apply( session )

				const shouldContinue = (
					( needsHeartbeatNudge( session ) || needsBrowserNudge( session ) ) &&
					! continueInFlight.has( id ) &&
					! runnerLock.isViewer( id ) &&
					( runnerLock.isOwner( id ) || runnerLock.claim( id ) )
				)
				if ( shouldContinue ) {
					continueInFlight.add( id )
					continueSession( id )
						.then( continued => {
							if ( ! cancelled && continued ) {
								apply( continued )
							}
						} )
						.catch( () => {
							// Next poll will retry if still stalled.
						} )
						.finally( () => {
							continueInFlight.delete( id )
						} )
				}
			} catch ( error ) {
				// Keep polling; transient network errors are fine.
			}
		}

		const tick = () => {
			ids.forEach( id => {
				pollOne( id )
			} )
		}

		tick()
		const timer = window.setInterval( tick, POLL_MS )
		return () => {
			cancelled = true
			window.clearInterval( timer )
		}
	}, [ runningSessionKey, applySession, runnerLock, sessionsByIdRef ] )
}
