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
	createTab,
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

/**
 * Detect Cmd vs Ctrl for shortcut labels.
 *
 * @return {string} Human-readable shortcut label.
 */
function getShortcutLabel() {
	const isMac = typeof navigator !== 'undefined' &&
		/Mac|iPhone|iPad|iPod/.test( navigator.platform || navigator.userAgent || '' )
	return isMac ? '⌘L' : 'Ctrl+L'
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

export default function Sidebar() {
	const initial = useMemo( () => loadPersistedState(), [] )
	const [ open, setOpen ] = useState( initial.open )
	const [ width, setWidth ] = useState( initial.width )
	const [ theme ] = useState( initial.theme )
	const [ mode, setMode ] = useState( initial.mode )
	const [ tabs, setTabs ] = useState( initial.tabs )
	const [ activeTabId, setActiveTabId ] = useState( initial.activeTabId )
	const [ messagesByTab, setMessagesByTab ] = useState( {} )
	const [ focusSignal, setFocusSignal ] = useState( 0 )
	const [ isMobile, setIsMobile ] = useState(
		() => typeof window !== 'undefined' && window.innerWidth < MOBILE_BREAKPOINT
	)
	const [ historyNotice, setHistoryNotice ] = useState( false )

	const resizingRef = useRef( false )
	const shortcutLabel = useMemo( () => getShortcutLabel(), [] )
	const context = window.ahentic?.context || {}

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

	// Global Cmd/Ctrl+L toggle.
	useEffect( () => {
		const onKeyDown = event => {
			const key = event.key?.toLowerCase()
			if ( key === 'l' && ( event.metaKey || event.ctrlKey ) && ! event.altKey ) {
				event.preventDefault()
				setOpen( value => ! value )
			}
		}
		window.addEventListener( 'keydown', onKeyDown )
		return () => window.removeEventListener( 'keydown', onKeyDown )
	}, [] )

	// Focus composer when opening or switching tabs.
	useEffect( () => {
		if ( open ) {
			setFocusSignal( value => value + 1 )
		}
	}, [ open, activeTabId ] )

	const activeMessages = messagesByTab[ activeTabId ] || []

	const openSidebar = useCallback( () => setOpen( true ), [] )
	const closeSidebar = useCallback( () => setOpen( false ), [] )

	const selectTab = useCallback( id => {
		setActiveTabId( id )
	}, [] )

	const addTab = useCallback( () => {
		const tab = createTab()
		setTabs( current => [ ...current, tab ] )
		setActiveTabId( tab.id )
		setOpen( true )
	}, [] )

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
			return next
		} )
	}, [] )

	const renameActiveTab = useCallback( () => {
		const active = tabs.find( tab => tab.id === activeTabId )
		if ( ! active ) {
			return
		}
		// Temporary mock rename until an in-sidebar rename UI exists.
		// eslint-disable-next-line no-alert
		const nextTitle = window.prompt( 'Rename conversation', active.title )
		if ( nextTitle === null ) {
			return
		}
		const title = nextTitle.trim() || 'New Agent'
		setTabs( current => current.map( tab => (
			tab.id === activeTabId ? { ...tab, title } : tab
		) ) )
	}, [ tabs, activeTabId ] )

	const duplicateActiveTab = useCallback( () => {
		const active = tabs.find( tab => tab.id === activeTabId )
		if ( ! active ) {
			return
		}
		const tab = {
			...createTab(),
			title: `${ active.title } copy`,
		}
		setTabs( current => [ ...current, tab ] )
		setMessagesByTab( messages => ( {
			...messages,
			[ tab.id ]: [ ...( messages[ activeTabId ] || [] ) ],
		} ) )
		setActiveTabId( tab.id )
	}, [ tabs, activeTabId ] )

	const clearAllTabs = useCallback( () => {
		const tab = createTab()
		setTabs( [ tab ] )
		setActiveTabId( tab.id )
		setMessagesByTab( {} )
	}, [] )

	const sendMessage = useCallback( text => {
		const userMessage = {
			id: `msg_${ Date.now() }_u`,
			role: 'user',
			content: text,
		}
		const assistantMessage = {
			id: `msg_${ Date.now() }_a`,
			role: 'assistant',
			content: 'Mock response — server / AI wiring comes next. Your message was received in the UI only.',
		}

		setMessagesByTab( messages => ( {
			...messages,
			[ activeTabId ]: [ ...( messages[ activeTabId ] || [] ), userMessage, assistantMessage ],
		} ) )

		setTabs( current => current.map( tab => {
			if ( tab.id !== activeTabId ) {
				return tab
			}
			if ( tab.title !== 'New Agent' ) {
				return tab
			}
			return { ...tab, title: truncateTitle( text ) }
		} ) )

		setFocusSignal( value => value + 1 )
	}, [ activeTabId ] )

	const onSuggestedPrompt = useCallback( prompt => {
		sendMessage( prompt )
	}, [ sendMessage ] )

	// Resize handle.
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
			{ ! open && (
				<button
					type="button"
					className="ahentic-launcher"
					onClick={ openSidebar }
					aria-label="Open Ahentic sidebar"
					title={ `Open Ahentic (${ shortcutLabel })` }
				>
					A
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

				<Toolbar onClose={ closeSidebar } shortcutLabel={ shortcutLabel } />

				<TabBar
					tabs={ tabs }
					activeTabId={ activeTabId }
					onSelect={ selectTab }
					onClose={ closeTab }
					onNew={ addTab }
					onRename={ renameActiveTab }
					onDuplicate={ duplicateActiveTab }
					onClearAll={ clearAllTabs }
					onHistory={ () => {
						setHistoryNotice( true )
						window.setTimeout( () => setHistoryNotice( false ), 2200 )
					} }
				/>

				{ historyNotice && (
					<div className="ahentic-toast" role="status">
						History will be available once conversations are saved to the database.
					</div>
				) }

				<TabContent
					messages={ activeMessages }
					onSuggestedPrompt={ onSuggestedPrompt }
				/>

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
