/**
 * Monochrome Ahentic mark — inherits color via currentColor.
 */

import classnames from 'classnames'

/**
 * @param {Object} props
 * @param {string} [props.className]
 * @param {number} [props.size]
 */
export default function AhenticLogo( {
	className, size = 14,
} ) {
	return (
		<svg
			className={ classnames( 'ahentic-logo', className ) }
			width={ size }
			height={ size }
			viewBox="0 0 512 512"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
			aria-hidden="true"
			focusable="false"
		>
			<path
				d="M416 421.034H353.188L256 164.929L158.812 421.034H96L225.113 90H286.887L416 421.034Z"
				fill="currentColor"
			/>
			<path
				d="M255.921 306.044C255.921 306.044 259.405 323.467 273.344 337.405C285.54 349.601 300.405 353.794 303.929 354.651L304.705 354.828C304.644 354.84 287.258 358.337 273.344 372.251C259.405 386.189 255.921 403.612 255.921 403.612C255.921 403.612 252.436 386.189 238.498 372.251C226.302 360.055 211.438 355.862 207.913 355.005L207.136 354.828L207.913 354.651C211.438 353.793 226.302 349.601 238.498 337.405C252.418 323.485 255.912 306.089 255.921 306.044Z"
				fill="currentColor"
			/>
		</svg>
	)
}
