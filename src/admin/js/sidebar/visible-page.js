/**
 * Collect a capped snapshot of what’s visible on the open page.
 */

/* eslint-disable camelcase -- Ability output matches PHP schema snake_case. */

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
 * Same keys as Ahentic_Abilities_Settings::option_write_denylist() /
 * Ahentic_Abilities_Browser::fill_fields_option_denylist() — keep in sync.
 * Prefer window.ahentic.fillFieldsOptionDenylist when localized.
 *
 * @return {string[]} Hard-denied option field names.
 */
export function fillFieldsOptionDenylist() {
	const fromBoot = window.ahentic?.fillFieldsOptionDenylist
	if ( Array.isArray( fromBoot ) && fromBoot.length > 0 ) {
		return fromBoot.map( String )
	}
	return [ 'siteurl', 'home', 'default_role', 'users_can_register', 'admin_email' ]
}

/**
 * @param {string} key Field name or id.
 * @return {boolean} True when the key is on the hard denylist.
 */
export function fillFieldsKeyIsDenied( key ) {
	const k = String( key || '' )
	if ( ! k ) {
		return false
	}
	return fillFieldsOptionDenylist().includes( k )
}

/**
 * @return {Object} Visible-page snapshot (headings, notices, actions, fields, excerpt).
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
			'On admin non-editor screens, use this to see if a setting is on the form, then ahentic-browser/fill-fields (does not submit). Prefer fill over update-option when the control is visible.',
		],
	}
}

/**
 * Fill visible form fields on the open page (never submits).
 *
 * Targets by name (preferred), then id; optional label disambiguates.
 *
 * @param {{ fields?: Array<{ name?: string, id?: string, label?: string, value: * }> }} input
 * @return {{ ok: boolean, filled: Array<Object>, skipped: Array<Object>, notes: string[] }} Fill result with per-field outcomes.
 */
export function fillFields( input ) {
	const requests = Array.isArray( input?.fields ) ? input.fields : []
	const filled = []
	const skipped = []
	const notes = [
		'Does not submit the form — user must click Save/Update.',
		'On admin forms, prefer fill over server update-option when the control is on this screen.',
	]

	if ( requests.length === 0 ) {
		return {
			ok: false,
			filled,
			skipped: [ { reason: 'empty_fields', message: 'fields array is required and must not be empty.' } ],
			notes,
		}
	}

	const root = resolveContentRoot()
	if ( ! root ) {
		return {
			ok: false,
			filled,
			skipped: requests.map( field => ( {
				...pickTarget( field ),
				reason: 'no_content_root',
				message: 'No main content root found.',
			} ) ),
			notes,
		}
	}

	const nodes = listFillableNodes( root )

	for ( const field of requests ) {
		if ( ! field || typeof field !== 'object' || ! Object.prototype.hasOwnProperty.call( field, 'value' ) ) {
			skipped.push( {
				reason: 'invalid_field',
				message: 'Each field needs a value.',
			} )
			continue
		}

		const target = pickTarget( field )
		const deniedKey = [ field.name, field.id ].map( v => String( v || '' ) ).find( fillFieldsKeyIsDenied )
		if ( deniedKey ) {
			skipped.push( {
				...target,
				reason: 'option_denied',
				message: `Field “${ deniedKey }” is hard-denied (same denylist as ahentic/update-option).`,
			} )
			continue
		}

		const match = matchFillableNode( nodes, field )
		if ( match.error ) {
			skipped.push( { ...target, ...match.error } )
			continue
		}

		const applied = applyFieldValue( match.node, field.value )
		if ( applied.error ) {
			skipped.push( { ...target, ...applied.error } )
			continue
		}

		filled.push( {
			...target,
			type: applied.type,
			value: applied.value,
		} )
	}

	return {
		ok: filled.length > 0,
		filled,
		skipped,
		counts: {
			filled: filled.length,
			skipped: skipped.length,
			requested: requests.length,
		},
		notes,
	}
}

/**
 * @return {Element|null} Main content root, or null if none found.
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
 * @return {boolean} True if the element is inside the Ahentic UI.
 */
function isInsideAhentic( el ) {
	return Boolean(
		el.closest?.( '#ahentic-root, .ahentic, [data-ahentic-root]' )
	)
}

/**
 * @param {Element} el
 * @return {boolean} True if the element is visible and not Ahentic chrome.
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
 * @return {string} Whitespace-collapsed text, truncated to max with an ellipsis.
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
 * @return {Array<{ level: number, text: string }>} Visible headings, capped at HEADINGS_MAX.
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
 * @return {Array<{ type: string, text: string }>} Visible notices, capped at NOTICES_MAX.
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
 * @return {Array<{ text: string, tag: string, type: string, name: string, href: string }>} Visible actions, capped at ACTIONS_MAX.
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
 * @return {Array<Object>} Visible form fields, capped at FIELDS_MAX.
 */
function collectFields( root ) {
	const out = []
	for ( const node of listFillableNodes( root ) ) {
		const meta = describeFillableNode( node )
		out.push( {
			label: meta.label,
			name: meta.name,
			id: meta.id,
			type: meta.type,
			value: meta.value,
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
 * @param {Element} root
 * @return {Element[]} Visible fillable input/select/textarea nodes.
 */
function listFillableNodes( root ) {
	const out = []
	const nodes = root.querySelectorAll( 'input, select, textarea' )
	for ( const node of nodes ) {
		if ( ! isVisible( node ) ) {
			continue
		}
		const type = fieldType( node )
		if ( UNSUPPORTED_FIELD_TYPES.includes( type ) ) {
			continue
		}
		out.push( node )
	}
	return out
}

const UNSUPPORTED_FIELD_TYPES = [ 'hidden', 'file', 'submit', 'button', 'reset', 'image' ]

/**
 * @param {Element} node
 * @return {string} Normalized field type.
 */
function fieldType( node ) {
	return String(
		node.getAttribute( 'type' ) ||
			( node.tagName === 'SELECT' ? 'select' : node.tagName === 'TEXTAREA' ? 'textarea' : 'text' )
	).toLowerCase()
}

/**
 * @param {Element} node
 * @return {{ label: string, name: string, id: string, type: string, value: string }} Field metadata for agents.
 */
function describeFillableNode( node ) {
	const type = fieldType( node )
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
	return {
		label, name, id, type, value,
	}
}

/**
 * @param {Object} field
 * @return {{ name: string, id: string, label: string }} Target selectors from the request.
 */
function pickTarget( field ) {
	return {
		name: String( field?.name || '' ),
		id: String( field?.id || '' ),
		label: String( field?.label || '' ),
	}
}

/**
 * @param {Element[]}                                      nodes
 * @param {{ name?: string, id?: string, label?: string }} field
 * @return {{ node?: Element, error?: { reason: string, message: string } }} Matched node or error.
 */
function matchFillableNode( nodes, field ) {
	const name = String( field.name || '' ).trim()
	const id = String( field.id || '' ).trim()
	const labelNeedle = String( field.label || '' ).trim().toLowerCase()

	if ( ! name && ! id && ! labelNeedle ) {
		return {
			error: {
				reason: 'missing_target',
				message: 'Provide name, id, or label to target a field.',
			},
		}
	}

	let candidates = nodes
	if ( name ) {
		candidates = nodes.filter( node => String( node.getAttribute( 'name' ) || '' ) === name )
		if ( candidates.length === 0 && id ) {
			candidates = nodes.filter( node => String( node.getAttribute( 'id' ) || '' ) === id )
		}
	} else if ( id ) {
		candidates = nodes.filter( node => String( node.getAttribute( 'id' ) || '' ) === id )
	}

	if ( labelNeedle ) {
		const labeled = candidates.filter( node => {
			const meta = describeFillableNode( node )
			return meta.label.toLowerCase().includes( labelNeedle )
		} )
		if ( labeled.length > 0 || ( ! name && ! id ) ) {
			candidates = labeled
		}
	}

	if ( candidates.length === 0 ) {
		return {
			error: {
				reason: 'not_found',
				message: 'No matching visible field.',
			},
		}
	}

	if ( candidates.length > 1 ) {
		return {
			error: {
				reason: 'ambiguous',
				message: `Matched ${ candidates.length } fields — add id or label to disambiguate.`,
			},
		}
	}

	return { node: candidates[ 0 ] }
}

/**
 * @param {Element} node
 * @param {*}       rawValue
 * @return {{ type?: string, value?: *, error?: { reason: string, message: string } }} Applied value or error.
 */
function applyFieldValue( node, rawValue ) {
	const type = fieldType( node )

	if ( type === 'checkbox' || type === 'radio' ) {
		const checked = coerceChecked( rawValue )
		if ( checked === null ) {
			return {
				error: {
					reason: 'invalid_value',
					message: 'Checkbox/radio value must be boolean or checked/unchecked/on/off/1/0.',
				},
			}
		}
		node.checked = checked
		dispatchFieldEvents( node )
		return { type, value: checked }
	}

	const stringValue = rawValue === null || rawValue === undefined ? '' : String( rawValue )

	if ( node.tagName === 'SELECT' ) {
		const matched = matchSelectValue( node, stringValue )
		if ( ! matched ) {
			return {
				error: {
					reason: 'invalid_value',
					message: `No select option matches “${ stringValue }”.`,
				},
			}
		}
		node.value = matched
		dispatchFieldEvents( node )
		return { type: 'select', value: matched }
	}

	node.value = stringValue
	dispatchFieldEvents( node )
	return { type, value: type === 'password' && stringValue ? '••••••' : stringValue }
}

/**
 * @param {*} rawValue
 * @return {boolean|null} Checked state, or null when the value is invalid.
 */
function coerceChecked( rawValue ) {
	if ( typeof rawValue === 'boolean' ) {
		return rawValue
	}
	const normalized = String( rawValue ).trim().toLowerCase()
	if ( [ '1', 'true', 'yes', 'on', 'checked' ].includes( normalized ) ) {
		return true
	}
	if ( [ '0', 'false', 'no', 'off', 'unchecked' ].includes( normalized ) ) {
		return false
	}
	return null
}

/**
 * @param {HTMLSelectElement} node
 * @param {string}            stringValue
 * @return {string|null} Option value to set.
 */
function matchSelectValue( node, stringValue ) {
	const options = Array.from( node.options || [] )
	const byValue = options.find( opt => opt.value === stringValue )
	if ( byValue ) {
		return byValue.value
	}
	const needle = stringValue.toLowerCase()
	const byText = options.find( opt => String( opt.text || '' ).trim().toLowerCase() === needle )
	return byText ? byText.value : null
}

/**
 * @param {Element} node
 */
function dispatchFieldEvents( node ) {
	node.dispatchEvent( new Event( 'input', { bubbles: true } ) )
	node.dispatchEvent( new Event( 'change', { bubbles: true } ) )
}

/**
 * @param {Element} node
 * @param {string}  id
 * @return {string} Best-effort label for the field.
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
 * @return {{ excerpt: string, truncated: boolean }} Plain-text excerpt and whether it was truncated.
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
