/**
 * @jest-environment node
 */

import {
	DEFAULT_WIDTH,
	FLOATING_GAP,
	MIN_FLOAT_HEIGHT,
	MIN_WIDTH,
	PLACEMENTS,
	recoverFloatingRectOnOpen,
} from './constants'

const VIEW = {
	width: 1280,
	height: 800,
}

describe( 'recoverFloatingRectOnOpen', () => {
	it( 'nudges a rect that spills past the right/bottom back inside with floating gap', () => {
		const recovered = recoverFloatingRectOnOpen(
			{
				left: 1100,
				top: 700,
				width: 400,
				height: 400,
			},
			PLACEMENTS.FLOATING,
			{ viewport: VIEW }
		)

		expect( recovered.left + recovered.width ).toBeLessThanOrEqual( VIEW.width - FLOATING_GAP )
		expect( recovered.top + recovered.height ).toBeLessThanOrEqual( VIEW.height - FLOATING_GAP )
		expect( recovered.left ).toBeGreaterThanOrEqual( FLOATING_GAP )
		expect( recovered.top ).toBeGreaterThanOrEqual( FLOATING_GAP )
		expect( recovered.width ).toBe( 400 )
		expect( recovered.height ).toBe( 400 )
	} )

	it( 'nudges a rect that sits past the left/top back inside with floating gap', () => {
		const recovered = recoverFloatingRectOnOpen(
			{
				left: -200,
				top: -100,
				width: 360,
				height: 500,
			},
			PLACEMENTS.FLOATING,
			{ viewport: VIEW }
		)

		expect( recovered.left ).toBe( FLOATING_GAP )
		expect( recovered.top ).toBe( FLOATING_GAP )
		expect( recovered.width ).toBe( 360 )
		expect( recovered.height ).toBe( 500 )
	} )

	it( 'leaves an already-valid floating rect unchanged', () => {
		const rect = {
			left: 100,
			top: 80,
			width: 360,
			height: 500,
		}
		expect( recoverFloatingRectOnOpen( rect, PLACEMENTS.FLOATING, { viewport: VIEW } ) ).toEqual( rect )
	} )

	it( 'restores default floating width when width is below minimum', () => {
		const recovered = recoverFloatingRectOnOpen(
			{
				left: 100,
				top: 80,
				width: 120,
				height: 500,
			},
			PLACEMENTS.FLOATING,
			{ viewport: VIEW }
		)

		expect( recovered.width ).toBe( DEFAULT_WIDTH )
		expect( recovered.width ).toBeGreaterThanOrEqual( MIN_WIDTH )
	} )

	it( 'restores placement default height when height is below minimum', () => {
		const recovered = recoverFloatingRectOnOpen(
			{
				left: 100,
				top: 80,
				width: 360,
				height: 100,
			},
			PLACEMENTS.FLOATING,
			{ viewport: VIEW }
		)

		expect( recovered.height ).toBe( VIEW.height - ( FLOATING_GAP * 2 ) )
		expect( recovered.height ).toBeGreaterThanOrEqual( MIN_FLOAT_HEIGHT )
	} )

	it( 'restores floating-small default height when height is below minimum', () => {
		const recovered = recoverFloatingRectOnOpen(
			{
				left: 100,
				top: 80,
				width: 360,
				height: 50,
			},
			PLACEMENTS.FLOATING_SMALL,
			{ viewport: VIEW }
		)

		expect( recovered.height ).toBe( 600 )
	} )
} )
