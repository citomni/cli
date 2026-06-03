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
 * CLI CONFIG OVERLAY (prod)
 * ------------------------------------------------------------------
 * This file overrides /config/citomni_cli_cfg.php for the "prod" environment.
 *
 * Merge model (last wins):
 *   vendor baseline -> providers -> app base -> this file (prod overlay)
 *   See: \CitOmni\Cli\Boot\Registry::CFG_CLI and classes listed in /config/providers.php
 *
 * Typical usage:
 *   - Production-only overrides that should not affect dev or stage.
 *   - Conservative hardening of rendering/logging behavior.
 *   - Infrastructure-specific paths or limits, when truly needed.
 *
 * Policy:
 *   - Only put keys here when you intend to override vendor/provider defaults
 *     or your shared app base config.
 *   - Keep this file minimal and deliberate. If production does not need any
 *     special overrides, leave the returned array empty.
 *
 * Notes:
 *   - CITOMNI_ENVIRONMENT must equal "prod" for this file to load.
 *   - In many apps, production can rely entirely on:
 *       1) vendor/provider baseline
 *       2) /config/citomni_cli_cfg.php
 *     In that case, this file remains intentionally empty.
 *
 * @internal App-owned overlay: small, deliberate, production-only.
 * ------------------------------------------------------------------
 */
return [

	/*
	 * ------------------------------------------------------------------
	 * ERROR HANDLER
	 * ------------------------------------------------------------------
	 * Production should normally stay quiet in the terminal and rely on logs.
	 * Uncomment only if production needs behavior different from the baseline
	 * or from /config/citomni_cli_cfg.php.
	 */

	/*
	'error_handler' => [
		'render' => [
			'trigger' => 0,
			'detail' => [
				'level' => 0,
			],
		],
		'log' => [
			'trigger'   => E_ALL,
			'path'      => \CITOMNI_APP_PATH . '/var/logs',
			'max_bytes' => 2_000_000,
			'max_files' => 10,
		],
	],
	*/


	/*
	 * ------------------------------------------------------------------
	 * LOCALE
	 * ------------------------------------------------------------------
	 * Usually inherited from the shared base config.
	 * Uncomment only if production jobs must run with a different locale
	 * or timezone than the rest of the app.
	 */

	/*
	'locale' => [
		'timezone'   => 'Europe/Copenhagen',
		'charset'    => 'UTF-8',
		'icu_locale' => 'da_DK',
	],
	*/


];
