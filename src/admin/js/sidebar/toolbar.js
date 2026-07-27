/**
 * Top toolbar: settings, brand, shortcut hint, collapse.
 */

import { Settings, PanelRightClose } from 'lucide-react'

/**
 * @param {Object}   props
 * @param {Function} props.onClose
 * @param {string}   props.shortcutLabel
 */
export default function Toolbar( {
	onClose, shortcutLabel,
} ) {
	const settingsUrl = window.ahentic?.settingsUrl || ''

	return (
		<div className="ahentic-toolbar">
			<div className="ahentic-toolbar__left">
				{ settingsUrl ? (
					<a
						className="ahentic-icon-btn"
						href={ settingsUrl }
						aria-label="Ahentic settings"
						title="Settings"
					>
						<Settings size={ 14 } strokeWidth={ 1.75 } />
					</a>
				) : (
					<button
						type="button"
						className="ahentic-icon-btn"
						aria-label="Ahentic settings"
						title="Settings"
						disabled
					>
						<Settings size={ 14 } strokeWidth={ 1.75 } />
					</button>
				) }
				<span className="ahentic-toolbar__brand">AHENTIC</span>
			</div>
			<div className="ahentic-toolbar__right">
				<span className="ahentic-toolbar__shortcut" aria-hidden="true">
					{ shortcutLabel }
				</span>
				<button
					type="button"
					className="ahentic-icon-btn"
					onClick={ onClose }
					aria-label="Collapse Ahentic sidebar"
					title={ `Collapse (${ shortcutLabel })` }
				>
					<PanelRightClose size={ 14 } strokeWidth={ 1.75 } />
				</button>
			</div>
		</div>
	)
}
