/**
 * Floating placement resize handles for the Ahentic sidebar.
 */

/**
 * @param {Object}   props
 * @param {Function} props.onResizeStart (event, dir) => void
 * @return {JSX.Element} Resize handle elements for floating placement.
 */
export default function FloatHandles( { onResizeStart } ) {
	return (
		<>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="ahentic-float-handle ahentic-float-handle--n"
				onMouseDown={ event => onResizeStart( event, 'n' ) }
				role="separator"
				aria-orientation="horizontal"
				aria-label="Resize Ahentic sidebar from top"
			/>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="ahentic-float-handle ahentic-float-handle--s"
				onMouseDown={ event => onResizeStart( event, 's' ) }
				role="separator"
				aria-orientation="horizontal"
				aria-label="Resize Ahentic sidebar from bottom"
			/>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="ahentic-float-handle ahentic-float-handle--e"
				onMouseDown={ event => onResizeStart( event, 'e' ) }
				role="separator"
				aria-orientation="vertical"
				aria-label="Resize Ahentic sidebar from right"
			/>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="ahentic-float-handle ahentic-float-handle--w"
				onMouseDown={ event => onResizeStart( event, 'w' ) }
				role="separator"
				aria-orientation="vertical"
				aria-label="Resize Ahentic sidebar from left"
			/>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="ahentic-float-handle ahentic-float-handle--nw"
				onMouseDown={ event => onResizeStart( event, 'nw' ) }
				role="separator"
				aria-label="Resize Ahentic sidebar from top-left corner"
			/>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="ahentic-float-handle ahentic-float-handle--ne"
				onMouseDown={ event => onResizeStart( event, 'ne' ) }
				role="separator"
				aria-label="Resize Ahentic sidebar from top-right corner"
			/>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="ahentic-float-handle ahentic-float-handle--sw"
				onMouseDown={ event => onResizeStart( event, 'sw' ) }
				role="separator"
				aria-label="Resize Ahentic sidebar from bottom-left corner"
			/>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="ahentic-float-handle ahentic-float-handle--se"
				onMouseDown={ event => onResizeStart( event, 'se' ) }
				role="separator"
				aria-label="Resize Ahentic sidebar from bottom-right corner"
			/>
		</>
	)
}
