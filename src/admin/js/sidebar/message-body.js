/**
 * Light message text repair + markdown → safe React nodes for the sidebar.
 */

import { createElement, Fragment } from '@wordpress/element'

/**
 * Repair text corrupted when JSON escapes lost their backslash in post meta.
 *
 * @param {string} text
 * @return {string}
 */
export function repairCorruptedText( text ) {
	if ( ! text || typeof text !== 'string' ) {
		return ''
	}

	if ( ! /u20[1-2][0-9a-fA-F]|u00[a-fA-F0-9]{2}|(?:[.!?:*\]])nn(?=[A-Z0-9*\-])|(?<=:)n(?=\s*-)/.test( text ) ) {
		return text
	}

	let out = text
		.replace( /u2018/g, '\u2018' )
		.replace( /u2019/g, '\u2019' )
		.replace( /u201[cC]/g, '\u201C' )
		.replace( /u201[dD]/g, '\u201D' )
		.replace( /u2013/g, '\u2013' )
		.replace( /u2014/g, '\u2014' )
		.replace( /u2026/g, '\u2026' )
		.replace( /u00[aA]0/g, '\u00A0' )

	out = out.replace( /(?<=[.!?:*\]])nn(?=[A-Z0-9*\-])/g, '\n\n' )
	out = out.replace( /(?<=[.:?\w*\]])n(?=\s+-\s)/g, '\n' )
	out = out.replace( /(?<=[.!?:])n(?=\d+\.\s)/g, '\n' )
	out = out.replace( /(?<=\*)n(?=\d+\.\s)/g, '\n' )

	return out
}

/**
 * Never show AHENTIC_DEBUG blocks in the chat thread (client safety net).
 *
 * @param {string} text
 * @return {string}
 */
export function stripAhenticDebug( text ) {
	if ( ! text || typeof text !== 'string' ) {
		return ''
	}

	let out = text.replace( /<<<\s*AHENTIC_DEBUG\s*[\s\S]*?\s*AHENTIC_DEBUG>{2,}/gi, '' )
	out = out.replace( /<<<\s*AHENTIC_DEBUG[\s\S]*/gi, '' )
	out = out.replace( /AHENTIC_DEBUG>{2,}/gi, '' )
	return out.trim()
}

/**
 * Escape HTML special characters.
 *
 * @param {string} text
 * @return {string}
 */
function escapeHtml( text ) {
	return text
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
}

/**
 * Allow only safe URL schemes for rendered links.
 *
 * @param {string} raw Escaped or plain URL candidate.
 * @return {string|null}
 */
function sanitizeHref( raw ) {
	const url = String( raw || '' ).trim()
	if ( ! url ) {
		return null
	}
	if ( /^(https?:\/\/|mailto:)/i.test( url ) ) {
		return url
	}
	if ( url.startsWith( '/' ) && ! url.startsWith( '//' ) ) {
		return url
	}
	return null
}

/**
 * Apply **bold** / *italic* to already-escaped inline text.
 *
 * @param {string} html
 * @return {string}
 */
function applyEmphasis( html ) {
	let out = html
	out = out.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
	out = out.replace( /(?<!\*)\*([^*]+)\*(?!\*)/g, '<em>$1</em>' )
	return out
}

/**
 * Inline markdown: links, **bold**, *italic*, `code`.
 *
 * @param {string} text Raw (unescaped) text.
 * @return {string} HTML string.
 */
function formatInline( text ) {
	let html = escapeHtml( text )
	const placeholders = []

	const hold = fragment => {
		const index = placeholders.length
		placeholders.push( fragment )
		return `\u0000PH${ index }\u0000`
	}

	// Protect code spans first so URLs inside them stay plain.
	html = html.replace( /`([^`]+)`/g, ( _, code ) => hold( `<code>${ code }</code>` ) )

	// Markdown links: [label](url) — emphasis inside the label is applied here.
	html = html.replace( /\[([^\]]+)\]\(([^)\s]+)\)/g, ( full, label, url ) => {
		const href = sanitizeHref( url )
		if ( ! href ) {
			return full
		}
		return hold(
			`<a href="${ href }" target="_blank" rel="noopener noreferrer">${ applyEmphasis( label ) }</a>`
		)
	} )

	// Autolink bare http(s) URLs.
	html = html.replace( /(https?:\/\/[^\s<]+)/gi, url => {
		let href = url
		let trailing = ''
		while ( /[.,;:!?)]$/.test( href ) ) {
			trailing = href.slice( -1 ) + trailing
			href = href.slice( 0, -1 )
		}
		const safe = sanitizeHref( href )
		if ( ! safe ) {
			return url
		}
		return hold(
			`<a href="${ safe }" target="_blank" rel="noopener noreferrer">${ href }</a>`
		) + trailing
	} )

	html = applyEmphasis( html )

	html = html.replace( /\u0000PH(\d+)\u0000/g, ( _, index ) => placeholders[ Number( index ) ] || '' )
	return html
}

/**
 * Convert a markdown-ish assistant reply into simple HTML.
 *
 * @param {string} raw
 * @return {string}
 */
export function markdownToHtml( raw ) {
	const text = stripAhenticDebug( repairCorruptedText( raw ) ).replace( /\r\n/g, '\n' ).trim()
	if ( ! text ) {
		return ''
	}

	const lines = text.split( '\n' )
	const blocks = []
	let listType = null // 'ul' | 'ol'
	let listItems = []

	const flushList = () => {
		if ( ! listType || ! listItems.length ) {
			listType = null
			listItems = []
			return
		}
		const tag = listType
		blocks.push(
			`<${ tag }>${ listItems.map( item => `<li>${ formatInline( item ) }</li>` ).join( '' ) }</${ tag }>`
		)
		listType = null
		listItems = []
	}

	for ( const line of lines ) {
		const trimmed = line.trim()
		if ( ! trimmed ) {
			flushList()
			continue
		}

		const ol = trimmed.match( /^(\d+)\.\s+(.*)$/ )
		const ul = trimmed.match( /^[-*]\s+(.*)$/ )
		const heading = trimmed.match( /^(#{1,3})\s+(.*)$/ )

		if ( ol ) {
			if ( listType && listType !== 'ol' ) {
				flushList()
			}
			listType = 'ol'
			listItems.push( ol[ 2 ] )
			continue
		}

		if ( ul ) {
			if ( listType && listType !== 'ul' ) {
				flushList()
			}
			listType = 'ul'
			listItems.push( ul[ 1 ] )
			continue
		}

		flushList()

		if ( heading ) {
			const level = Math.min( heading[ 1 ].length + 2, 5 ) // h3–h5 in sidebar
			blocks.push( `<h${ level }>${ formatInline( heading[ 2 ] ) }</h${ level }>` )
			continue
		}

		blocks.push( `<p>${ formatInline( trimmed ) }</p>` )
	}

	flushList()
	return blocks.join( '' )
}

/**
 * Render message content for the sidebar.
 *
 * @param {Object} props
 * @param {string} props.content
 * @param {string} props.role
 * @return {import('@wordpress/element').WPElement}
 */
export function MessageBody( { content, role } ) {
	const cleaned = role === 'assistant'
		? stripAhenticDebug( content || '' )
		: ( content || '' )
	const repaired = repairCorruptedText( cleaned )

	if ( role === 'assistant' ) {
		const html = markdownToHtml( repaired )
		if ( ! html ) {
			return createElement( Fragment, null, null )
		}
		return createElement( 'div', {
			className: 'ahentic-message__rich',
			dangerouslySetInnerHTML: { __html: html },
		} )
	}

	// User / system: preserve newlines, no markdown.
	return createElement( Fragment, null, repaired )
}
