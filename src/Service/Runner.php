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
 * Runner: CLI dispatch service that runs the command an argv names.
 *
 * Turns the command name in argv into the command class that the App's command map registers
 * for it, and hands back that command's exit code. Parsing the command's own arguments and
 * options belongs to the command; ending the process belongs to the caller.
 *
 * Rules:
 * - Return the exit code; never end the process.
 * - Create only subclasses of BaseCommand, with the constructor arguments BaseCommand declares.
 * - Reject with \RuntimeException an entry whose command class or options cannot be used; never
 *   fall back to another command.
 */
class Runner extends BaseService {

	/**
	 * Run the command that argv names and return its exit code.
	 *
	 * The command gets the full argv and parses its own arguments and options, --help included.
	 *
	 * Behavior:
	 * - Without a command name, or with "list", prints the registered commands to stdout and
	 *   returns 0.
	 * - An unknown command name prints an error to stderr and returns 2.
	 * - Otherwise creates the command with the App, its name, the entry's description ('' when
	 *   the entry has no string description) and the entry's options ([] when it has none), and
	 *   returns the exit code of its run().
	 *
	 * Typical usage:
	 *   $exitCode = $this->app->runner->run(['bin/citomni', 'cache:warm', '--mode=cli']);
	 *
	 * @param  list<string>  $argv  The process argv: script, command name, then the command's arguments.
	 * @return int  Process exit code.
	 * @throws \RuntimeException  When the command's entry has no string 'command', names a class that does not exist or does not extend BaseCommand, or has 'options' that is not an array.
	 * @see \CitOmni\Kernel\Command\BaseCommand::run()  How a command parses argv, and the exit codes it returns.
	 */
	public function run(array $argv): int {
		// -- 1. Find the entry -------------------------------------------
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

		// -- 2. Validate the entry ---------------------------------------
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

		// -- 3. Create and run the command -------------------------------
		$options     = $def['options'] ?? [];
		$description = (isset($def['description']) && \is_string($def['description'])) ? $def['description'] : '';
		$instance    = new $class($this->app, $commandName, $description, $options);

		return $instance->run($argv);
	}






	// ----------------------------------------------------------------
	// Output helpers
	// ----------------------------------------------------------------

	/**
	 * Print the registered commands, grouped by the part of their name before the first colon.
	 *
	 * Behavior:
	 * - Groups, and the commands in each group, are sorted by name. A name without a colon is in
	 *   the group "general".
	 * - Each command is printed with its description, or alone when its entry has no string
	 *   description.
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

		$groups = [];
		foreach ($commands as $name => $def) {
			$colonPos = \strpos($name, ':');
			$group = ($colonPos !== false) ? \substr($name, 0, $colonPos) : 'general';
			$groups[$group][$name] = $def;
		}
		\ksort($groups);

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
	 * @param  string  $line  Text without the line ending; PHP_EOL is appended.
	 * @return void
	 */
	private function stdout(string $line): void {
		\fwrite(\STDOUT, $line . \PHP_EOL);
	}


	/**
	 * Write a line to stderr.
	 *
	 * @param  string  $line  Text without the line ending; PHP_EOL is appended.
	 * @return void
	 */
	private function stderr(string $line): void {
		\fwrite(\STDERR, $line . \PHP_EOL);
	}

}
