/**
 * Card prompting the user to request a missing ability on X.
 */

import { useCallback, useState } from '@wordpress/element'
import { __, sprintf } from '@wordpress/i18n'
import { Check, ChevronRight, Copy, ExternalLink } from 'lucide-react'

/**
 * @param {Object} props
 * @param {Object} props.request Capability request payload from the server.
 */
export default function CapabilityRequestCard( { request } ) {
	const [ open, setOpen ] = useState( false )
	const [ copied, setCopied ] = useState( false )

	const tweetText = request?.tweet_text || ''
	const intentUrl = request?.intent_url || ''
	const label = request?.ability_label || request?.ability || __( 'new', 'ahentic' )
	const handle = request?.handle ? `@${ request.handle }` : '@wpahentic'
	const hashtag = request?.hashtag ? `#${ request.hashtag }` : '#ahenticrequest'

	const onCopy = useCallback( async () => {
		if ( ! tweetText ) {
			return
		}
		try {
			await navigator.clipboard.writeText( tweetText )
			setCopied( true )
			window.setTimeout( () => setCopied( false ), 2000 )
		} catch ( _err ) {
			// Ignore clipboard failures.
		}
	}, [ tweetText ] )

	if ( ! tweetText && ! intentUrl ) {
		return null
	}

	if ( ! open ) {
		return (
			<div className="ahentic-cap-request-wrap">
				<button
					type="button"
					className="ahentic-cap-request__reveal"
					onClick={ () => setOpen( true ) }
				>
					<span>{ __( 'Request missing ability', 'ahentic' ) }</span>
					<ChevronRight size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
				</button>
			</div>
		)
	}

	return (
		<div className="ahentic-cap-request" role="complementary">
			<div className="ahentic-cap-request__eyebrow">
				{ __( 'Missing ability', 'ahentic' ) }
			</div>
			<h4 className="ahentic-cap-request__title">
				{ sprintf(
					/* translators: %s: ability label */
					__( 'Request “%s” on X', 'ahentic' ),
					label
				) }
			</h4>

			<blockquote className="ahentic-cap-request__tweet">
				<p>{ tweetText }</p>
				<div className="ahentic-cap-request__meta">
					<span>{ handle }</span>
					<span>{ hashtag }</span>
				</div>
			</blockquote>

			<div className="ahentic-cap-request__actions">
				{ intentUrl ? (
					<a
						className="ahentic-cap-request__cta"
						href={ intentUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						<ExternalLink size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
						{ __( 'Post request on X', 'ahentic' ) }
					</a>
				) : null }
				<button
					type="button"
					className="ahentic-cap-request__copy"
					onClick={ onCopy }
					disabled={ ! tweetText }
				>
					{ copied
						? <Check size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
						: <Copy size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
					}
					{ copied
						? __( 'Copied', 'ahentic' )
						: __( 'Copy text', 'ahentic' )
					}
				</button>
			</div>
		</div>
	)
}
