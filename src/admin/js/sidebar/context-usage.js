/**
 * Context budget ring + breakdown popover (Cursor-like usage gauge).
 *
 * Portaled with position:fixed so floating sidebar overflow:hidden cannot clip it.
 *
 * @see pro__premium_only/docs/future-sidebar-usage.md
 */

import {
	createPortal,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element'
import { __ } from '@wordpress/i18n'

const PANEL_GAP = 8
const PANEL_VIEWPORT_MARGIN = 8

/**
 * Prefer `.ahentic` so theme CSS variables still apply.
 *
 * @return {Element} Portal mount node.
 */
function getPortalRoot() {
	return document.querySelector( '.ahentic' ) || document.body
}

/**
 * Place the panel above the trigger when possible; clamp to the viewport.
 *
 * @param {Element}     triggerEl Trigger element.
 * @param {HTMLElement} panelEl   Panel element.
 * @return {{ top: number, left: number }} Fixed viewport coordinates.
 */
function computePanelPosition( triggerEl, panelEl ) {
	const trigger = triggerEl.getBoundingClientRect()
	const panel = panelEl.getBoundingClientRect()
	const vw = window.innerWidth
	const vh = window.innerHeight

	let top = trigger.top - panel.height - PANEL_GAP
	if ( top < PANEL_VIEWPORT_MARGIN ) {
		top = trigger.bottom + PANEL_GAP
		if ( top + panel.height > vh - PANEL_VIEWPORT_MARGIN ) {
			top = Math.max( PANEL_VIEWPORT_MARGIN, vh - panel.height - PANEL_VIEWPORT_MARGIN )
		}
	}

	let left = trigger.right - panel.width
	if ( left < PANEL_VIEWPORT_MARGIN ) {
		left = PANEL_VIEWPORT_MARGIN
	}
	if ( left + panel.width > vw - PANEL_VIEWPORT_MARGIN ) {
		left = Math.max( PANEL_VIEWPORT_MARGIN, vw - panel.width - PANEL_VIEWPORT_MARGIN )
	}

	return {
		top: Math.round( top ),
		left: Math.round( left ),
	}
}

const BUCKET_META = [
	{
		key: 'system_prompt',
		label: __( 'System prompt', 'ahentic' ),
		description: __( 'Standing rules Ahentic always follows', 'ahentic' ),
		color: 'var(--ah-fg-muted, #8b8b9a)',
	},
	{
		key: 'ability_schemas',
		label: __( 'Ability schemas', 'ahentic' ),
		description: __( 'Catalog of WordPress actions it can run', 'ahentic' ),
		color: '#8b5cf6',
	},
	{
		key: 'chat_turns',
		label: __( 'Chat turns', 'ahentic' ),
		description: __( 'Messages you and Ahentic exchanged', 'ahentic' ),
		color: '#f59e0b',
	},
	{
		key: 'tool_results',
		label: __( 'Tool results', 'ahentic' ),
		description: __( 'Data returned after those actions ran', 'ahentic' ),
		color: '#ec4899',
	},
	{
		key: 'page_context',
		label: __( 'Page context', 'ahentic' ),
		description: __( 'The admin screen or editor you have open', 'ahentic' ),
		color: '#22c55e',
	},
	{
		key: 'plan_artifacts',
		label: __( 'Plan & artifacts', 'ahentic' ),
		description: __( 'Checklist, goal, and drafts in progress', 'ahentic' ),
		color: '#3b82f6',
	},
	{
		key: 'compacted_summary',
		label: __( 'Compacted summary', 'ahentic' ),
		description: __( 'Shorter recap of older messages', 'ahentic' ),
		color: '#94a3b8',
	},
]

const SESSION_META = [
	{
		key: 'tokensIn',
		label: __( 'Input', 'ahentic' ),
		description: __( 'Tokens sent to the model this session', 'ahentic' ),
	},
	{
		key: 'tokensOut',
		label: __( 'Output', 'ahentic' ),
		description: __( 'Tokens the model wrote back', 'ahentic' ),
	},
	{
		key: 'tokensUsed',
		label: __( 'Total', 'ahentic' ),
		description: __( 'All tokens used in this session', 'ahentic' ),
	},
]

/**
 * @param {number} n Tokens.
 * @return {string} Compact label.
 */
export function formatTokenCount( n ) {
	const v = Math.max( 0, Math.round( Number( n ) || 0 ) )
	if ( v < 1000 ) {
		return String( v )
	}
	if ( v < 10000 ) {
		return `${ ( v / 1000 ).toFixed( 1 ).replace( /\.0$/, '' ) }K`
	}
	if ( v < 1000000 ) {
		return `${ Math.round( v / 1000 ) }K`
	}
	return `${ ( v / 1000000 ).toFixed( 1 ).replace( /\.0$/, '' ) }M`
}

/**
 * @param {Object|null} contextUsage
 * @return {{ percent: number, used: number, budget: number, buckets: Array }} Normalized usage.
 */
function normalizeUsage( contextUsage ) {
	const budget = Math.max( 1, Number( contextUsage?.budgetTokens ) || 200000 )
	const used = Math.max( 0, Number( contextUsage?.usedTokens ) || 0 )
	const percent = typeof contextUsage?.percent === 'number'
		? Math.min( 100, Math.max( 0, contextUsage.percent ) )
		: Math.min( 100, Math.round( ( used / budget ) * 100 ) )
	const raw = contextUsage?.buckets && typeof contextUsage.buckets === 'object'
		? contextUsage.buckets
		: {}
	const buckets = BUCKET_META.map( meta => {
		const row = raw[ meta.key ] || {}
		return {
			...meta,
			tokens: Math.max( 0, Number( row.tokens ) || 0 ),
			chars: Math.max( 0, Number( row.chars ) || 0 ),
		}
	} )
	return {
		percent, used, budget, buckets,
	}
}

/**
 * @param {Object}      props
 * @param {Object|null} props.contextUsage
 * @param {number}      [props.tokensIn]
 * @param {number}      [props.tokensOut]
 * @param {number}      [props.tokensUsed]
 */
export default function ContextUsageControl( {
	contextUsage = null,
	tokensIn = 0,
	tokensOut = 0,
	tokensUsed = 0,
} ) {
	const [ open, setOpen ] = useState( false )
	const [ panelPos, setPanelPos ] = useState( null )
	const rootRef = useRef( null )
	const triggerRef = useRef( null )
	const panelRef = useRef( null )
	const usage = normalizeUsage( contextUsage )
	const circumference = 2 * Math.PI * 7
	const dash = ( usage.percent / 100 ) * circumference

	useLayoutEffect( () => {
		if ( ! open ) {
			setPanelPos( null )
			return undefined
		}
		const update = () => {
			const trigger = triggerRef.current
			const panel = panelRef.current
			if ( ! trigger || ! panel ) {
				return
			}
			setPanelPos( computePanelPosition( trigger, panel ) )
		}
		update()
		window.addEventListener( 'resize', update )
		// Capture scroll from sidebar / page so the panel tracks the trigger.
		window.addEventListener( 'scroll', update, true )
		return () => {
			window.removeEventListener( 'resize', update )
			window.removeEventListener( 'scroll', update, true )
		}
	}, [ open, usage.percent, usage.used ] )

	useEffect( () => {
		if ( ! open ) {
			return undefined
		}
		const onDoc = event => {
			const target = event.target
			if ( rootRef.current?.contains( target ) || panelRef.current?.contains( target ) ) {
				return
			}
			setOpen( false )
		}
		const onKey = event => {
			if ( event.key === 'Escape' ) {
				setOpen( false )
			}
		}
		document.addEventListener( 'mousedown', onDoc )
		document.addEventListener( 'keydown', onKey )
		return () => {
			document.removeEventListener( 'mousedown', onDoc )
			document.removeEventListener( 'keydown', onKey )
		}
	}, [ open ] )

	const visibleBuckets = usage.buckets.filter( b => b.tokens > 0 || b.key === 'system_prompt' || b.key === 'ability_schemas' )
	const barTotal = Math.max( 1, visibleBuckets.reduce( ( sum, b ) => sum + b.tokens, 0 ) )
	const sessionValues = {
		tokensIn,
		tokensOut,
		tokensUsed,
	}

	const panel = open ? createPortal(
		<div
			ref={ panelRef }
			className={ `ahentic-context-usage__panel${ panelPos ? ' is-placed' : '' }` }
			role="dialog"
			aria-label={ __( 'Context Usage', 'ahentic' ) }
			style={ panelPos
				? {
					top: `${ panelPos.top }px`,
					left: `${ panelPos.left }px`,
				}
				: undefined }
		>
			<div className="ahentic-context-usage__panel-head">
				<span className="ahentic-context-usage__panel-title">{ __( 'Context Usage', 'ahentic' ) }</span>
				<button
					type="button"
					className="ahentic-context-usage__close"
					aria-label={ __( 'Close', 'ahentic' ) }
					onClick={ () => setOpen( false ) }
				>
					×
				</button>
			</div>
			<div className="ahentic-context-usage__summary">
				<span>{ usage.percent }% { __( 'Full', 'ahentic' ) }</span>
				<span>~{ formatTokenCount( usage.used ) } / { formatTokenCount( usage.budget ) } { __( 'Tokens', 'ahentic' ) }</span>
			</div>
			<p className="ahentic-context-usage__budget-label">
				{ __( 'Context budget · 200k', 'ahentic' ) }
			</p>
			<div className="ahentic-context-usage__bar" aria-hidden="true">
				{ visibleBuckets.map( bucket => (
					<span
						key={ bucket.key }
						className="ahentic-context-usage__bar-seg"
						style={ {
							width: `${ ( bucket.tokens / barTotal ) * 100 }%`,
							background: bucket.color,
						} }
					/>
				) ) }
			</div>
			<ul className="ahentic-context-usage__list">
				{ visibleBuckets.map( bucket => (
					<li key={ bucket.key } className="ahentic-context-usage__row">
						<span className="ahentic-context-usage__swatch" style={ { background: bucket.color } } />
						<span className="ahentic-context-usage__row-copy">
							<span className="ahentic-context-usage__row-label">{ bucket.label }</span>
							<span className="ahentic-context-usage__row-desc">{ bucket.description }</span>
						</span>
						<span className="ahentic-context-usage__row-value">{ formatTokenCount( bucket.tokens ) }</span>
					</li>
				) ) }
			</ul>
			<div className="ahentic-context-usage__session">
				<div className="ahentic-context-usage__session-title">{ __( 'This session', 'ahentic' ) }</div>
				<div className="ahentic-context-usage__session-grid">
					{ SESSION_META.map( row => (
						<div key={ row.key } className="ahentic-context-usage__session-row">
							<span className="ahentic-context-usage__session-copy">
								<span className="ahentic-context-usage__session-label">{ row.label }</span>
								<span className="ahentic-context-usage__session-desc">{ row.description }</span>
							</span>
							<span className="ahentic-context-usage__session-value">
								{ formatTokenCount( sessionValues[ row.key ] ) }
							</span>
						</div>
					) ) }
				</div>
			</div>
		</div>,
		getPortalRoot()
	) : null

	return (
		<div className="ahentic-context-usage" ref={ rootRef }>
			<button
				ref={ triggerRef }
				type="button"
				className={ `ahentic-context-usage__trigger${ open ? ' is-open' : '' }` }
				aria-haspopup="dialog"
				aria-expanded={ open }
				aria-label={ __( 'Context usage', 'ahentic' ) }
				title={ __( 'Context usage', 'ahentic' ) }
				onClick={ () => setOpen( v => ! v ) }
			>
				<svg className="ahentic-context-usage__ring" viewBox="0 0 18 18" width="16" height="16" aria-hidden="true">
					<circle
						className="ahentic-context-usage__ring-track"
						cx="9"
						cy="9"
						r="7"
						fill="none"
						strokeWidth="2"
					/>
					<circle
						className="ahentic-context-usage__ring-value"
						cx="9"
						cy="9"
						r="7"
						fill="none"
						strokeWidth="2"
						strokeDasharray={ `${ dash } ${ circumference }` }
						strokeLinecap="round"
						transform="rotate(-90 9 9)"
					/>
				</svg>
			</button>
			{ panel }
		</div>
	)
}
