/**
 * Main Ahentic sidebar shell.
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import classnames from 'classnames'
import Toolbar from './toolbar'
import TabBar from './tab-bar'
import TabContent from './tab-content'
import Composer from './composer'
import {
	MOBILE_BREAKPOINT,
	MIN_WIDTH,
	MAX_WIDTH,
	PLACEMENTS,
	isFloatingPlacement,
	getDefaultFloatingRect,
	clampFloatingRect,
} from './constants'
import {
	loadPersistedState,
	savePersistedState,
	clampWidth,
} from './storage'
import { syncPageInset, clearPageInset } from './page-inset'
import AhenticLogo from './ahentic-logo'
import DebuggerPanel from './debugger-panel'
import {
	createSession,
	getSession,
	patchSession,
	postMessage,
	continueSession,
	cancelSession,
	postApproval,
	postSuggestedAction,
	postBrowserResult,
	mapEntriesToMessages,
	isSessionId,
} from './api'
import { collectPageContext } from './page-context'
import { runBrowserAbility } from './browser-abilities'
import {
	exportEditorRefs,
	hydrateEditorRefs,
} from './block-ref-registry'

const POLL_MS = 650
/** Quiet queue nudge when the worker heartbeat goes quiet (not progress-label based). */
const HEARTBEAT_STALL_MS = 8000
/** Show stuck recovery UI when heartbeat is this old while still running. */
const HEARTBEAT_DEAD_MS = 45000
/** Recover stale awaiting_browser via continue (server timed fallback). */
const BROWSER_STALL_MS = 45000

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
 * Optimistic progress label after HITL approval (mirrors server tool labels).
 *
 * @param {string} ability Ability name.
 * @return {string} Progress label for the live-status row.
 */
function progressLabelForAbility( ability ) {
	const map = {
		'ahentic/create-post': __( 'Creating a draft post…', 'ahentic' ),
		'ahentic/update-post': __( 'Updating post content…', 'ahentic' ),
		'ahentic/set-post-status': __( 'Updating post status…', 'ahentic' ),
		'ahentic/install-plugin': __( 'Installing plugin…', 'ahentic' ),
		'ahentic/activate-plugin': __( 'Activating plugin…', 'ahentic' ),
		'ahentic/deactivate-plugin': __( 'Deactivating plugin…', 'ahentic' ),
		'ahentic/uninstall-plugin': __( 'Uninstalling plugin…', 'ahentic' ),
		'ahentic/update-term': __( 'Updating taxonomy term…', 'ahentic' ),
		'ahentic-browser/save-post': __( 'Saving the post…', 'ahentic' ),
		'ahentic-browser/convert-blocks': __( 'Converting blocks to core…', 'ahentic' ),
	}
	if ( map[ ability ] ) {
		return map[ ability ]
	}
	const short = String( ability || '' ).replace( /^.*\//, '' ).replace( /-/g, ' ' )
	return short
		? sprintf(
			/* translators: %s: tool slug */
			__( 'Running %s…', 'ahentic' ),
			short
		)
		: __( 'Working…', 'ahentic' )
}

/**
 * Age of a heartbeat ISO timestamp in ms, or null when unknown.
 *
 * @param {string} heartbeatAt ISO timestamp.
 * @return {number|null}
 */
function heartbeatAgeMs( heartbeatAt ) {
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
 * @param {string}      progressLabel Server progress.label.
 * @param {Array}       trace         Session trace events.
 * @param {boolean}     isBusy        Whether the session is actively working.
 * @param {Object|null} pendingTool   HITL pending tool, if any.
 * @param {string}      [sessionStatus] Session status.
 * @return {string} Label for the live-status row.
 */
function resolveLiveStatusLabel( progressLabel, trace, isBusy, pendingTool, sessionStatus = '' ) {
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

/**
 * Compact fingerprint to detect whether a session payload is newer than local state.
 *
 * @param {Object} session Session REST payload.
 * @return {string} Stable fingerprint string for comparison.
 */
function sessionFingerprint( session ) {
	if ( ! session ) {
		return ''
	}
	const messages = Array.isArray( session.messages ) ? session.messages : []
	const last = messages[ messages.length - 1 ]
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
	].join( '\u0001' )
}

/**
 * Comparable session progress snapshot (for rejecting stale poll/sync payloads).
 *
 * @param {Object|null} session
 * @return {Object} Meta snapshot used for freshness checks.
 */
function extractSessionMeta( session ) {
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
		traceLen: trace.length,
		modifiedAt: Date.parse( session.modifiedAt || '' ) || 0,
		progressAt: Date.parse( session.progress?.updatedAt || '' ) || 0,
		planAt: Date.parse( session.plan?.updatedAt || '' ) || 0,
		status: session.status || 'idle',
	}
}

/**
 * Whether an incoming REST payload is older than state we already applied / floored.
 *
 * @param {Object}           incoming Incoming session payload.
 * @param {Object|undefined} known    Previously applied / floored meta.
 * @return {boolean} True when the payload should be ignored.
 */
function isSessionPayloadStale( incoming, known ) {
	if ( ! known ) {
		return false
	}
	const next = extractSessionMeta( incoming )

	// Main race: a poll GET that left before POST appended the user message.
	if ( next.lastSeq < known.lastSeq ) {
		return true
	}
	if ( next.messageCount < known.messageCount ) {
		return true
	}

	if ( next.lastSeq === known.lastSeq && next.messageCount === known.messageCount ) {
		if ( next.stepCount < known.stepCount ) {
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
			next.stepCount <= known.stepCount
		) {
			return true
		}
		if (
			known.planAt &&
			next.planAt &&
			next.planAt < known.planAt &&
			next.stepCount <= known.stepCount
		) {
			return true
		}
	}

	return false
}

/**
 * Detect Cmd vs Ctrl for shortcut labels.
 *
 * @return {string} Human-readable shortcut label.
 */
function getShortcutLabel() {
	const isMac = typeof navigator !== 'undefined' &&
		/Mac|iPhone|iPad|iPod/.test( navigator.platform || navigator.userAgent || '' )
	return isMac ? '⌘I' : 'Ctrl+I'
}

/**
 * Truncate a title for tab display / auto-naming.
 *
 * @param {string} text Source text.
 * @param {number} max  Max length.
 * @return {string} Truncated title.
 */
function truncateTitle( text, max = 32 ) {
	const clean = text.replace( /\s+/g, ' ' ).trim()
	if ( clean.length <= max ) {
		return clean
	}
	return `${ clean.slice( 0, max - 1 ) }…`
}

/**
 * Whether a message is an optimistic local user turn (not yet confirmed by the server).
 *
 * @param {Object} message Message object.
 * @return {boolean} True when the message is a pending local user bubble.
 */
function isLocalPendingUserMessage( message ) {
	return Boolean(
		message &&
		message.role === 'user' &&
		String( message.id || '' ).startsWith( 'local_u_' )
	)
}

/**
 * Merge server transcript with trailing optimistic user turns still in flight.
 * Pending locals already mirrored as the newest server user turn(s) are dropped
 * so polls cannot render a duplicate bubble.
 *
 * @param {Array}            serverMessages  Mapped server messages.
 * @param {Array}            currentMessages Current UI messages for the tab.
 * @param {Object|undefined} pendingById     Map of local id → content for in-flight sends.
 * @return {Array} Messages to render for the tab.
 */
function mergeServerMessagesWithPendingLocal( serverMessages, currentMessages, pendingById ) {
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

	// Drop locals already present as the newest server user turn(s) (match from the end).
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
 * Whether a session has optimistic user turns waiting on POST confirmation.
 *
 * @param {Object|undefined} pendingByTab Pending map keyed by session id.
 * @param {string}           sessionId    Session id.
 * @return {boolean} True when a send is still in flight for this session.
 */
function hasPendingLocalTurns( pendingByTab, sessionId ) {
	const pending = pendingByTab?.[ sessionId ]
	return Boolean( pending && Object.keys( pending ).length )
}

/**
 * Whether every in-flight local user turn already appears as a trailing server user row.
 *
 * @param {Array}  serverMessages Mapped server messages.
 * @param {Object} pendingById    Map of local id → content.
 * @return {boolean} True when the server transcript confirms all pending locals.
 */
function pendingLocalsConfirmedOnServer( serverMessages, pendingById ) {
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
 * Apply a session payload into local tab + message / trace / progress state.
 *
 * @param {Object}   session               Session REST payload.
 * @param {Function} setTabs               Tabs state setter.
 * @param {Function} setMessagesByTab      Messages state setter.
 * @param {Function} [setStatusByTab]      Status state setter.
 * @param {Function} [setTraceByTab]       Trace state setter.
 * @param {Function} [setProgressByTab]    Progress state setter.
 * @param {Function} [setPendingToolByTab] Pending tool state setter.
 * @param {Function} [setPlanByTab]        Plan state setter.
 * @param {Function} [setThoughtByTab]     Ephemeral thought setter.
 * @param {Object}   [pendingLocalByTab]   In-flight optimistic user messages keyed by session id.
 */
function applySessionPayload( session, setTabs, setMessagesByTab, setStatusByTab, setTraceByTab, setProgressByTab, setPendingToolByTab, setPlanByTab, pendingLocalByTab, setThoughtByTab ) {
	if ( ! session?.id ) {
		return
	}
	const id = String( session.id )
	setTabs( current => current.map( tab => (
		tab.id === id
			? {
				...tab,
				title: session.title || tab.title,
				status: session.status,
			}
			: tab
	) ) )
	if ( Array.isArray( session.messages ) ) {
		const pendingById = pendingLocalByTab?.[ id ]
		setMessagesByTab( messages => ( {
			...messages,
			[ id ]: mergeServerMessagesWithPendingLocal(
				mapEntriesToMessages( session.messages ),
				messages[ id ],
				pendingById
			),
		} ) )
	}
	if ( setStatusByTab ) {
		setStatusByTab( statuses => ( {
			...statuses,
			[ id ]: session.status || 'idle',
		} ) )
	}
	if ( setTraceByTab && Array.isArray( session.trace ) ) {
		setTraceByTab( traces => ( {
			...traces,
			[ id ]: session.trace,
		} ) )
	}
	if ( setProgressByTab ) {
		const label = session.progress?.label || ''
		const heartbeatAt = typeof session.heartbeatAt === 'string' ? session.heartbeatAt : ''
		setProgressByTab( progress => {
			if ( ! label && ! heartbeatAt ) {
				if ( ! progress[ id ] ) {
					return progress
				}
				const copy = { ...progress }
				delete copy[ id ]
				return copy
			}
			return {
				...progress,
				[ id ]: {
					label: label || progress[ id ]?.label || '',
					updatedAt: session.progress?.updatedAt || progress[ id ]?.updatedAt || '',
					heartbeatAt: heartbeatAt || progress[ id ]?.heartbeatAt || '',
					seenAt: progress[ id ]?.seenAt || Date.now(),
				},
			}
		} )
	}
	if ( setPendingToolByTab ) {
		setPendingToolByTab( pending => {
			const next = session.pendingTool && typeof session.pendingTool === 'object'
				? session.pendingTool
				: null
			if ( ! next ) {
				if ( ! pending[ id ] ) {
					return pending
				}
				const copy = { ...pending }
				delete copy[ id ]
				return copy
			}
			return {
				...pending,
				[ id ]: next,
			}
		} )
	}
	if ( setPlanByTab ) {
		setPlanByTab( plans => {
			const next = session.plan && typeof session.plan === 'object' && Array.isArray( session.plan.steps ) && session.plan.steps.length
				? session.plan
				: null
			if ( ! next ) {
				if ( ! plans[ id ] ) {
					return plans
				}
				const copy = { ...plans }
				delete copy[ id ]
				return copy
			}
			return {
				...plans,
				[ id ]: next,
			}
		} )
	}
	if ( setThoughtByTab ) {
		const thought = session.thoughtProcess?.text || session.thoughtProcess?.Text || ''
		setThoughtByTab( thoughts => {
			if ( ! thought || session.status === 'idle' || session.status === 'error' || session.status === 'cancelled' ) {
				if ( ! thoughts[ id ] ) {
					return thoughts
				}
				const copy = { ...thoughts }
				delete copy[ id ]
				return copy
			}
			return {
				...thoughts,
				[ id ]: {
					text: thought,
					updatedAt: session.thoughtProcess?.updatedAt || '',
				},
			}
		} )
	}
	if ( session.editorRefs && typeof session.editorRefs === 'object' ) {
		const livePostId = Number( collectPageContext()?.post_id || 0 )
		hydrateEditorRefs( session.editorRefs, livePostId )
	}
}

export default function Sidebar() {
	const initial = useMemo( () => loadPersistedState(), [] )
	const [ open, setOpen ] = useState( initial.open )
	const [ width, setWidth ] = useState( initial.width )
	const [ theme ] = useState( initial.theme )
	const [ mode, setMode ] = useState( initial.mode )
	const [ placement, setPlacement ] = useState( initial.placement )
	const [ floatRect, setFloatRect ] = useState( () => (
		initial.floatRect || (
			isFloatingPlacement( initial.placement )
				? getDefaultFloatingRect( initial.placement, initial.width )
				: null
		)
	) )
	const [ tabs, setTabs ] = useState( initial.tabs )
	const [ activeTabId, setActiveTabId ] = useState( initial.activeTabId )
	const [ messagesByTab, setMessagesByTab ] = useState( {} )
	const [ statusByTab, setStatusByTab ] = useState( {} )
	const [ progressByTab, setProgressByTab ] = useState( {} )
	const [ pendingToolByTab, setPendingToolByTab ] = useState( {} )
	const [ planByTab, setPlanByTab ] = useState( {} )
	const [ thoughtByTab, setThoughtByTab ] = useState( {} )
	const [ traceByTab, setTraceByTab ] = useState( {} )
	/** @type {[{[id: string]: string}, Function]} HITL decision in flight per tab (survives debugger toggle). */
	const [ approvingByTab, setApprovingByTab ] = useState( {} )
	const [ debugOpen, setDebugOpen ] = useState( false )
	const [ sending, setSending ] = useState( false )
	const [ stopping, setStopping ] = useState( false )
	/** Session ids the user asked to stop while a send/run was still landing. */
	const stopRequestedRef = useRef( {} )
	/** Transient send failure (not part of session messages — polls must not own this). */
	const [ sendError, setSendError ] = useState( '' )
	const [ focusSignal, setFocusSignal ] = useState( 0 )
	const [ isMobile, setIsMobile ] = useState(
		() => typeof window !== 'undefined' && window.innerWidth < MOBILE_BREAKPOINT
	)
	const [ historyNotice, setHistoryNotice ] = useState( false )
	const [ hasAdminBar, setHasAdminBar ] = useState(
		() => typeof document !== 'undefined' && Boolean( document.getElementById( 'wpadminbar' ) )
	)
	const [ aiReady, setAiReady ] = useState(
		() => Boolean( window.ahentic?.aiPlugin?.isReady )
	)
	const [ hasConnector, setHasConnector ] = useState(
		() => Boolean( window.ahentic?.aiPlugin?.hasConnector )
	)
	// Bumps when hydratedRef changes so session-loading UI can re-render.
	const [ hydratedVersion, setHydratedVersion ] = useState( 0 )

	const resizingRef = useRef( false )
	const resizeEdgeRef = useRef( null )
	const dragRef = useRef( null )
	const floatRectRef = useRef( floatRect )
	floatRectRef.current = floatRect
	const placementRef = useRef( placement )
	placementRef.current = placement
	const hydratedRef = useRef( new Set() )
	const sessionStampRef = useRef( {} )
	const sessionMetaRef = useRef( {} )
	/** @type {React.MutableRefObject<{[sessionId: string]: {[localId: string]: string}}>} */
	const pendingLocalRef = useRef( {} )
	const syncInflightRef = useRef( new Map() )
	const approvingRef = useRef( {} )
	approvingRef.current = approvingByTab
	const pendingToolByTabRef = useRef( pendingToolByTab )
	pendingToolByTabRef.current = pendingToolByTab
	const statusByTabRef = useRef( statusByTab )
	statusByTabRef.current = statusByTab
	const tabsRef = useRef( tabs )
	tabsRef.current = tabs

	const markHydrated = useCallback( id => {
		const sid = String( id || '' )
		if ( ! sid || hydratedRef.current.has( sid ) ) {
			return
		}
		hydratedRef.current.add( sid )
		setHydratedVersion( version => version + 1 )
	}, [] )

	/**
	 * Apply a session payload unless it is older than what we already have.
	 *
	 * @param {Object}  session
	 * @param {Object}  [options]
	 * @param {boolean} [options.force] Skip freshness checks (cold load / tab replace).
	 * @return {boolean} Whether state was applied.
	 */
	const applySession = useCallback( ( session, options = {} ) => {
		if ( ! session?.id ) {
			return false
		}
		const id = String( session.id )
		const force = options.force === true
		const fp = sessionFingerprint( session )
		const known = sessionMetaRef.current[ id ]

		if ( ! force ) {
			// Quiet poll/focus-sync must not clobber an in-flight send (restores old
			// plan/idle and can duplicate the optimistic user bubble).
			if ( hasPendingLocalTurns( pendingLocalRef.current, id ) ) {
				const mapped = mapEntriesToMessages(
					Array.isArray( session.messages ) ? session.messages : []
				)
				if ( ! pendingLocalsConfirmedOnServer( mapped, pendingLocalRef.current[ id ] ) ) {
					return false
				}
				// Server already has the turn(s); clear pending so merge won't duplicate.
				delete pendingLocalRef.current[ id ]
			}
			if ( sessionStampRef.current[ id ] === fp ) {
				return false
			}
			if ( isSessionPayloadStale( session, known ) ) {
				return false
			}
		}

		sessionStampRef.current[ id ] = fp
		sessionMetaRef.current[ id ] = extractSessionMeta( session )
		applySessionPayload(
			session,
			setTabs,
			setMessagesByTab,
			setStatusByTab,
			setTraceByTab,
			setProgressByTab,
			setPendingToolByTab,
			setPlanByTab,
			pendingLocalRef.current,
			setThoughtByTab
		)
		return true
	}, [] )

	const shortcutLabel = useMemo( () => getShortcutLabel(), [] )
	const adminBarId = window.ahentic?.adminBarId || 'ahentic-toggle'
	const aiPlugin = window.ahentic?.aiPlugin || {}
	const canGenerate = aiReady && hasConnector
	const connectorsUrl = aiPlugin.connectorsUrl || ''

	// Persist chrome state (not message bodies).
	useEffect( () => {
		savePersistedState( {
			open,
			width,
			theme,
			mode,
			placement,
			floatRect,
			tabs,
			activeTabId,
		} )
	}, [ open, width, theme, mode, placement, floatRect, tabs, activeTabId ] )

	// Push page content on desktop (docked placements only).
	useEffect( () => {
		syncPageInset( {
			open, width, isMobile, placement,
		} )
		return () => clearPageInset()
	}, [ open, width, isMobile, placement ] )

	const changePlacement = useCallback( nextPlacement => {
		const resolved = Object.values( PLACEMENTS ).includes( nextPlacement )
			? nextPlacement
			: PLACEMENTS.RIGHT

		if ( resolved === placementRef.current ) {
			return
		}

		setPlacement( resolved )

		if ( isFloatingPlacement( resolved ) ) {
			setFloatRect( getDefaultFloatingRect( resolved, width ) )
		}
	}, [ width ] )

	// Responsive breakpoint.
	useEffect( () => {
		const onResize = () => {
			setIsMobile( window.innerWidth < MOBILE_BREAKPOINT )
		}
		window.addEventListener( 'resize', onResize )
		return () => window.removeEventListener( 'resize', onResize )
	}, [] )

	/**
	 * Fetch a session and apply it when newer than local state.
	 * Cold loads (not yet hydrated) drive the spinner; quiet syncs do not.
	 *
	 * @param {string}  tabId
	 * @param {Object}  options
	 * @param {boolean} options.cold Replace missing sessions; show spinner via !hydrated.
	 * @return {Promise<void>}
	 */
	const syncSession = useCallback( async ( tabId, {
		cold = false,
	} = {} ) => {
		if ( ! isSessionId( tabId ) ) {
			return
		}

		const existing = syncInflightRef.current.get( tabId )
		if ( existing ) {
			await existing
			return
		}

		const tabTitle = tabsRef.current.find( tab => tab.id === tabId )?.title || 'New Agent'

		const run = ( async () => {
			try {
				const session = await getSession( tabId )
				// Send started after this GET was issued — drop the stale snapshot.
				if ( ! cold && hasPendingLocalTurns( pendingLocalRef.current, tabId ) ) {
					return
				}
				const fp = sessionFingerprint( session )
				const alreadyHydrated = hydratedRef.current.has( tabId )
				if ( alreadyHydrated && sessionStampRef.current[ tabId ] === fp ) {
					return
				}
				markHydrated( tabId )
				applySession( session, { force: cold } )
			} catch ( error ) {
				if ( ! cold ) {
					return
				}
				// Missing/invalid session — replace this tab with a fresh one.
				try {
					const session = await createSession( {
						mode,
						title: tabTitle,
					} )
					const id = String( session.id )
					setTabs( current => current.map( tab => (
						tab.id === tabId
							? {
								id,
								title: session.title || tab.title || 'New Agent',
								createdAt: tab.createdAt || Date.now(),
								status: session.status || 'idle',
							}
							: tab
					) ) )
					setActiveTabId( current => ( current === tabId ? id : current ) )
					setMessagesByTab( messages => {
						const copy = { ...messages }
						delete copy[ tabId ]
						return {
							...copy,
							[ id ]: mapEntriesToMessages( session.messages ),
						}
					} )
					setStatusByTab( statuses => {
						const copy = { ...statuses }
						delete copy[ tabId ]
						return {
							...copy,
							[ id ]: session.status || 'idle',
						}
					} )
					setTraceByTab( traces => {
						const copy = { ...traces }
						delete copy[ tabId ]
						return {
							...copy,
							[ id ]: Array.isArray( session.trace ) ? session.trace : [],
						}
					} )
					setPendingToolByTab( pending => {
						if ( ! pending[ tabId ] ) {
							return pending
						}
						const copy = { ...pending }
						delete copy[ tabId ]
						return copy
					} )
					delete sessionStampRef.current[ tabId ]
					delete sessionMetaRef.current[ tabId ]
					markHydrated( id )
					applySession( session, { force: true } )
				} catch ( createError ) {
					markHydrated( tabId )
				}
			}
		} )()

		syncInflightRef.current.set( tabId, run )
		try {
			await run
		} finally {
			syncInflightRef.current.delete( tabId )
		}
	}, [ mode, markHydrated, applySession ] )

	// Load or quietly refresh the active tab while the sidebar is open.
	useEffect( () => {
		if ( ! open || ! activeTabId || ! isSessionId( activeTabId ) ) {
			return
		}

		const tabId = activeTabId
		const cold = ! hydratedRef.current.has( tabId )
		syncSession( tabId, { cold } )
	}, [ open, activeTabId, syncSession ] )

	// When returning to this browser tab, quietly refresh the open agent session.
	useEffect( () => {
		const onVisibility = () => {
			if ( document.visibilityState !== 'visible' ) {
				return
			}
			if ( ! open || ! isSessionId( activeTabId ) || ! hydratedRef.current.has( activeTabId ) ) {
				return
			}
			syncSession( activeTabId, { cold: false } )
		}

		document.addEventListener( 'visibilitychange', onVisibility )
		return () => {
			document.removeEventListener( 'visibilitychange', onVisibility )
		}
	}, [ open, activeTabId, syncSession ] )

	// Keep session page context fresh when the user navigates (URL / open editor post).
	useEffect( () => {
		if ( ! open || ! isSessionId( activeTabId ) ) {
			return undefined
		}

		const sessionId = activeTabId
		let lastKey = ''
		let cancelled = false
		let inflight = false

		const contextKey = ctx => [
			ctx?.url || '',
			ctx?.is_block_editor ? '1' : '0',
			ctx?.post_id === null || ctx?.post_id === undefined ? '' : String( ctx.post_id ),
		].join( '|' )

		const syncContext = async ( { force = false } = {} ) => {
			if ( cancelled || inflight ) {
				return
			}
			const ctx = collectPageContext()
			const key = contextKey( ctx )
			if ( ! force && key === lastKey ) {
				return
			}
			const previous = lastKey
			lastKey = key
			// First observation after mount/tab change — baseline only (message send already posts context).
			if ( ! force && ! previous ) {
				return
			}
			inflight = true
			try {
				await patchSession( sessionId, {
					pageContext: ctx,
					editorRefs: exportEditorRefs(),
				} )
			} catch {
				// Best-effort; next send still attaches fresh context.
				lastKey = previous
			} finally {
				inflight = false
			}
		}

		// Establish baseline without a PATCH, then watch for navigations.
		lastKey = contextKey( collectPageContext() )
		const intervalId = window.setInterval( () => {
			syncContext()
		}, 2000 )

		const onPopState = () => {
			syncContext( { force: true } )
		}
		window.addEventListener( 'popstate', onPopState )

		let unsubscribeEditor = null
		try {
			const wpData = window.wp?.data
			if ( wpData?.subscribe && wpData?.select ) {
				unsubscribeEditor = wpData.subscribe( () => {
					const editor = wpData.select( 'core/editor' )
					if ( ! editor?.getCurrentPostId ) {
						return
					}
					syncContext()
				} )
			}
		} catch {
			unsubscribeEditor = null
		}

		return () => {
			cancelled = true
			window.clearInterval( intervalId )
			window.removeEventListener( 'popstate', onPopState )
			if ( typeof unsubscribeEditor === 'function' ) {
				unsubscribeEditor()
			}
		}
	}, [ open, activeTabId ] )

	// Global Cmd/Ctrl+I toggle.
	useEffect( () => {
		const onKeyDown = event => {
			const isI = event.code === 'KeyI' || event.key?.toLowerCase() === 'i'
			const withModifier = event.metaKey || event.ctrlKey
			if ( ! isI || ! withModifier || event.altKey || event.shiftKey ) {
				return
			}

			event.preventDefault()
			event.stopPropagation()
			setOpen( value => ! value )
		}

		const boundDocs = new WeakSet()
		const watchedFrames = new WeakSet()
		const cleanups = []

		const bindDocument = doc => {
			if ( ! doc || boundDocs.has( doc ) ) {
				return
			}
			boundDocs.add( doc )
			doc.addEventListener( 'keydown', onKeyDown, true )
			cleanups.push( () => doc.removeEventListener( 'keydown', onKeyDown, true ) )
		}

		const bindIframes = () => {
			document.querySelectorAll( 'iframe' ).forEach( iframe => {
				const tryBind = () => {
					try {
						bindDocument( iframe.contentDocument )
					} catch ( error ) {
						// Cross-origin frames are ignored.
					}
				}

				tryBind()

				if ( ! watchedFrames.has( iframe ) ) {
					watchedFrames.add( iframe )
					iframe.addEventListener( 'load', tryBind )
					cleanups.push( () => iframe.removeEventListener( 'load', tryBind ) )
				}
			} )
		}

		bindDocument( document )
		bindIframes()

		const observer = new MutationObserver( bindIframes )
		observer.observe( document.documentElement, {
			childList: true,
			subtree: true,
		} )

		return () => {
			observer.disconnect()
			cleanups.forEach( cleanup => cleanup() )
		}
	}, [] )

	// Focus composer when opening or switching tabs.
	useEffect( () => {
		if ( open ) {
			setFocusSignal( value => value + 1 )
		}
	}, [ open, activeTabId ] )

	useEffect( () => {
		setHasAdminBar( Boolean( document.getElementById( 'wpadminbar' ) ) )
	}, [] )

	useEffect( () => {
		const link = document.querySelector( `#wp-admin-bar-${ adminBarId } > .ab-item` )
		if ( ! link ) {
			return undefined
		}

		const onClick = event => {
			event.preventDefault()
			setOpen( value => ! value )
		}

		link.addEventListener( 'click', onClick )
		return () => link.removeEventListener( 'click', onClick )
	}, [ adminBarId ] )

	useEffect( () => {
		const node = document.getElementById( `wp-admin-bar-${ adminBarId }` )
		const link = node?.querySelector( ':scope > .ab-item' )
		if ( ! link ) {
			return
		}
		link.setAttribute( 'aria-expanded', open ? 'true' : 'false' )
		node.classList.toggle( 'is-ahentic-open', open )
	}, [ open, adminBarId ] )

	const activeMessages = messagesByTab[ activeTabId ] || []
	const activeTrace = traceByTab[ activeTabId ] || []
	const activeStatus = statusByTab[ activeTabId ] || 'idle'
	const activeProgress = progressByTab[ activeTabId ]
	const activeApproving = approvingByTab[ activeTabId ] || ''
	const activePendingTool = activeApproving
		? null
		: ( pendingToolByTab[ activeTabId ] || null )
	const activePlan = planByTab[ activeTabId ] || null
	const activeThought = thoughtByTab[ activeTabId ] || null
	const activeTab = tabs.find( tab => tab.id === activeTabId )
	const isBusy = sending ||
		Boolean( activeApproving ) ||
		activeStatus === 'running' ||
		activeStatus === 'awaiting_human' ||
		activeStatus === 'awaiting_browser'
	const progressLabel = resolveLiveStatusLabel(
		activeProgress?.label || '',
		activeTrace,
		isBusy,
		activePendingTool,
		activeApproving ? 'running' : activeStatus
	)
	const activeHeartbeatAge = heartbeatAgeMs( activeProgress?.heartbeatAt || '' )
	const runElapsedMs = activeProgress?.seenAt
		? Math.max( 0, Date.now() - activeProgress.seenAt )
		: 0
	const isHeartbeatDead = activeStatus === 'running' &&
		! activeApproving &&
		(
			( activeHeartbeatAge !== null && activeHeartbeatAge >= HEARTBEAT_DEAD_MS ) ||
			( activeHeartbeatAge === null && runElapsedMs >= HEARTBEAT_DEAD_MS )
		)
	// Existing session tabs show a spinner until fetched; only while the sidebar is open.
	const isSessionLoading = useMemo(
		() => open && isSessionId( activeTabId ) && ! hydratedRef.current.has( activeTabId ),
		// hydratedVersion: hydratedRef alone would not re-render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ open, activeTabId, hydratedVersion ]
	)

	const runningSessionKey = useMemo( () => (
		Object.entries( statusByTab )
			.filter( ( [ , status ] ) => status === 'running' || status === 'awaiting_browser' )
			.map( ( [ id ] ) => id )
			.sort()
			.join( ',' )
	), [ statusByTab ] )

	/**
	 * Tracks browser-tool resume attempts.
	 * Values: 'inflight' | 'done'. Cleared on failure so another attempt can run.
	 *
	 * @type {React.MutableRefObject<{[key: string]: string}>}
	 */
	const browserResumeRef = useRef( {} )
	/** Bumps when a resume attempt exhausts retries so the effect can try again. */
	const [ browserResumeNudge, setBrowserResumeNudge ] = useState( 0 )

	const activeBrowserPending = pendingToolByTab[ activeTabId ]
	const activeBrowserCallId = activeBrowserPending?.runtime === 'browser'
		? ( activeBrowserPending.call_id || activeBrowserPending.callId || activeBrowserPending.name || '' )
		: ''

	const browserResumeKey = (
		activeStatus === 'awaiting_browser' &&
		isSessionId( activeTabId ) &&
		activeBrowserCallId
	) ? `${ activeTabId }:${ activeBrowserCallId }` : ''

	// Execute pending browser abilities when the orchestrator pauses for them.
	// Important: once started, finish the POST even if deps churn — cancelling
	// mid-flight and leaving a sticky "handled" flag stuck the live status on
	// labels like "Reading editor blocks…".
	useEffect( () => {
		if ( ! browserResumeKey ) {
			return undefined
		}

		const state = browserResumeRef.current[ browserResumeKey ]
		if ( state === 'done' || state === 'inflight' ) {
			return undefined
		}

		const pending = pendingToolByTabRef.current[ activeTabId ]
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
	}, [ browserResumeKey, browserResumeNudge, activeTabId, applySession ] )

	// Poll running sessions for live progress + final messages.
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
			if ( id && approvingRef.current[ id ] && session.status === 'awaiting_human' ) {
				return
			}
			applySession( session )
		}

		/** Quiet queue recovery when heartbeat is stale — not progress-label based. */
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

		/** Timed browser recovery (server falls back or errors clearly). */
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
					! continueInFlight.has( id )
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
	}, [ runningSessionKey, applySession ] )

	const openSidebar = useCallback( () => setOpen( true ), [] )
	const closeSidebar = useCallback( () => setOpen( false ), [] )

	const selectTab = useCallback( id => {
		setSendError( '' )
		setActiveTabId( id )
	}, [] )

	const addTab = useCallback( async () => {
		try {
			const session = await createSession( { mode } )
			const id = String( session.id )
			const tab = {
				id,
				title: session.title || 'New Agent',
				createdAt: Date.now(),
				status: session.status || 'idle',
			}
			setTabs( current => [ ...current, tab ] )
			setMessagesByTab( messages => ( {
				...messages,
				[ id ]: mapEntriesToMessages( session.messages ),
			} ) )
			setStatusByTab( statuses => ( {
				...statuses,
				[ id ]: session.status || 'idle',
			} ) )
			setTraceByTab( traces => ( {
				...traces,
				[ id ]: Array.isArray( session.trace ) ? session.trace : [],
			} ) )
			sessionStampRef.current[ id ] = sessionFingerprint( session )
			sessionMetaRef.current[ id ] = extractSessionMeta( session )
			markHydrated( id )
			setActiveTabId( id )
			setOpen( true )
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			window.alert( error.message || 'Could not create a new session.' )
		}
	}, [ mode, markHydrated ] )

	const closeTab = useCallback( async id => {
		const closingLast = tabsRef.current.length <= 1
		if ( closingLast ) {
			try {
				const session = await createSession( { mode } )
				const nextId = String( session.id )
				hydratedRef.current = new Set( [ nextId ] )
				sessionStampRef.current = {
					[ nextId ]: sessionFingerprint( session ),
				}
				sessionMetaRef.current = {
					[ nextId ]: extractSessionMeta( session ),
				}
				pendingLocalRef.current = {}
				setHydratedVersion( version => version + 1 )
				setTabs( [ {
					id: nextId,
					title: session.title || 'New Agent',
					createdAt: Date.now(),
					status: session.status || 'idle',
				} ] )
				setActiveTabId( nextId )
				setMessagesByTab( { [ nextId ]: mapEntriesToMessages( session.messages ) } )
				setStatusByTab( { [ nextId ]: session.status || 'idle' } )
				setTraceByTab( { [ nextId ]: Array.isArray( session.trace ) ? session.trace : [] } )
				setProgressByTab( {} )
				setPendingToolByTab( {} )
				setPlanByTab( {} )
			} catch ( error ) {
				// eslint-disable-next-line no-alert
				window.alert( error.message || 'Could not start a new session.' )
			}
			return
		}

		setTabs( current => {
			const next = current.filter( tab => tab.id !== id )
			setActiveTabId( active => {
				if ( active !== id ) {
					return active
				}
				const index = current.findIndex( tab => tab.id === id )
				const fallback = next[ Math.max( 0, index - 1 ) ] || next[ 0 ]
				return fallback.id
			} )
			setMessagesByTab( messages => {
				const copy = { ...messages }
				delete copy[ id ]
				return copy
			} )
			setStatusByTab( statuses => {
				const copy = { ...statuses }
				delete copy[ id ]
				return copy
			} )
			setTraceByTab( traces => {
				const copy = { ...traces }
				delete copy[ id ]
				return copy
			} )
			setPendingToolByTab( pending => {
				const copy = { ...pending }
				delete copy[ id ]
				return copy
			} )
			setProgressByTab( progress => {
				if ( ! progress[ id ] ) {
					return progress
				}
				const copy = { ...progress }
				delete copy[ id ]
				return copy
			} )
			setPlanByTab( plans => {
				if ( ! plans[ id ] ) {
					return plans
				}
				const copy = { ...plans }
				delete copy[ id ]
				return copy
			} )
			hydratedRef.current.delete( id )
			delete sessionStampRef.current[ id ]
			delete sessionMetaRef.current[ id ]
			delete pendingLocalRef.current[ id ]
			setHydratedVersion( version => version + 1 )
			return next
		} )
	}, [ mode ] )

	const renameActiveTab = useCallback( async () => {
		const active = tabs.find( tab => tab.id === activeTabId )
		if ( ! active ) {
			return
		}
		// eslint-disable-next-line no-alert
		const nextTitle = window.prompt( 'Rename conversation', active.title )
		if ( nextTitle === null ) {
			return
		}
		const title = nextTitle.trim() || 'New Agent'
		setTabs( current => current.map( tab => (
			tab.id === activeTabId ? { ...tab, title } : tab
		) ) )
		if ( isSessionId( activeTabId ) ) {
			try {
				await patchSession( activeTabId, { title } )
			} catch ( error ) {
				// Local title still updated.
			}
		}
	}, [ tabs, activeTabId ] )

	const duplicateActiveTab = useCallback( async () => {
		const active = tabs.find( tab => tab.id === activeTabId )
		if ( ! active ) {
			return
		}
		try {
			const session = await createSession( {
				mode,
				title: `${ active.title } copy`,
			} )
			const id = String( session.id )
			setTabs( current => [ ...current, {
				id,
				title: session.title || `${ active.title } copy`,
				createdAt: Date.now(),
				status: 'idle',
			} ] )
			setMessagesByTab( messages => ( {
				...messages,
				[ id ]: [],
			} ) )
			setStatusByTab( statuses => ( {
				...statuses,
				[ id ]: 'idle',
			} ) )
			setTraceByTab( traces => ( {
				...traces,
				[ id ]: [],
			} ) )
			markHydrated( id )
			setActiveTabId( id )
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			window.alert( error.message || 'Could not duplicate session.' )
		}
	}, [ tabs, activeTabId, mode, markHydrated ] )

	const clearAllTabs = useCallback( async () => {
		try {
			const session = await createSession( { mode } )
			const id = String( session.id )
			hydratedRef.current = new Set( [ id ] )
			sessionStampRef.current = {
				[ id ]: sessionFingerprint( session ),
			}
			sessionMetaRef.current = {
				[ id ]: extractSessionMeta( session ),
			}
			pendingLocalRef.current = {}
			setHydratedVersion( version => version + 1 )
			setTabs( [ {
				id,
				title: session.title || 'New Agent',
				createdAt: Date.now(),
				status: 'idle',
			} ] )
			setActiveTabId( id )
			setMessagesByTab( { [ id ]: mapEntriesToMessages( session.messages ) } )
			setStatusByTab( { [ id ]: 'idle' } )
			setTraceByTab( { [ id ]: Array.isArray( session.trace ) ? session.trace : [] } )
			setProgressByTab( {} )
			setPendingToolByTab( {} )
			setPlanByTab( {} )
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			window.alert( error.message || 'Could not reset sessions.' )
		}
	}, [ mode ] )

	const sendMessage = useCallback( async text => {
		if ( ! text?.trim() || sending || ! canGenerate ) {
			return false
		}
		// Wait until an existing session tab has finished loading.
		if ( isSessionId( activeTabId ) && ! hydratedRef.current.has( activeTabId ) ) {
			return false
		}

		const currentStatus = statusByTabRef.current[ activeTabId ] || 'idle'
		// Mid-run sends are rejected by the server (409) except during HITL redirect.
		if (
			currentStatus === 'running' ||
			currentStatus === 'awaiting_browser' ||
			approvingRef.current[ activeTabId ]
		) {
			setSendError( __( 'This session is still working. Wait for it to finish.', 'ahentic' ) )
			return false
		}

		let sessionId = activeTabId

		if ( ! isSessionId( sessionId ) ) {
			try {
				const session = await createSession( { mode } )
				sessionId = String( session.id )
				setTabs( current => current.map( tab => (
					tab.id === activeTabId
						? {
							id: sessionId,
							title: session.title || tab.title,
							createdAt: tab.createdAt,
							status: 'idle',
						}
						: tab
				) ) )
				setActiveTabId( sessionId )
				markHydrated( sessionId )
			} catch ( error ) {
				setMessagesByTab( messages => ( {
					...messages,
					[ activeTabId ]: [
						...( messages[ activeTabId ] || [] ),
						{
							id: `err_${ Date.now() }`,
							role: 'assistant',
							content: error.message || 'Could not create a session.',
						},
					],
				} ) )
				return false
			}
		}

		const optimisticUser = {
			id: `local_u_${ Date.now() }`,
			role: 'user',
			content: text.trim(),
		}
		const metaBeforeSend = sessionMetaRef.current[ sessionId ]
			? { ...sessionMetaRef.current[ sessionId ] }
			: extractSessionMeta( null )

		// Track pending local turns so polls merge instead of wiping them.
		pendingLocalRef.current[ sessionId ] = {
			...( pendingLocalRef.current[ sessionId ] || {} ),
			[ optimisticUser.id ]: optimisticUser.content,
		}

		setMessagesByTab( messages => ( {
			...messages,
			[ sessionId ]: [ ...( messages[ sessionId ] || [] ), optimisticUser ],
		} ) )
		setTabs( current => current.map( tab => {
			if ( tab.id !== sessionId ) {
				return tab
			}
			if ( tab.title && tab.title !== 'New Agent' ) {
				return tab
			}
			return { ...tab, title: truncateTitle( text ) }
		} ) )
		setStatusByTab( statuses => ( {
			...statuses,
			[ sessionId ]: 'running',
		} ) )
		setProgressByTab( progress => ( {
			...progress,
			[ sessionId ]: {
				label: 'Planning next steps…',
				updatedAt: '',
			},
		} ) )
		// Drop prior-run trace so live status cannot reuse old intentions before the new run_start lands.
		setTraceByTab( traces => ( {
			...traces,
			[ sessionId ]: [],
		} ) )
		setPendingToolByTab( pending => {
			if ( ! pending[ sessionId ] ) {
				return pending
			}
			const copy = { ...pending }
			delete copy[ sessionId ]
			return copy
		} )
		// New user turn starts a fresh plan (server clears meta; clear UI immediately).
		setPlanByTab( plans => {
			if ( ! plans[ sessionId ] ) {
				return plans
			}
			const copy = { ...plans }
			delete copy[ sessionId ]
			return copy
		} )
		setSending( true )
		setSendError( '' )

		try {
			const session = await postMessage( sessionId, {
				content: text.trim(),
				mode,
				pageContext: collectPageContext(),
			} )
			// Clear pending before apply so the server transcript replaces the local bubble.
			if ( pendingLocalRef.current[ sessionId ] ) {
				delete pendingLocalRef.current[ sessionId ][ optimisticUser.id ]
				if ( ! Object.keys( pendingLocalRef.current[ sessionId ] ).length ) {
					delete pendingLocalRef.current[ sessionId ]
				}
			}
			applySession( session, { force: true } )
			setSendError( '' )
			setFocusSignal( value => value + 1 )

			// User hit Stop while this send was in flight — cancel the run that just started.
			if ( stopRequestedRef.current[ sessionId ] ) {
				delete stopRequestedRef.current[ sessionId ]
				try {
					const cancelled = await cancelSession( sessionId )
					applySession( cancelled, { force: true } )
				} catch {
					// Polling / next Stop click can reconcile.
				}
			}

			return true
		} catch ( error ) {
			// Drop the optimistic turn — it never landed on the server.
			if ( pendingLocalRef.current[ sessionId ] ) {
				delete pendingLocalRef.current[ sessionId ][ optimisticUser.id ]
				if ( ! Object.keys( pendingLocalRef.current[ sessionId ] ).length ) {
					delete pendingLocalRef.current[ sessionId ]
				}
			}
			setMessagesByTab( messages => ( {
				...messages,
				[ sessionId ]: ( messages[ sessionId ] || [] ).filter(
					message => message.id !== optimisticUser.id
				),
			} ) )
			// Restore pre-send freshness so a poll cannot race ahead of reconcile.
			sessionMetaRef.current[ sessionId ] = metaBeforeSend

			// Reconcile with the server (keeps real running/awaiting_* status; restores trace).
			try {
				const session = await getSession( sessionId )
				applySession( session, { force: true } )
			} catch {
				setStatusByTab( statuses => ( {
					...statuses,
					[ sessionId ]: metaBeforeSend.status || 'idle',
				} ) )
			}

			// Composer restores the draft when we return false; surface why send failed.
			setSendError( error.message || __( 'Request failed.', 'ahentic' ) )
			setFocusSignal( value => value + 1 )
			return false
		} finally {
			setSending( false )
		}
	}, [ activeTabId, mode, sending, markHydrated, applySession, canGenerate ] )

	const continueStuckSession = useCallback( async () => {
		if ( ! isSessionId( activeTabId ) ) {
			return
		}
		const sessionId = activeTabId
		try {
			const session = await continueSession( sessionId )
			if ( session ) {
				applySession( session, { force: true } )
			}
		} catch ( error ) {
			setSendError( error.message || __( 'Could not continue this run.', 'ahentic' ) )
		}
	}, [ activeTabId, applySession ] )

	const stopSession = useCallback( async () => {
		if ( ! isSessionId( activeTabId ) || stopping ) {
			return
		}

		const sessionId = activeTabId
		stopRequestedRef.current[ sessionId ] = true
		setStopping( true )
		setSendError( '' )

		// Optimistically unlock the composer while the cancel request lands.
		setStatusByTab( statuses => ( {
			...statuses,
			[ sessionId ]: 'idle',
		} ) )
		setProgressByTab( progress => {
			if ( ! progress[ sessionId ] ) {
				return progress
			}
			const copy = { ...progress }
			delete copy[ sessionId ]
			return copy
		} )
		setPendingToolByTab( pending => {
			if ( ! pending[ sessionId ] ) {
				return pending
			}
			const copy = { ...pending }
			delete copy[ sessionId ]
			return copy
		} )
		setApprovingByTab( current => {
			if ( ! current[ sessionId ] ) {
				return current
			}
			const copy = { ...current }
			delete copy[ sessionId ]
			return copy
		} )
		setSending( false )

		// Drop any in-flight browser resume for this tab.
		Object.keys( browserResumeRef.current ).forEach( key => {
			if ( key.startsWith( `${ sessionId }:` ) ) {
				delete browserResumeRef.current[ key ]
			}
		} )

		try {
			const session = await cancelSession( sessionId )
			applySession( session, { force: true } )
			setFocusSignal( value => value + 1 )
		} catch ( error ) {
			setSendError( error.message || __( 'Could not stop the run.', 'ahentic' ) )
			try {
				const session = await getSession( sessionId )
				applySession( session, { force: true } )
			} catch {
				// Leave optimistic idle; user can retry Stop.
			}
		} finally {
			delete stopRequestedRef.current[ sessionId ]
			setStopping( false )
		}
	}, [ activeTabId, stopping, applySession ] )

	const onApproval = useCallback( async decision => {
		if ( ! isSessionId( activeTabId ) ) {
			return
		}
		const sessionId = activeTabId
		if ( approvingRef.current[ sessionId ] ) {
			return
		}

		const pending = pendingToolByTabRef.current[ sessionId ] || null
		const previousStatus = statusByTabRef.current[ sessionId ] || 'awaiting_human'
		const optimisticLabel = decision === 'deny'
			? __( 'Skipping that action…', 'ahentic' )
			: progressLabelForAbility( pending?.name || '' )

		setApprovingByTab( current => ( {
			...current,
			[ sessionId ]: decision,
		} ) )

		// Optimistic: hide HITL, show live status, start polling via running.
		setPendingToolByTab( current => {
			if ( ! current[ sessionId ] ) {
				return current
			}
			const next = { ...current }
			delete next[ sessionId ]
			return next
		} )
		setStatusByTab( current => ( {
			...current,
			[ sessionId ]: 'running',
		} ) )
		setProgressByTab( current => ( {
			...current,
			[ sessionId ]: {
				label: optimisticLabel,
				updatedAt: new Date().toISOString(),
			},
		} ) )
		sessionMetaRef.current[ sessionId ] = {
			...( sessionMetaRef.current[ sessionId ] || {} ),
			status: 'running',
			progressAt: Date.now(),
		}

		try {
			const session = await postApproval( sessionId, { decision } )
			applySession( session, { force: true } )
		} catch ( error ) {
			setStatusByTab( current => ( {
				...current,
				[ sessionId ]: previousStatus,
			} ) )
			if ( pending ) {
				setPendingToolByTab( current => ( {
					...current,
					[ sessionId ]: pending,
				} ) )
			}
			setProgressByTab( current => ( {
				...current,
				[ sessionId ]: {
					label: __( 'Waiting for your approval…', 'ahentic' ),
					updatedAt: new Date().toISOString(),
				},
			} ) )
			throw error
		} finally {
			setApprovingByTab( current => {
				if ( ! current[ sessionId ] ) {
					return current
				}
				const next = { ...current }
				delete next[ sessionId ]
				return next
			} )
		}
	}, [ activeTabId, applySession ] )

	const onSuggestedAction = useCallback( async action => {
		if ( ! isSessionId( activeTabId ) || ! action ) {
			return
		}
		if ( action.type === 'link' && action.url ) {
			window.open( action.url, '_blank', 'noopener,noreferrer' )
			return
		}
		const session = await postSuggestedAction( activeTabId, {
			type: action.type || 'ability',
			id: action.id || '',
			name: action.name || '',
			input: action.input || {},
			label: action.label || '',
		} )
		applySession( session )
	}, [ activeTabId, applySession ] )

	const onSuggestedPrompt = useCallback( prompt => {
		sendMessage( prompt )
	}, [ sendMessage ] )

	// Docked width resize + floating move/resize.
	useEffect( () => {
		const clearInteraction = () => {
			resizingRef.current = false
			resizeEdgeRef.current = null
			dragRef.current = null
			document.body.classList.remove(
				'ahentic-is-resizing',
				'ahentic-is-resizing--row',
				'ahentic-is-resizing--corner',
				'ahentic-is-resizing--corner-nesw',
				'ahentic-is-dragging'
			)
		}

		const onMove = event => {
			if ( isMobile ) {
				return
			}

			if ( dragRef.current ) {
				const {
					startX, startY, originLeft, originTop,
				} = dragRef.current
				const next = clampFloatingRect( {
					...floatRectRef.current,
					left: originLeft + ( event.clientX - startX ),
					top: originTop + ( event.clientY - startY ),
				} )
				setFloatRect( next )
				return
			}

			if ( ! resizingRef.current ) {
				return
			}

			const currentPlacement = placementRef.current
			const edge = resizeEdgeRef.current

			if ( isFloatingPlacement( currentPlacement ) && edge ) {
				const origin = resizeEdgeRef.current.origin
				const anchorRight = origin.left + origin.width
				const anchorBottom = origin.top + origin.height
				let {
					left, top, width: nextW, height: nextH,
				} = origin

				if ( edge.dir.includes( 'e' ) ) {
					nextW = event.clientX - origin.left
				}
				if ( edge.dir.includes( 's' ) ) {
					nextH = event.clientY - origin.top
				}
				if ( edge.dir.includes( 'w' ) ) {
					nextW = anchorRight - event.clientX
					left = event.clientX
				}
				if ( edge.dir.includes( 'n' ) ) {
					nextH = anchorBottom - event.clientY
					top = event.clientY
				}

				const clamped = clampFloatingRect( {
					left,
					top,
					width: nextW,
					height: nextH,
				} )

				// Keep the opposite edge fixed when min/max size clamping kicks in.
				if ( edge.dir.includes( 'w' ) ) {
					clamped.left = Math.max( 0, anchorRight - clamped.width )
					clamped.width = Math.min( clamped.width, anchorRight - clamped.left )
				}
				if ( edge.dir.includes( 'n' ) ) {
					clamped.top = Math.max( 0, anchorBottom - clamped.height )
					clamped.height = Math.min( clamped.height, anchorBottom - clamped.top )
				}

				setFloatRect( clamped )
				setWidth( clamped.width )
				return
			}

			if ( currentPlacement === PLACEMENTS.LEFT ) {
				setWidth( clampWidth( event.clientX ) )
				return
			}

			if ( currentPlacement === PLACEMENTS.RIGHT ) {
				setWidth( clampWidth( window.innerWidth - event.clientX ) )
			}
		}

		const onUp = () => {
			clearInteraction()
		}

		window.addEventListener( 'mousemove', onMove )
		window.addEventListener( 'mouseup', onUp )
		return () => {
			window.removeEventListener( 'mousemove', onMove )
			window.removeEventListener( 'mouseup', onUp )
			clearInteraction()
		}
	}, [ isMobile ] )

	const startDockResize = event => {
		if ( isMobile || isFloatingPlacement( placement ) ) {
			return
		}
		event.preventDefault()
		resizingRef.current = true
		resizeEdgeRef.current = null
		document.body.classList.add( 'ahentic-is-resizing' )
	}

	const startFloatResize = ( event, dir ) => {
		if ( isMobile || ! isFloatingPlacement( placement ) ) {
			return
		}
		const origin = floatRect || getDefaultFloatingRect( placement, width )
		if ( ! floatRect ) {
			setFloatRect( origin )
		}
		event.preventDefault()
		event.stopPropagation()
		resizingRef.current = true
		resizeEdgeRef.current = {
			dir,
			origin: { ...origin },
		}
		document.body.classList.add( 'ahentic-is-resizing' )
		if ( dir === 'n' || dir === 's' ) {
			document.body.classList.add( 'ahentic-is-resizing--row' )
		} else if ( dir.length > 1 ) {
			document.body.classList.add( 'ahentic-is-resizing--corner' )
			if ( dir === 'ne' || dir === 'sw' ) {
				document.body.classList.add( 'ahentic-is-resizing--corner-nesw' )
			}
		}
	}

	const startFloatDrag = event => {
		if ( isMobile || ! isFloatingPlacement( placement ) || ! floatRect ) {
			return
		}
		event.preventDefault()
		dragRef.current = {
			startX: event.clientX,
			startY: event.clientY,
			originLeft: floatRect.left,
			originTop: floatRect.top,
		}
		document.body.classList.add( 'ahentic-is-dragging' )
	}

	const floating = ! isMobile && isFloatingPlacement( placement )
	const activeFloat = floating
		? ( floatRect || getDefaultFloatingRect( placement, width ) )
		: null

	const panelStyle = ( () => {
		if ( isMobile ) {
			return undefined
		}
		if ( activeFloat ) {
			return {
				width: `${ activeFloat.width }px`,
				height: `${ activeFloat.height }px`,
				left: `${ activeFloat.left }px`,
				top: `${ activeFloat.top }px`,
			}
		}
		return { width: `${ width }px` }
	} )()

	return (
		<div
			className="ahentic"
			data-ahentic-theme={ theme }
		>
			{ ! open && ! hasAdminBar && (
				<button
					type="button"
					className="ahentic-launcher"
					onClick={ openSidebar }
					aria-label="Open Ahentic sidebar"
					title={ `Open Ahentic (${ shortcutLabel })` }
				>
					<AhenticLogo size={ 18 } />
				</button>
			) }

			{ open && isMobile && (
				<button
					type="button"
					className="ahentic-backdrop"
					aria-label="Close Ahentic sidebar"
					onClick={ closeSidebar }
				/>
			) }

			<aside
				className={ classnames( 'ahentic-sidebar', {
					'is-open': open,
					'is-mobile': isMobile,
					'is-placement-left': ! isMobile && placement === PLACEMENTS.LEFT,
					'is-placement-floating': ! isMobile && placement === PLACEMENTS.FLOATING,
					'is-placement-floating-small': ! isMobile && placement === PLACEMENTS.FLOATING_SMALL,
				} ) }
				style={ panelStyle }
				aria-label="Ahentic AI sidebar"
				aria-hidden={ ! open }
			>
				{ ! isMobile && ! floating && (
					// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
					<div
						className="ahentic-resize"
						onMouseDown={ startDockResize }
						role="separator"
						aria-orientation="vertical"
						aria-valuemin={ MIN_WIDTH }
						aria-valuemax={ MAX_WIDTH }
						aria-valuenow={ width }
						aria-label="Resize Ahentic sidebar"
					/>
				) }

				{ floating && (
					<>
						{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
						<div
							className="ahentic-float-handle ahentic-float-handle--n"
							onMouseDown={ event => startFloatResize( event, 'n' ) }
							role="separator"
							aria-orientation="horizontal"
							aria-label="Resize Ahentic sidebar from top"
						/>
						{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
						<div
							className="ahentic-float-handle ahentic-float-handle--s"
							onMouseDown={ event => startFloatResize( event, 's' ) }
							role="separator"
							aria-orientation="horizontal"
							aria-label="Resize Ahentic sidebar from bottom"
						/>
						{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
						<div
							className="ahentic-float-handle ahentic-float-handle--e"
							onMouseDown={ event => startFloatResize( event, 'e' ) }
							role="separator"
							aria-orientation="vertical"
							aria-label="Resize Ahentic sidebar from right"
						/>
						{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
						<div
							className="ahentic-float-handle ahentic-float-handle--w"
							onMouseDown={ event => startFloatResize( event, 'w' ) }
							role="separator"
							aria-orientation="vertical"
							aria-label="Resize Ahentic sidebar from left"
						/>
						{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
						<div
							className="ahentic-float-handle ahentic-float-handle--nw"
							onMouseDown={ event => startFloatResize( event, 'nw' ) }
							role="separator"
							aria-label="Resize Ahentic sidebar from top-left corner"
						/>
						{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
						<div
							className="ahentic-float-handle ahentic-float-handle--ne"
							onMouseDown={ event => startFloatResize( event, 'ne' ) }
							role="separator"
							aria-label="Resize Ahentic sidebar from top-right corner"
						/>
						{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
						<div
							className="ahentic-float-handle ahentic-float-handle--sw"
							onMouseDown={ event => startFloatResize( event, 'sw' ) }
							role="separator"
							aria-label="Resize Ahentic sidebar from bottom-left corner"
						/>
						{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
						<div
							className="ahentic-float-handle ahentic-float-handle--se"
							onMouseDown={ event => startFloatResize( event, 'se' ) }
							role="separator"
							aria-label="Resize Ahentic sidebar from bottom-right corner"
						/>
					</>
				) }

				<Toolbar
					onClose={ closeSidebar }
					shortcutLabel={ shortcutLabel }
					placement={ placement }
					onPlacementChange={ changePlacement }
					onDragHandlePointerDown={ startFloatDrag }
					isMobile={ isMobile }
				/>

				<TabBar
					tabs={ tabs }
					activeTabId={ activeTabId }
					onSelect={ selectTab }
					onClose={ closeTab }
					onNew={ addTab }
					onRename={ renameActiveTab }
					onDuplicate={ duplicateActiveTab }
					onClearAll={ clearAllTabs }
					debugOpen={ debugOpen }
					onToggleDebug={ () => setDebugOpen( value => ! value ) }
					onHistory={ () => {
						setHistoryNotice( true )
						window.setTimeout( () => setHistoryNotice( false ), 2200 )
					} }
				/>

				{ historyNotice && (
					<div className="ahentic-toast" role="status">
						Sessions are saved on this site. Full history browser coming soon.
					</div>
				) }

				{ debugOpen ? (
					<DebuggerPanel
						trace={ activeTrace }
						sessionTitle={ activeTab?.title || '' }
						onClose={ () => setDebugOpen( false ) }
					/>
				) : (
					<TabContent
						aiReady={ aiReady }
						hasConnector={ hasConnector }
						aiPlugin={ aiPlugin }
						onAiReady={ setAiReady }
						onHasConnector={ setHasConnector }
						messages={ activeMessages }
						sessionId={ activeTabId }
						onSuggestedPrompt={ onSuggestedPrompt }
						ready={ ! isSessionLoading }
						loading={ isSessionLoading }
						busy={ isBusy }
						progressLabel={ progressLabel }
						pendingTool={ activePendingTool }
						plan={ activePlan }
						thoughtProcess={ isBusy ? ( activeThought?.text || '' ) : '' }
						sessionStatus={ activeApproving ? 'running' : activeStatus }
						approvingDecision={ activeApproving }
						onApproval={ onApproval }
						onSuggestedAction={ onSuggestedAction }
						liveness={ isHeartbeatDead ? 'stuck' : '' }
						onContinue={ continueStuckSession }
						onCancelRun={ stopSession }
					/>
				) }

				<Composer
					mode={ mode }
					onModeChange={ setMode }
					onSubmit={ sendMessage }
					focusSignal={ focusSignal }
					shortcutLabel={ shortcutLabel }
					error={ sendError }
					onClearError={ () => setSendError( '' ) }
					placeholder={
						activeStatus === 'awaiting_human' && activePendingTool
							? __( 'Send to change direction (skips this approval)…', 'ahentic' )
							: __( 'Plan, Build, / for skills, @ for context', 'ahentic' )
					}
					disabled={ ! canGenerate }
					inputDisabled={
						! canGenerate ||
						sending ||
						stopping ||
						Boolean( activeApproving ) ||
						activeStatus === 'running' ||
						activeStatus === 'awaiting_browser'
					}
					canStop={
						isSessionId( activeTabId ) &&
						(
							sending ||
							stopping ||
							Boolean( activeApproving ) ||
							activeStatus === 'running' ||
							activeStatus === 'awaiting_human' ||
							activeStatus === 'awaiting_browser'
						)
					}
					onStop={ stopSession }
					stopping={ stopping }
					disabledHint={
						canGenerate || activeMessages.length === 0
							? ''
							: ( ! aiReady
								? 'Install WordPress AI to start chatting.'
								: ( ! hasConnector
									? 'Add an AI connector in Settings → Connectors to start chatting.'
									: ''
								)
							)
					}
					connectorsUrl={
						! canGenerate && activeMessages.length > 0 && ! hasConnector
							? connectorsUrl
							: ''
					}
				/>
			</aside>
		</div>
	)
}
