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
 * MAIN APPLICATION CONFIGURATION (CLI)
 * ------------------------------------------------------------------
 * This file defines CLI-facing settings for your CitOmni app.
 *
 * POLICY:
 *   - Keep this file minimal and ONLY declare keys you intend to override from
 *     vendor/provider defaults. Do not mirror baseline config here.
 *
 * MERGE MODEL (cfg, last wins):
 *   1) Vendor CLI baseline:    \CitOmni\Cli\Boot\Registry::CFG_CLI
 *   2) Provider CFGs (whitelisted in /config/providers.php via CFG_CLI)
 *   3) App CLI base:           /config/citomni_cli_cfg.php        (this file)
 *   4) App CLI overlay:        /config/citomni_cli_cfg.{ENV}.php  <- wins last
 *
 *   {ENV} comes from CITOMNI_ENVIRONMENT ('dev' | 'stage' | 'prod').
 *
 * MERGE RULES:
 *   - Associative arrays merge recursively; per-key, the last source wins.
 *   - Numeric arrays (lists) are replaced wholesale by the last source.
 *   - Empty values ('', 0, false, null, []) are still valid overrides and they win.
 *
 * ACCESS IN CODE:
 *   - $this->app->cfg->locale->timezone
 *   - $this->app->cfg->error_handler->log->path
 *
 * RUNTIME:
 *   - \CitOmni\Cli\Kernel::boot() calls Runtime::configure($app->cfg).
 *   - The kernel enforces locale.timezone via date_default_timezone_set()
 *     and locale.charset via ini_set('default_charset').
 *   - If ext-intl is installed, locale.icu_locale is applied via Locale::setDefault().
 *
 * ERROR HANDLER:
 *   - If the errorHandler service is registered, CLI kernel installs it during boot.
 *   - Non-fatal PHP errors are rendered only when their level is included in
 *     error_handler.render.trigger.
 *   - Detailed traces are shown only when CITOMNI_ENVIRONMENT === 'dev'
 *     and error_handler.render.detail.level >= 1.
 *   - Logs are written in JSONL with bounded rotation under /var/logs when log.path is set
 *     or left empty and resolved by the ErrorHandler service.
 *
 * ENV & SECRETS:
 *   - It is okay to keep secrets here; OPcache holds PHP in memory.
 *   - Keep app-owned overrides small and deliberate. Providers own the baseline.
 *
 * NOTE:
 *   - This file is for application overrides, not for documenting every possible
 *     baseline key from vendor and provider packages.
 * ------------------------------------------------------------------
 */
return [

	/*
	 *------------------------------------------------------------------
	 * LOCALE
	 *------------------------------------------------------------------
	 * Language & formatting settings for this app.
	 *
	 * POLICY:
	 * - Use 'timezone' for CLI date/time behavior (timestamps, scheduling, logs, formatting).
	 * - Use 'charset' for CLI/runtime defaults where relevant.
	 * - Use 'icu_locale' only if you rely on Intl/ICU formatting in commands/services.
	 *
	 * BASELINE VS. APP:
	 * - Kernel/runtime defaults are effectively UTC / UTF-8 / en_US unless overridden.
	 * - This skeleton overrides to Danish + Europe/Copenhagen, matching the HTTP skeleton.
	 */
	'locale' => [
		'language'	 => 'da',
		'icu_locale' => 'da_DK',
		'timezone'   => 'Europe/Copenhagen',
		'charset'    => 'UTF-8',
	],


	/*
	 *------------------------------------------------------------------
	 * ERROR HANDLER
	 *------------------------------------------------------------------
	 * Overview:
	 * - The CLI ErrorHandler is installed during kernel boot when the service exists.
	 * - Uncaught exceptions, shutdown fatals, and configured PHP errors are handled centrally.
	 * - Logs are JSONL files with bounded rotation.
	 *
	 * RENDERING:
	 * - error_handler.render.trigger controls which non-fatal PHP levels are rendered.
	 * - Baseline is already safe for stage/prod (trigger = 0, detail.level = 0).
	 * - Keep base config minimal here; use the dev overlay for developer-facing rendering.
	 *
	 * LOGGING:
	 * - The CLI baseline already defines trigger/max_bytes/max_files.
	 * - We only override the log path here so skeleton apps get a deterministic location.
	 */
	'error_handler' => [
		'log' => [
			'path' => \CITOMNI_APP_PATH . '/var/logs',
		],
	],


];