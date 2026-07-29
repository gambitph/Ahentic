/**
 * Top toolbar: settings, help, brand, shortcut hint, collapse.
 */

import {
	CircleHelp, PanelRightClose, Settings,
} from 'lucide-react'
import { __ } from '@wordpress/i18n'

/**
 * @param {Object}   props
 * @param {Function} props.onClose
 * @param {string}   props.shortcutLabel
 */
export default function Toolbar( {
	onClose, shortcutLabel,
} ) {
	const settingsUrl = window.ahentic?.settingsUrl || ''
	const docsUrl = window.ahentic?.docsUrl || 'https://ahentic.com/docs'

	return (
		<div className="ahentic-toolbar">
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
					<PanelRightClose size={ 14 } strokeWidth={ 1.75 } />
				</button>
			</div>
		</div>
	)
}
