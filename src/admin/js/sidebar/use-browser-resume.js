/**
 * Execute pending browser abilities when the orchestrator pauses for them.
 */

import {
	useCallback, useEffect, useRef, useState,
} from '@wordpress/element'
import {
	getSession,
	isSessionId,
	patchSession,
	postBrowserResult,
} from './api'
import { runBrowserAbility } from './browser-abilities'
import { getSessionRecord } from './session-state'

/**
 * @param {Object}   options
 * @param {string}   options.activeTabId
 * @param {string}   options.activeStatus
 * @param {Object}   options.activeSession
 * @param {boolean}  options.isViewerSession
 * @param {Object}   options.sessionsByIdRef
 * @param {Object}   options.runnerLock
 * @param {Function} options.claimRunner
 * @param {Function} options.applySession
 * @return {{ clearBrowserResumesForSession: Function }} Helpers for Stop / cancel.
 */
export function useBrowserResume( {
	activeTabId,
	activeStatus,
	activeSession,
	isViewerSession,
	sessionsByIdRef,
	runnerLock,
	claimRunner,
	applySession,
} ) {
	/**
	 * Tracks browser-tool resume attempts.
	 * Values: 'inflight' | 'done'. Cleared on failure so another attempt can run.
	 *
	 * @type {React.MutableRefObject<{[key: string]: string}>}
	 */
	const browserResumeRef = useRef( {} )
	/** Bumps when a resume attempt exhausts retries so the effect can try again. */
	const [ browserResumeNudge, setBrowserResumeNudge ] = useState( 0 )

	const clearBrowserResumesForSession = useCallback( sessionId => {
		Object.keys( browserResumeRef.current ).forEach( key => {
			if ( key.startsWith( `${ sessionId }:` ) ) {
				delete browserResumeRef.current[ key ]
			}
		} )
	}, [] )

	const activeBrowserPending = activeSession.pendingTool
	const activeBrowserCallId = activeBrowserPending?.runtime === 'browser'
		? ( activeBrowserPending.call_id || activeBrowserPending.callId || activeBrowserPending.name || '' )
		: ''

	const browserResumeKey = (
		activeStatus === 'awaiting_browser' &&
		isSessionId( activeTabId ) &&
		activeBrowserCallId
	) ? `${ activeTabId }:${ activeBrowserCallId }` : ''

	// Important: once started, finish the POST even if deps churn — cancelling
	// mid-flight and leaving a sticky "handled" flag stuck the live status on
	// labels like "Reading editor blocks…".
	useEffect( () => {
		if ( ! browserResumeKey || isViewerSession ) {
			return undefined
		}

		if ( ! runnerLock.isOwner( activeTabId ) && ! claimRunner( activeTabId ) ) {
			return undefined
		}

		const state = browserResumeRef.current[ browserResumeKey ]
		if ( state === 'done' || state === 'inflight' ) {
			return undefined
		}

		const pending = getSessionRecord( sessionsByIdRef.current, activeTabId ).pendingTool
		if ( ! pending || pending.runtime !== 'browser' ) {
			return undefined
		}

		const callId = pending.call_id || pending.callId || ''
		const sessionId = activeTabId
		const resumeKey = browserResumeKey
		browserResumeRef.current[ resumeKey ] = 'inflight'

		const sleep = ms => new Promise( resolve => {
			window.setTimeout( resolve, ms )
		} )

		const isAlreadyResumedError = error => {
			const code = error?.code || ''
			const status = error?.status
			return status === 409 ||
				code === 'ahentic_not_awaiting' ||
				code === 'ahentic_no_pending' ||
				code === 'ahentic_call_mismatch'
		}

		;( async () => {
			const maxAttempts = 5
			for ( let attempt = 1; attempt <= maxAttempts; attempt++ ) {
				try {
					const outcome = await runBrowserAbility( pending )
					if ( outcome?.result?.wiped ) {
						try {
							await patchSession( sessionId, { editorRefs: null } )
						} catch {
							// Poller / next sync will catch up.
						}
					}
					const session = await postBrowserResult( sessionId, {
						// REST body uses snake_case (matches server pending tool).
						// eslint-disable-next-line camelcase
						call_id: callId,
						...( outcome.error
							? { error: outcome.error }
							: { result: outcome.result }
						),
					} )
					browserResumeRef.current[ resumeKey ] = 'done'
					applySession( session, { force: true } )
					return
				} catch ( error ) {
					if ( isAlreadyResumedError( error ) ) {
						browserResumeRef.current[ resumeKey ] = 'done'
						try {
							const session = await getSession( sessionId )
							applySession( session, { force: true } )
						} catch {
							// Poller will catch up.
						}
						return
					}
					if ( attempt < maxAttempts ) {
						await sleep( 400 * attempt )
					}
				}
			}

			// Exhausted retries — clear and nudge so the effect can retry.
			if ( browserResumeRef.current[ resumeKey ] === 'inflight' ) {
				delete browserResumeRef.current[ resumeKey ]
				await sleep( 1500 )
				// Only nudge if this call is still the active browser pause.
				if ( ! browserResumeRef.current[ resumeKey ] ) {
					setBrowserResumeNudge( value => value + 1 )
				}
			}
		} )()

		return undefined
	}, [
		browserResumeKey,
		browserResumeNudge,
		activeTabId,
		applySession,
		isViewerSession,
		runnerLock,
		claimRunner,
		sessionsByIdRef,
	] )

	return { clearBrowserResumesForSession }
}
