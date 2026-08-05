/**
 * Multi-step plan card — live checklist from orchestrator session state.
 */

import { __ } from '@wordpress/i18n'
import {
	Check, Circle, LoaderCircle, Minus,
} from 'lucide-react'

/**
 * @param {Object} props
 * @param {Object} props.plan Session plan payload { title?, steps, updatedAt }.
 */
export default function PlanCard( { plan } ) {
	const steps = Array.isArray( plan?.steps ) ? plan.steps : []
	if ( ! steps.length ) {
		return null
	}

	const title = typeof plan.title === 'string' ? plan.title.trim() : ''
	const done = steps.filter( step => step.status === 'completed' ).length
	const allDone = done === steps.length && steps.length > 0
	const stopped = ! allDone &&
		steps.some( step => step.status === 'cancelled' ) &&
		! steps.some( step => step.status === 'in_progress' )

	let state = ''
	if ( allDone ) {
		state = ' is-complete'
	} else if ( stopped ) {
		state = ' is-stopped'
	}

	return (
		<div
			className={ `ahentic-plan${ state }` }
			role="status"
			aria-live="polite"
			aria-label={ title || __( 'Plan', 'ahentic' ) }
		>
			<div className="ahentic-plan__header">
				<span className="ahentic-plan__eyebrow">
					{ allDone
						? __( 'Plan complete', 'ahentic' )
						: ( stopped
							? __( 'Plan stopped', 'ahentic' )
							: __( 'Plan', 'ahentic' ) ) }
				</span>
				<span className="ahentic-plan__count">
					{ done }/{ steps.length }
				</span>
			</div>
			{ title ? (
				<div className="ahentic-plan__title">{ title }</div>
			) : null }
			<ol className="ahentic-plan__steps">
				{ steps.map( step => {
					const status = step.status || 'pending'
					const content = step.content || ''
					return (
						<li
							key={ step.id || content }
							className={ `ahentic-plan__step is-${ status }` }
						>
							<span className="ahentic-plan__icon" aria-hidden="true">
								{ status === 'completed' ? (
									<Check size={ 14 } strokeWidth={ 2.25 } />
								) : null }
								{ status === 'in_progress' ? (
									<LoaderCircle size={ 14 } strokeWidth={ 2 } className="ahentic-spin" />
								) : null }
								{ status === 'cancelled' ? (
									<Minus size={ 14 } strokeWidth={ 2 } />
								) : null }
								{ status === 'pending' ? (
									<Circle size={ 14 } strokeWidth={ 1.75 } />
								) : null }
							</span>
							<span className="ahentic-plan__text">{ content }</span>
						</li>
					)
				} ) }
			</ol>
		</div>
	)
}
