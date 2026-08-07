/**
 * @jest-environment node
 */

import { resolveLiveStatusLabel, heartbeatAgeMs } from './sidebar-live-status'

describe( 'heartbeatAgeMs', () => {
	it( 'returns null for missing timestamps', () => {
		expect( heartbeatAgeMs( '' ) ).toBeNull()
		expect( heartbeatAgeMs( null ) ).toBeNull()
	} )

	it( 'returns a non-negative age for ISO timestamps', () => {
		const age = heartbeatAgeMs( new Date( Date.now() - 5000 ).toISOString() )
		expect( age ).toBeGreaterThanOrEqual( 4000 )
	} )
} )

describe( 'resolveLiveStatusLabel', () => {
	it( 'returns empty when not busy', () => {
		expect( resolveLiveStatusLabel( 'Working…', [], false, null, 'idle' ) ).toBe( '' )
	} )

	it( 'prefers HITL waiting copy when awaiting_human', () => {
		const label = resolveLiveStatusLabel(
			'',
			[],
			true,
			{ name: 'ahentic/create-post', summary: 'Create a draft' },
			'awaiting_human'
		)
		expect( label ).toMatch( /Waiting for your approval: Create a draft/ )
	} )

	it( 'uses tool_executed summary from the current run trace', () => {
		const label = resolveLiveStatusLabel(
			'Planning next steps…',
			[
				{ type: 'run_start', summary: 'Start' },
				{ type: 'tool_executed', summary: 'Installed plugin' },
			],
			true,
			null,
			'running'
		)
		expect( label ).toBe( 'Installed plugin' )
	} )
} )
