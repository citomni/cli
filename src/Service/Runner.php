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

namespace CitOmni\Cli\Service;

use CitOmni\Kernel\Command\BaseCommand;
use CitOmni\Kernel\Service\BaseService;

/**
 * CLI command dispatcher - resolves and executes commands from $app->commands.
 *
 * This is the CLI counterpart to \CitOmni\Http\Service\Router. Both are
 * dispatch services: Router matches HTTP paths to controllers, Runner
 * matches command names to BaseCommand classes.
 *
 * Dispatch pipeline:
 *   1. Extract command name from $argv[1] (missing or 'list' -> list all commands).
 *   2. Look up command definition in $app->commands.
 *   3. Validate definition shape, class existence, and BaseCommand ancestry.
 *   4. Instantiate: new $class($this->app, $commandName, $description, $options).
 *   5. Call $command->run($argv) and return the exit code.
 *
 * Command definition shape (from Registry::COMMANDS_CLI or config files):
 *   'cache:warm' => [
 *       'command'     => \Vendor\Package\Command\CacheWarmCommand::class,
 *       'description' => 'Build and write config, route, and service caches',
 *       'options'     => [],   // optional - passed to command constructor
 *   ],
 *
 * Contract:
 * - Command classes MUST extend \CitOmni\Kernel\Command\BaseCommand.
 * - BaseCommand::run(array $argv): int returns an exit code
 *   (0 = success, 1 = runtime failure, 2 = usage error).
 * - Runner returns the exit code to the caller. It does not call exit() -
 *   that is Kernel::run()'s responsibility.
 *
 * Error handling:
 * - Unknown command name -> error message to stderr, returns exit code 2.
 * - Missing or non-string 'command' key -> RuntimeException (fail-fast).
 * - Non-array 'options' value -> RuntimeException (fail-fast).
 * - Non-existent command class -> RuntimeException (fail-fast).
 * - Class not extending BaseCommand -> RuntimeException (fail-fast).
 *
 * Typical usage (via Kernel):
 *   \CitOmni\Cli\Kernel::run(CITOMNI_APP_PATH . '/config', $argv);
 *
 * Direct usage (tests):
 *   $app = \CitOmni\Cli\Kernel::boot(__DIR__ . '/config');
 *   $exitCode = $app->runner->run(['bin/citomni', 'cache:warm']);
 */
final class Runner extends BaseService {

	/**
	 * Dispatch a CLI command based on argv.
	 *
	 * Behavior:
	 * - $argv[0] is the script name (ignored for dispatch).
	 * - $argv[1] is the command name. If absent or 'list', lists all commands.
	 * - The full $argv array is passed to the command's run() method, so
	 *   commands can parse their own flags and arguments (including --help).
	 *
	 * @param  array  $argv  Raw CLI arguments (typically $argv from PHP).
	 * @return int  Exit code from the dispatched command (0 = success).
	 * @throws \RuntimeException  If the command definition is invalid, the class does not exist,
	 *                            the class does not extend BaseCommand, or options is not an array.
	 */
	public function run(array $argv): int {
		$commandName = $argv[1] ?? null;

		if ($commandName === null || $commandName === 'list') {
			$this->listCommands();
			return 0;
		}

		$commands = $this->app->commands;

		if (!isset($commands[$commandName])) {
			$this->stderr("Unknown command: {$commandName}");
			$this->stderr("Run 'list' to see available commands.");
			return 2;
		}

		$def = $commands[$commandName];

		// -- Validate definition --------------------------------------
		if (!\is_array($def) || !isset($def['command']) || !\is_string($def['command'])) {
			throw new \RuntimeException("Invalid command definition for '{$commandName}': missing or non-string 'command' key.");
		}

		$class = $def['command'];
		if (!\class_exists($class)) {
			throw new \RuntimeException("Command class not found: {$class} (command: {$commandName})");
		}

		if (!\is_a($class, BaseCommand::class, true)) {
			throw new \RuntimeException("Command class {$class} must extend " . BaseCommand::class . " (command: {$commandName})");
		}

		if (isset($def['options']) && !\is_array($def['options'])) {
			throw new \RuntimeException("Command options must be an array for '{$commandName}'.");
		}

		// -- Instantiate and run --------------------------------------
		$options     = $def['options'] ?? [];
		$description = (isset($def['description']) && \is_string($def['description'])) ? $def['description'] : '';
		$instance    = new $class($this->app, $commandName, $description, $options);

		return $instance->run($argv);
	}






	// ----------------------------------------------------------------
	// Output helpers
	// ----------------------------------------------------------------

	/**
	 * List all registered commands with their descriptions.
	 *
	 * Groups commands by namespace prefix (the part before the first colon).
	 * Commands without a colon are listed under "general".
	 *
	 * @return void
	 */
	private function listCommands(): void {
		$commands = $this->app->commands;

		$this->stdout('CitOmni CLI');
		$this->stdout('');

		if ($commands === []) {
			$this->stdout('  No commands registered.');
			$this->stdout('');
			return;
		}

		$this->stdout('Available commands:');

		// Group by namespace prefix
		$groups = [];
		foreach ($commands as $name => $def) {
			$colonPos = \strpos($name, ':');
			$group = ($colonPos !== false) ? \substr($name, 0, $colonPos) : 'general';
			$groups[$group][$name] = $def;
		}
		\ksort($groups);

		// Determine column width for alignment
		$maxLen = 0;
		foreach ($commands as $name => $def) {
			$len = \strlen($name);
			if ($len > $maxLen) {
				$maxLen = $len;
			}
		}
		$pad = $maxLen + 4;

		foreach ($groups as $group => $entries) {
			$this->stdout('');
			$this->stdout(" {$group}");
			\ksort($entries);
			foreach ($entries as $name => $def) {
				$desc = '';
				if (\is_array($def) && isset($def['description']) && \is_string($def['description'])) {
					$desc = $def['description'];
				}
				$this->stdout('  ' . \str_pad($name, $pad) . $desc);
			}
		}

		$this->stdout('');
	}


	/**
	 * Write a line to stdout.
	 *
	 * @param  string  $line  Text to write.
	 * @return void
	 */
	private function stdout(string $line): void {
		\fwrite(\STDOUT, $line . \PHP_EOL);
	}


	/**
	 * Write a line to stderr.
	 *
	 * @param  string  $line  Text to write.
	 * @return void
	 */
	private function stderr(string $line): void {
		\fwrite(\STDERR, $line . \PHP_EOL);
	}

}
