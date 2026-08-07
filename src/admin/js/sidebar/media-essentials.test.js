/**
 * @jest-environment node
 */

import { pickMediaEssentialAttrs } from './media-essentials'

describe( 'pickMediaEssentialAttrs', () => {
	it( 'returns core/image url, alt, and id from the known map', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					url: 'https://example.com/a.jpg',
					alt: 'A cat',
					id: 42,
					sizeSlug: 'large',
				},
				'core/image'
			)
		).toEqual( {
			url: 'https://example.com/a.jpg',
			alt: 'A cat',
			id: 42,
		} )
	} )

	it( 'returns core/media-text mediaUrl / mediaAlt / mediaId', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					mediaUrl: 'https://example.com/b.webp',
					mediaAlt: 'Beach',
					mediaId: 7,
					isStackedOnMobile: true,
				},
				'core/media-text'
			)
		).toEqual( {
			mediaUrl: 'https://example.com/b.webp',
			mediaAlt: 'Beach',
			mediaId: 7,
		} )
	} )

	it( 'guesses common media key names on unknown third-party blocks', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					imageUrl: 'https://cdn.example.com/hero.png',
					imageAlt: 'Hero shot',
					imageId: 99,
					layout: 'full',
				},
				'acme/hero-image'
			)
		).toEqual( {
			imageUrl: 'https://cdn.example.com/hero.png',
			imageAlt: 'Hero shot',
			imageId: 99,
		} )
	} )

	it( 'finds an image-looking URL value when keys are nonstandard', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					background: 'https://uploads.example.com/2024/01/photo.jpeg',
					theme: 'dark',
				},
				'acme/banner'
			)
		).toEqual( {
			background: 'https://uploads.example.com/2024/01/photo.jpeg',
		} )
	} )

	it( 'pulls url out of a shallow object attribute', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					image: { url: 'https://example.com/nested.png', id: 3 },
					label: 'x',
				},
				'acme/card'
			)
		).toEqual( {
			image: { url: 'https://example.com/nested.png', id: 3 },
		} )
	} )

	it( 'skips link/href/url keys that are not image URLs', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					url: 'https://example.com/about',
					href: 'https://example.com/about',
					link: 'https://example.com/contact',
					title: 'Hello',
				},
				'core/button'
			)
		).toEqual( {} )
	} )

	it( 'does not treat bare alt/id on non-media blocks as media essentials', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					alt: 'not an image block', id: 123, content: 'Hi',
				},
				'core/paragraph'
			)
		).toEqual( {} )
	} )

	it( 'accepts extension-less https on strongly media-named keys', () => {
		expect(
			pickMediaEssentialAttrs(
				{ imageUrl: 'https://images.example.com/photo?w=800', imageAlt: 'Sky' },
				'acme/photo'
			)
		).toEqual( {
			imageUrl: 'https://images.example.com/photo?w=800',
			imageAlt: 'Sky',
		} )
	} )
} )
