/**
 * Scrollable agent tab strip + right-side actions.
 */

import {
	useEffect, useRef, useState,
} from '@wordpress/element'
import classnames from 'classnames'
import {
	MessageSquare, Plus, History, MoreHorizontal, X,
} from 'lucide-react'

/**
 * @param {Object}   props
 * @param {Array}    props.tabs
 * @param {string}   props.activeTabId
 * @param {Function} props.onSelect
 * @param {Function} props.onClose
 * @param {Function} props.onNew
 * @param {Function} props.onRename
 * @param {Function} props.onDuplicate
 * @param {Function} props.onClearAll
 * @param {Function} props.onHistory
 * @param {boolean}  props.debugOpen
 * @param {Function} props.onToggleDebug
 */
export default function TabBar( {
	tabs,
	activeTabId,
	onSelect,
	onClose,
	onNew,
	onRename,
	onDuplicate,
	onClearAll,
	onHistory,
	debugOpen = false,
	onToggleDebug,
} ) {
	const [ menuOpen, setMenuOpen ] = useState( false )
	const menuRef = useRef( null )

	useEffect( () => {
		if ( ! menuOpen ) {
			return undefined
		}

		const onPointerDown = event => {
			if ( menuRef.current && ! menuRef.current.contains( event.target ) ) {
				setMenuOpen( false )
			}
		}

		document.addEventListener( 'mousedown', onPointerDown )
		return () => document.removeEventListener( 'mousedown', onPointerDown )
	}, [ menuOpen ] )

	return (
		<div className="ahentic-tabbar">
			<div className="ahentic-tabbar__scroll" role="tablist" aria-label="Agent conversations">
				{ tabs.map( tab => (
					<div
						key={ tab.id }
						className={ classnames( 'ahentic-tab', {
							'is-active': tab.id === activeTabId,
						} ) }
						role="tab"
						aria-selected={ tab.id === activeTabId }
						tabIndex={ 0 }
						onClick={ () => onSelect( tab.id ) }
						onKeyDown={ event => {
							if ( event.key === 'Enter' || event.key === ' ' ) {
								event.preventDefault()
								onSelect( tab.id )
							}
						} }
					>
						<MessageSquare size={ 12 } strokeWidth={ 1.75 } className="ahentic-tab__icon" />
						<span className="ahentic-tab__title">{ tab.title }</span>
						<button
							type="button"
							className="ahentic-tab__close"
							aria-label={ `Close ${ tab.title }` }
							title="Close tab"
							onClick={ event => {
								event.stopPropagation()
								onClose( tab.id )
							} }
						>
							<X size={ 12 } strokeWidth={ 2 } />
						</button>
					</div>
				) ) }
			</div>

			<div className="ahentic-tabbar__actions">
				<button
					type="button"
					className="ahentic-icon-btn"
					onClick={ onNew }
					aria-label="New agent"
					title="New agent"
				>
					<Plus size={ 14 } strokeWidth={ 1.75 } />
				</button>
				<button
					type="button"
					className="ahentic-icon-btn"
					onClick={ onHistory }
					aria-label="Agent history"
					title="History"
				>
					<History size={ 14 } strokeWidth={ 1.75 } />
				</button>
				<div className="ahentic-menu" ref={ menuRef }>
					<button
						type="button"
						className="ahentic-icon-btn"
						onClick={ () => setMenuOpen( value => ! value ) }
						aria-label="Tab actions"
						aria-expanded={ menuOpen }
						title="More"
					>
						<MoreHorizontal size={ 14 } strokeWidth={ 1.75 } />
					</button>
					{ menuOpen && (
						<div className="ahentic-menu__panel" role="menu">
							<button
								type="button"
								role="menuitem"
								className="ahentic-menu__item"
								onClick={ () => {
									setMenuOpen( false )
									onRename()
								} }
							>
								Rename
							</button>
							<button
								type="button"
								role="menuitem"
								className="ahentic-menu__item"
								onClick={ () => {
									setMenuOpen( false )
									onDuplicate()
								} }
							>
								Duplicate
							</button>
							<button
								type="button"
								role="menuitem"
								className="ahentic-menu__item"
								aria-pressed={ debugOpen }
								onClick={ () => {
									setMenuOpen( false )
									onToggleDebug?.()
								} }
							>
								{ debugOpen ? 'Hide debugger' : 'Debugger' }
							</button>
							<button
								type="button"
								role="menuitem"
								className="ahentic-menu__item"
								onClick={ () => {
									setMenuOpen( false )
									// Mock only — export later.
								} }
							>
								Export conversation
							</button>
							<button
								type="button"
								role="menuitem"
								className="ahentic-menu__item ahentic-menu__item--danger"
								onClick={ () => {
									setMenuOpen( false )
									onClearAll()
								} }
							>
								Clear all
							</button>
						</div>
					) }
				</div>
			</div>
		</div>
	)
}
