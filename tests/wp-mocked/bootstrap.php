<?php
/**
 * PHPUnit bootstrap for Ahentic's "medium" tier: small/medium orchestrator
 * logic exercised with WordPress *functions* faked via Brain Monkey — never a
 * real WordPress boot (that stays the Playwright suite's job, see
 * docs/agents/testing.md). Distinct from tests/bootstrap.php's zero-stub pure
 * tier; keep the two bootstraps separate so neither tier's assumptions leak
 * into the other.
 *
 * Composer's autoloader must be the very first thing required — it wires up
 * Brain Monkey's Patchwork-based function interception, which needs to
 * install its stream wrapper before any other file that might call a
 * WordPress function is loaded.
 *
 * @package Ahentic
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

// Classes Brain Monkey cannot fake (it only intercepts function calls).
require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/ai-prompt-builder-double.php';

require_once dirname( __DIR__, 2 ) . '/src/orchestrator/class-ai.php';
