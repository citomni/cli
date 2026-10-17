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
 * Kernel: CLI entry point that boots the App in CLI mode and runs one command.
 *
 * Turns a config directory and the process argv into a finished CLI process, for entry scripts
 * and for other scripts that only need a booted App. It owns the boot order and the process
 * exit code. Building cfg, commands and services belongs to App, the process-wide runtime
 * settings to Runtime, reporting failures to the errorHandler service and running the command
 * to the runner service. HTTP concerns stay out of CLI boot: No output buffering, no
 * maintenance guard, no public root URL and no trusted proxies.
 *
 * Rules:
 * - Install the error handler as soon as the App exists, before any other boot step that can
 *   fail.
 * - End the process only in run(); boot() returns to its caller.
 */
class Kernel {


	/**
	 * Boot the App in CLI mode without running a command.
	 *
	 * Builds the App and makes the process ready for commands: From then on failures reach the
	 * CLI error handler when the App registers one, and PHP's timezone and charset, and with
	 * intl the ICU locale, follow the App's cfg.
	 *
	 * Behavior:
	 * - Installs the errorHandler service when one is registered, before the runtime settings
	 *   are applied, so the handler reports a failure there.
	 * - A failure while the App is built (cfg, command map, service map) happens before any
	 *   handler exists and is left to PHP.
	 * - Does not require the intl extension.
	 *
	 * Typical usage:
	 *   $app = \CitOmni\Cli\Kernel::boot(CITOMNI_APP_PATH . '/config');
	 *
	 * @param  string  $configDir  Absolute path to the app's config directory.
	 * @return App  The App in CLI mode.
	 * @throws \RuntimeException  When the App cannot be built from $configDir, or its cfg holds an invalid timezone, charset or ICU locale.
	 * @see \CitOmni\Kernel\Runtime::configure()  The runtime settings and how they fail.
	 * @see \CitOmni\Cli\Service\ErrorHandler::install()  What the installed handler takes over.
	 */
	public static function boot(string $configDir): App {
		$app = new App($configDir, Mode::CLI);

		// Installed first, so the handler reports a failure in the runtime settings below.
		if ($app->hasService('errorHandler')) {
			$app->errorHandler->install();
		}

		Runtime::configure($app->cfg);

		return $app;
	}


	/**
	 * Boot the App, run the command that argv names and end the process with its exit code.
	 *
	 * Typical usage:
	 *   \CitOmni\Cli\Kernel::run(CITOMNI_APP_PATH . '/config', $_SERVER['argv'] ?? []);
	 *
	 * @param  string        $configDir  Absolute path to the app's config directory.
	 * @param  list<string>  $argv       The process argv: script, command name, then the command's arguments.
	 * @return never
	 * @throws \RuntimeException  When boot() fails, or the runner rejects the command's entry in the command map.
	 * @see \CitOmni\Cli\Service\Runner::run()  How argv selects the command, and the exit code it returns.
	 */
	public static function run(string $configDir, array $argv): never {
		$app = self::boot($configDir);
		$exitCode = $app->runner->run($argv);
		exit($exitCode);
	}

}
