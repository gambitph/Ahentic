/**
 * Collect lightweight page context for the agent (URL / title / admin / editor hints).
 */

/* eslint-disable camelcase -- Ability / pageContext I/O matches PHP schema snake_case. */
/* eslint-disable jsdoc/require-returns-description -- Compact helpers. */

/**
 * Peek block-editor store state when Gutenberg is open.
 *
 * @return {Object}
 */
function peekEditorContext() {
	const empty = {
		is_block_editor: false,
		post_id: null,
		post_type: '',
		editor_title: '',
		status: '',
		is_dirty: false,
		is_new: false,
		blocks_count: 0,
	}

	const wp = typeof window !== 'undefined' ? window.wp : null
	if ( ! wp?.data?.select ) {
		return empty
	}

	try {
		const editor = wp.data.select( 'core/editor' )
		const blockEditor = wp.data.select( 'core/block-editor' )
		if ( ! editor?.getCurrentPostId || ! blockEditor?.getBlocks ) {
			return empty
		}

		const postType = editor.getCurrentPostType?.() || ''
		const postId = editor.getCurrentPostId?.()
		const pathname = window.location?.pathname || ''
		const bodyClass = typeof document !== 'undefined' && document.body
			? String( document.body.className || '' )
			: ''
		const looksLikeEditorUrl = /post-new\.php|post\.php|site-editor\.php/.test( pathname ) ||
			/\bblock-editor-page\b|\bsite-editor\b|\bpost-type-/.test( bodyClass )

		// core/editor can exist outside the post editor; require a post type or editor URL.
		if ( ! postType && ! looksLikeEditorUrl ) {
			return empty
		}

		return {
			is_block_editor: true,
			post_id: postId === undefined || postId === null ? null : postId,
			post_type: postType,
			editor_title: editor.getEditedPostAttribute?.( 'title' ) || '',
			status: editor.getEditedPostAttribute?.( 'status' ) || '',
			is_dirty: Boolean( editor.isEditedPostDirty?.() ),
			is_new: Boolean( editor.isEditedPostNew?.() ),
			blocks_count: ( blockEditor.getBlocks?.() || [] ).length,
		}
	} catch ( error ) {
		return empty
	}
}

/**
 * Collect lightweight page context for the agent (URL / title / admin hints).
 *
 * @return {Object}
 */
export function collectPageContext() {
	if ( typeof window === 'undefined' || ! window.location ) {
		return {
			url: '',
			title: '',
			pathname: '',
			search: '',
			hash: '',
			isAdmin: false,
			bodyClass: '',
			...peekEditorContext(),
		}
	}

	const pathname = window.location.pathname || ''
	const isAdmin = Boolean( window.ahentic?.isAdmin ) || /\/wp-admin(?:\/|$)/.test( pathname )
	const bodyClass = typeof document !== 'undefined' && document.body
		? String( document.body.className || '' ).slice( 0, 500 )
		: ''

	return {
		url: window.location.href || '',
		title: typeof document !== 'undefined' ? String( document.title || '' ) : '',
		pathname,
		search: window.location.search || '',
		hash: window.location.hash || '',
		isAdmin,
		bodyClass,
		...peekEditorContext(),
	}
}
