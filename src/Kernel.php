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

namespace CitOmni\Cli;

use CitOmni\Kernel\App;
use CitOmni\Kernel\Mode;
use CitOmni\Kernel\Runtime;

/**
 * CLI application kernel - boot and dispatch for command-line entry points.
 *
 * This is the CLI counterpart to \CitOmni\Http\Kernel. Both are thin
 * adapters: they construct the shared App core, apply mode-specific
 * runtime concerns, and hand off to the appropriate dispatcher.
 *
 * Boot pipeline:
 *   bin/citomni -> Cli\Kernel::run($configDir, $argv)
 *     -> boot($configDir) -> new App($configDir, Mode::CLI)
 *     -> Runtime::configure($app->cfg)  (timezone, charset, ICU best-effort)
 *     -> CLI error handler install (when available)
 *     -> $app->runner->run($argv)       (command dispatch)
 *
 * Differences from Http\Kernel:
 * - No output buffering (CLI output is unbuffered by convention).
 * - No maintenance mode guard (maintenance is an HTTP concern).
 * - No CITOMNI_PUBLIC_ROOT_URL resolution (no web root in CLI).
 * - No trusted proxy configuration (no HTTP transport).
 * - No hard intl requirement (simple CLI commands may not need it;
 *   services that require intl will fail at point of use).
 * - Exit code propagation: run() terminates with the command's exit code.
 *
 * Typical entry point (bin/citomni):
 *   #!/usr/bin/env php
 *   <?php
 *   declare(strict_types=1);
 *   define('CITOMNI_ENVIRONMENT', 'dev');
 *   define('CITOMNI_APP_PATH', dirname(__DIR__));
 *   require CITOMNI_APP_PATH . '/vendor/autoload.php';
 *   \CitOmni\Cli\Kernel::run(CITOMNI_APP_PATH . '/config', $argv);
 *
 * Manual boot (tests / scripts):
 *   $app = \CitOmni\Cli\Kernel::boot(__DIR__ . '/config');
 *   // Use $app->commands, $app->cfg, etc. without dispatching.
 */
final class Kernel {

	/**
	 * Boot the CLI application without dispatching.
	 *
	 * Constructs the App in CLI mode, applies shared runtime configuration
	 * (timezone, charset, ICU locale), and installs the CLI error handler
	 * when available. Returns the fully configured App instance.
	 *
	 * Behavior:
	 * - Runtime::configure() sets timezone and charset (fail-fast on invalid
	 *   values) and ICU locale (best-effort; skipped if intl is absent).
	 * - The CLI error handler is installed only when the class exists. This
	 *   allows the package to evolve incrementally: boot works without it,
	 *   and the handler plugs in once implemented.
	 *
	 * @param  string  $configDir  Absolute path to the application's /config directory.
	 * @return App  Fully configured application instance in CLI mode.
	 */
	public static function boot(string $configDir): App {
		$app = new App($configDir, Mode::CLI);

		// Shared runtime configuration (timezone, charset, ICU best-effort).
		Runtime::configure($app->cfg);

		// CLI error handler - installed when available.
		if ($app->hasService('errorHandler')) {
			$app->errorHandler->install();
		}

		return $app;
	}

	/**
	 * Boot and dispatch a CLI command from argv.
	 *
	 * This is the primary entry point for bin/citomni. It boots the
	 * application, dispatches the command via the Runner service, and
	 * terminates with the command's exit code.
	 *
	 * @param  string  $configDir  Absolute path to the application's /config directory.
	 * @param  array   $argv       Raw CLI arguments (typically $argv from PHP).
	 * @return never
	 */
	public static function run(string $configDir, array $argv): never {
		$app = self::boot($configDir);
		$exitCode = $app->runner->run($argv);
		exit($exitCode);
	}
}
