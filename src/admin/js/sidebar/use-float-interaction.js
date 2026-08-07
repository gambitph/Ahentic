/**
 * Docked width resize + floating move/resize pointer interaction.
 */

import { useEffect, useRef } from '@wordpress/element'
import {
	PLACEMENTS,
	isFloatingPlacement,
	getDefaultFloatingRect,
	clampFloatingRect,
} from './constants'
import { clampWidth } from './storage'

/**
 * @param {Object}      options
 * @param {boolean}     options.isMobile
 * @param {string}      options.placement
 * @param {Object|null} options.floatRect
 * @param {number}      options.width
 * @param {Function}    options.setFloatRect
 * @param {Function}    options.setWidth
 * @return {{ startDockResize: Function, startFloatResize: Function, startFloatDrag: Function, floating: boolean, panelStyle: Object|undefined }} Float interaction API.
 */
export function useFloatInteraction( {
	isMobile,
	placement,
	floatRect,
	width,
	setFloatRect,
	setWidth,
} ) {
	const resizingRef = useRef( false )
	const resizeEdgeRef = useRef( null )
	const dragRef = useRef( null )
	const floatRectRef = useRef( floatRect )
	floatRectRef.current = floatRect
	const placementRef = useRef( placement )
	placementRef.current = placement

	useEffect( () => {
		const clearInteraction = () => {
			resizingRef.current = false
			resizeEdgeRef.current = null
			dragRef.current = null
			document.body.classList.remove(
				'ahentic-is-resizing',
				'ahentic-is-resizing--row',
				'ahentic-is-resizing--corner',
				'ahentic-is-resizing--corner-nesw',
				'ahentic-is-dragging'
			)
		}

		const onMove = event => {
			if ( isMobile ) {
				return
			}

			if ( dragRef.current ) {
				const {
					startX, startY, originLeft, originTop,
				} = dragRef.current
				const next = clampFloatingRect( {
					...floatRectRef.current,
					left: originLeft + ( event.clientX - startX ),
					top: originTop + ( event.clientY - startY ),
				} )
				setFloatRect( next )
				return
			}

			if ( ! resizingRef.current ) {
				return
			}

			const currentPlacement = placementRef.current
			const edge = resizeEdgeRef.current

			if ( isFloatingPlacement( currentPlacement ) && edge ) {
				const origin = resizeEdgeRef.current.origin
				const anchorRight = origin.left + origin.width
				const anchorBottom = origin.top + origin.height
				let {
					left, top, width: nextW, height: nextH,
				} = origin

				if ( edge.dir.includes( 'e' ) ) {
					nextW = event.clientX - origin.left
				}
				if ( edge.dir.includes( 's' ) ) {
					nextH = event.clientY - origin.top
				}
				if ( edge.dir.includes( 'w' ) ) {
					nextW = anchorRight - event.clientX
					left = event.clientX
				}
				if ( edge.dir.includes( 'n' ) ) {
					nextH = anchorBottom - event.clientY
					top = event.clientY
				}

				const clamped = clampFloatingRect( {
					left,
					top,
					width: nextW,
					height: nextH,
				} )

				// Keep the opposite edge fixed when min/max size clamping kicks in.
				if ( edge.dir.includes( 'w' ) ) {
					clamped.left = Math.max( 0, anchorRight - clamped.width )
					clamped.width = Math.min( clamped.width, anchorRight - clamped.left )
				}
				if ( edge.dir.includes( 'n' ) ) {
					clamped.top = Math.max( 0, anchorBottom - clamped.height )
					clamped.height = Math.min( clamped.height, anchorBottom - clamped.top )
				}

				setFloatRect( clamped )
				setWidth( clamped.width )
				return
			}

			if ( currentPlacement === PLACEMENTS.LEFT ) {
				setWidth( clampWidth( event.clientX ) )
				return
			}

			if ( currentPlacement === PLACEMENTS.RIGHT ) {
				setWidth( clampWidth( window.innerWidth - event.clientX ) )
			}
		}

		const onUp = () => {
			clearInteraction()
		}

		window.addEventListener( 'mousemove', onMove )
		window.addEventListener( 'mouseup', onUp )
		return () => {
			window.removeEventListener( 'mousemove', onMove )
			window.removeEventListener( 'mouseup', onUp )
			clearInteraction()
		}
	}, [ isMobile, setFloatRect, setWidth ] )

	const startDockResize = event => {
		if ( isMobile || isFloatingPlacement( placement ) ) {
			return
		}
		event.preventDefault()
		resizingRef.current = true
		resizeEdgeRef.current = null
		document.body.classList.add( 'ahentic-is-resizing' )
	}

	const startFloatResize = ( event, dir ) => {
		if ( isMobile || ! isFloatingPlacement( placement ) ) {
			return
		}
		const origin = floatRect || getDefaultFloatingRect( placement, width )
		if ( ! floatRect ) {
			setFloatRect( origin )
		}
		event.preventDefault()
		event.stopPropagation()
		resizingRef.current = true
		resizeEdgeRef.current = {
			dir,
			origin: { ...origin },
		}
		document.body.classList.add( 'ahentic-is-resizing' )
		if ( dir === 'n' || dir === 's' ) {
			document.body.classList.add( 'ahentic-is-resizing--row' )
		} else if ( dir.length > 1 ) {
			document.body.classList.add( 'ahentic-is-resizing--corner' )
			if ( dir === 'ne' || dir === 'sw' ) {
				document.body.classList.add( 'ahentic-is-resizing--corner-nesw' )
			}
		}
	}

	const startFloatDrag = event => {
		if ( isMobile || ! isFloatingPlacement( placement ) || ! floatRect ) {
			return
		}
		event.preventDefault()
		dragRef.current = {
			startX: event.clientX,
			startY: event.clientY,
			originLeft: floatRect.left,
			originTop: floatRect.top,
		}
		document.body.classList.add( 'ahentic-is-dragging' )
	}

	const floating = ! isMobile && isFloatingPlacement( placement )
	const activeFloat = floating
		? ( floatRect || getDefaultFloatingRect( placement, width ) )
		: null

	const panelStyle = ( () => {
		if ( isMobile ) {
			return undefined
		}
		if ( activeFloat ) {
			return {
				width: `${ activeFloat.width }px`,
				height: `${ activeFloat.height }px`,
				left: `${ activeFloat.left }px`,
				top: `${ activeFloat.top }px`,
			}
		}
		return { width: `${ width }px` }
	} )()

	return {
		startDockResize,
		startFloatResize,
		startFloatDrag,
		floating,
		panelStyle,
	}
}
