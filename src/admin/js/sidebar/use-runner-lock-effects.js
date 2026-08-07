/**
 * Multi-window runner-lock heartbeat / claim effects for live sessions.
 */

import { useEffect } from '@wordpress/element'
import { isSessionId } from './api'
import { isActiveRunStatus } from './session-state'
import {
	HEARTBEAT_MS as RUNNER_HEARTBEAT_MS,
	RUNNER_LOCK_KEY,
} from './session-runner-lock'

/**
 * Keep runner-lock claims aligned with session run status across tabs/windows.
 *
 * @param {Object}   options
 * @param {Object}   options.sessionsById
 * @param {Object}   options.sessionsByIdRef  Mutable ref mirroring sessionsById.
 * @param {Object}   options.runnerLock       From createSessionRunnerLock().
 * @param {Function} options.bumpLockRevision
 */
export function useRunnerLockEffects( {
	sessionsById,
	sessionsByIdRef,
	runnerLock,
	bumpLockRevision,
} ) {
	useEffect( () => {
		Object.entries( sessionsById ).forEach( ( [ id, record ] ) => {
			if ( ! isSessionId( id ) ) {
				return
			}
			const status = record.status
			if ( ! isActiveRunStatus( status ) ) {
				if ( runnerLock.readClaim( id )?.ownerId === runnerLock.ownerId ) {
					runnerLock.release( id )
				}
				return
			}
			if ( runnerLock.isOwner( id ) ) {
				runnerLock.heartbeat( id )
				return
			}
			if ( runnerLock.isViewer( id ) ) {
				return
			}
			runnerLock.claim( id )
		} )
		bumpLockRevision()
	}, [ sessionsById, runnerLock, bumpLockRevision ] )

	useEffect( () => {
		const tick = () => {
			let changed = false
			Object.entries( sessionsByIdRef.current ).forEach( ( [ id, record ] ) => {
				const status = record?.status
				if ( ! isSessionId( id ) || ! isActiveRunStatus( status ) ) {
					return
				}
				if ( runnerLock.isOwner( id ) ) {
					runnerLock.heartbeat( id )
					return
				}
				if ( runnerLock.isViewer( id ) ) {
					return
				}
				if ( runnerLock.claim( id ) ) {
					changed = true
				}
			} )
			if ( changed ) {
				bumpLockRevision()
			}
		}
		const timer = window.setInterval( tick, RUNNER_HEARTBEAT_MS )
		return () => {
			window.clearInterval( timer )
		}
	}, [ runnerLock, bumpLockRevision, sessionsByIdRef ] )

	useEffect( () => {
		const onStorage = event => {
			if ( event.key !== RUNNER_LOCK_KEY ) {
				return
			}
			Object.entries( sessionsByIdRef.current ).forEach( ( [ id, record ] ) => {
				const status = record?.status
				if ( ! isSessionId( id ) || ! isActiveRunStatus( status ) ) {
					return
				}
				if ( runnerLock.isOwner( id ) || runnerLock.isViewer( id ) ) {
					return
				}
				runnerLock.claim( id )
			} )
			bumpLockRevision()
		}
		window.addEventListener( 'storage', onStorage )
		return () => {
			window.removeEventListener( 'storage', onStorage )
		}
	}, [ bumpLockRevision, runnerLock, sessionsByIdRef ] )

	useEffect( () => {
		const renew = () => {
			Object.entries( sessionsByIdRef.current ).forEach( ( [ id, record ] ) => {
				const status = record?.status
				if ( isSessionId( id ) && isActiveRunStatus( status ) && runnerLock.isOwner( id ) ) {
					runnerLock.heartbeat( id )
				}
			} )
			bumpLockRevision()
		}
		document.addEventListener( 'visibilitychange', renew )
		window.addEventListener( 'focus', renew )
		return () => {
			document.removeEventListener( 'visibilitychange', renew )
			window.removeEventListener( 'focus', renew )
		}
	}, [ runnerLock, bumpLockRevision, sessionsByIdRef ] )

	useEffect( () => {
		const onPageHide = () => {
			Object.keys( sessionsByIdRef.current ).forEach( id => {
				runnerLock.release( id )
			} )
		}
		window.addEventListener( 'pagehide', onPageHide )
		return () => {
			window.removeEventListener( 'pagehide', onPageHide )
		}
	}, [ runnerLock, sessionsByIdRef ] )
}
