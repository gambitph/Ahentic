/**
 * @jest-environment node
 */

import { getShortcutLabel, truncateTitle } from './sidebar-chrome-utils'

describe( 'truncateTitle', () => {
	it( 'returns short titles unchanged', () => {
		expect( truncateTitle( 'Hello' ) ).toBe( 'Hello' )
	} )

	it( 'truncates long titles with an ellipsis', () => {
		const long = 'abcdefghijklmnopqrstuvwxyz0123456789'
		expect( truncateTitle( long, 10 ) ).toBe( 'abcdefghi…' )
	} )
} )

describe( 'getShortcutLabel', () => {
	it( 'returns a non-empty shortcut label', () => {
		expect( getShortcutLabel().length ).toBeGreaterThan( 0 )
	} )
} )
