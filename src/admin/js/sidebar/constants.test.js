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
	createTab,
	createSessionTitleFromTab,
	tabAllowsAutoTitle,
	tabFromSession,
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

describe( 'autoTitle tab identity', () => {
	it( 'marks new local tabs as auto-titled', () => {
		const tab = createTab()
		expect( tab.autoTitle ).toBe( true )
		expect( createSessionTitleFromTab( tab ) ).toBeUndefined()
	} )

	it( 'sends a custom title only when autoTitle is false', () => {
		expect( createSessionTitleFromTab( {
			title: 'New Agent',
			autoTitle: true,
		} ) ).toBeUndefined()
		expect( createSessionTitleFromTab( {
			title: 'Launch checklist',
			autoTitle: false,
		} ) ).toBe( 'Launch checklist' )
	} )

	it( 'blocks client auto-rename when autoTitle is false', () => {
		expect( tabAllowsAutoTitle( { autoTitle: true } ) ).toBe( true )
		expect( tabAllowsAutoTitle( { autoTitle: false } ) ).toBe( false )
		expect( tabAllowsAutoTitle( { title: 'Anything' } ) ).toBe( true )
	} )

	it( 'copies autoTitle from the session payload', () => {
		expect( tabFromSession( {
			id: 42,
			title: 'Custom',
			autoTitle: false,
			status: 'idle',
		} ).autoTitle ).toBe( false )
		expect( tabFromSession( {
			id: 43,
			title: 'New Agent',
			autoTitle: true,
			status: 'idle',
		} ).autoTitle ).toBe( true )
	} )
} )
