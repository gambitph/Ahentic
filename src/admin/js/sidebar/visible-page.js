/**
 * Collect a capped snapshot of what’s visible on the open page.
 */

import { collectPageContext } from './page-context'

const HEADINGS_MAX = 20
const NOTICES_MAX = 10
const ACTIONS_MAX = 20
const FIELDS_MAX = 40
const EXCERPT_MAX = 4000
const FIELD_VALUE_MAX = 120
const NOTICE_TEXT_MAX = 400
const ACTION_LABEL_MAX = 80

/**
 * @return {Object}
 */
export function collectVisiblePage() {
	const page = collectPageContext()
	const root = resolveContentRoot()

	if ( ! root ) {
		return {
			...page,
			available: false,
			surface: page.isAdmin ? 'admin' : 'front',
			heading: '',
			headings: [],
			notices: [],
			actions: [],
			fields: [],
			excerpt: '',
			excerpt_truncated: false,
			notes: [ 'No main content root found.' ],
		}
	}

	const headings = collectHeadings( root )
	const notices = collectNotices( root )
	const actions = collectActions( root )
	const fields = collectFields( root )
	const { excerpt, truncated } = collectExcerpt( root )

	return {
		...page,
		available: true,
		surface: page.isAdmin ? 'admin' : 'front',
		heading: headings[ 0 ]?.text || '',
		headings,
		notices,
		actions,
		fields,
		excerpt,
		excerpt_truncated: truncated,
		counts: {
			headings: headings.length,
			notices: notices.length,
			actions: actions.length,
			fields: fields.length,
		},
		notes: [
			'Read-only snapshot of visible page content (Ahentic UI excluded).',
			'Prefer server abilities for site changes; use this to explain the screen or plan fills.',
		],
	}
}

/**
 * @return {Element|null}
 */
function resolveContentRoot() {
	if ( typeof document === 'undefined' ) {
		return null
	}

	const candidates = [
		document.getElementById( 'wpbody-content' ),
		document.getElementById( 'wpbody' ),
		document.querySelector( 'main' ),
		document.getElementById( 'content' ),
		document.body,
	]

	for ( const el of candidates ) {
		if ( el && ! isInsideAhentic( el ) ) {
			return el
		}
	}
	return null
}

/**
 * @param {Element} el
 * @return {boolean}
 */
function isInsideAhentic( el ) {
	return Boolean(
		el.closest?.( '#ahentic-root, .ahentic, [data-ahentic-root]' )
	)
}

/**
 * @param {Element} el
 * @return {boolean}
 */
function isVisible( el ) {
	if ( ! el || el.nodeType !== 1 ) {
		return false
	}
	if ( isInsideAhentic( el ) ) {
		return false
	}
	const style = window.getComputedStyle?.( el )
	if ( style ) {
		if ( style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0' ) {
			return false
		}
	}
	const rect = el.getBoundingClientRect?.()
	if ( rect && ( rect.width < 1 || rect.height < 1 ) ) {
		return false
	}
	return true
}

/**
 * @param {string} text
 * @param {number} max
 * @return {string}
 */
function clip( text, max ) {
	const value = String( text || '' ).replace( /\s+/g, ' ' ).trim()
	if ( value.length <= max ) {
		return value
	}
	return `${ value.slice( 0, max - 1 ) }…`
}

/**
 * @param {Element} root
 * @return {Array<{ level: number, text: string }>}
 */
function collectHeadings( root ) {
	const out = []
	const nodes = root.querySelectorAll( 'h1, h2, h3' )
	for ( const node of nodes ) {
		if ( ! isVisible( node ) ) {
			continue
		}
		const text = clip( node.textContent, 160 )
		if ( ! text ) {
			continue
		}
		const level = Number( ( node.tagName || 'H1' ).slice( 1 ) ) || 1
		out.push( { level, text } )
		if ( out.length >= HEADINGS_MAX ) {
			break
		}
	}
	return out
}

/**
 * @param {Element} root
 * @return {Array<{ type: string, text: string }>}
 */
function collectNotices( root ) {
	const out = []
	const selectors = [
		'.notice',
		'.update-nag',
		'#message',
		'.woocommerce-message',
		'.woocommerce-error',
		'[role="alert"]',
	]
	const seen = new Set()

	for ( const selector of selectors ) {
		const nodes = root.querySelectorAll( selector )
		for ( const node of nodes ) {
			if ( ! isVisible( node ) || seen.has( node ) ) {
				continue
			}
			seen.add( node )
			const text = clip( node.textContent, NOTICE_TEXT_MAX )
			if ( ! text || text.length < 3 ) {
				continue
			}
			let type = 'info'
			const className = String( node.className || '' )
			if ( /error|notice-error/i.test( className ) || node.getAttribute( 'role' ) === 'alert' ) {
				type = 'error'
			} else if ( /warning|notice-warning|update-nag/i.test( className ) ) {
				type = 'warning'
			} else if ( /success|notice-success|updated/i.test( className ) ) {
				type = 'success'
			}
			out.push( { type, text } )
			if ( out.length >= NOTICES_MAX ) {
				return out
			}
		}
	}
	return out
}

/**
 * @param {Element} root
 * @return {Array<{ text: string, tag: string, type: string, name: string, href: string }>}
 */
function collectActions( root ) {
	const out = []
	const nodes = root.querySelectorAll(
		'a.page-title-action, .wrap .page-title-action, .wp-heading-inline + a, button, input[type="submit"], input[type="button"], a.button, a.button-primary, a.button-secondary'
	)
	const seen = new Set()

	for ( const node of nodes ) {
		if ( ! isVisible( node ) ) {
			continue
		}
		const text = clip(
			node.value || node.getAttribute( 'aria-label' ) || node.textContent,
			ACTION_LABEL_MAX
		)
		if ( ! text || text.length < 2 ) {
			continue
		}
		const key = `${ node.tagName }:${ text }:${ node.getAttribute( 'href' ) || node.getAttribute( 'name' ) || '' }`
		if ( seen.has( key ) ) {
			continue
		}
		seen.add( key )

		// Skip tiny icon-only chrome that isn't a real CTA.
		if ( text.length < 2 ) {
			continue
		}

		out.push( {
			text,
			tag: String( node.tagName || '' ).toLowerCase(),
			type: String( node.getAttribute( 'type' ) || '' ),
			name: String( node.getAttribute( 'name' ) || '' ),
			href: String( node.getAttribute( 'href' ) || '' ).slice( 0, 300 ),
			primary: /\bbutton-primary\b/.test( String( node.className || '' ) ),
		} )
		if ( out.length >= ACTIONS_MAX ) {
			break
		}
	}
	return out
}

/**
 * @param {Element} root
 * @return {Array<Object>}
 */
function collectFields( root ) {
	const out = []
	const nodes = root.querySelectorAll( 'input, select, textarea' )

	for ( const node of nodes ) {
		if ( ! isVisible( node ) ) {
			continue
		}
		const type = String( node.getAttribute( 'type' ) || ( node.tagName === 'SELECT' ? 'select' : node.tagName === 'TEXTAREA' ? 'textarea' : 'text' ) ).toLowerCase()
		if ( [ 'hidden', 'file', 'submit', 'button', 'reset', 'image' ].includes( type ) ) {
			continue
		}

		const name = String( node.getAttribute( 'name' ) || '' )
		const id = String( node.getAttribute( 'id' ) || '' )
		const label = resolveFieldLabel( node, id )
		let value = ''
		if ( type === 'checkbox' || type === 'radio' ) {
			value = node.checked ? 'checked' : 'unchecked'
		} else if ( node.tagName === 'SELECT' ) {
			const selected = node.selectedOptions?.[ 0 ]
			value = clip( selected?.text || node.value || '', FIELD_VALUE_MAX )
		} else if ( type === 'password' ) {
			value = node.value ? '••••••' : ''
		} else {
			value = clip( node.value || '', FIELD_VALUE_MAX )
		}

		out.push( {
			label,
			name,
			id,
			type,
			value,
			required: Boolean( node.required ),
			placeholder: clip( node.getAttribute( 'placeholder' ) || '', 80 ),
		} )
		if ( out.length >= FIELDS_MAX ) {
			break
		}
	}
	return out
}

/**
 * @param {Element} node
 * @param {string} id
 * @return {string}
 */
function resolveFieldLabel( node, id ) {
	const aria = node.getAttribute( 'aria-label' )
	if ( aria ) {
		return clip( aria, 120 )
	}
	if ( id && typeof document !== 'undefined' ) {
		const labels = document.querySelectorAll( 'label[for]' )
		for ( const labelEl of labels ) {
			if ( labelEl.htmlFor === id && ! isInsideAhentic( labelEl ) ) {
				return clip( labelEl.textContent, 120 )
			}
		}
	}
	const wrap = node.closest( 'label' )
	if ( wrap && ! isInsideAhentic( wrap ) ) {
		return clip( wrap.textContent, 120 )
	}
	const row = node.closest( 'tr' )
	const th = row?.querySelector?.( 'th, .title, label' )
	if ( th && ! isInsideAhentic( th ) ) {
		return clip( th.textContent, 120 )
	}
	return clip( node.getAttribute( 'name' ) || node.getAttribute( 'placeholder' ) || '', 120 )
}

/**
 * @param {Element} root
 * @return {{ excerpt: string, truncated: boolean }}
 */
function collectExcerpt( root ) {
	const clone = root.cloneNode( true )
	clone.querySelectorAll(
		'script, style, noscript, #ahentic-root, .ahentic, [data-ahentic-root], .notice, .update-nag'
	).forEach( el => el.remove() )

	const text = clip( clone.textContent || '', EXCERPT_MAX + 1 )
	const truncated = text.length > EXCERPT_MAX
	return {
		excerpt: truncated ? `${ text.slice( 0, EXCERPT_MAX - 1 ) }…` : text,
		truncated,
	}
}
