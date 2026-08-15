/**
 * Top toolbar: settings, help, brand, placement, shortcut hint, collapse.
 */

import {
	useEffect, useRef, useState,
} from '@wordpress/element'
import {
	AppWindow,
	CircleHelp,
	PanelLeft,
	PanelLeftClose,
	PanelRight,
	PanelRightClose,
	Settings,
} from 'lucide-react'
import { __ } from '@wordpress/i18n'
import {
	PLACEMENTS,
	isFloatingPlacement,
} from './constants'

const PLACEMENT_OPTIONS = [
	{
		id: PLACEMENTS.RIGHT,
		label: __( 'Right sidebar', 'ahentic' ),
		Icon: PanelRight,
	},
	{
		id: PLACEMENTS.LEFT,
		label: __( 'Left sidebar', 'ahentic' ),
		Icon: PanelLeft,
	},
	{
		id: PLACEMENTS.FLOATING,
		label: __( 'Floating sidebar', 'ahentic' ),
		Icon: AppWindow,
	},
	{
		id: PLACEMENTS.FLOATING_SMALL,
		label: __( 'Floating small', 'ahentic' ),
		Icon: AppWindow,
	},
]

/**
 * @param {Object}   props
 * @param {Function} props.onClose
 * @param {string}   props.shortcutLabel
 * @param {string}   props.placement
 * @param {Function} props.onPlacementChange
 * @param {Function} [props.onDragHandlePointerDown]
 * @param {boolean}  [props.isMobile]
 */
export default function Toolbar( {
	onClose,
	shortcutLabel,
	placement,
	onPlacementChange,
	onDragHandlePointerDown,
	isMobile = false,
} ) {
	const settingsUrl = window.ahentic?.settingsUrl || ''
	const docsUrl = window.ahentic?.docsUrl || 'https://docs.wpahentic.com'
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
		const onKeyDown = event => {
			if ( event.key === 'Escape' ) {
				setMenuOpen( false )
			}
		}

		document.addEventListener( 'mousedown', onPointerDown )
		document.addEventListener( 'keydown', onKeyDown )
		return () => {
			document.removeEventListener( 'mousedown', onPointerDown )
			document.removeEventListener( 'keydown', onKeyDown )
		}
	}, [ menuOpen ] )

	const CloseIcon = placement === PLACEMENTS.LEFT ? PanelLeftClose : PanelRightClose
	const PlacementIcon = PLACEMENT_OPTIONS.find( option => option.id === placement )?.Icon || PanelRight
	const canDrag = ! isMobile && isFloatingPlacement( placement )

	return (
		// Floating: drag from empty toolbar chrome (buttons/links excluded in handler).
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div
			className={ `ahentic-toolbar${ canDrag ? ' is-draggable' : '' }` }
			onMouseDown={ event => {
				if ( ! canDrag || ! onDragHandlePointerDown ) {
					return
				}
				if ( event.target.closest( 'button, a, .ahentic-placement' ) ) {
					return
				}
				onDragHandlePointerDown( event )
			} }
		>
			<div className="ahentic-toolbar__left">
				{ settingsUrl ? (
					<a
						className="ahentic-icon-btn"
						href={ settingsUrl }
						aria-label={ __( 'Ahentic settings', 'ahentic' ) }
						title={ __( 'Settings', 'ahentic' ) }
					>
						<Settings size={ 14 } strokeWidth={ 1.75 } />
					</a>
				) : (
					<button
						type="button"
						className="ahentic-icon-btn"
						aria-label={ __( 'Ahentic settings', 'ahentic' ) }
						title={ __( 'Settings', 'ahentic' ) }
						disabled
					>
						<Settings size={ 14 } strokeWidth={ 1.75 } />
					</button>
				) }
				<a
					className="ahentic-icon-btn"
					href={ docsUrl }
					target="_blank"
					rel="noopener noreferrer"
					aria-label={ __( 'Ahentic help and documentation', 'ahentic' ) }
					title={ __( 'Help', 'ahentic' ) }
				>
					<CircleHelp size={ 14 } strokeWidth={ 1.75 } />
				</a>
				<span className="ahentic-toolbar__brand">
					AHENTIC
				</span>
			</div>
			<div className="ahentic-toolbar__right">
				{ ! isMobile && (
					<div className="ahentic-placement" ref={ menuRef }>
						<button
							type="button"
							className={ `ahentic-icon-btn${ menuOpen ? ' is-active' : '' }` }
							onClick={ () => setMenuOpen( open => ! open ) }
							aria-haspopup="listbox"
							aria-expanded={ menuOpen }
							aria-label={ __( 'Sidebar placement', 'ahentic' ) }
							title={ __( 'Placement', 'ahentic' ) }
						>
							<PlacementIcon size={ 14 } strokeWidth={ 1.75 } />
						</button>
						{ menuOpen && (
							<div className="ahentic-placement__menu" role="listbox">
								{ PLACEMENT_OPTIONS.map( option => {
									const Icon = option.Icon
									return (
										<button
											key={ option.id }
											type="button"
											role="option"
											aria-selected={ placement === option.id }
											className={ `ahentic-placement__option${ placement === option.id ? ' is-selected' : '' }` }
											onClick={ () => {
												onPlacementChange( option.id )
												setMenuOpen( false )
											} }
										>
											<Icon size={ 14 } strokeWidth={ 1.75 } />
											<span>{ option.label }</span>
										</button>
									)
								} ) }
							</div>
						) }
					</div>
				) }
				<span className="ahentic-toolbar__shortcut" aria-hidden="true">
					{ shortcutLabel }
				</span>
				<button
					type="button"
					className="ahentic-icon-btn"
					onClick={ onClose }
					aria-label={ __( 'Collapse Ahentic sidebar', 'ahentic' ) }
					title={ `${ __( 'Collapse', 'ahentic' ) } (${ shortcutLabel })` }
				>
					<CloseIcon size={ 14 } strokeWidth={ 1.75 } />
				</button>
			</div>
		</div>
	)
}
