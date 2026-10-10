<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

/*
 * ------------------------------------------------------------------
 * CLI CONFIG OVERLAY (dev)
 * ------------------------------------------------------------------
 * This is the final CLI-specific config layer for the "dev" environment.
 *
 * Merge model (last wins):
 *   1) Vendor CLI baseline:   \CitOmni\Cli\Boot\Registry::CFG_CLI
 *   2) Providers:             each CFG_COMMON, then CFG_CLI, in providers.php order
 *   3) App common base:       /config/citomni_cfg.php
 *   4) App CLI base:          /config/citomni_cli_cfg.php
 *   5) App common env:        /config/citomni_cfg.{ENV}.php
 *   6) App CLI env:           /config/citomni_cli_cfg.{ENV}.php    (this file)
 *
 * This file wins over all earlier cfg layers when the resolved {ENV} is "dev".
 *
 * Typical usage:
 *   - Enable developer-friendly rendering of non-fatal PHP errors.
 *   - Show bounded traces in the terminal when debugging commands.
 *   - Keep logging verbose and deterministic.
 *
 * Policy:
 *   - Only place keys here when you intend to *override* vendor/provider defaults.
 *     Keep overlays minimal and intentional; do not mirror the full baseline.
 *   - Dev overlays may be more permissive for diagnostics, but should remain
 *     bounded and deterministic (no unbounded traces, no surprise side effects).
 *   - Dev overrides shared by HTTP and CLI belong in /config/citomni_cfg.dev.php.
 *
 * Notes:
 *   - CITOMNI_ENVIRONMENT must equal "dev" for this file to load.
 *   - detail.level >= 1 only becomes developer-visible when ENV === 'dev'.
 *
 * @internal App-owned overlay: small, deliberate, developer-focused.
 * ------------------------------------------------------------------
 */
return [

	/*
	 * ------------------------------------------------------------------
	 * ERROR HANDLER
	 * ------------------------------------------------------------------
	 * Show non-fatal errors to the developer with bounded traces.
	 * Do NOT include fatal classes in render.trigger; the handler
	 * will sanitize them away even if misconfigured.
	 */
	'error_handler' => [
		'render' => [
			'trigger' => E_WARNING | E_NOTICE | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING | E_USER_NOTICE | E_RECOVERABLE_ERROR | E_DEPRECATED | E_USER_DEPRECATED,
			'detail' => [
				'level' => 1, // developer details (effective only when ENV === 'dev')
				'trace' => [
					'max_frames'      => 120,
					'max_arg_strlen'  => 512,
					'max_array_items' => 20,
					'max_depth'       => 3,
					'ellipsis'        => '...',
				],
			],
			// 'force_error_reporting' => E_ALL, // optional: override ini in dev
		],
		'log' => [
			// Keep logs verbose in dev; defaults are already sensible.
			// 'trigger'   => E_ALL,
			// 'path'      => \CITOMNI_APP_PATH . '/var/logs',
			// 'max_bytes' => 2_000_000,
			// 'max_files' => 10,
		],
	],


];
