/**
 * Ahentic Settings — token usage chart + live daily-limit field.
 *
 * Expects window.ahenticSettings:
 * {
 *   series: [{ date, label, total, in?, out? }],
 *   todayUsed: number,
 *   effectiveLimit: number,
 *   tempBoost: boolean,
 *   locale: string,
 *   i18n: { usedLabel, tokensSuffix, invalidLimit }
 * }
 *
 * Live bar math must stay aligned with Ahentic_Usage::{format_usage_pct,
 * live_bar_denominator, usage_bar_width_pct} (PHP unit: UsageSeriesTest).
 */

( function() {
	'use strict'

	const cfg = window.ahenticSettings || {}
	const series = Array.isArray( cfg.series ) ? cfg.series : []
	const todayUsed = Number( cfg.todayUsed ) || 0
	const effectiveLimit = Number( cfg.effectiveLimit ) || 0
	const tempBoost = !! cfg.tempBoost
	const locale = cfg.locale || undefined
	const i18n = cfg.i18n || {}

	function formatInt( n ) {
		try {
			return Number( n ).toLocaleString( locale )
		} catch ( e ) {
			return String( n )
		}
	}

	function formatCompact( n ) {
		n = Number( n ) || 0
		if ( n >= 1000000 ) {
			const m = n / 1000000
			return ( Math.round( m * 10 ) / 10 ) + 'M'
		}
		if ( n >= 1000 ) {
			return Math.round( n / 1000 ) + 'K'
		}
		return String( Math.round( n ) )
	}

	/**
	 * @param {number} used
	 * @param {number} limit
	 * @see Ahentic_Usage::format_usage_pct
	 */
	function formatUsagePct( used, limit ) {
		used = Math.max( 0, Number( used ) || 0 )
		limit = Number( limit ) || 0
		if ( limit <= 0 ) {
			return 0
		}
		const raw = ( used / limit ) * 100
		const clamped = Math.max( 0, Math.min( 100, raw ) )
		if ( clamped > 0 && clamped < 1 ) {
			return Math.round( clamped * 100 ) / 100
		}
		return Math.round( clamped )
	}

	/**
	 * @param {number} inputLimit
	 * @param {number} eff
	 * @see Ahentic_Usage::live_bar_denominator
	 */
	function liveDenominator( inputLimit, eff ) {
		inputLimit = Number( inputLimit ) || 0
		if ( inputLimit <= 0 ) {
			return 0
		}
		return Math.max( inputLimit, Math.max( 0, Number( eff ) || 0 ) )
	}

	/**
	 * @param {number} pct
	 * @param {number} used
	 * @see Ahentic_Usage::usage_bar_width_pct
	 */
	function barWidthPct( pct, used ) {
		pct = Number( pct ) || 0
		used = Number( used ) || 0
		if ( used <= 0 || pct <= 0 ) {
			return 0
		}
		return Math.max( pct, 0.5 )
	}

	function formatPctDisplay( pct ) {
		if ( typeof pct === 'number' && pct > 0 && pct < 1 ) {
			return pct.toFixed( 2 )
		}
		return String( Math.round( Number( pct ) || 0 ) )
	}

	/* ---------- Live daily limit field ---------- */

	function initLiveLimit() {
		const input = document.getElementById( 'ahentic_daily_limit' )
		if ( ! input ) {
			return
		}

		const usedEl = document.getElementById( 'ahentic-usage-used' )
		const limitEl = document.getElementById( 'ahentic-usage-limit' )
		const pctEl = document.getElementById( 'ahentic-usage-pct' )
		const bar = document.getElementById( 'ahentic-usage-bar' )
		const fill = document.getElementById( 'ahentic-usage-bar-fill' )
		const errorEl = document.getElementById( 'ahentic-daily-limit-error' )
		const submit = document.querySelector( '#ahentic-settings-form .button-primary' )
		const tempNote = document.getElementById( 'ahentic-usage-temp-note' )

		function sync( updateFigures ) {
			const raw = input.value.trim()
			const parsed = raw === '' ? NaN : parseInt( raw, 10 )
			const valid = ! isNaN( parsed ) && parsed > 0

			if ( ! valid ) {
				input.setAttribute( 'aria-invalid', 'true' )
				if ( errorEl ) {
					errorEl.hidden = false
					errorEl.textContent = i18n.invalidLimit || 'Enter a limit greater than zero.'
				}
				if ( submit ) {
					submit.disabled = true
				}
				return
			}

			input.removeAttribute( 'aria-invalid' )
			if ( errorEl ) {
				errorEl.hidden = true
				errorEl.textContent = ''
			}
			if ( submit ) {
				submit.disabled = false
			}

			if ( ! updateFigures ) {
				return
			}

			const denom = liveDenominator( parsed, effectiveLimit )
			const pct = formatUsagePct( todayUsed, denom )
			const width = barWidthPct( pct, todayUsed )

			if ( usedEl ) {
				usedEl.textContent = formatInt( todayUsed )
			}
			if ( limitEl ) {
				limitEl.textContent = formatInt( denom )
			}
			if ( pctEl ) {
				pctEl.textContent = formatPctDisplay( pct )
			}
			if ( fill ) {
				fill.style.width = width + '%'
			}
			if ( bar ) {
				bar.setAttribute( 'aria-valuenow', String( Math.round( Number( pct ) || 0 ) ) )
			}
			if ( tempNote ) {
				tempNote.hidden = ! ( tempBoost && denom !== parsed )
			}
		}

		input.addEventListener( 'input', function() {
			sync( true )
		} )
		input.addEventListener( 'change', function() {
			sync( true )
		} )
		// Validate only — keep PHP number_format_i18n figures until the user edits.
		sync( false )
	}

	/* ---------- Area chart ---------- */

	function niceMax( maxVal ) {
		if ( maxVal <= 0 ) {
			return 1000
		}
		const exp = Math.pow( 10, Math.floor( Math.log( maxVal ) / Math.LN10 ) )
		const fraction = maxVal / exp
		let nice
		if ( fraction <= 1 ) {
			nice = 1
		} else if ( fraction <= 2 ) {
			nice = 2
		} else if ( fraction <= 5 ) {
			nice = 5
		} else {
			nice = 10
		}
		return nice * exp
	}

	/**
	 * Monotone cubic Hermite (Fritsch–Carlson) — smooth without overshoot.
	 *
	 * @param {{x:number,y:number}[]} points Plot points.
	 * @param {number|false}          close  Baseline Y to close area, or false for stroke only.
	 * @return {string} SVG path d.
	 */
	function buildPath( points, close ) {
		if ( ! points.length ) {
			return ''
		}
		if ( points.length === 1 ) {
			const only = points[ 0 ]
			let single = 'M ' + only.x + ' ' + only.y
			if ( close !== false ) {
				single +=
					' L ' +
					only.x +
					' ' +
					close +
					' L ' +
					only.x +
					' ' +
					close +
					' Z'
			}
			return single
		}

		const n = points.length
		const dx = []
		const dy = []
		const m = []
		let i

		for ( i = 0; i < n - 1; i++ ) {
			dx[ i ] = points[ i + 1 ].x - points[ i ].x
			dy[ i ] = points[ i + 1 ].y - points[ i ].y
			m[ i ] = dx[ i ] ? dy[ i ] / dx[ i ] : 0
		}

		const tangents = []
		tangents[ 0 ] = m[ 0 ]
		for ( i = 1; i < n - 1; i++ ) {
			if ( m[ i - 1 ] * m[ i ] <= 0 ) {
				tangents[ i ] = 0
			} else {
				tangents[ i ] = ( m[ i - 1 ] + m[ i ] ) / 2
			}
		}
		tangents[ n - 1 ] = m[ n - 2 ]

		for ( i = 0; i < n - 1; i++ ) {
			if ( Math.abs( m[ i ] ) < 1e-12 ) {
				tangents[ i ] = 0
				tangents[ i + 1 ] = 0
			} else {
				const a = tangents[ i ] / m[ i ]
				const b = tangents[ i + 1 ] / m[ i ]
				const s = ( a * a ) + ( b * b )
				if ( s > 9 ) {
					const t = 3 / Math.sqrt( s )
					tangents[ i ] = t * a * m[ i ]
					tangents[ i + 1 ] = t * b * m[ i ]
				}
			}
		}

		let d = 'M ' + points[ 0 ].x + ' ' + points[ 0 ].y
		for ( i = 0; i < n - 1; i++ ) {
			const p0 = points[ i ]
			const p1 = points[ i + 1 ]
			const segDx = dx[ i ] / 3
			d +=
				' C ' +
				( p0.x + segDx ) +
				' ' +
				( p0.y + ( tangents[ i ] * segDx ) ) +
				', ' +
				( p1.x - segDx ) +
				' ' +
				( p1.y - ( tangents[ i + 1 ] * segDx ) ) +
				', ' +
				p1.x +
				' ' +
				p1.y
		}

		if ( close !== false && points.length ) {
			const last = points[ n - 1 ]
			const first = points[ 0 ]
			d += ' L ' + last.x + ' ' + close + ' L ' + first.x + ' ' + close + ' Z'
		}
		return d
	}

	function initChart() {
		const host = document.getElementById( 'ahentic-token-usage-chart' )
		if ( ! host ) {
			return
		}

		const width = host.clientWidth || 640
		const height = 224
		const pad = {
			top: 12, right: 12, bottom: 28, left: 44,
		}
		const plotW = Math.max( 1, width - pad.left - pad.right )
		const plotH = Math.max( 1, height - pad.top - pad.bottom )

		const totals = series.map( function( row ) {
			return Number( row.total ) || 0
		} )
		const yMax = niceMax( Math.max.apply( null, totals.concat( [ 0 ] ) ) )

		const n = series.length || 1
		const points = series.map( function( row, i ) {
			const x = pad.left + ( n === 1 ? plotW / 2 : ( i / ( n - 1 ) ) * plotW )
			const y = pad.top + plotH - ( ( ( Number( row.total ) || 0 ) / yMax ) * plotH )
			return {
				x, y, row,
			}
		} )

		const ns = 'http://www.w3.org/2000/svg'
		const svg = document.createElementNS( ns, 'svg' )
		svg.setAttribute( 'viewBox', '0 0 ' + width + ' ' + height )
		svg.setAttribute( 'width', '100%' )
		svg.setAttribute( 'height', String( height ) )
		svg.setAttribute( 'aria-hidden', 'true' )

		const defs = document.createElementNS( ns, 'defs' )
		const grad = document.createElementNS( ns, 'linearGradient' )
		grad.setAttribute( 'id', 'ahentic-usage-fill' )
		grad.setAttribute( 'x1', '0' )
		grad.setAttribute( 'y1', '0' )
		grad.setAttribute( 'x2', '0' )
		grad.setAttribute( 'y2', '1' )
		const stop0 = document.createElementNS( ns, 'stop' )
		stop0.setAttribute( 'offset', '0%' )
		stop0.setAttribute( 'stop-color', '#2271b1' )
		stop0.setAttribute( 'stop-opacity', '0.35' )
		const stop1 = document.createElementNS( ns, 'stop' )
		stop1.setAttribute( 'offset', '100%' )
		stop1.setAttribute( 'stop-color', '#2271b1' )
		stop1.setAttribute( 'stop-opacity', '0.02' )
		grad.appendChild( stop0 )
		grad.appendChild( stop1 )
		defs.appendChild( grad )
		svg.appendChild( defs )

		const gridCount = 4
		for ( let g = 0; g <= gridCount; g++ ) {
			const gy = pad.top + ( ( plotH * g ) / gridCount )
			const line = document.createElementNS( ns, 'line' )
			line.setAttribute( 'x1', String( pad.left ) )
			line.setAttribute( 'x2', String( pad.left + plotW ) )
			line.setAttribute( 'y1', String( gy ) )
			line.setAttribute( 'y2', String( gy ) )
			line.setAttribute( 'class', 'ahentic-usage-chart__grid' )
			svg.appendChild( line )
		}

		if ( points.length ) {
			const area = document.createElementNS( ns, 'path' )
			area.setAttribute( 'd', buildPath( points, pad.top + plotH ) )
			area.setAttribute( 'fill', 'url(#ahentic-usage-fill)' )
			area.setAttribute( 'class', 'ahentic-usage-chart__area' )
			svg.appendChild( area )

			const stroke = document.createElementNS( ns, 'path' )
			stroke.setAttribute( 'd', buildPath( points, false ) )
			stroke.setAttribute( 'fill', 'none' )
			stroke.setAttribute( 'stroke', '#2271b1' )
			stroke.setAttribute( 'stroke-width', '2' )
			stroke.setAttribute( 'class', 'ahentic-usage-chart__line' )
			svg.appendChild( stroke )
		}

		for ( let yi = 0; yi <= gridCount; yi++ ) {
			const val = yMax - ( ( yMax * yi ) / gridCount )
			const ty = pad.top + ( ( plotH * yi ) / gridCount )
			const yText = document.createElementNS( ns, 'text' )
			yText.setAttribute( 'x', String( pad.left - 8 ) )
			yText.setAttribute( 'y', String( ty + 3 ) )
			yText.setAttribute( 'text-anchor', 'end' )
			yText.setAttribute( 'class', 'ahentic-usage-chart__axis-label' )
			yText.textContent = formatCompact( val )
			svg.appendChild( yText )
		}

		const xStep = Math.max( 1, Math.ceil( n / 7 ) )
		for ( let xi = 0; xi < n; xi++ ) {
			if ( xi % xStep !== 0 && xi !== n - 1 ) {
				continue
			}
			const xText = document.createElementNS( ns, 'text' )
			xText.setAttribute( 'x', String( points[ xi ].x ) )
			xText.setAttribute( 'y', String( height - 8 ) )
			xText.setAttribute( 'text-anchor', 'middle' )
			xText.setAttribute( 'class', 'ahentic-usage-chart__axis-label' )
			xText.textContent = series[ xi ].label || series[ xi ].date || ''
			svg.appendChild( xText )
		}

		const xAxis = document.createElementNS( ns, 'line' )
		xAxis.setAttribute( 'x1', String( pad.left ) )
		xAxis.setAttribute( 'x2', String( pad.left + plotW ) )
		xAxis.setAttribute( 'y1', String( pad.top + plotH ) )
		xAxis.setAttribute( 'y2', String( pad.top + plotH ) )
		xAxis.setAttribute( 'class', 'ahentic-usage-chart__axis' )
		svg.appendChild( xAxis )

		host.innerHTML = ''
		host.appendChild( svg )

		const tip = document.createElement( 'div' )
		tip.className = 'ahentic-usage-chart__tooltip'
		tip.hidden = true
		host.appendChild( tip )

		const tipStrong = document.createElement( 'strong' )
		const tipBr1 = document.createElement( 'br' )
		const tipValue = document.createElement( 'span' )
		const tipBr2 = document.createElement( 'br' )
		const tipDate = document.createElement( 'span' )
		tipDate.className = 'ahentic-usage-chart__tooltip-date'
		tip.appendChild( tipStrong )
		tip.appendChild( tipBr1 )
		tip.appendChild( tipValue )
		tip.appendChild( tipBr2 )
		tip.appendChild( tipDate )

		const hit = document.createElementNS( ns, 'rect' )
		hit.setAttribute( 'x', String( pad.left ) )
		hit.setAttribute( 'y', String( pad.top ) )
		hit.setAttribute( 'width', String( plotW ) )
		hit.setAttribute( 'height', String( plotH ) )
		hit.setAttribute( 'fill', 'transparent' )
		hit.style.cursor = 'crosshair'
		svg.appendChild( hit )

		function nearestIndex( clientX ) {
			const rect = svg.getBoundingClientRect()
			const scale = width / rect.width
			const localX = ( clientX - rect.left ) * scale
			let best = 0
			let bestDist = Infinity
			for ( let i = 0; i < points.length; i++ ) {
				const d = Math.abs( points[ i ].x - localX )
				if ( d < bestDist ) {
					bestDist = d
					best = i
				}
			}
			return best
		}

		function showTip( evt ) {
			if ( ! points.length ) {
				return
			}
			const idx = nearestIndex( evt.clientX )
			const p = points[ idx ]
			const row = p.row
			tipStrong.textContent = i18n.usedLabel || 'Used'
			tipValue.textContent =
				formatInt( row.total ) + ' ' + ( i18n.tokensSuffix || 'tokens' )
			tipDate.textContent = row.label || row.date || ''
			tip.hidden = false
			const hostRect = host.getBoundingClientRect()
			let left = evt.clientX - hostRect.left + 12
			const top = evt.clientY - hostRect.top - 12
			if ( left + tip.offsetWidth > hostRect.width ) {
				left = evt.clientX - hostRect.left - tip.offsetWidth - 12
			}
			tip.style.left = Math.max( 0, left ) + 'px'
			tip.style.top = Math.max( 0, top ) + 'px'
		}

		hit.addEventListener( 'mousemove', showTip )
		hit.addEventListener( 'mouseleave', function() {
			tip.hidden = true
		} )
	}

	function boot() {
		initLiveLimit()
		initChart()
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot )
	} else {
		boot()
	}
}() )
