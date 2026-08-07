/**
 * @jest-environment node
 */

import {
	STALE_MS,
	createSessionRunnerLock,
} from './session-runner-lock'

function createMemoryStorage() {
	const map = new Map()
	return {
		getItem( key ) {
			return map.has( key ) ? map.get( key ) : null
		},
		setItem( key, value ) {
			map.set( key, String( value ) )
		},
		removeItem( key ) {
			map.delete( key )
		},
	}
}

describe( 'session-runner-lock', () => {
	it( 'lets the first owner claim a session when no claim exists', () => {
		const storage = createMemoryStorage()
		const now = 1_000
		const lock = createSessionRunnerLock( {
			storage,
			ownerId: 'window-a',
			now: () => now,
		} )

		expect( lock.claim( '42' ) ).toBe( true )
		expect( lock.isOwner( '42' ) ).toBe( true )
		expect( lock.isViewer( '42' ) ).toBe( false )
	} )

	it( 'makes a second window a viewer while the first claim is fresh', () => {
		const storage = createMemoryStorage()
		const now = 1_000
		const lockA = createSessionRunnerLock( {
			storage,
			ownerId: 'window-a',
			now: () => now,
		} )
		const lockB = createSessionRunnerLock( {
			storage,
			ownerId: 'window-b',
			now: () => now,
		} )

		expect( lockA.claim( '42' ) ).toBe( true )
		expect( lockB.claim( '42' ) ).toBe( false )
		expect( lockB.isViewer( '42' ) ).toBe( true )
		expect( lockB.isOwner( '42' ) ).toBe( false )
	} )

	it( 'allows reclaim after the claim goes stale', () => {
		const storage = createMemoryStorage()
		let now = 1_000
		const lockA = createSessionRunnerLock( {
			storage,
			ownerId: 'window-a',
			now: () => now,
			staleMs: STALE_MS,
		} )
		const lockB = createSessionRunnerLock( {
			storage,
			ownerId: 'window-b',
			now: () => now,
			staleMs: STALE_MS,
		} )

		expect( lockA.claim( '42' ) ).toBe( true )
		now += STALE_MS
		expect( lockB.claim( '42' ) ).toBe( true )
		expect( lockB.isOwner( '42' ) ).toBe( true )
		expect( lockA.isViewer( '42' ) ).toBe( true )
	} )

	it( 'keeps ownership fresh when the owner heartbeats before stale', () => {
		const storage = createMemoryStorage()
		let now = 1_000
		const lockA = createSessionRunnerLock( {
			storage,
			ownerId: 'window-a',
			now: () => now,
			staleMs: STALE_MS,
		} )
		const lockB = createSessionRunnerLock( {
			storage,
			ownerId: 'window-b',
			now: () => now,
			staleMs: STALE_MS,
		} )

		expect( lockA.claim( '42' ) ).toBe( true )
		now += STALE_MS - 1
		expect( lockA.heartbeat( '42' ) ).toBe( true )
		now += STALE_MS - 1
		expect( lockB.claim( '42' ) ).toBe( false )
		expect( lockA.isOwner( '42' ) ).toBe( true )
	} )

	it( 'release drops the claim so another window can become active', () => {
		const storage = createMemoryStorage()
		const now = 1_000
		const lockA = createSessionRunnerLock( {
			storage,
			ownerId: 'window-a',
			now: () => now,
		} )
		const lockB = createSessionRunnerLock( {
			storage,
			ownerId: 'window-b',
			now: () => now,
		} )

		expect( lockA.claim( '42' ) ).toBe( true )
		lockA.release( '42' )
		expect( lockA.isOwner( '42' ) ).toBe( false )
		expect( lockB.claim( '42' ) ).toBe( true )
	} )

	it( 'does not let a non-owner release someone else\'s claim', () => {
		const storage = createMemoryStorage()
		const now = 1_000
		const lockA = createSessionRunnerLock( {
			storage,
			ownerId: 'window-a',
			now: () => now,
		} )
		const lockB = createSessionRunnerLock( {
			storage,
			ownerId: 'window-b',
			now: () => now,
		} )

		expect( lockA.claim( '42' ) ).toBe( true )
		lockB.release( '42' )
		expect( lockA.isOwner( '42' ) ).toBe( true )
		expect( lockB.isViewer( '42' ) ).toBe( true )
	} )

	it( 'tracks claims per session id independently', () => {
		const storage = createMemoryStorage()
		const now = 1_000
		const lockA = createSessionRunnerLock( {
			storage,
			ownerId: 'window-a',
			now: () => now,
		} )
		const lockB = createSessionRunnerLock( {
			storage,
			ownerId: 'window-b',
			now: () => now,
		} )

		expect( lockA.claim( '10' ) ).toBe( true )
		expect( lockB.claim( '20' ) ).toBe( true )
		expect( lockA.isOwner( '10' ) ).toBe( true )
		expect( lockB.isOwner( '20' ) ).toBe( true )
		expect( lockB.isViewer( '10' ) ).toBe( true )
		expect( lockA.isViewer( '20' ) ).toBe( true )
	} )
} )
