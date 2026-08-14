/**
 * @jest-environment node
 */

import {
	pickMediaEssentialAttrs,
	resolveAttributePatch,
	looksLikeSmallOverlay,
	mediaKindFromEssentials,
	isCanvasBackgroundSurface,
} from './media-essentials'

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

	it( 'compacts Greenshift container background.image arrays without size/repeat', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					background: {
						image: [ 'https://example.com/uploads/home-hero.webp', null ],
						size: [ 'cover' ],
						repeat: [ 'no-repeat' ],
						positionImage: [ { x: '0.50', y: '0.63' } ],
					},
					id: 'gsbp-hero',
					width: [ 100 ],
				},
				'greenshift-blocks/container'
			)
		).toEqual( {
			background: {
				image: [ 'https://example.com/uploads/home-hero.webp', null ],
			},
		} )
	} )

	it( 'picks core/group style.background.backgroundImage without other style keys', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					style: {
						color: { background: '#111111' },
						background: {
							backgroundImage: {
								url: 'https://example.com/wp-content/uploads/hero.jpg',
							},
						},
					},
					layout: { type: 'constrained' },
				},
				'core/group'
			)
		).toEqual( {
			style: {
				background: {
					backgroundImage: {
						url: 'https://example.com/wp-content/uploads/hero.jpg',
					},
				},
			},
		} )
	} )

	it( 'picks a Stackable-style blockBackground image object', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					blockBackground: {
						image: { url: 'https://example.com/wp-content/uploads/banner.webp' },
					},
					uniqueId: 'abc',
				},
				'stackable/hero'
			)
		).toEqual( {
			blockBackground: {
				image: { url: 'https://example.com/wp-content/uploads/banner.webp' },
			},
		} )
	} )

	it( 'picks a Kadence-style bgImg URL string', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					bgImg: 'https://example.com/wp-content/uploads/row.jpg',
					bgImgID: 44,
					uniqueID: 'kad-1',
				},
				'kadence/rowlayout'
			)
		).toEqual( {
			bgImg: 'https://example.com/wp-content/uploads/row.jpg',
			bgImgID: 44,
		} )
	} )

	it( 'picks a GenerateBlocks-style bgImage nested url', () => {
		expect(
			pickMediaEssentialAttrs(
				{
					bgImage: {
						image: {
							url: 'https://example.com/wp-content/uploads/gb-hero.webp',
							id: 21,
						},
					},
					uniqueId: 'gb1',
				},
				'generateblocks/container'
			)
		).toEqual( {
			bgImage: {
				image: {
					url: 'https://example.com/wp-content/uploads/gb-hero.webp',
					id: 21,
				},
			},
		} )
	} )
} )

describe( 'looksLikeSmallOverlay', () => {
	it( 'treats a 120px Greenshift image as an overlay', () => {
		expect( looksLikeSmallOverlay( {
			originalWidth: 120,
			customWidth: [ 60 ],
			widthUnit: [ 'px' ],
		} ) ).toBe( true )
	} )

	it( 'does not treat percent-width containers as overlays', () => {
		expect( looksLikeSmallOverlay( { width: [ 100 ], widthUnit: [ '%' ] } ) ).toBe( false )
	} )

	it( 'treats a numeric width under 400px as an overlay on any library', () => {
		expect( looksLikeSmallOverlay( { width: 120 } ) ).toBe( true )
	} )
} )

describe( 'mediaKindFromEssentials', () => {
	it( 'marks core/cover url/alt/id as a background canvas, not an inline image', () => {
		expect(
			mediaKindFromEssentials(
				{
					url: 'https://example.com/hero.jpg',
					alt: 'Hero',
					id: 12,
				},
				'core/cover'
			)
		).toBe( 'background' )
	} )

	it( 'marks core/image url/alt/id as an inline image', () => {
		expect(
			mediaKindFromEssentials(
				{
					url: 'https://example.com/hero.jpg',
					alt: 'Hero',
					id: 12,
				},
				'core/image'
			)
		).toBe( 'image' )
	} )

	it( 'marks a non-image block with a URL as a background canvas', () => {
		expect(
			mediaKindFromEssentials(
				{ imageUrl: 'https://example.com/hero.jpg' },
				'acme/banner'
			)
		).toBe( 'background' )
		expect(
			isCanvasBackgroundSurface(
				'acme/banner',
				{ imageUrl: 'https://example.com/hero.jpg' }
			)
		).toBe( true )
	} )

	it( 'treats an empty cover as a canvas surface for retargeting', () => {
		expect( isCanvasBackgroundSurface( 'core/cover', {} ) ).toBe( true )
		expect( mediaKindFromEssentials( {}, 'core/cover' ) ).toBe( '' )
	} )

	it( 'marks nested background objects as canvas regardless of namespace', () => {
		expect(
			mediaKindFromEssentials(
				{ style: { background: { backgroundImage: { url: 'https://example.com/a.jpg' } } } },
				'core/group'
			)
		).toBe( 'background' )
		expect(
			mediaKindFromEssentials(
				{ blockBackground: { image: { url: 'https://example.com/a.jpg' } } },
				'stackable/hero'
			)
		).toBe( 'background' )
		expect(
			mediaKindFromEssentials(
				{ bgImg: 'https://example.com/a.jpg' },
				'kadence/rowlayout'
			)
		).toBe( 'background' )
		expect(
			mediaKindFromEssentials(
				{ bgImage: { image: { url: 'https://example.com/a.jpg' } } },
				'generateblocks/container'
			)
		).toBe( 'background' )
		expect(
			mediaKindFromEssentials(
				{ background: { image: [ 'https://example.com/a.jpg' ] } },
				'acme/banner'
			)
		).toBe( 'background' )
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

	it( 'maps guessed mediaurl onto Greenshift background.image and keeps size/repeat', () => {
		const oldUrl = 'https://example.com/uploads/home-hero.webp'
		const newUrl = 'https://example.com/uploads/farm.png'
		const resolved = resolveAttributePatch(
			{
				background: {
					image: [ oldUrl, null ],
					size: [ 'cover' ],
					repeat: [ 'no-repeat' ],
					positionImage: [ { x: '0.50', y: '0.63' } ],
				},
				id: 'gsbp-hero',
			},
			{
				mediaurl: newUrl,
				mediaid: 1295,
				alt: 'Outdoor farm',
			},
			{ blockName: 'greenshift-blocks/container' }
		)

		expect( resolved.patch.background ).toEqual( {
			image: [ newUrl, null ],
			size: [ 'cover' ],
			repeat: [ 'no-repeat' ],
			positionImage: [ { x: '0.50', y: '0.63' } ],
		} )
		expect( resolved.patch.mediaurl ).toBeUndefined()
		expect( resolved.remapped.mediaurl ).toBe( 'background.image' )
		expect( resolved.ignored ).toEqual( [ 'mediaid', 'alt' ] )
	} )

	it( 'rewrites the old URL inside a nested background.image array when CSS also holds it', () => {
		const oldUrl = 'https://example.com/uploads/home-hero.webp'
		const newUrl = 'https://example.com/uploads/farm.png'
		const resolved = resolveAttributePatch(
			{
				background: {
					image: [ oldUrl ],
					size: [ 'cover' ],
				},
				inlineCssStyles: `.hero{background-image:url(${ oldUrl })}`,
			},
			{ mediaurl: newUrl },
			{ blockName: 'greenshift-blocks/container' }
		)

		expect( resolved.patch.background.image ).toEqual( [ newUrl ] )
		expect( resolved.patch.inlineCssStyles ).toBe( `.hero{background-image:url(${ newUrl })}` )
	} )

	it( 'maps guessed mediaurl onto core/group style.background.backgroundImage and keeps sibling style', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/hero.jpg'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const resolved = resolveAttributePatch(
			{
				style: {
					color: { background: '#111111' },
					background: {
						backgroundImage: { url: oldUrl },
					},
				},
			},
			{ mediaurl: newUrl },
			{ blockName: 'core/group' }
		)

		expect( resolved.patch.style ).toEqual( {
			color: { background: '#111111' },
			background: {
				backgroundImage: { url: newUrl },
			},
		} )
		expect( resolved.remapped.mediaurl ).toBe( 'style.background.backgroundImage.url' )
	} )

	it( 'maps guessed mediaurl onto a Kadence-style bgImg string', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/row.jpg'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const resolved = resolveAttributePatch(
			{
				bgImg: oldUrl,
				bgImgID: 44,
			},
			{ mediaurl: newUrl, mediaid: 90 },
			{ blockName: 'kadence/rowlayout' }
		)

		expect( resolved.patch.bgImg ).toBe( newUrl )
		expect( resolved.patch.bgImgID ).toBe( 90 )
		expect( resolved.remapped.mediaurl ).toBe( 'bgImg' )
		expect( resolved.remapped.mediaid ).toBe( 'bgImgID' )
	} )

	it( 'maps guessed mediaurl onto a GenerateBlocks-style bgImage nested url', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/gb-hero.webp'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const resolved = resolveAttributePatch(
			{
				bgImage: {
					image: { url: oldUrl, id: 21 },
				},
			},
			{ mediaurl: newUrl, mediaid: 99 },
			{ blockName: 'generateblocks/container' }
		)

		expect( resolved.patch.bgImage ).toEqual( {
			image: { url: newUrl, id: 99 },
		} )
		expect( resolved.remapped.mediaurl ).toBe( 'bgImage.image.url' )
		expect( resolved.remapped.mediaid ).toBe( 'bgImage.image.id' )
	} )

	it( 'maps guessed mediaurl onto a Stackable-style blockBackground image url', () => {
		const oldUrl = 'https://example.com/wp-content/uploads/banner.webp'
		const newUrl = 'https://example.com/wp-content/uploads/farm.png'
		const resolved = resolveAttributePatch(
			{
				blockBackground: {
					image: { url: oldUrl },
					color: '#111111',
				},
			},
			{ mediaurl: newUrl },
			{ blockName: 'stackable/hero' }
		)

		expect( resolved.patch.blockBackground.image ).toEqual( { url: newUrl } )
		expect( resolved.patch.blockBackground.color ).toBe( '#111111' )
		expect( resolved.remapped.mediaurl ).toBe( 'blockBackground.image.url' )
	} )
} )
