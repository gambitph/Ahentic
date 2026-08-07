/**
 * @jest-environment node
 */

import { resolvePlanCardPresentation } from './plan-card'

const allDonePlan = {
	title: 'Ship the page',
	steps: [
		{ id: '1', content: 'Draft', status: 'completed' },
		{ id: '2', content: 'Polish', status: 'completed' },
	],
}

describe( 'resolvePlanCardPresentation', () => {
	it( 'shows Plan complete when all steps are done and the session is idle', () => {
		const view = resolvePlanCardPresentation( allDonePlan, { busy: false } )
		expect( view.showComplete ).toBe( true )
		expect( view.wrappingUp ).toBe( false )
		expect( view.stateClass ).toBe( ' is-complete' )
		expect( view.eyebrow ).toMatch( /complete/i )
		expect( view.done ).toBe( 2 )
		expect( view.total ).toBe( 2 )
		expect( view.steps.every( step => step.status === 'completed' ) ).toBe( true )
	} )

	// Regression: model may mark every checklist step completed while finish gate /
	// verify / live status is still running — do not celebrate early.
	it( 'does not show Plan complete while the session is still busy', () => {
		const view = resolvePlanCardPresentation( allDonePlan, { busy: true } )
		expect( view.showComplete ).toBe( false )
		expect( view.wrappingUp ).toBe( true )
		expect( view.stateClass ).toBe( ' is-wrapping-up' )
		expect( view.eyebrow ).toMatch( /finishing/i )
		expect( view.done ).toBe( 2 )
		expect( view.total ).toBe( 2 )
		// Last step stays visually in progress so the card is not all-green checks.
		expect( view.steps.map( step => step.status ) ).toEqual( [
			'completed',
			'in_progress',
		] )
	} )

	it( 'shows Plan stopped when cancelled steps remain after idle', () => {
		const view = resolvePlanCardPresentation(
			{
				steps: [
					{ id: '1', content: 'A', status: 'completed' },
					{ id: '2', content: 'B', status: 'cancelled' },
				],
			},
			{ busy: false }
		)
		expect( view.stopped ).toBe( true )
		expect( view.stateClass ).toBe( ' is-stopped' )
		expect( view.eyebrow ).toMatch( /stopped/i )
	} )

	it( 'keeps an in-progress plan as Plan while busy', () => {
		const view = resolvePlanCardPresentation(
			{
				steps: [
					{ id: '1', content: 'A', status: 'completed' },
					{ id: '2', content: 'B', status: 'in_progress' },
				],
			},
			{ busy: true }
		)
		expect( view.showComplete ).toBe( false )
		expect( view.wrappingUp ).toBe( false )
		expect( view.stopped ).toBe( false )
		expect( view.eyebrow ).toBe( 'Plan' )
		expect( view.steps.map( step => step.status ) ).toEqual( [
			'completed',
			'in_progress',
		] )
	} )
} )
