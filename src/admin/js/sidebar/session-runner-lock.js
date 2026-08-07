/**
 * Per-session active-runner claim for same-origin multi-window safety.
 *
 * Separate from sidebar chrome (`ahentic.sidebar.v1`). First non-stale claim wins;
 * heartbeats keep ownership alive; viewers must not drive the orchestrator.
 */

import { RUNNER_LOCK_KEY } from './constants'

export { RUNNER_LOCK_KEY }
export const HEARTBEAT_MS = 1000
export const STALE_MS = 15000

/**
 * @return {string} Stable-enough id for this JS realm / window.
 */
export function createOwnerId() {
	return `w_${ Date.now().toString( 36 ) }_${ Math.random().toString( 36 ).slice( 2, 10 ) }`
}

/**
 * @typedef {Object} RunnerClaim
 * @property {string} ownerId     Window that owns the claim.
 * @property {number} heartbeatAt Epoch ms of last heartbeat.
 */

/**
 * @typedef {Object} SessionRunnerLockOptions
 * @property {Pick<Storage, 'getItem'|'setItem'|'removeItem'>} [storage] Storage backend.
 * @property {string}                                          [ownerId] This window's id.
 * @property {() => number}                                    [now]     Clock (ms).
 * @property {number}                                          [staleMs] Stale threshold.
 * @property {string}                                          [key]     Storage key.
 */

/**
 * @param {SessionRunnerLockOptions} [options]
 * @return {{
 *   ownerId: string,
 *   claim: (sessionId: string) => boolean,
 *   heartbeat: (sessionId: string) => boolean,
 *   release: (sessionId: string) => void,
 *   isOwner: (sessionId: string) => boolean,
 *   isViewer: (sessionId: string) => boolean,
 *   readClaim: (sessionId: string) => RunnerClaim|null,
 * }} Lock API.
 */
export function createSessionRunnerLock( options = {} ) {
	const storage = options.storage || ( typeof window !== 'undefined' ? window.localStorage : null )
	const ownerId = options.ownerId || createOwnerId()
	const now = options.now || ( () => Date.now() )
	const staleMs = typeof options.staleMs === 'number' ? options.staleMs : STALE_MS
	const key = options.key || RUNNER_LOCK_KEY

	const readAll = () => {
		if ( ! storage ) {
			return {}
		}
		try {
			const raw = storage.getItem( key )
			if ( ! raw ) {
				return {}
			}
			const parsed = JSON.parse( raw )
			return parsed && typeof parsed === 'object' && ! Array.isArray( parsed ) ? parsed : {}
		} catch {
			return {}
		}
	}

	const writeAll = next => {
		if ( ! storage ) {
			return
		}
		try {
			storage.setItem( key, JSON.stringify( next ) )
		} catch {
			// Quota / private mode — ignore.
		}
	}

	/**
	 * @param {string} sessionId
	 * @return {RunnerClaim|null} Freshness-agnostic claim, or null when missing/invalid.
	 */
	const readClaim = sessionId => {
		const id = String( sessionId || '' )
		if ( ! id ) {
			return null
		}
		const entry = readAll()[ id ]
		if ( ! entry || typeof entry !== 'object' ) {
			return null
		}
		const claimOwner = typeof entry.ownerId === 'string' ? entry.ownerId : ''
		const heartbeatAt = Number( entry.heartbeatAt )
		if ( ! claimOwner || ! Number.isFinite( heartbeatAt ) ) {
			return null
		}
		return { ownerId: claimOwner, heartbeatAt }
	}

	/**
	 * @param {RunnerClaim|null} claim
	 * @return {boolean} True when heartbeat is within the stale window.
	 */
	const isFresh = claim => {
		if ( ! claim ) {
			return false
		}
		return ( now() - claim.heartbeatAt ) < staleMs
	}

	/**
	 * @param {string} sessionId
	 * @return {boolean} True when this window owns a fresh claim.
	 */
	const isOwner = sessionId => {
		const claim = readClaim( sessionId )
		return Boolean( claim && isFresh( claim ) && claim.ownerId === ownerId )
	}

	/**
	 * @param {string} sessionId
	 * @return {boolean} True when another window owns a fresh claim.
	 */
	const isViewer = sessionId => {
		const claim = readClaim( sessionId )
		return Boolean( claim && isFresh( claim ) && claim.ownerId !== ownerId )
	}

	/**
	 * Become the active runner if no fresh foreign claim exists.
	 *
	 * @param {string} sessionId
	 * @return {boolean} True when this window owns the claim afterwards.
	 */
	const claim = sessionId => {
		const id = String( sessionId || '' )
		if ( ! id ) {
			return false
		}
		const existing = readClaim( id )
		if ( existing && isFresh( existing ) && existing.ownerId !== ownerId ) {
			return false
		}
		const all = readAll()
		all[ id ] = { ownerId, heartbeatAt: now() }
		writeAll( all )
		const verify = readClaim( id )
		return Boolean( verify && isFresh( verify ) && verify.ownerId === ownerId )
	}

	/**
	 * Renew heartbeat if we own the claim.
	 *
	 * @param {string} sessionId
	 * @return {boolean} True when heartbeat was written.
	 */
	const heartbeat = sessionId => {
		const id = String( sessionId || '' )
		if ( ! id || ! isOwner( id ) ) {
			return false
		}
		const all = readAll()
		all[ id ] = { ownerId, heartbeatAt: now() }
		writeAll( all )
		return true
	}

	/**
	 * Drop our claim for the session (no-op if we do not own it).
	 *
	 * @param {string} sessionId
	 */
	const release = sessionId => {
		const id = String( sessionId || '' )
		if ( ! id ) {
			return
		}
		const existing = readClaim( id )
		if ( ! existing || existing.ownerId !== ownerId ) {
			return
		}
		const all = readAll()
		delete all[ id ]
		writeAll( all )
	}

	return {
		ownerId,
		claim,
		heartbeat,
		release,
		isOwner,
		isViewer,
		readClaim,
	}
}
