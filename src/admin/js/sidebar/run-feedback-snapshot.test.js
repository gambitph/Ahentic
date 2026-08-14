/**
 * @jest-environment node
 */

import {
	buildRunFeedbackObservations,
	slimPageContextForFeedback,
} from './run-feedback-snapshot'

describe( 'slimPageContextForFeedback', () => {
	it( 'keeps pathname and editor flags, drops site URL and document title', () => {
		expect( slimPageContextForFeedback( {
			url: 'https://should-not-leak.example/wp-admin/post.php?post=12',
			title: 'Edit Page ‹ Example',
			pathname: '/wp-admin/post.php',
			search: '?post=12&action=edit',
			isAdmin: true,
			bodyClass: 'wp-admin',
			is_block_editor: true,
			post_id: 12,
			post_type: 'page',
			editor_title: 'About',
			status: 'draft',
			is_dirty: true,
			is_new: false,
			blocks_count: 4,
		} ) ).toEqual( {
			pathname: '/wp-admin/post.php',
			search: '?post=12&action=edit',
			isAdmin: true,
			is_block_editor: true,
			post_id: 12,
			post_type: 'page',
			editor_title: 'About',
			status: 'draft',
			is_dirty: true,
			is_new: false,
			blocks_count: 4,
		} )
	} )
} )

describe( 'buildRunFeedbackObservations', () => {
	it( 'notes when the block editor is closed', () => {
		const observations = buildRunFeedbackObservations(
			slimPageContextForFeedback( {
				pathname: '/wp-admin/options-general.php',
				isAdmin: true,
				is_block_editor: false,
			} ),
			{ available: false }
		)
		expect( observations ).toEqual(
			expect.arrayContaining( [
				{ code: 'pathname', detail: '/wp-admin/options-general.php' },
				{ code: 'admin_screen', detail: 'Open tab is wp-admin.' },
				{ code: 'block_editor_closed', detail: 'Block editor is not open.' },
			] )
		)
	} )

	it( 'notes editor open, dirty, and selected block names', () => {
		const observations = buildRunFeedbackObservations(
			{
				pathname: '/wp-admin/post.php',
				isAdmin: true,
				is_block_editor: true,
				post_type: 'page',
				blocks_count: 4,
				is_dirty: true,
			},
			{
				available: true,
				selection: {
					has_selection: true,
					blocks: [
						{ name: 'core/heading', preview: 'Welcome' },
						{ name: 'core/paragraph' },
					],
				},
			}
		)
		expect( observations ).toEqual(
			expect.arrayContaining( [
				{
					code: 'block_editor_open',
					detail: 'post_type=page blocks_count=4 dirty',
				},
				{
					code: 'selection',
					detail: 'core/heading, core/paragraph',
				},
			] )
		)
	} )
} )
