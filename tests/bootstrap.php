<?php
/**
 * PHPUnit bootstrap for Ahentic's pure unit tests.
 *
 * These tests cover control-block parsing, which needs no WordPress runtime — only
 * the ABSPATH guard satisfied and the translation function stubbed. Anything that
 * needs real WordPress behaviour belongs in a Playwright/e2e suite instead (see
 * docs/agents/testing.md), not behind a growing pile of stubs here.
 *
 * @package Ahentic
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/src/orchestrator/class-ai.php';
