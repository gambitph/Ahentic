/**
 * Apply a session REST payload into tabs + unified sessionsById state.
 */

import { mapEntriesToMessages } from './api'
import { collectPageContext } from './page-context'
import { hydrateEditorRefs } from './block-ref-registry'
import { patchSessionRecord, mergeServerSessionIntoRecord } from './session-state'

/**
 * @param {Object}   session
 * @param {Function} setTabs
 * @param {Function} setSessionsById
 * @param {Object}   [pendingLocalBySession]
 */
export function applySessionPayload( session, setTabs, setSessionsById, pendingLocalBySession ) {
	if ( ! session?.id ) {
		return
	}
	const id = String( session.id )
	setTabs( current => current.map( tab => (
		tab.id === id
			? {
				...tab,
				title: session.title || tab.title,
				status: session.status,
				...( typeof session.autoTitle === 'boolean'
					? { autoTitle: session.autoTitle }
					: {} ),
			}
			: tab
	) ) )
	setSessionsById( sessions => patchSessionRecord(
		sessions,
		id,
		record => mergeServerSessionIntoRecord(
			session,
			record,
			pendingLocalBySession?.[ id ],
			mapEntriesToMessages
		)
	) )
	if ( session.editorRefs && typeof session.editorRefs === 'object' ) {
		const livePostId = Number( collectPageContext()?.post_id || 0 )
		hydrateEditorRefs( session.editorRefs, livePostId )
	}
}
