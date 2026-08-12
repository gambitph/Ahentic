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
	recoverFloatingRectOnOpen,
	defaultAgentTitle,
} from './constants'
import {
	loadPersistedState,
	savePersistedState,
} from './storage'
import { syncPageInset, clearPageInset } from './page-inset'
import { openLink } from './links'
import AhenticLogo from './ahentic-logo'
import DebuggerPanel from './debugger-panel'
import FloatHandles from './float-handles'
import {
	createSession,
	getSession,
	getAiPluginStatus,
	normalizeHasConnector,
	checkingModelConnectionLabel,
	patchSession,
	postMessage,
	continueSession,
	cancelSession,
	postApproval,
	postSuggestedAction,
	mapEntriesToMessages,
	isSessionId,
} from './api'
import { collectPageContext } from './page-context'
import { exportEditorRefs } from './block-ref-registry'
import { createSessionRunnerLock } from './session-runner-lock'
import {
	createEmptySessionRecord,
	getSessionRecord,
	patchSessionRecord,
	omitSessionRecord,
	remapSessionRecord,
	sessionFingerprint,
	extractSessionMeta,
	isActiveRunStatus,
	isSessionPayloadStale,
	hasPendingLocalTurns,
	pendingLocalsConfirmedOnServer,
	cancelIncompletePlanSteps,
} from './session-state'
import ViewerOverlay from './viewer-overlay'
import { progressLabelForAbility } from './progress-label'
import { applySessionPayload } from './apply-session-payload'
import { getShortcutLabel, truncateTitle } from './sidebar-chrome-utils'
import { resolveLiveStatusLabel, heartbeatAgeMs } from './sidebar-live-status'
import { HEARTBEAT_DEAD_MS, VIEWER_ACTIVE_ELSEWHERE } from './session-run-constants'
import { useRunnerLockEffects } from './use-runner-lock-effects'
import { useFloatInteraction } from './use-float-interaction'
import { useSessionPoll } from './use-session-poll'
import { useBrowserResume } from './use-browser-resume'
import RunFeedbackBar, { shouldShowRunFeedback } from './run-feedback-bar'

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
	/** Per-session UI record (messages, status, plan, HITL, poll watch, …). */
	const [ sessionsById, setSessionsById ] = useState( {} )
	const [ debugOpen, setDebugOpen ] = useState( false )
	const [ sending, setSending ] = useState( false )
	const [ stopping, setStopping ] = useState( false )
	/** Session ids the user asked to stop while a send/run was still landing. */
	const stopRequestedRef = useRef( {} )
	/** Per-window active-runner lock (multi-window viewer safety). */
	const runnerLock = useMemo( () => createSessionRunnerLock(), [] )
	const [ lockRevision, setLockRevision ] = useState( 0 )
	const bumpLockRevision = useCallback( () => {
		setLockRevision( value => value + 1 )
	}, [] )
	/** Transient send failure (not part of session messages — polls must not own this). */
	const [ sendError, setSendError ] = useState( '' )
	const [ sendErrorCode, setSendErrorCode ] = useState( '' )
	const [ focusSignal, setFocusSignal ] = useState( 0 )
	const [ feedbackDismissed, setFeedbackDismissed ] = useState( {} )
	const [ isMobile, setIsMobile ] = useState(
		() => typeof window !== 'undefined' && window.innerWidth < MOBILE_BREAKPOINT
	)
	const [ hasAdminBar, setHasAdminBar ] = useState(
		() => typeof document !== 'undefined' && Boolean( document.getElementById( 'wpadminbar' ) )
	)
	const [ aiReady, setAiReady ] = useState(
		() => Boolean( window.ahentic?.aiPlugin?.isReady )
	)
	const [ hasConnector, setHasConnector ] = useState(
		() => normalizeHasConnector( window.ahentic?.aiPlugin?.hasConnector )
	)
	// Bumps when hydratedRef changes so session-loading UI can re-render.
	const [ hydratedVersion, setHydratedVersion ] = useState( 0 )

	const placementRef = useRef( placement )
	placementRef.current = placement
	const openRef = useRef( open )
	openRef.current = open
	const floatRectRef = useRef( floatRect )
	floatRectRef.current = floatRect
	const widthRef = useRef( width )
	widthRef.current = width
	const isMobileRef = useRef( isMobile )
	isMobileRef.current = isMobile
	const hydratedRef = useRef( new Set() )
	const sessionStampRef = useRef( {} )
	const sessionMetaRef = useRef( {} )
	/** @type {React.MutableRefObject<{[sessionId: string]: {[localId: string]: string}}>} */
	const pendingLocalRef = useRef( {} )
	const syncInflightRef = useRef( new Map() )
	/** Mirrors sessionsById for send/poll/HITL guards without stale closures. */
	const sessionsByIdRef = useRef( sessionsById )
	sessionsByIdRef.current = sessionsById

	/**
	 * Become the active runner for a live session drive action.
	 *
	 * @param {string} sessionId
	 * @return {boolean} True when this window may drive the session.
	 */
	const claimRunner = useCallback( sessionId => {
		if ( ! isSessionId( sessionId ) ) {
			return false
		}
		if ( runnerLock.isViewer( sessionId ) ) {
			return false
		}
		const ok = runnerLock.claim( sessionId )
		bumpLockRevision()
		return ok
	}, [ runnerLock, bumpLockRevision ] )

	/**
	 * Drop our claim after a failed drive that never left idle, or on idle.
	 *
	 * @param {string} sessionId
	 */
	const releaseRunner = useCallback( sessionId => {
		if ( ! isSessionId( sessionId ) ) {
			return
		}
		runnerLock.release( sessionId )
		bumpLockRevision()
	}, [ runnerLock, bumpLockRevision ] )

	useRunnerLockEffects( {
		sessionsById,
		sessionsByIdRef,
		runnerLock,
		bumpLockRevision,
	} )

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
			const localStatus = getSessionRecord( sessionsByIdRef.current, id ).status || ''
			const knownForCompare = known
				? {
					...known,
					// Prefer live UI busy state when meta lagged behind optimistic send.
					status: isActiveRunStatus( localStatus ) ? localStatus : known.status,
				}
				: ( isActiveRunStatus( localStatus )
					? { ...extractSessionMeta( null ), status: localStatus }
					: known
				)
			if ( isSessionPayloadStale( session, knownForCompare ) ) {
				return false
			}
		}

		sessionStampRef.current[ id ] = fp
		sessionMetaRef.current[ id ] = extractSessionMeta( session )
		applySessionPayload(
			session,
			setTabs,
			setSessionsById,
			pendingLocalRef.current
		)
		return true
	}, [] )

	const shortcutLabel = useMemo( () => getShortcutLabel(), [] )
	const adminBarId = window.ahentic?.adminBarId || 'ahentic-toggle'
	const aiPlugin = window.ahentic?.aiPlugin || {}
	const canGenerate = aiReady && hasConnector === true
	const connectorsUrl = aiPlugin.connectorsUrl || ''
	const pluginInstalled = Boolean( aiPlugin.pluginInstalled )

	/**
	 * AI/connector status reconcile over REST.
	 *
	 * Boot `window.ahentic.aiPlugin` is a localize-time probe and can
	 * false-negative (list-models / network flake). Soft-false never
	 * downgrades a previously green gate; unknown never shows the
	 * "Add an AI connector" CTA. One short retry when the live GET is
	 * still unknown. Do not re-call on open/focus/visibility.
	 */
	const syncAiPluginStatus = useCallback( async () => {
		/**
		 * @param {Object} status
		 * @return {boolean|null} Normalized connector flag after apply.
		 */
		const applyStatus = status => {
			if ( ! status || typeof status !== 'object' ) {
				return null
			}
			const nextReady = Boolean( status.isReady )
			const nextConnector = normalizeHasConnector( status.hasConnector )
			// Upgrade-only: recover localize false-negatives; never flip green→red.
			setAiReady( prev => ( nextReady ? true : prev ) )
			setHasConnector( prev => {
				if ( nextConnector === true ) {
					return true
				}
				if ( prev === true ) {
					return true
				}
				// Once confirmed missing, stay missing until a true upgrade.
				if ( prev === false || nextConnector === false ) {
					return false
				}
				return null
			} )
			if ( window.ahentic?.aiPlugin && typeof window.ahentic.aiPlugin === 'object' ) {
				const prev = window.ahentic.aiPlugin
				const isReady = nextReady || Boolean( prev.isReady )
				const prevConnector = normalizeHasConnector( prev.hasConnector )
				let hasConnectorNext = nextConnector
				if ( hasConnectorNext !== true && prevConnector === true ) {
					hasConnectorNext = true
				} else if ( hasConnectorNext === null && prevConnector === false ) {
					hasConnectorNext = false
				}
				window.ahentic.aiPlugin = {
					...prev,
					...status,
					isReady,
					hasConnector: hasConnectorNext,
					canGenerate: isReady && hasConnectorNext === true,
				}
			}
			return nextConnector
		}

		try {
			let nextConnector = applyStatus( await getAiPluginStatus() )
			if ( nextConnector === null ) {
				await new Promise( resolve => setTimeout( resolve, 750 ) )
				nextConnector = applyStatus( await getAiPluginStatus() )
			}
			return nextConnector
		} catch {
			// Keep boot values — offline / permission errors should not clear a
			// previously green composer.
			return normalizeHasConnector( window.ahentic?.aiPlugin?.hasConnector )
		}
	}, [] )

	// Once per page lifetime (sidebar mount). Mid-session health is chat errors.
	useEffect( () => {
		syncAiPluginStatus()
	}, [ syncAiPluginStatus ] )

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

		const tabTitle = tabsRef.current.find( tab => tab.id === tabId )?.title || defaultAgentTitle()

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
								title: session.title || tab.title || defaultAgentTitle(),
								createdAt: tab.createdAt || Date.now(),
								status: session.status || 'idle',
							}
							: tab
					) ) )
					setActiveTabId( current => ( current === tabId ? id : current ) )
					setSessionsById( sessions => remapSessionRecord(
						sessions,
						tabId,
						id,
						{
							messages: mapEntriesToMessages( session.messages ),
							status: session.status || 'idle',
							trace: Array.isArray( session.trace ) ? session.trace : [],
						}
					) )
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

	// Promote local tab shells (tab_*) to real sessions before the first send so
	// createSession + activeTabId remap cannot race the optimistic busy state.
	const promotingTabsRef = useRef( new Set() )
	useEffect( () => {
		if ( ! open ) {
			return
		}

		const promote = async () => {
			const localTabs = tabsRef.current.filter( tab => (
				! isSessionId( tab.id ) && ! promotingTabsRef.current.has( tab.id )
			) )
			if ( ! localTabs.length ) {
				return
			}

			for ( const tab of localTabs ) {
				const previousId = tab.id
				promotingTabsRef.current.add( previousId )
				try {
					const session = await createSession( {
						mode,
						title: tab.title && tab.title !== defaultAgentTitle() ? tab.title : undefined,
					} )
					const id = String( session.id )
					setTabs( current => {
						if ( ! current.some( item => item.id === previousId ) ) {
							return current
						}
						return current.map( item => (
							item.id === previousId
								? {
									id,
									title: session.title || item.title || defaultAgentTitle(),
									createdAt: item.createdAt || Date.now(),
									status: session.status || 'idle',
								}
								: item
						) )
					} )
					setActiveTabId( current => ( current === previousId ? id : current ) )
					setSessionsById( sessions => {
						const prior = getSessionRecord( sessions, previousId )
						const seedMessages = prior.messages.length
							? prior.messages
							: mapEntriesToMessages( session.messages )
						return remapSessionRecord( sessions, previousId, id, {
							...prior,
							messages: seedMessages,
							status: session.status || 'idle',
						} )
					} )
					sessionStampRef.current[ id ] = sessionFingerprint( session )
					sessionMetaRef.current[ id ] = extractSessionMeta( session )
					markHydrated( id )
				} catch {
					// First send still creates a session as a fallback.
				} finally {
					promotingTabsRef.current.delete( previousId )
				}
			}
		}

		promote()
	}, [ open, mode, markHydrated ] )

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

	/**
	 * When opening a floating sidebar, nudge it back into the viewport and
	 * restore unusable sizes. Closing never repositions.
	 */
	const recoverFloatingChrome = useCallback( () => {
		if ( isMobileRef.current || ! isFloatingPlacement( placementRef.current ) ) {
			return
		}
		const placementNow = placementRef.current
		const current = floatRectRef.current || getDefaultFloatingRect( placementNow, widthRef.current )
		const next = recoverFloatingRectOnOpen( current, placementNow )
		if (
			next.left !== current.left ||
			next.top !== current.top ||
			next.width !== current.width ||
			next.height !== current.height
		) {
			setFloatRect( next )
			if ( next.width !== widthRef.current ) {
				setWidth( next.width )
			}
		}
	}, [] )

	const toggleSidebar = useCallback( () => {
		if ( openRef.current ) {
			setOpen( false )
			return
		}
		recoverFloatingChrome()
		setOpen( true )
	}, [ recoverFloatingChrome ] )

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
			toggleSidebar()
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
	}, [ toggleSidebar ] )

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
			toggleSidebar()
		}

		link.addEventListener( 'click', onClick )
		return () => link.removeEventListener( 'click', onClick )
	}, [ adminBarId, toggleSidebar ] )

	useEffect( () => {
		const node = document.getElementById( `wp-admin-bar-${ adminBarId }` )
		const link = node?.querySelector( ':scope > .ab-item' )
		if ( ! link ) {
			return
		}
		link.setAttribute( 'aria-expanded', open ? 'true' : 'false' )
		node.classList.toggle( 'is-ahentic-open', open )
	}, [ open, adminBarId ] )

	const activeSession = getSessionRecord( sessionsById, activeTabId )
	const activeMessages = activeSession.messages
	const activeTrace = activeSession.trace
	const activeStatus = activeSession.status || 'idle'
	const activeProgress = activeSession.progress
	const activeApproving = activeSession.approving || ''
	const activePendingTool = activeApproving
		? null
		: ( activeSession.pendingTool || null )
	const activePlan = activeSession.plan || null
	const activeThought = activeSession.thought || null
	const activeTab = tabs.find( tab => tab.id === activeTabId )
	const isBusy = sending ||
		Boolean( activeApproving ) ||
		activeStatus === 'running' ||
		activeStatus === 'awaiting_human' ||
		activeStatus === 'awaiting_browser'
	const isViewerSession = useMemo( () => {
		// lockRevision forces re-read after storage / heartbeat updates.
		void lockRevision
		if ( ! isSessionId( activeTabId ) ) {
			return false
		}
		const status = activeApproving ? 'running' : activeStatus
		const liveOrStopping = isActiveRunStatus( status ) || stopping
		if ( ! liveOrStopping ) {
			return false
		}
		return runnerLock.isViewer( activeTabId )
	}, [ activeTabId, activeStatus, activeApproving, stopping, lockRevision, runnerLock ] )
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
	const isJobResumable = Boolean( activeSession.jobResumable ) &&
		! isBusy &&
		! isViewerSession
	const sessionSoftBudgetCode = window.ahentic?.tokenLimitCodes?.sessionSoft || 'ahentic_session_token_budget'
	const isSessionSoftBudgetPause = isJobResumable &&
		activeSession.lastErrorCode === sessionSoftBudgetCode
	const continueLiveness = isHeartbeatDead && ! isViewerSession
		? 'stuck'
		: ( isSessionSoftBudgetPause
			? 'token_budget'
			: ( isJobResumable ? 'resumable' : '' ) )
	const showRunFeedback = shouldShowRunFeedback(
		activeSession,
		Boolean( feedbackDismissed[ String( activeTabId ) ] )
	) && ! isViewerSession
	const dismissRunFeedback = useCallback( () => {
		const id = String( activeTabId )
		setFeedbackDismissed( current => ( {
			...current,
			[ id ]: true,
		} ) )
	}, [ activeTabId ] )

	// Clear dismiss when a new run starts so the next idle can ask again.
	useEffect( () => {
		if ( activeSession.status === 'idle' ) {
			return
		}
		setFeedbackDismissed( current => {
			const id = String( activeTabId )
			if ( ! current[ id ] ) {
				return current
			}
			const next = { ...current }
			delete next[ id ]
			return next
		} )
	}, [ activeSession.status, activeTabId ] )
	// Existing session tabs show a spinner until fetched; only while the sidebar is open.
	const isSessionLoading = useMemo(
		() => open && isSessionId( activeTabId ) && ! hydratedRef.current.has( activeTabId ),
		// hydratedVersion: hydratedRef alone would not re-render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ open, activeTabId, hydratedVersion ]
	)

	const runningSessionKey = useMemo( () => {
		const ids = new Set()
		Object.entries( sessionsById ).forEach( ( [ id, record ] ) => {
			if ( record.status === 'running' || record.status === 'awaiting_browser' || record.pollWatch ) {
				ids.add( id )
			}
		} )
		return [ ...ids ].sort().join( ',' )
	}, [ sessionsById ] )

	const { clearBrowserResumesForSession } = useBrowserResume( {
		activeTabId,
		activeStatus,
		activeSession,
		isViewerSession,
		sessionsByIdRef,
		runnerLock,
		claimRunner,
		applySession,
	} )

	useSessionPoll( {
		runningSessionKey,
		sessionsByIdRef,
		applySession,
		runnerLock,
	} )

	const openSidebar = useCallback( () => {
		if ( ! openRef.current ) {
			recoverFloatingChrome()
		}
		setOpen( true )
	}, [ recoverFloatingChrome ] )
	const closeSidebar = useCallback( () => setOpen( false ), [] )

	const selectTab = useCallback( id => {
		setSendError( '' )
		setSendErrorCode( '' )
		setActiveTabId( id )
	}, [] )

	const addTab = useCallback( async () => {
		try {
			const session = await createSession( { mode } )
			const id = String( session.id )
			const tab = {
				id,
				title: session.title || defaultAgentTitle(),
				createdAt: Date.now(),
				status: session.status || 'idle',
			}
			setTabs( current => [ ...current, tab ] )
			setSessionsById( sessions => patchSessionRecord( sessions, id, {
				messages: mapEntriesToMessages( session.messages ),
				status: session.status || 'idle',
				trace: Array.isArray( session.trace ) ? session.trace : [],
			} ) )
			sessionStampRef.current[ id ] = sessionFingerprint( session )
			sessionMetaRef.current[ id ] = extractSessionMeta( session )
			markHydrated( id )
			setActiveTabId( id )
			openSidebar()
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			window.alert( error.message || __( 'Could not create a new session.', 'ahentic' ) )
		}
	}, [ mode, markHydrated, openSidebar ] )

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
					title: session.title || defaultAgentTitle(),
					createdAt: Date.now(),
					status: session.status || 'idle',
				} ] )
				setActiveTabId( nextId )
				setSessionsById( {
					[ nextId ]: {
						...createEmptySessionRecord(),
						messages: mapEntriesToMessages( session.messages ),
						status: session.status || 'idle',
						trace: Array.isArray( session.trace ) ? session.trace : [],
					},
				} )
			} catch ( error ) {
				// eslint-disable-next-line no-alert
				window.alert( error.message || __( 'Could not start a new session.', 'ahentic' ) )
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
			setSessionsById( sessions => omitSessionRecord( sessions, id ) )
			hydratedRef.current.delete( id )
			delete sessionStampRef.current[ id ]
			delete sessionMetaRef.current[ id ]
			delete pendingLocalRef.current[ id ]
			setHydratedVersion( version => version + 1 )
			return next
		} )
	}, [ mode ] )

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
				title: session.title || defaultAgentTitle(),
				createdAt: Date.now(),
				status: 'idle',
			} ] )
			setActiveTabId( id )
			setSessionsById( {
				[ id ]: {
					...createEmptySessionRecord(),
					messages: mapEntriesToMessages( session.messages ),
					status: 'idle',
					trace: Array.isArray( session.trace ) ? session.trace : [],
				},
			} )
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			window.alert( error.message || __( 'Could not reset sessions.', 'ahentic' ) )
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

		const currentStatus = getSessionRecord( sessionsByIdRef.current, activeTabId ).status || 'idle'
		// Mid-run sends are rejected by the server (409) except during HITL redirect.
		if (
			currentStatus === 'running' ||
			currentStatus === 'awaiting_browser' ||
			getSessionRecord( sessionsByIdRef.current, activeTabId ).approving
		) {
			setSendError( __( 'This session is still working. Wait for it to finish.', 'ahentic' ) )
			setSendErrorCode( '' )
			return false
		}

		if ( isSessionId( activeTabId ) && runnerLock.isViewer( activeTabId ) ) {
			setSendError( VIEWER_ACTIVE_ELSEWHERE )
			setSendErrorCode( '' )
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
				setSessionsById( sessions => patchSessionRecord( sessions, activeTabId, record => ( {
					...record,
					messages: [
						...record.messages,
						{
							id: `err_${ Date.now() }`,
							role: 'assistant',
							content: error.message || __( 'Could not create a session.', 'ahentic' ),
						},
					],
				} ) ) )
				return false
			}
		}

		if ( ! claimRunner( sessionId ) ) {
			setSendError( VIEWER_ACTIVE_ELSEWHERE )
			setSendErrorCode( '' )
			return false
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

		setSessionsById( sessions => patchSessionRecord( sessions, sessionId, record => ( {
			...record,
			messages: [ ...record.messages, optimisticUser ],
			status: 'running',
			progress: {
				label: __( 'Planning next steps…', 'ahentic' ),
				updatedAt: '',
				heartbeatAt: '',
				seenAt: Date.now(),
			},
			pollWatch: true,
			trace: [],
			pendingTool: null,
			plan: null,
		} ) ) )
		setTabs( current => current.map( tab => {
			if ( tab.id !== sessionId ) {
				return tab
			}
			if ( tab.title && tab.title !== defaultAgentTitle() ) {
				return tab
			}
			return { ...tab, title: truncateTitle( text ) }
		} ) )
		// Floor freshness so a raced poll (same user turn, still idle) cannot
		// clobber busy chrome before postMessage / the worker advances.
		// Reset stepCount: each start_message zeroes steps server-side; keeping the
		// prior run's stepCount makes real polls look "stale" (step 1 < known 6).
		sessionMetaRef.current[ sessionId ] = {
			...metaBeforeSend,
			status: 'running',
			progressAt: Date.now(),
			stepCount: 0,
			messageCount: Math.max( metaBeforeSend.messageCount, metaBeforeSend.messageCount + 1 ),
			lastSeq: Math.max( metaBeforeSend.lastSeq, metaBeforeSend.lastSeq + 1 ),
		}
		setSending( true )
		setSendError( '' )
		setSendErrorCode( '' )

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
			setSendErrorCode( '' )
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
			setSessionsById( sessions => patchSessionRecord( sessions, sessionId, record => ( {
				...record,
				messages: record.messages.filter( message => message.id !== optimisticUser.id ),
			} ) ) )
			// Restore pre-send freshness so a poll cannot race ahead of reconcile.
			sessionMetaRef.current[ sessionId ] = metaBeforeSend

			// Reconcile with the server (keeps real running/awaiting_* status; restores trace).
			try {
				const session = await getSession( sessionId )
				applySession( session, { force: true } )
			} catch {
				// POST may have succeeded server-side while the response failed — keep
				// polling rather than freezing the UI on idle with a live worker.
				setSessionsById( sessions => patchSessionRecord( sessions, sessionId, {
					status: 'running',
					pollWatch: true,
				} ) )
			}

			// Composer restores the draft when we return false; surface why send failed.
			setSendError( error.message || __( 'Request failed.', 'ahentic' ) )
			setSendErrorCode( error.code || '' )
			setFocusSignal( value => value + 1 )
			if ( ! isActiveRunStatus( getSessionRecord( sessionsByIdRef.current, sessionId ).status || '' ) ) {
				releaseRunner( sessionId )
			}
			return false
		} finally {
			setSending( false )
		}
	}, [ activeTabId, mode, sending, markHydrated, applySession, canGenerate, runnerLock, claimRunner, releaseRunner ] )

	const continueStuckSession = useCallback( async () => {
		if ( ! isSessionId( activeTabId ) || isViewerSession ) {
			return
		}
		const sessionId = activeTabId
		if ( ! claimRunner( sessionId ) ) {
			return
		}
		setSessionsById( sessions => patchSessionRecord( sessions, sessionId, record => ( {
			...record,
			status: 'running',
			pollWatch: true,
			jobResumable: false,
			lastErrorCode: '',
			progress: {
				label: __( 'Planning next steps…', 'ahentic' ),
				updatedAt: new Date().toISOString(),
				heartbeatAt: new Date().toISOString(),
				seenAt: Date.now(),
			},
		} ) ) )
		try {
			const session = await continueSession( sessionId )
			if ( session ) {
				applySession( session, { force: true } )
			}
		} catch ( error ) {
			setSendError( error.message || __( 'Could not continue this run.', 'ahentic' ) )
			setSendErrorCode( error.code || '' )
			releaseRunner( sessionId )
		}
	}, [ activeTabId, applySession, isViewerSession, claimRunner, releaseRunner ] )

	const stopSession = useCallback( async () => {
		if ( ! isSessionId( activeTabId ) || stopping ) {
			return
		}

		const sessionId = activeTabId
		stopRequestedRef.current[ sessionId ] = true
		setStopping( true )
		setSendError( '' )
		setSendErrorCode( '' )

		// Optimistically unlock the composer while the cancel request lands.
		setSessionsById( sessions => patchSessionRecord( sessions, sessionId, record => ( {
			...record,
			status: 'idle',
			pollWatch: false,
			progress: null,
			pendingTool: null,
			approving: '',
			jobResumable: false,
			lastErrorCode: '',
			plan: cancelIncompletePlanSteps( record.plan ),
		} ) ) )
		setSending( false )

		// Drop any in-flight browser resume for this tab.
		clearBrowserResumesForSession( sessionId )

		try {
			const session = await cancelSession( sessionId )
			applySession( session, { force: true } )
			releaseRunner( sessionId )
			setFocusSignal( value => value + 1 )
		} catch ( error ) {
			setSendError( error.message || __( 'Could not stop the run.', 'ahentic' ) )
			setSendErrorCode( error.code || '' )
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
	}, [ activeTabId, stopping, applySession, releaseRunner, clearBrowserResumesForSession ] )

	const onApproval = useCallback( async decision => {
		if ( ! isSessionId( activeTabId ) || isViewerSession ) {
			return
		}
		const sessionId = activeTabId
		if ( getSessionRecord( sessionsByIdRef.current, sessionId ).approving ) {
			return
		}
		if ( ! claimRunner( sessionId ) ) {
			return
		}

		const pending = getSessionRecord( sessionsByIdRef.current, sessionId ).pendingTool || null
		const previousStatus = getSessionRecord( sessionsByIdRef.current, sessionId ).status || 'awaiting_human'
		const optimisticLabel = decision === 'deny'
			? __( 'Skipping that action…', 'ahentic' )
			: progressLabelForAbility( pending?.name || '' )

		// Optimistic: hide HITL, show live status, start polling via running.
		setSessionsById( sessions => patchSessionRecord( sessions, sessionId, {
			approving: decision,
			pendingTool: null,
			status: 'running',
			progress: {
				label: optimisticLabel,
				updatedAt: new Date().toISOString(),
				heartbeatAt: '',
				seenAt: Date.now(),
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
			setSessionsById( sessions => patchSessionRecord( sessions, sessionId, {
				status: previousStatus,
				pendingTool: pending,
				progress: {
					label: __( 'Waiting for your approval…', 'ahentic' ),
					updatedAt: new Date().toISOString(),
					heartbeatAt: '',
					seenAt: Date.now(),
				},
			} ) )
			throw error
		} finally {
			setSessionsById( sessions => patchSessionRecord( sessions, sessionId, {
				approving: '',
			} ) )
		}
	}, [ activeTabId, applySession, isViewerSession, claimRunner ] )

	const onSuggestedAction = useCallback( async action => {
		if ( ! isSessionId( activeTabId ) || ! action ) {
			return
		}
		if ( action.type === 'link' && action.url ) {
			openLink( action.url )
			return
		}
		if ( isViewerSession || ! claimRunner( activeTabId ) ) {
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
	}, [ activeTabId, applySession, isViewerSession, claimRunner ] )

	const onSuggestedPrompt = useCallback( prompt => {
		sendMessage( prompt )
	}, [ sendMessage ] )

	const {
		startDockResize,
		startFloatResize,
		startFloatDrag,
		floating,
		panelStyle,
	} = useFloatInteraction( {
		isMobile,
		placement,
		floatRect,
		width,
		setFloatRect,
		setWidth,
	} )

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
					aria-label={ __( 'Open Ahentic sidebar', 'ahentic' ) }
					title={ sprintf(
						/* translators: %s: keyboard shortcut */
						__( 'Open Ahentic (%s)', 'ahentic' ),
						shortcutLabel
					) }
				>
					<AhenticLogo size={ 18 } />
				</button>
			) }

			{ open && isMobile && (
				<button
					type="button"
					className="ahentic-backdrop"
					aria-label={ __( 'Close Ahentic sidebar', 'ahentic' ) }
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
				aria-label={ __( 'Ahentic AI sidebar', 'ahentic' ) }
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
						aria-label={ __( 'Resize Ahentic sidebar', 'ahentic' ) }
					/>
				) }

				{ floating && (
					<FloatHandles onResizeStart={ startFloatResize } />
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
					onClearAll={ clearAllTabs }
					debugOpen={ debugOpen }
					onToggleDebug={ () => setDebugOpen( value => ! value ) }
				/>

				<div
					className={ classnames( 'ahentic-session-pane', {
						'is-viewer': isViewerSession,
					} ) }
				>
					{ debugOpen ? (
						<DebuggerPanel
							trace={ activeTrace }
							sessionId={ isSessionId( activeTabId ) ? activeTabId : 0 }
							isBusy={ isBusy }
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
							progressHint={
								! isViewerSession &&
								( activeApproving ? 'running' : activeStatus ) === 'awaiting_browser'
									? __( 'Keep this tab visible while this runs', 'ahentic' )
									: ''
							}
							pendingTool={ activePendingTool }
							plan={ activePlan }
							thoughtProcess={ isBusy ? ( activeThought?.text || '' ) : '' }
							sessionStatus={ activeApproving ? 'running' : activeStatus }
							approvingDecision={ activeApproving }
							onApproval={ isViewerSession ? undefined : onApproval }
							onSuggestedAction={ isViewerSession ? undefined : onSuggestedAction }
							liveness={ continueLiveness }
							onContinue={ isViewerSession ? undefined : continueStuckSession }
							onCancelRun={ stopSession }
						/>
					) }

					{ showRunFeedback ? (
						<RunFeedbackBar
							sessionId={ activeTabId }
							onDismiss={ dismissRunFeedback }
							disabled={ isBusy || Boolean( activePendingTool ) }
						/>
					) : null }

					<Composer
						mode={ mode }
						onModeChange={ setMode }
						onSubmit={ sendMessage }
						focusSignal={ focusSignal }
						shortcutLabel={ shortcutLabel }
						error={ sendError }
						errorCode={ sendErrorCode }
						settingsUrl={ window.ahentic?.settingsUrl || '' }
						onClearError={ () => {
							setSendError( '' )
							setSendErrorCode( '' )
						} }
						contextUsage={ activeSession.contextUsage || null }
						tokensIn={ activeSession.tokensIn || 0 }
						tokensOut={ activeSession.tokensOut || 0 }
						tokensUsed={ activeSession.tokensUsed || 0 }
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
							activeStatus === 'awaiting_browser' ||
							isViewerSession ||
							isSessionSoftBudgetPause
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
									? ( pluginInstalled
										? __( 'Activate WordPress AI to continue chatting.', 'ahentic' )
										: __( 'Install WordPress AI to start chatting.', 'ahentic' )
									)
									: ( hasConnector === false
										? __( 'Add an AI connector in Settings → Connectors to start chatting.', 'ahentic' )
										: ( hasConnector === null
											? checkingModelConnectionLabel()
											: ''
										)
									)
								)
						}
						connectorsUrl={
							! canGenerate && activeMessages.length > 0 && hasConnector === false
								? connectorsUrl
								: ''
						}
						blockedCtaLabel={
							! canGenerate && activeMessages.length > 0 && ! aiReady
								? ( pluginInstalled
									? __( 'Activate', 'ahentic' )
									: __( 'Install & Activate', 'ahentic' )
								)
								: ''
						}
					/>

					{ isViewerSession ? (
						<ViewerOverlay
							onStop={ stopSession }
							stopping={ stopping }
						/>
					) : null }
				</div>
			</aside>
		</div>
	)
}
