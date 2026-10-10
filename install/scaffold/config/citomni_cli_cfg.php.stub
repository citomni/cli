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
 * MAIN APPLICATION CONFIGURATION (CLI)
 * ------------------------------------------------------------------
 * This file defines CLI-facing settings for your CitOmni app.
 *
 * POLICY:
 *   - Keep this file minimal and ONLY declare CLI-specific keys that should
 *     override earlier cfg layers. Do not mirror the vendor baseline here.
 *   - Settings shared by HTTP and CLI, such as locale and identity, belong in
 *     /config/citomni_cfg.php. Repeating them here shadows that file in CLI mode.
 *
 * MERGE MODEL (cfg, last wins):
 *   1) Vendor CLI baseline:   \CitOmni\Cli\Boot\Registry::CFG_CLI
 *   2) Providers:             each CFG_COMMON, then CFG_CLI, in providers.php order
 *   3) App common base:       /config/citomni_cfg.php
 *   4) App CLI base:          /config/citomni_cli_cfg.php          (this file)
 *   5) App common env:        /config/citomni_cfg.{ENV}.php
 *   6) App CLI env:           /config/citomni_cli_cfg.{ENV}.php    <- wins last
 *
 *   {ENV} is an explicit buildConfig($env) override when supplied; otherwise it
 *   comes from CITOMNI_ENVIRONMENT and falls back to "prod" when undefined.
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
 *   - Without locale.* in any cfg layer, Runtime uses UTC / UTF-8 / en_US.
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
 *   - Keep secrets in var/secrets/app.secret.<dev|stage|prod>.php and access them through $this->app->secrets.
 *   - Keep non-secret runtime configuration and app-owned overrides small and deliberate. Providers own the baseline.
 *
 * NOTE:
 *   - This file is for application overrides, not for documenting every possible
 *     baseline key from vendor and provider packages.
 * ------------------------------------------------------------------
 */
return [

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