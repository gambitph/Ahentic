/**
 * Multi-step plan card — live checklist from orchestrator session state.
 *
 * Visible only when the plan has at least {@link MIN_VISIBLE_PLAN_STEPS} steps.
 * Shorter checklists stay in session state for the orchestrator but do not render.
 */

import { __ } from '@wordpress/i18n'
import {
	Check, Circle, LoaderCircle, Minus,
} from 'lucide-react'

/** Minimum checklist length before the sidebar plan card is shown. */
export const MIN_VISIBLE_PLAN_STEPS = 3

/**
 * Whether the plan payload is long enough to show the plan card.
 *
 * @param {Object|null|undefined} plan Session plan payload.
 * @return {boolean} True when the card should render.
 */
export function shouldShowPlanCard( plan ) {
	const steps = Array.isArray( plan?.steps ) ? plan.steps : []
	return steps.length >= MIN_VISIBLE_PLAN_STEPS
}

/**
 * Presentation state for the plan card header / chrome.
 *
 * Steps may all be marked completed while the run is still busy (finish gate,
 * verify, final reply). Do not show "Plan complete" until the session is idle.
 * Idle clarifying pauses (ask_user) leave unfinished steps open: show a waiting
 * eyebrow instead of celebrating completion.
 *
 * @param {Object}  plan
 * @param {Object}  [options]
 * @param {boolean} [options.busy] Session still working (live status visible).
 * @return {{ done: number, total: number, showComplete: boolean, wrappingUp: boolean, waitingOnUser: boolean, stopped: boolean, stateClass: string, eyebrow: string, steps: Array, visible: boolean }} Result.
 */
export function resolvePlanCardPresentation( plan, { busy = false } = {} ) {
	const rawSteps = Array.isArray( plan?.steps ) ? plan.steps : []
	const visible = rawSteps.length >= MIN_VISIBLE_PLAN_STEPS
	const done = rawSteps.filter( step => step.status === 'completed' ).length
	const total = rawSteps.length
	const stepsAllDone = visible && done === total && total > 0
	// Checklist finished on paper, but the agent is still wrapping up.
	const wrappingUp = stepsAllDone && busy
	const showComplete = stepsAllDone && ! busy
	const stopped = visible &&
		! showComplete &&
		! busy &&
		rawSteps.some( step => step.status === 'cancelled' ) &&
		! rawSteps.some( step => step.status === 'in_progress' )
	const waitingOnUser = visible &&
		! busy &&
		! showComplete &&
		! stopped &&
		rawSteps.some( step => step.status === 'pending' || step.status === 'in_progress' )

	let stateClass = ''
	if ( showComplete ) {
		stateClass = ' is-complete'
	} else if ( wrappingUp ) {
		stateClass = ' is-wrapping-up'
	} else if ( waitingOnUser ) {
		stateClass = ' is-paused'
	} else if ( stopped ) {
		stateClass = ' is-stopped'
	}

	let eyebrow = __( 'Plan', 'ahentic' )
	if ( showComplete ) {
		eyebrow = __( 'Plan complete', 'ahentic' )
	} else if ( wrappingUp ) {
		eyebrow = __( 'Finishing…', 'ahentic' )
	} else if ( waitingOnUser ) {
		eyebrow = __( 'Waiting for you…', 'ahentic' )
	} else if ( stopped ) {
		eyebrow = __( 'Plan stopped', 'ahentic' )
	}

	// Display-only: keep the last step spinning so the card does not look finished
	// while live status is still on screen.
	let steps = rawSteps
	if ( wrappingUp && rawSteps.length ) {
		const last = rawSteps.length - 1
		steps = rawSteps.map( ( step, index ) => (
			index === last
				? { ...step, status: 'in_progress' }
				: step
		) )
	}

	return {
		done,
		total,
		showComplete,
		wrappingUp,
		waitingOnUser,
		stopped,
		stateClass,
		eyebrow,
		steps,
		visible,
	}
}

/**
 * @param {Object}  props
 * @param {Object}  props.plan   Session plan payload { title?, steps, updatedAt }.
 * @param {boolean} [props.busy] Whether the session is still actively working.
 */
export default function PlanCard( { plan, busy = false } ) {
	if ( ! shouldShowPlanCard( plan ) ) {
		return null
	}

	const title = typeof plan.title === 'string' ? plan.title.trim() : ''
	const {
		done, total, stateClass, eyebrow, steps,
	} = resolvePlanCardPresentation( plan, { busy } )

	return (
		<div
			className={ `ahentic-plan${ stateClass }` }
			role="status"
			aria-live="polite"
			aria-label={ title || __( 'Plan', 'ahentic' ) }
		>
			<div className="ahentic-plan__header">
				<span className="ahentic-plan__eyebrow">
					{ eyebrow }
				</span>
				<span className="ahentic-plan__count">
					{ done }/{ total }
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
