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
import classnames from 'classnames'
import Toolbar from './toolbar'
import TabBar from './tab-bar'
import TabContent from './tab-content'
import Composer from './composer'
import {
	MOBILE_BREAKPOINT,
	MIN_WIDTH,
	MAX_WIDTH,
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
	mapEntriesToMessages,
	isSessionId,
} from './api'

const POLL_MS = 650
const STALL_MS = 2500

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
 * Apply a session payload into local tab + message / trace / progress state.
 *
 * @param {Object}   session
 * @param {Function} setTabs
 * @param {Function} setMessagesByTab
 * @param {Function} [setStatusByTab]
 * @param {Function} [setTraceByTab]
 * @param {Function} [setProgressByTab]
 */
function applySessionPayload( session, setTabs, setMessagesByTab, setStatusByTab, setTraceByTab, setProgressByTab ) {
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
		setMessagesByTab( messages => ( {
			...messages,
			[ id ]: mapEntriesToMessages( session.messages ),
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
		setProgressByTab( progress => {
			if ( ! label ) {
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
					label,
					updatedAt: session.progress?.updatedAt || '',
				},
			}
		} )
	}
}

export default function Sidebar() {
	const initial = useMemo( () => loadPersistedState(), [] )
	const [ open, setOpen ] = useState( initial.open )
	const [ width, setWidth ] = useState( initial.width )
	const [ theme ] = useState( initial.theme )
	const [ mode, setMode ] = useState( initial.mode )
	const [ tabs, setTabs ] = useState( initial.tabs )
	const [ activeTabId, setActiveTabId ] = useState( initial.activeTabId )
	const [ messagesByTab, setMessagesByTab ] = useState( {} )
	const [ statusByTab, setStatusByTab ] = useState( {} )
	const [ progressByTab, setProgressByTab ] = useState( {} )
	const [ traceByTab, setTraceByTab ] = useState( {} )
	const [ debugOpen, setDebugOpen ] = useState( false )
	const [ sending, setSending ] = useState( false )
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
	const [ bootstrapped, setBootstrapped ] = useState( false )

	const resizingRef = useRef( false )
	const hydratedRef = useRef( new Set() )
	const shortcutLabel = useMemo( () => getShortcutLabel(), [] )
	const context = window.ahentic?.context || {}
	const adminBarId = window.ahentic?.adminBarId || 'ahentic-toggle'
	const aiPlugin = window.ahentic?.aiPlugin || {}

	// Persist chrome state (not message bodies).
	useEffect( () => {
		savePersistedState( {
			open,
			width,
			theme,
			mode,
			tabs,
			activeTabId,
		} )
	}, [ open, width, theme, mode, tabs, activeTabId ] )

	// Push page content on desktop.
	useEffect( () => {
		syncPageInset( {
			open, width, isMobile,
		} )
		return () => clearPageInset()
	}, [ open, width, isMobile ] )

	// Responsive breakpoint.
	useEffect( () => {
		const onResize = () => {
			setIsMobile( window.innerWidth < MOBILE_BREAKPOINT )
		}
		window.addEventListener( 'resize', onResize )
		return () => window.removeEventListener( 'resize', onResize )
	}, [] )

	// Bootstrap: ensure every open tab is a real ahentic-session post.
	useEffect( () => {
		let cancelled = false

		const bootstrap = async () => {
			const nextTabs = []
			const nextMessages = {}
			const nextStatuses = {}
			const nextTraces = {}
			const nextProgress = {}

			for ( const tab of tabs ) {
				if ( cancelled ) {
					return
				}

				if ( isSessionId( tab.id ) ) {
					try {
						const session = await getSession( tab.id )
						if ( cancelled ) {
							return
						}
						const sid = String( session.id )
						nextTabs.push( {
							id: sid,
							title: session.title || tab.title || 'New Agent',
							createdAt: tab.createdAt || Date.now(),
							status: session.status,
						} )
						nextMessages[ sid ] = mapEntriesToMessages( session.messages )
						nextStatuses[ sid ] = session.status || 'idle'
						nextTraces[ sid ] = Array.isArray( session.trace ) ? session.trace : []
						if ( session.progress?.label ) {
							nextProgress[ sid ] = {
								label: session.progress.label,
								updatedAt: session.progress.updatedAt || '',
							}
						}
						hydratedRef.current.add( sid )
						continue
					} catch ( error ) {
						// Fall through and create a fresh session.
					}
				}

				try {
					const session = await createSession( { mode, title: tab.title || 'New Agent' } )
					if ( cancelled ) {
						return
					}
					const id = String( session.id )
					nextTabs.push( {
						id,
						title: session.title || 'New Agent',
						createdAt: Date.now(),
						status: session.status || 'idle',
					} )
					nextMessages[ id ] = mapEntriesToMessages( session.messages )
					nextStatuses[ id ] = session.status || 'idle'
					nextTraces[ id ] = Array.isArray( session.trace ) ? session.trace : []
					hydratedRef.current.add( id )
				} catch ( error ) {
					// Keep a local placeholder if REST fails (AI not ready, etc.).
					nextTabs.push( tab )
				}
			}

			if ( cancelled ) {
				return
			}

			if ( ! nextTabs.length ) {
				try {
					const session = await createSession( { mode } )
					const id = String( session.id )
					nextTabs.push( {
						id,
						title: session.title || 'New Agent',
						createdAt: Date.now(),
						status: 'idle',
					} )
					nextMessages[ id ] = []
					nextStatuses[ id ] = 'idle'
					nextTraces[ id ] = []
					hydratedRef.current.add( id )
				} catch ( error ) {
					// Leave empty; user can retry by opening a new tab.
				}
			}

			setTabs( nextTabs )
			setMessagesByTab( current => {
				const merged = { ...nextMessages }
				// Do not wipe messages if the user already sent while bootstrap was in flight.
				Object.keys( current ).forEach( id => {
					if ( Array.isArray( current[ id ] ) && current[ id ].length ) {
						const incoming = merged[ id ]
						if ( ! incoming || incoming.length < current[ id ].length ) {
							merged[ id ] = current[ id ]
						}
					}
				} )
				return merged
			} )
			setStatusByTab( nextStatuses )
			setTraceByTab( nextTraces )
			setProgressByTab( nextProgress )
			setActiveTabId( current => {
				if ( nextTabs.some( tab => tab.id === current ) ) {
					return current
				}
				return nextTabs[ 0 ]?.id || current
			} )
			setBootstrapped( true )
		}

		bootstrap()

		return () => {
			cancelled = true
		}
		// Run once on mount.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] )

	// Hydrate messages when switching to a session tab.
	useEffect( () => {
		if ( ! bootstrapped || ! isSessionId( activeTabId ) ) {
			return undefined
		}
		if ( hydratedRef.current.has( activeTabId ) ) {
			return undefined
		}

		let cancelled = false
		;( async () => {
			try {
				const session = await getSession( activeTabId )
				if ( cancelled ) {
					return
				}
				hydratedRef.current.add( activeTabId )
				applySessionPayload( session, setTabs, setMessagesByTab, setStatusByTab, setTraceByTab, setProgressByTab )
			} catch ( error ) {
				// Ignore hydrate failures.
			}
		} )()

		return () => {
			cancelled = true
		}
	}, [ activeTabId, bootstrapped ] )

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
	const activeTab = tabs.find( tab => tab.id === activeTabId )
	const isBusy = sending || activeStatus === 'running' || activeStatus === 'awaiting_human' || activeStatus === 'awaiting_browser'
	const progressLabel = activeProgress?.label || ( isBusy ? 'Ahentic is thinking…' : '' )

	const runningSessionKey = useMemo( () => (
		Object.entries( statusByTab )
			.filter( ( [ , status ] ) => status === 'running' )
			.map( ( [ id ] ) => id )
			.sort()
			.join( ',' )
	), [ statusByTab ] )

	// Poll running sessions for live progress + final messages.
	useEffect( () => {
		if ( ! runningSessionKey ) {
			return undefined
		}

		const ids = runningSessionKey.split( ',' ).filter( Boolean )
		let cancelled = false
		const continueInFlight = new Set()
		const seenAt = {}

		const apply = session => {
			applySessionPayload(
				session,
				setTabs,
				setMessagesByTab,
				setStatusByTab,
				setTraceByTab,
				setProgressByTab
			)
		}

		const isStalled = session => {
			if ( session?.status !== 'running' ) {
				return false
			}
			const label = session.progress?.label || ''
			const updatedAt = session.progress?.updatedAt || ''
			const key = `${ label }|${ updatedAt }|${ session.stepCount || 0 }`
			const now = Date.now()
			if ( ! seenAt[ session.id ] || seenAt[ session.id ].key !== key ) {
				seenAt[ session.id ] = { key, since: now }
				return false
			}
			return ( now - seenAt[ session.id ].since ) >= STALL_MS
		}

		const pollOne = async id => {
			try {
				const session = await getSession( id )
				if ( cancelled ) {
					return
				}
				apply( session )

				if ( isStalled( session ) && ! continueInFlight.has( id ) ) {
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
	}, [ runningSessionKey ] )

	const openSidebar = useCallback( () => setOpen( true ), [] )
	const closeSidebar = useCallback( () => setOpen( false ), [] )

	const selectTab = useCallback( id => {
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
			hydratedRef.current.add( id )
			setActiveTabId( id )
			setOpen( true )
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			window.alert( error.message || 'Could not create a new session.' )
		}
	}, [ mode ] )

	const closeTab = useCallback( id => {
		setTabs( current => {
			if ( current.length <= 1 ) {
				return current
			}
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
			hydratedRef.current.delete( id )
			return next
		} )
	}, [] )

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
			hydratedRef.current.add( id )
			setActiveTabId( id )
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			window.alert( error.message || 'Could not duplicate session.' )
		}
	}, [ tabs, activeTabId, mode ] )

	const clearAllTabs = useCallback( async () => {
		try {
			const session = await createSession( { mode } )
			const id = String( session.id )
			hydratedRef.current = new Set( [ id ] )
			setTabs( [ {
				id,
				title: session.title || 'New Agent',
				createdAt: Date.now(),
				status: 'idle',
			} ] )
			setActiveTabId( id )
			setMessagesByTab( { [ id ]: [] } )
			setStatusByTab( { [ id ]: 'idle' } )
			setTraceByTab( { [ id ]: [] } )
			setProgressByTab( {} )
		} catch ( error ) {
			// eslint-disable-next-line no-alert
			window.alert( error.message || 'Could not reset sessions.' )
		}
	}, [ mode ] )

	const sendMessage = useCallback( async text => {
		if ( ! text?.trim() || sending || ! bootstrapped ) {
			return
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
				hydratedRef.current.add( sessionId )
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
				return
			}
		}

		const optimisticUser = {
			id: `local_u_${ Date.now() }`,
			role: 'user',
			content: text.trim(),
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
				label: 'Starting…',
				updatedAt: '',
			},
		} ) )
		setSending( true )
		setFocusSignal( value => value + 1 )

		try {
			const session = await postMessage( sessionId, {
				content: text.trim(),
				mode,
			} )
			applySessionPayload( session, setTabs, setMessagesByTab, setStatusByTab, setTraceByTab, setProgressByTab )
		} catch ( error ) {
			setMessagesByTab( messages => ( {
				...messages,
				[ sessionId ]: [
					...( messages[ sessionId ] || [] ),
					{
						id: `err_${ Date.now() }`,
						role: 'assistant',
						content: error.message || 'Request failed.',
						meta: { error: true },
					},
				],
			} ) )
			setStatusByTab( statuses => ( {
				...statuses,
				[ sessionId ]: 'idle',
			} ) )
		} finally {
			setSending( false )
		}
	}, [ activeTabId, mode, sending, bootstrapped ] )

	const onSuggestedPrompt = useCallback( prompt => {
		sendMessage( prompt )
	}, [ sendMessage ] )

	useEffect( () => {
		const onMove = event => {
			if ( ! resizingRef.current || isMobile ) {
				return
			}
			const next = clampWidth( window.innerWidth - event.clientX )
			setWidth( next )
		}
		const onUp = () => {
			resizingRef.current = false
			document.body.classList.remove( 'ahentic-is-resizing' )
		}
		window.addEventListener( 'mousemove', onMove )
		window.addEventListener( 'mouseup', onUp )
		return () => {
			window.removeEventListener( 'mousemove', onMove )
			window.removeEventListener( 'mouseup', onUp )
		}
	}, [ isMobile ] )

	const startResize = event => {
		if ( isMobile ) {
			return
		}
		event.preventDefault()
		resizingRef.current = true
		document.body.classList.add( 'ahentic-is-resizing' )
	}

	const panelStyle = isMobile
		? undefined
		: { width: `${ width }px` }

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
				} ) }
				style={ panelStyle }
				aria-label="Ahentic AI sidebar"
				aria-hidden={ ! open }
			>
				{ ! isMobile && (
					// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
					<div
						className="ahentic-resize"
						onMouseDown={ startResize }
						role="separator"
						aria-orientation="vertical"
						aria-valuemin={ MIN_WIDTH }
						aria-valuemax={ MAX_WIDTH }
						aria-valuenow={ width }
						aria-label="Resize Ahentic sidebar"
					/>
				) }

				<Toolbar
					onClose={ closeSidebar }
					shortcutLabel={ shortcutLabel }
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
						aiPlugin={ aiPlugin }
						onAiReady={ setAiReady }
						messages={ activeMessages }
						onSuggestedPrompt={ onSuggestedPrompt }
						ready={ bootstrapped }
						busy={ isBusy }
						progressLabel={ progressLabel }
					/>
				) }

				<Composer
					mode={ mode }
					onModeChange={ setMode }
					onSubmit={ sendMessage }
					focusSignal={ focusSignal }
					shortcutLabel={ shortcutLabel }
					context={ context }
				/>
			</aside>
		</div>
	)
}
