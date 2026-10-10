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
 * CLI CONFIG OVERLAY (prod)
 * ------------------------------------------------------------------
 * This is the final CLI-specific config layer for the "prod" environment.
 *
 * Merge model (last wins):
 *   1) Vendor CLI baseline:   \CitOmni\Cli\Boot\Registry::CFG_CLI
 *   2) Providers:             each CFG_COMMON, then CFG_CLI, in providers.php order
 *   3) App common base:       /config/citomni_cfg.php
 *   4) App CLI base:          /config/citomni_cli_cfg.php
 *   5) App common env:        /config/citomni_cfg.{ENV}.php
 *   6) App CLI env:           /config/citomni_cli_cfg.{ENV}.php    (this file)
 *
 * This file wins over all earlier cfg layers when the resolved {ENV} is "prod".
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
 *   - Production overrides shared by HTTP and CLI belong in /config/citomni_cfg.prod.php.
 *
 * Notes:
 *   - CITOMNI_ENVIRONMENT must equal "prod" for this file to load.
 *   - In many apps, production can rely entirely on:
 *       1) vendor/provider baseline
 *       2) /config/citomni_cfg.php and /config/citomni_cli_cfg.php
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
	 * Normally inherited from /config/citomni_cfg.php. Production locale
	 * settings shared by HTTP and CLI belong in /config/citomni_cfg.prod.php.
	 * Uncomment here only if production CLI jobs must deliberately run with
	 * a different locale or timezone than HTTP.
	 */

	/*
	'locale' => [
		'timezone'   => 'Europe/Copenhagen',
		'charset'    => 'UTF-8',
		'icu_locale' => 'da_DK',
	],
	*/


];
