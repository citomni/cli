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
 * CLI CONFIG OVERLAY (stage)
 * ------------------------------------------------------------------
 * This is the final CLI-specific config layer for the "stage" environment.
 *
 * Merge model (last wins):
 *   1) Vendor CLI baseline:   \CitOmni\Cli\Boot\Registry::CFG_CLI
 *   2) Providers:             each CFG_COMMON, then CFG_CLI, in providers.php order
 *   3) App common base:       /config/citomni_cfg.php
 *   4) App CLI base:          /config/citomni_cli_cfg.php
 *   5) App common env:        /config/citomni_cfg.{ENV}.php
 *   6) App CLI env:           /config/citomni_cli_cfg.{ENV}.php    (this file)
 *
 * This file wins over all earlier cfg layers when the resolved {ENV} is "stage".
 *
 * Typical usage:
 *   - Mirror production error posture without exposing developer traces.
 *   - Keep logs informative for QA and staging verification.
 *   - Override only what truly differs from the shared base config.
 *
 * Policy:
 *   - Only put keys here when you intend to *override* vendor/provider defaults.
 *     Keep overlays minimal and intentional; do not mirror the full baseline.
 *   - Stage should behave predictably and stay close to production.
 *   - Stage overrides shared by HTTP and CLI belong in /config/citomni_cfg.stage.php.
 *
 * Notes:
 *   - CITOMNI_ENVIRONMENT must equal "stage" for this file to load.
 *   - Stage should not show developer traces in terminal output by default; rely on logs instead.
 *
 * @internal App-owned overlay: small, deliberate, CI-friendly.
 * ------------------------------------------------------------------
 */
return [

	/*
	 * ------------------------------------------------------------------
	 * ERROR HANDLER
	 * ------------------------------------------------------------------
	 * Do not show dev details on stage; keep logs verbose.
	 */
	'error_handler' => [
		'render' => [
			'trigger' => 0,
			'detail' => [
				'level' => 0,
			],
			// no force_error_reporting override on stage
		],
		'log' => [
			'trigger'   => E_ALL,
			'path'      => \CITOMNI_APP_PATH . '/var/logs',
			'max_bytes' => 2_000_000,
			'max_files' => 10,
		],
	],


];
