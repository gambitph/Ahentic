/**
 * Pure session UI state for the Ahentic sidebar.
 *
 * One record per session id — deepen the *ByTab maps into a single shape so
 * send / stop / approve / poll apply through one path.
 */

/**
 * @typedef {Object} SessionRecord
 * @property {Array}       messages     Mapped chat messages for the tab.
 * @property {string}      status       Session run status.
 * @property {Object|null} progress     Live progress / heartbeat snapshot.
 * @property {Object|null} pendingTool  HITL or browser pending tool.
 * @property {Object|null} plan         Agent plan payload.
 * @property {Object|null} thought      Ephemeral thought-process text.
 * @property {Array}       trace        Debugger trace events.
 * @property {string}      approving    In-flight HITL decision, if any.
 * @property {boolean}     pollWatch    Keep polling after send even if status flickers.
 * @property {number}      tokensIn     Cumulative input tokens.
 * @property {number}      tokensOut    Cumulative output tokens.
 * @property {number}      tokensUsed   Cumulative total tokens.
 * @property {Object|null} contextUsage Soft context budget snapshot from REST.
 */
/**
 * Empty per-session UI record.
 *
 * @return {SessionRecord} Result.
 */
export function createEmptySessionRecord() {
	return {
		messages: [],
		status: 'idle',
		progress: null,
		pendingTool: null,
		plan: null,
		thought: null,
		trace: [],
		approving: '',
		pollWatch: false,
		tokensIn: 0,
		tokensOut: 0,
		tokensUsed: 0,
		contextUsage: null,
	}
}

/**
 * @param {Object} sessions
 * @param {string} id
 * @return {SessionRecord} Result.
 */
export function getSessionRecord( sessions, id ) {
	return sessions?.[ id ] || createEmptySessionRecord()
}

/**
 * @param {Object}          sessions
 * @param {string}          id
 * @param {Object|Function} patch    Object fields or (record) => record.
 * @return {Object} Next sessions map.
 */
export function patchSessionRecord( sessions, id, patch ) {
	const current = getSessionRecord( sessions, id )
	const next = typeof patch === 'function'
		? patch( current )
		: { ...current, ...patch }
	if ( next === current ) {
		return sessions || {}
	}
	return {
		...( sessions || {} ),
		[ id ]: next,
	}
}

/**
 * @param {Object} sessions
 * @param {string} id
 * @return {Object} Result.
 */
export function omitSessionRecord( sessions, id ) {
	if ( ! sessions?.[ id ] ) {
		return sessions || {}
	}
	const copy = { ...sessions }
	delete copy[ id ]
	return copy
}

/**
 * Move a session record from one id to another (tab_* → real session id).
 *
 * @param {Object} sessions
 * @param {string} fromId
 * @param {string} toId
 * @param {Object} [seed]   Partial record when fromId is missing.
 * @return {Object} Result.
 */
export function remapSessionRecord( sessions, fromId, toId, seed = null ) {
	const map = sessions || {}
	const prior = map[ fromId ]
	const base = prior
		? { ...prior }
		: {
			...createEmptySessionRecord(),
			...( seed || {} ),
		}
	const next = { ...map, [ toId ]: base }
	delete next[ fromId ]
	return next
}

/**
 * Compact fingerprint to detect whether a session payload changed.
 *
 * @param {Object} session Session REST payload.
 * @return {string} Result.
 */
export function sessionFingerprint( session ) {
	if ( ! session ) {
		return ''
	}
	const messages = Array.isArray( session.messages ) ? session.messages : []
	const last = messages[ messages.length - 1 ]
	const trace = Array.isArray( session.trace ) ? session.trace : []
	const traceLen = Number( session.traceCount ) || trace.length
	return [
		session.modifiedAt || '',
		session.status || '',
		session.stepCount || 0,
		messages.length,
		last?.id || last?.seq || '',
		session.progress?.label || '',
		session.progress?.updatedAt || '',
		session.plan?.updatedAt || '',
		session.pendingTool ? JSON.stringify( session.pendingTool ) : '',
		// Debugger / live-status can advance via trace alone while progress stays generic.
		traceLen,
	].join( '\u0001' )
}

/**
 * @param {Object|null} session
 * @return {Object} Result.
 */
export function extractSessionMeta( session ) {
	if ( ! session ) {
		return {
			messageCount: 0,
			lastSeq: 0,
			stepCount: 0,
			traceLen: 0,
			modifiedAt: 0,
			progressAt: 0,
			planAt: 0,
			status: 'idle',
		}
	}
	const messages = Array.isArray( session.messages ) ? session.messages : []
	const last = messages[ messages.length - 1 ]
	const trace = Array.isArray( session.trace ) ? session.trace : []
	return {
		messageCount: messages.length,
		lastSeq: Number( last?.seq ) || 0,
		stepCount: Number( session.stepCount ) || 0,
		traceLen: Number( session.traceCount ) || trace.length,
		modifiedAt: Date.parse( session.modifiedAt || '' ) || 0,
		progressAt: Date.parse( session.progress?.updatedAt || '' ) || 0,
		planAt: Date.parse( session.plan?.updatedAt || '' ) || 0,
		status: session.status || 'idle',
	}
}

/**
 * @param {string} status
 * @return {boolean} Result.
 */
export function isActiveRunStatus( status ) {
	return status === 'running' ||
		status === 'awaiting_human' ||
		status === 'awaiting_browser'
}

/**
 * @param {Object}           incoming
 * @param {Object|undefined} known
 * @return {boolean} Result.
 */
export function isSessionPayloadStale( incoming, known ) {
	if ( ! known ) {
		return false
	}
	const next = extractSessionMeta( incoming )

	if ( next.lastSeq < known.lastSeq ) {
		return true
	}
	if ( next.messageCount < known.messageCount ) {
		return true
	}

	if (
		isActiveRunStatus( known.status ) &&
		next.status === 'idle' &&
		next.messageCount <= known.messageCount &&
		next.lastSeq <= known.lastSeq &&
		next.stepCount <= known.stepCount &&
		next.traceLen <= known.traceLen
	) {
		return true
	}

	if ( next.lastSeq === known.lastSeq && next.messageCount === known.messageCount ) {
		// stepCount resets to 0 on each new run while the debug trace keeps growing.
		// Only treat a lower stepCount as stale when the trace did not advance.
		if ( next.stepCount < known.stepCount && next.traceLen <= known.traceLen ) {
			return true
		}
		if ( next.traceLen < known.traceLen ) {
			return true
		}
		if ( known.modifiedAt && next.modifiedAt && next.modifiedAt < known.modifiedAt ) {
			return true
		}
		if (
			known.progressAt &&
			next.progressAt &&
			next.progressAt < known.progressAt &&
			next.stepCount <= known.stepCount &&
			next.traceLen <= known.traceLen
		) {
			return true
		}
		if (
			known.planAt &&
			next.planAt &&
			next.planAt < known.planAt &&
			next.stepCount <= known.stepCount &&
			next.traceLen <= known.traceLen
		) {
			return true
		}
	}

	return false
}

/**
 * @param {Object} message
 * @return {boolean} Result.
 */
export function isLocalPendingUserMessage( message ) {
	return Boolean(
		message &&
		message.role === 'user' &&
		String( message.id || '' ).startsWith( 'local_u_' )
	)
}

/**
 * @param {Array}            serverMessages
 * @param {Array}            currentMessages
 * @param {Object|undefined} pendingById
 * @return {Array} Result.
 */
export function mergeServerMessagesWithPendingLocal( serverMessages, currentMessages, pendingById ) {
	const server = Array.isArray( serverMessages ) ? serverMessages : []
	if ( ! pendingById || ! Object.keys( pendingById ).length ) {
		return server
	}
	const current = Array.isArray( currentMessages ) ? currentMessages : []
	const pending = []
	for ( let i = current.length - 1; i >= 0; i-- ) {
		const message = current[ i ]
		const localId = message?.id
		if ( localId && pendingById[ localId ] && isLocalPendingUserMessage( message ) ) {
			pending.unshift( message )
			continue
		}
		break
	}
	if ( ! pending.length ) {
		return server
	}

	const stillPending = []
	let serverIdx = server.length - 1
	for ( let p = pending.length - 1; p >= 0; p-- ) {
		const content = String( pending[ p ].content || '' )
		let matched = false
		while ( serverIdx >= 0 ) {
			const entry = server[ serverIdx ]
			serverIdx -= 1
			if ( entry?.role !== 'user' ) {
				continue
			}
			if ( String( entry.content || '' ) === content ) {
				matched = true
			}
			break
		}
		if ( ! matched ) {
			stillPending.unshift( pending[ p ] )
		}
	}

	if ( ! stillPending.length ) {
		return server
	}
	return [ ...server, ...stillPending ]
}

/**
 * @param {Object|undefined} pendingBySession
 * @param {string}           sessionId
 * @return {boolean} Result.
 */
export function hasPendingLocalTurns( pendingBySession, sessionId ) {
	const pending = pendingBySession?.[ sessionId ]
	return Boolean( pending && Object.keys( pending ).length )
}

/**
 * @param {Array}  serverMessages
 * @param {Object} pendingById
 * @return {boolean} Result.
 */
export function pendingLocalsConfirmedOnServer( serverMessages, pendingById ) {
	if ( ! pendingById || ! Object.keys( pendingById ).length ) {
		return true
	}
	const pendingContents = Object.values( pendingById ).map( content => String( content || '' ) )
	const server = Array.isArray( serverMessages ) ? serverMessages : []
	let serverIdx = server.length - 1
	for ( let p = pendingContents.length - 1; p >= 0; p-- ) {
		const content = pendingContents[ p ]
		let matched = false
		while ( serverIdx >= 0 ) {
			const entry = server[ serverIdx ]
			serverIdx -= 1
			if ( entry?.role !== 'user' ) {
				continue
			}
			if ( String( entry.content || '' ) === content ) {
				matched = true
			}
			break
		}
		if ( ! matched ) {
			return false
		}
	}
	return true
}

/**
 * Merge a REST session payload into one UI record (pure).
 *
 * @param {Object}           session     REST session.
 * @param {SessionRecord}    current     Current UI record.
 * @param {Object|undefined} pendingById Optimistic local user turns.
 * @param {Function}         mapEntries  mapEntriesToMessages from api.js.
 * @return {SessionRecord} Result.
 */
export function mergeServerSessionIntoRecord( session, current, pendingById, mapEntries ) {
	const base = current || createEmptySessionRecord()
	const status = session.status || 'idle'
	let messages = base.messages
	if ( Array.isArray( session.messages ) ) {
		messages = mergeServerMessagesWithPendingLocal(
			mapEntries( session.messages ),
			base.messages,
			pendingById
		)
	}

	let trace = base.trace
	if ( Array.isArray( session.trace ) ) {
		trace = session.trace
	}

	const label = session.progress?.label || ''
	const heartbeatAt = typeof session.heartbeatAt === 'string' ? session.heartbeatAt : ''
	let progress = base.progress
	if ( ! label && ! heartbeatAt ) {
		progress = null
	} else {
		progress = {
			label: label || base.progress?.label || '',
			updatedAt: session.progress?.updatedAt || base.progress?.updatedAt || '',
			heartbeatAt: heartbeatAt || base.progress?.heartbeatAt || '',
			seenAt: base.progress?.seenAt || Date.now(),
		}
	}

	const pendingTool = session.pendingTool && typeof session.pendingTool === 'object'
		? session.pendingTool
		: null

	const plan = session.plan && typeof session.plan === 'object' && Array.isArray( session.plan.steps ) && session.plan.steps.length
		? session.plan
		: null

	const thoughtText = session.thoughtProcess?.text || session.thoughtProcess?.Text || ''
	let thought = base.thought
	if ( ! thoughtText || status === 'idle' || status === 'error' || status === 'cancelled' ) {
		thought = null
	} else {
		thought = {
			text: thoughtText,
			updatedAt: session.thoughtProcess?.updatedAt || '',
		}
	}

	return {
		...base,
		messages,
		status,
		progress,
		pendingTool,
		plan,
		thought,
		trace,
		pollWatch: isActiveRunStatus( status ) ? base.pollWatch : false,
		tokensIn: typeof session.tokensIn === 'number' ? session.tokensIn : ( base.tokensIn || 0 ),
		tokensOut: typeof session.tokensOut === 'number' ? session.tokensOut : ( base.tokensOut || 0 ),
		tokensUsed: typeof session.tokensUsed === 'number' ? session.tokensUsed : ( base.tokensUsed || 0 ),
		contextUsage: session.contextUsage && typeof session.contextUsage === 'object'
			? session.contextUsage
			: ( base.contextUsage || null ),
	}
}

/**
 * @param {Object|null} plan
 * @return {Object|null} Result.
 */
export function cancelIncompletePlanSteps( plan ) {
	if ( ! plan || ! Array.isArray( plan.steps ) ) {
		return plan
	}
	let changed = false
	const steps = plan.steps.map( step => {
		if ( step.status === 'completed' || step.status === 'cancelled' ) {
			return step
		}
		changed = true
		return { ...step, status: 'cancelled' }
	} )
	if ( ! changed ) {
		return plan
	}
	return { ...plan, steps }
}
