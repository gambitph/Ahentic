/**
 * Optimistic ability progress labels — prefer PHP bootstrap map over a hard-coded table.
 */

import { __, sprintf } from '@wordpress/i18n'

/**
 * Resolve a progress label for an ability name.
 *
 * @param {string}                ability  Ability name (e.g. ahentic/create-post).
 * @param {Object<string,string>} [labels] Optional map (defaults to window.ahentic.abilityProgressLabels).
 * @return {string} Label for the live-status row.
 */
export function progressLabelForAbility( ability, labels ) {
	const map = labels && typeof labels === 'object'
		? labels
		: ( typeof window !== 'undefined' && window.ahentic?.abilityProgressLabels ) || {}
	const name = String( ability || '' )
	if ( name && typeof map[ name ] === 'string' && map[ name ] ) {
		return map[ name ]
	}
	const short = name.replace( /^.*\//, '' ).replace( /-/g, ' ' )
	return short
		? sprintf(
			/* translators: %s: tool slug */
			__( 'Running %s…', 'ahentic' ),
			short
		)
		: __( 'Working…', 'ahentic' )
}
