/**
 * Live page / editor snapshot collected on Run feedback No (before draft+file).
 */

/* eslint-disable camelcase -- Feedback snapshot I/O matches PHP REST snake_case. */
/* eslint-disable jsdoc/require-returns-description -- Compact helpers. */

import { collectPageContext } from './page-context'
import { getBlocks, getSelection } from './editor-abilities'

const SELECTED_NAMES_MAX = 8
const SELECTED_TEXT_MAX = 500

/**
 * Page identity safe to send with a report (no site URL or document title).
 *
 * @param {Object} [page]
 * @return {Object}
 */
export function slimPageContextForFeedback( page = {} ) {
	const postId = page?.post_id
	return {
		pathname: String( page?.pathname || '' ),
		search: String( page?.search || '' ),
		isAdmin: Boolean( page?.isAdmin ),
		is_block_editor: Boolean( page?.is_block_editor ),
		post_id: postId === undefined || postId === null || postId === '' ? null : postId,
		post_type: String( page?.post_type || '' ),
		editor_title: String( page?.editor_title || '' ),
		status: String( page?.status || '' ),
		is_dirty: Boolean( page?.is_dirty ),
		is_new: Boolean( page?.is_new ),
		blocks_count: Math.max( 0, Number( page?.blocks_count ) || 0 ),
	}
}

/**
 * Deterministic mismatch notes from the live tab (no LLM).
 *
 * @param {Object} page           Slim page context.
 * @param {Object} editorSnapshot Compact get-blocks / selection payload.
 * @return {Array<{ code: string, detail: string }>}
 */
export function buildRunFeedbackObservations( page = {}, editorSnapshot = {} ) {
	const observations = []
	const pathname = String( page?.pathname || '' )
	if ( pathname ) {
		observations.push( {
			code: 'pathname',
			detail: pathname,
		} )
	}
	if ( page?.isAdmin ) {
		observations.push( {
			code: 'admin_screen',
			detail: 'Open tab is wp-admin.',
		} )
	}
	if ( page?.is_block_editor ) {
		const parts = [
			page.post_type ? `post_type=${ page.post_type }` : '',
			`blocks_count=${ Number( page.blocks_count ) || 0 }`,
			page.is_dirty ? 'dirty' : 'clean',
		].filter( Boolean )
		observations.push( {
			code: 'block_editor_open',
			detail: parts.join( ' ' ),
		} )
		const selected = Array.isArray( editorSnapshot?.selection?.blocks )
			? editorSnapshot.selection.blocks
			: []
		const names = selected.map( block => block?.name ).filter( Boolean ).slice( 0, SELECTED_NAMES_MAX )
		if ( names.length ) {
			observations.push( {
				code: 'selection',
				detail: names.join( ', ' ),
			} )
		}
	} else {
		observations.push( {
			code: 'block_editor_closed',
			detail: 'Block editor is not open.',
		} )
	}
	return observations
}

/**
 * Collect live page + compact editor slice for the draft/file payload.
 *
 * @return {{ page_context: Object, editor_snapshot: Object, observations: Array }}
 */
export function collectRunFeedbackSnapshot() {
	const pageContext = slimPageContextForFeedback( collectPageContext() )
	let editorSnapshot = {
		available: false,
	}

	if ( pageContext.is_block_editor ) {
		try {
			const blocks = getBlocks( { max_blocks: 40 } )
			const selection = getSelection()
			editorSnapshot = {
				available: Boolean( blocks?.ok ),
				blocks: blocks?.ok ? blocks : null,
				selection: selection?.ok
					? {
						has_selection: Boolean( selection.has_selection ),
						count: Number( selection.count ) || 0,
						blocks: Array.isArray( selection.blocks ) ? selection.blocks : [],
						selected_text: String( selection.selected_text || '' ).slice( 0, SELECTED_TEXT_MAX ),
					}
					: null,
			}
		} catch {
			editorSnapshot = { available: false }
		}
	}

	return {
		page_context: pageContext,
		editor_snapshot: editorSnapshot,
		observations: buildRunFeedbackObservations( pageContext, editorSnapshot ),
	}
}
