/**
 * Site-wide daily token limits — default 1M + sticky temp-boost regression.
 *
 * REST-direct covers Ahentic_Usage gate/status against a live Playground
 * option store. Browser covers the Settings form field after a fresh reset
 * (no ahentic_token_limits option).
 */
const { test, expect } = require( '../fixtures/test' )
const { usageLimits } = require( '../utils/ability-client' )

test.describe( 'Daily token limits (REST)', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		const reset = await usageLimits( requestUtils, { action: 'reset' } )
		expect( reset.ok ).toBe( true )
		expect( reset.option_present ).toBe( false )
	} )

	test.afterEach( async ( { requestUtils } ) => {
		await usageLimits( requestUtils, { action: 'reset' } )
	} )

	test( 'fresh install defaults to a 1M daily limit', async ( { requestUtils } ) => {
		const body = await usageLimits( requestUtils )

		expect( body.ok ).toBe( true )
		expect( body.option_present ).toBe( false )
		expect( body.default_daily ).toBe( 1_000_000 )
		expect( body.status ).toMatchObject( {
			daily_limit: 1_000_000,
			effective_limit: 1_000_000,
			today_used: 0,
			blocked: false,
			runaway_locked: false,
		} )
		expect( body.may_spend ).toBe( true )
	} )

	test( 'raising the permanent limit supersedes a lower same-day temp boost', async ( {
		requestUtils,
	} ) => {
		const today = ( await usageLimits( requestUtils ) ).status.today

		await usageLimits( requestUtils, {
			action: 'set_limits',
			limits: {
				daily_limit: 1000,
				runaway_locked: false,
				streak: 0,
				last_hit_day: '',
				temp_limit: 1100,
				temp_limit_day: today,
			},
		} )
		await usageLimits( requestUtils, {
			action: 'set_today_used',
			total: 1100,
		} )

		const before = await usageLimits( requestUtils )
		expect( before.status.effective_limit ).toBe( 1100 )
		expect( before.status.today_used ).toBe( 1100 )
		expect( before.may_spend ).toBe( false )
		expect( before.may_spend_code ).toBe( 'ahentic_daily_token_limit' )

		const raised = await usageLimits( requestUtils, {
			action: 'raise_daily',
			daily_limit: 5_000_000,
		} )

		expect( raised.status.daily_limit ).toBe( 5_000_000 )
		expect( raised.status.effective_limit ).toBe( 5_000_000 )
		expect( raised.limits.temp_limit ).toBe( 0 )
		expect( raised.limits.temp_limit_day ).toBe( '' )
		expect( raised.status.today_used ).toBe( 1100 )
		expect( raised.may_spend ).toBe( true )
		expect( raised.status.blocked ).toBe( false )
	} )

	test( 'stale lower temp boost cannot pin the gate below the permanent limit', async ( {
		requestUtils,
	} ) => {
		const today = ( await usageLimits( requestUtils ) ).status.today

		// Simulate the pre-fix option shape: permanent already raised, but an
		// obsolete same-day temp boost remains stored.
		await usageLimits( requestUtils, {
			action: 'set_limits',
			limits: {
				daily_limit: 5_000_000,
				runaway_locked: false,
				streak: 0,
				last_hit_day: '',
				temp_limit: 110_001,
				temp_limit_day: today,
			},
		} )
		await usageLimits( requestUtils, {
			action: 'set_today_used',
			total: 547_089,
		} )

		const body = await usageLimits( requestUtils )
		expect( body.status.daily_limit ).toBe( 5_000_000 )
		expect( body.status.effective_limit ).toBe( 5_000_000 )
		expect( body.limits.temp_limit ).toBe( 110_001 )
		expect( body.may_spend ).toBe( true )
		expect( body.status.blocked ).toBe( false )
	} )
} )

test.describe( 'Daily token limits (Settings UI)', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await usageLimits( requestUtils, { action: 'reset' } )
	} )

	test.afterEach( async ( { requestUtils } ) => {
		await usageLimits( requestUtils, { action: 'reset' } )
	} )

	test( 'settings field shows 1000000 when no limits option is stored', async ( {
		page,
		requestUtils,
	} ) => {
		const reset = await usageLimits( requestUtils, { action: 'reset' } )
		expect( reset.option_present ).toBe( false )

		await page.goto( '/wp-admin/options-general.php?page=ahentic' )
		const input = page.locator( '#ahentic_daily_limit' )
		await expect( input ).toBeVisible( { timeout: 15_000 } )
		await expect( input ).toHaveValue( '1000000' )
	} )
} )
