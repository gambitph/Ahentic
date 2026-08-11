/**
 * @jest-environment node
 */

import {
	MIN_VISIBLE_PLAN_STEPS,
	resolvePlanCardPresentation,
	shouldShowPlanCard,
} from './plan-card'

const allDonePlan = {
	title: 'Ship the page',
	steps: [
		{
			id: '1', content: 'Draft', status: 'completed',
		},
		{
			id: '2', content: 'Polish', status: 'completed',
		},
		{
			id: '3', content: 'Publish', status: 'completed',
		},
	],
}

describe( 'shouldShowPlanCard', () => {
	it( 'hides plans with fewer than three steps', () => {
		expect( MIN_VISIBLE_PLAN_STEPS ).toBe( 3 )
		expect( shouldShowPlanCard( {
			steps: [
				{
					id: '1', content: 'A', status: 'pending',
				},
				{
					id: '2', content: 'B', status: 'pending',
				},
			],
		} ) ).toBe( false )
		expect( shouldShowPlanCard( allDonePlan ) ).toBe( true )
		expect( shouldShowPlanCard( null ) ).toBe( false )
	} )
} )

describe( 'resolvePlanCardPresentation', () => {
	it( 'marks short plans as not visible', () => {
		const view = resolvePlanCardPresentation(
			{
				steps: [
					{
						id: '1', content: 'A', status: 'completed',
					},
					{
						id: '2', content: 'B', status: 'cancelled',
					},
				],
			},
			{ busy: false }
		)
		expect( view.visible ).toBe( false )
		expect( view.showComplete ).toBe( false )
		expect( view.stopped ).toBe( false )
	} )

	it( 'shows Plan complete when all steps are done and the session is idle', () => {
		const view = resolvePlanCardPresentation( allDonePlan, { busy: false } )
		expect( view.visible ).toBe( true )
		expect( view.showComplete ).toBe( true )
		expect( view.wrappingUp ).toBe( false )
		expect( view.stateClass ).toBe( ' is-complete' )
		expect( view.eyebrow ).toMatch( /complete/i )
		expect( view.done ).toBe( 3 )
		expect( view.total ).toBe( 3 )
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
		expect( view.done ).toBe( 3 )
		expect( view.total ).toBe( 3 )
		// Last step stays visually in progress so the card is not all-green checks.
		expect( view.steps.map( step => step.status ) ).toEqual( [
			'completed',
			'completed',
			'in_progress',
		] )
	} )

	it( 'shows Plan stopped when cancelled steps remain after idle', () => {
		const view = resolvePlanCardPresentation(
			{
				steps: [
					{
						id: '1', content: 'A', status: 'completed',
					},
					{
						id: '2', content: 'B', status: 'cancelled',
					},
					{
						id: '3', content: 'C', status: 'cancelled',
					},
				],
			},
			{ busy: false }
		)
		expect( view.stopped ).toBe( true )
		expect( view.stateClass ).toBe( ' is-stopped' )
		expect( view.eyebrow ).toMatch( /stopped/i )
	} )

	it( 'shows Waiting for you when idle with unfinished steps (ask_user pause)', () => {
		const view = resolvePlanCardPresentation(
			{
				steps: [
					{
						id: '1', content: 'Confirm username', status: 'completed',
					},
					{
						id: '2', content: 'Ask for email', status: 'pending',
					},
					{
						id: '3', content: 'Create the user', status: 'pending',
					},
				],
			},
			{ busy: false }
		)
		expect( view.showComplete ).toBe( false )
		expect( view.waitingOnUser ).toBe( true )
		expect( view.stopped ).toBe( false )
		expect( view.stateClass ).toBe( ' is-paused' )
		expect( view.eyebrow ).toMatch( /waiting/i )
	} )

	it( 'keeps an in-progress plan as Plan while busy', () => {
		const view = resolvePlanCardPresentation(
			{
				steps: [
					{
						id: '1', content: 'A', status: 'completed',
					},
					{
						id: '2', content: 'B', status: 'in_progress',
					},
					{
						id: '3', content: 'C', status: 'pending',
					},
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
			'pending',
		] )
	} )
} )
