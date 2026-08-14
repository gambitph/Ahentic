/**
 * @jest-environment node
 */

import { pickMediaEssentialAttrs, resolveAttributePatch } from './media-essentials'

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

describe( 'resolveAttributePatch', () => {
	it( 'remaps guessed lowercase media keys onto live camelCase keys', () => {
		const resolved = resolveAttributePatch(
			{
				mediaUrl: 'https://example.com/old.jpg',
				mediaId: 10,
				alt: 'Old field',
				layout: 'full',
			},
			{
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
			{ blockName: 'greenshift-blocks/image' }
		)

		expect( resolved.patch ).toEqual( {
			mediaUrl: 'https://example.com/farm.png',
			mediaId: 1291,
			alt: 'Outdoor farm',
		} )
		expect( resolved.remapped ).toEqual( {
			mediaurl: 'mediaUrl',
			mediaid: 'mediaId',
		} )
		expect( resolved.ignored ).toEqual( [] )
	} )

	it( 'deep-merges a guessed media URL onto a nested image object', () => {
		const resolved = resolveAttributePatch(
			{
				backgroundImage: {
					url: 'https://example.com/old.jpg',
					id: 10,
					alt: 'Old field',
					width: 1600,
				},
				layout: 'hero',
			},
			{
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
			{ blockName: 'greenshift-blocks/container' }
		)

		expect( resolved.patch ).toEqual( {
			backgroundImage: {
				url: 'https://example.com/farm.png',
				id: 1291,
				alt: 'Outdoor farm',
				width: 1600,
			},
		} )
		expect( resolved.remapped ).toEqual( {
			mediaurl: 'backgroundImage.url',
			mediaid: 'backgroundImage.id',
			alt: 'backgroundImage.alt',
		} )
		expect( resolved.ignored ).toEqual( [] )
	} )

	it( 'rewrites the old media URL inside compiled CSS strings', () => {
		const oldUrl = 'https://example.com/old.jpg'
		const newUrl = 'https://example.com/farm.png'
		const resolved = resolveAttributePatch(
			{
				mediaUrl: oldUrl,
				mediaId: 10,
				inlineCssStyles: `.hero{background-image:url(${ oldUrl })}`,
			},
			{ mediaurl: newUrl, mediaid: 1291 },
			{ blockName: 'greenshift-blocks/image' }
		)

		expect( resolved.patch.mediaUrl ).toBe( newUrl )
		expect( resolved.patch.mediaId ).toBe( 1291 )
		expect( resolved.patch.inlineCssStyles ).toBe( `.hero{background-image:url(${ newUrl })}` )
	} )

	it( 'does not invent media keys when the live block has no media', () => {
		const resolved = resolveAttributePatch(
			{ content: 'Hello', dropCap: false },
			{
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
			{ blockName: 'core/paragraph' }
		)

		expect( resolved.patch ).toEqual( {} )
		expect( resolved.ignored ).toEqual( [ 'mediaurl', 'mediaid', 'alt' ] )
	} )

	it( 'keeps exact core/image url/alt/id keys without remapping', () => {
		const resolved = resolveAttributePatch(
			{
				url: 'https://example.com/old.jpg',
				alt: 'Old',
				id: 5,
			},
			{
				url: 'https://example.com/farm.png',
				alt: 'Outdoor farm',
				id: 1291,
			},
			{ blockName: 'core/image' }
		)

		expect( resolved.patch ).toEqual( {
			url: 'https://example.com/farm.png',
			alt: 'Outdoor farm',
			id: 1291,
		} )
		expect( resolved.remapped ).toEqual( {} )
		expect( resolved.ignored ).toEqual( [] )
	} )

	it( 'maps guessed mediaurl/mediaid onto core/image url/id', () => {
		const resolved = resolveAttributePatch(
			{
				url: 'https://example.com/old.jpg',
				alt: 'Old',
				id: 5,
			},
			{
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
			{ blockName: 'core/image' }
		)

		expect( resolved.patch ).toEqual( {
			url: 'https://example.com/farm.png',
			id: 1291,
			alt: 'Outdoor farm',
		} )
		expect( resolved.remapped ).toEqual( {
			mediaurl: 'url',
			mediaid: 'id',
		} )
		expect( resolved.ignored ).toEqual( [] )
	} )

	it( 'ignores an empty unused mediaUrl and remaps onto nested live media', () => {
		const resolved = resolveAttributePatch(
			{
				mediaUrl: '',
				backgroundImage: {
					url: 'https://example.com/old.jpg',
					id: 10,
					alt: 'Old field',
					width: 1600,
				},
			},
			{
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
			{ blockName: 'greenshift-blocks/container' }
		)

		expect( resolved.patch.mediaUrl ).toBeUndefined()
		expect( resolved.patch.backgroundImage ).toEqual( {
			url: 'https://example.com/farm.png',
			id: 1291,
			alt: 'Outdoor farm',
			width: 1600,
		} )
		expect( resolved.remapped ).toEqual( {
			mediaurl: 'backgroundImage.url',
			mediaid: 'backgroundImage.id',
			alt: 'backgroundImage.alt',
		} )
	} )

	it( 'rewrites compiled CSS when the live media URL has no file extension', () => {
		const oldUrl = 'https://images.example.com/photo?w=1600'
		const newUrl = 'https://example.com/farm.png'
		const resolved = resolveAttributePatch(
			{
				backgroundImage: { url: oldUrl, id: 10 },
				inlineCssStyles: `.hero{background-image:url(${ oldUrl })}`,
			},
			{ mediaurl: newUrl, mediaid: 1291 },
			{ blockName: 'greenshift-blocks/container' }
		)

		expect( resolved.patch.backgroundImage.url ).toBe( newUrl )
		expect( resolved.patch.inlineCssStyles ).toBe( `.hero{background-image:url(${ newUrl })}` )
	} )

	it( 'maps guessed mediaid onto a nested mediaId field', () => {
		const resolved = resolveAttributePatch(
			{
				backgroundImage: {
					url: 'https://example.com/old.jpg',
					mediaId: 10,
					alt: 'Old field',
					width: 1600,
				},
			},
			{
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
			},
			{ blockName: 'greenshift-blocks/container' }
		)

		expect( resolved.patch.backgroundImage ).toEqual( {
			url: 'https://example.com/farm.png',
			mediaId: 1291,
			alt: 'Old field',
			width: 1600,
		} )
		expect( resolved.remapped.mediaid ).toBe( 'backgroundImage.mediaId' )
		expect( resolved.patch.backgroundImage.id ).toBeUndefined()
	} )

	it( 'does not rewrite href or prose that only mentions the old media URL', () => {
		const oldUrl = 'https://example.com/old.jpg'
		const newUrl = 'https://example.com/farm.png'
		const resolved = resolveAttributePatch(
			{
				mediaUrl: oldUrl,
				href: oldUrl,
				content: `See ${ oldUrl }`,
				inlineCssStyles: `.hero{background-image:url(${ oldUrl })}`,
			},
			{ mediaurl: newUrl },
			{ blockName: 'greenshift-blocks/image' }
		)

		expect( resolved.patch.mediaUrl ).toBe( newUrl )
		expect( resolved.patch.href ).toBeUndefined()
		expect( resolved.patch.content ).toBeUndefined()
		expect( resolved.patch.inlineCssStyles ).toBe( `.hero{background-image:url(${ newUrl })}` )
	} )

	it( 'does not invent a schema-only mediaurl when the live block has no media', () => {
		const resolved = resolveAttributePatch(
			{ content: 'Hello', dropCap: false },
			{
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
			{
				blockName: 'core/paragraph',
				schemaKeys: [ 'content', 'dropCap', 'mediaurl', 'mediaid', 'alt' ],
			}
		)

		expect( resolved.patch ).toEqual( {} )
		expect( resolved.ignored ).toEqual( [ 'mediaurl', 'mediaid', 'alt' ] )
	} )

	it( 'still writes a non-image live url key such as a button link', () => {
		const resolved = resolveAttributePatch(
			{ url: 'https://example.com/about', text: 'About' },
			{ url: 'https://example.com/contact' },
			{ blockName: 'core/button' }
		)

		expect( resolved.patch ).toEqual( { url: 'https://example.com/contact' } )
		expect( resolved.ignored ).toEqual( [] )
	} )

	it( 'prefers live nested media over a schema-only mediaurl key', () => {
		const resolved = resolveAttributePatch(
			{
				backgroundImage: {
					url: 'https://example.com/old.jpg',
					id: 10,
					alt: 'Old field',
					width: 1600,
				},
			},
			{
				mediaurl: 'https://example.com/farm.png',
				mediaid: 1291,
				alt: 'Outdoor farm',
			},
			{
				blockName: 'greenshift-blocks/container',
				schemaKeys: [ 'mediaurl', 'mediaid', 'alt', 'backgroundImage' ],
			}
		)

		expect( resolved.patch.mediaurl ).toBeUndefined()
		expect( resolved.patch.backgroundImage ).toEqual( {
			url: 'https://example.com/farm.png',
			id: 1291,
			alt: 'Outdoor farm',
			width: 1600,
		} )
	} )
} )
