/**
 * @jest-environment node
 */

import { progressLabelForAbility } from './progress-label'

describe( 'progressLabelForAbility', () => {
	it( 'uses the provided PHP bootstrap map when present', () => {
		expect(
			progressLabelForAbility( 'ahentic/create-post', {
				'ahentic/create-post': 'Creating a draft post…',
			} )
		).toBe( 'Creating a draft post…' )
	} )

	it( 'falls back to a slug-based label when the map misses', () => {
		expect( progressLabelForAbility( 'ahentic/some-new-tool', {} ) ).toMatch( /some new tool/i )
	} )

	it( 'falls back to Working when the ability name is empty', () => {
		expect( progressLabelForAbility( '', {} ) ).toMatch( /working/i )
	} )
} )
