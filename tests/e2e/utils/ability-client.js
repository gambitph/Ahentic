/**
 * Thin client for the e2e-only `ahentic-e2e/v1/*` REST routes.
 *
 * Those routes (tests/e2e/mu-plugins/ahentic-e2e-ability-runner.php) delegate
 * straight to `Ahentic_Abilities::execute()` — ability dispatch (what the
 * Tool runner calls after HITL / browser / from_memory). Specs assert real
 * ability behaviour against a live WordPress instance without driving an LLM
 * turn or the full Tool runner pipeline through the sidebar.
 *
 * Built on `requestUtils.rest()` (from `@wordpress/e2e-test-utils-playwright`)
 * rather than a bespoke auth client — see tests/e2e/global-setup.js for how
 * that fixture gets authenticated.
 */

/**
 * Run a single Ahentic ability as the e2e admin user.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils The `requestUtils` fixture.
 * @param {string}                                                      name         Ability name, e.g. "ahentic/get-site-snapshot".
 * @param {Object}                                                      [input]      Ability input.
 * @return {Promise<{ok: boolean, data?: *, error?: string, message?: string}>} Parsed run-ability response.
 */
async function runAbility( requestUtils, name, input = {} ) {
	return requestUtils.rest( {
		path: '/ahentic-e2e/v1/run-ability',
		method: 'POST',
		data: { name, input },
	} )
}

/**
 * Seed a queue of canned AI responses that `Ahentic_AI::complete_chat()` will
 * pop from (in order) instead of calling a real provider, for the lifetime of
 * the current WordPress request-handling session (until consumed or reset).
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils The `requestUtils` fixture.
 * @param {Array<Object|string>}                                        responses    Ordered canned `complete_chat()`-shaped results, or plain strings (shorthand for `{ text: ... }`). Replaces any previously queued responses (does not append).
 * @return {Promise<{ok: boolean, queued: number}>} Confirmation of how many responses were queued.
 */
async function seedAiResponses( requestUtils, responses ) {
	return requestUtils.rest( {
		path: '/ahentic-e2e/v1/seed-ai-responses',
		method: 'POST',
		data: { responses },
	} )
}

/**
 * Seed WordPress fixture data (posts/users/options) via the e2e mu-plugin,
 * so a spec doesn't need a slow UI walk-through to reach a given state.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils The `requestUtils` fixture.
 * @param {Object}                                                      fixture      Declarative fixture, e.g. `{ posts: [...], users: [...], options: {...} }`.
 * @return {Promise<{ok: boolean, created: Object}>} IDs/keys of what was created.
 */
async function seed( requestUtils, fixture ) {
	return requestUtils.rest( {
		path: '/ahentic-e2e/v1/seed',
		method: 'POST',
		data: fixture,
	} )
}

/**
 * Clear any queued AI responses and reset the e2e mu-plugin's per-run state
 * (does not touch fixture data created via `seed()` — delete that explicitly
 * if a spec needs a clean slate).
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils The `requestUtils` fixture.
 * @return {Promise<{ok: boolean}>} Confirmation.
 */
async function resetAiResponses( requestUtils ) {
	return requestUtils.rest( {
		path: '/ahentic-e2e/v1/reset',
		method: 'POST',
	} )
}

module.exports = {
	runAbility, seedAiResponses, seed, resetAiResponses,
}
