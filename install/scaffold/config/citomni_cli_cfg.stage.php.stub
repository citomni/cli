<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: MIT
 * Copyright (C) 2012-present Lars Grove Mortensen
 *
 * CitOmni CLI Skeleton - Minimal starter for high-performance CitOmni CLI apps.
 * Source:  https://github.com/citomni/cli-skeleton
 * License: See the LICENSE file for full terms.
 */

/*
 * ------------------------------------------------------------------
 * CLI CONFIG OVERLAY (stage)
 * ------------------------------------------------------------------
 * This file overrides /config/citomni_cli_cfg.php for the "stage" environment.
 *
 * Merge model (last wins):
 *   vendor baseline -> providers -> app base -> this file (stage overlay)
 *   See: \CitOmni\Cli\Boot\Registry::CFG_CLI and classes listed in /config/providers.php
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
