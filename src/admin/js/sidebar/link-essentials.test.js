/**
 * @jest-environment node
 */

import { pickLinkEssentialAttrs } from './link-essentials'

describe( 'pickLinkEssentialAttrs', () => {
	it( 'picks button url and label keys from third-party attrs', () => {
		expect(
			pickLinkEssentialAttrs( {
				buttonContent: 'Shop Now',
				buttonUrl: 'http://ai.local/about-us/',
				backBackgroundColor: '#000000b3',
				width: 100,
			} )
		).toEqual( {
			buttonContent: 'Shop Now',
			buttonUrl: 'http://ai.local/about-us/',
		} )
	} )

	it( 'picks href and label', () => {
		expect(
			pickLinkEssentialAttrs( {
				href: '/about-us/',
				label: 'Learn more',
				align: 'center',
			} )
		).toEqual( {
			href: '/about-us/',
			label: 'Learn more',
		} )
	} )

	it( 'ignores non-link design tokens', () => {
		expect(
			pickLinkEssentialAttrs( {
				background: '#fff',
				rowLayout: 'flex',
				id: 'gsbp-e0dc819',
			} )
		).toEqual( {} )
	} )
} )
