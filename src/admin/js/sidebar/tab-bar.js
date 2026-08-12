/**
 * Scrollable agent tab strip + right-side actions.
 */

import {
	useEffect, useRef, useState,
} from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import classnames from 'classnames'
import {
	MessageSquare, Plus, MoreHorizontal, X,
} from 'lucide-react'

/**
 * @param {Object}   props
 * @param {Array}    props.tabs
 * @param {string}   props.activeTabId
 * @param {Function} props.onSelect
 * @param {Function} props.onClose
 * @param {Function} props.onNew
 * @param {Function} props.onClearAll
 * @param {boolean}  props.debugOpen
 * @param {Function} props.onToggleDebug
 */
export default function TabBar( {
	tabs,
	activeTabId,
	onSelect,
	onClose,
	onNew,
	onClearAll,
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
			<div
				className="ahentic-tabbar__scroll"
				role="tablist"
				aria-label={ __( 'Agent conversations', 'ahentic' ) }
			>
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
						onMouseDown={ event => {
							// Middle-click closes the tab (browser-tab convention).
							if ( event.button === 1 ) {
								event.preventDefault()
								onClose( tab.id )
							}
						} }
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
							aria-label={ sprintf(
								/* translators: %s: tab title */
								__( 'Close %s', 'ahentic' ),
								tab.title
							) }
							title={ __( 'Close tab', 'ahentic' ) }
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
					aria-label={ __( 'New agent', 'ahentic' ) }
					title={ __( 'New agent', 'ahentic' ) }
				>
					<Plus size={ 14 } strokeWidth={ 1.75 } />
				</button>
				{ /* History / saved sessions — hidden for now. */ }
				<div className="ahentic-menu" ref={ menuRef }>
					<button
						type="button"
						className="ahentic-icon-btn"
						onClick={ () => setMenuOpen( value => ! value ) }
						aria-label={ __( 'Tab actions', 'ahentic' ) }
						aria-expanded={ menuOpen }
						title={ __( 'More', 'ahentic' ) }
					>
						<MoreHorizontal size={ 14 } strokeWidth={ 1.75 } />
					</button>
					{ menuOpen && (
						<div className="ahentic-menu__panel" role="menu">
							{ /* Rename, export conversation — hidden for now. */ }
							<button
								type="button"
								role="menuitemcheckbox"
								className="ahentic-menu__item"
								aria-checked={ debugOpen }
								onClick={ () => {
									setMenuOpen( false )
									onToggleDebug?.()
								} }
							>
								{ debugOpen
									? __( 'Hide debugger', 'ahentic' )
									: __( 'Debugger', 'ahentic' ) }
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
								{ __( 'Clear all', 'ahentic' ) }
							</button>
						</div>
					) }
				</div>
			</div>
		</div>
	)
}
