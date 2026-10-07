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

namespace CitOmni\Cli\Tests\CacheCommands;

use CitOmni\Cli\Boot\Registry;
use CitOmni\Cli\Command\CacheClearCommand;
use CitOmni\Cli\Command\CacheWarmCommand;

/*
 * Standalone suite for the cache:warm and cache:clear commands.
 *
 * The commands run unchanged on top of the kernel doubles in doubles.php. The
 * checks cover mode routing, the --env pass-through, exit codes, text and JSON
 * output, the OPcache note, and that App exceptions are not caught. They run
 * first without citomni/http, then with \CitOmni\Http\Boot\Registry present.
 *
 * Usage:
 *   php tests/cache-commands/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Default target environment for cache:warm without --env.
\define('CITOMNI_ENVIRONMENT', 'dev');

require __DIR__ . '/doubles.php';
require __DIR__ . '/../../src/Boot/Registry.php';
require __DIR__ . '/../../src/Command/CacheWarmCommand.php';
require __DIR__ . '/../../src/Command/CacheClearCommand.php';

/** The OPcache note's first line, written to stderr whenever HTTP was warmed or cleared. */
const NOTE_START = 'Note: OPcache invalidation from the CLI';

const HTTP_MISSING = '--mode=http requires citomni/http, which is not installed.';


/**
 * A failed expectation, reported as FAIL with its message.
 */
final class CheckFailed extends \RuntimeException {
}


// ----------------------------------------------------------------
// Assertions and helpers
// ----------------------------------------------------------------

/** Fail the current check with $message unless $condition holds. */
function expect(bool $condition, string $message): void {
	if (!$condition) {
		throw new CheckFailed($message);
	}
}


/** Fail the current check unless $actual is identical to $expected. */
function same(mixed $expected, mixed $actual, string $what): void {
	if ($expected !== $actual) {
		throw new CheckFailed($what . ': expected ' . export($expected) . ', got ' . export($actual));
	}
}


/** One-line rendering of a value for failure messages. */
function export(mixed $value): string {
	return (string)\json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
}


/**
 * Run a command the way the CLI runner does: a CLI App, the command map name, then run(argv).
 *
 * @return array{exit: int, stdout: list<string>, stderr: list<string>, calls: list<list<mixed>>}
 */
function runCommand(string $class, string $name, string ...$args): array {
	$app = new AppDouble('/app/config', ModeDouble::CLI);
	AppDouble::$calls = [];

	$command = new $class($app, $name, 'Test command.');
	$exit    = $command->run(['citomni', $name, ...$args]);

	return [
		'exit'   => $exit,
		'stdout' => $command->stdoutLines,
		'stderr' => $command->stderrLines,
		'calls'  => AppDouble::$calls,
	];
}


/**
 * Decode the single JSON document a command printed with --json.
 *
 * @return mixed  Decoded value; objects as arrays unless $assoc is false.
 */
function jsonOutput(array $r, bool $assoc = true): mixed {
	same(1, \count($r['stdout']), 'stdout writes with --json');
	return \json_decode($r['stdout'][0], $assoc, 512, \JSON_THROW_ON_ERROR);
}


// ----------------------------------------------------------------
// Checks without citomni/http
// ----------------------------------------------------------------

$withoutHttp = [

	'Registry maps cache:warm and cache:clear to the commands' => static function (): void {
		same(CacheWarmCommand::class, Registry::COMMANDS_CLI['cache:warm']['command'] ?? null, 'cache:warm');
		same(CacheClearCommand::class, Registry::COMMANDS_CLI['cache:clear']['command'] ?? null, 'cache:clear');
		expect((Registry::COMMANDS_CLI['cache:warm']['description'] ?? '') !== '', 'cache:warm has no description');
		expect((Registry::COMMANDS_CLI['cache:clear']['description'] ?? '') !== '', 'cache:clear has no description');
	},

	'cache:warm --mode=cli warms the running CLI App only' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm', '--mode=cli');
		same(0, $r['exit'], 'exit code');
		same([['warm', 'cli', null]], $r['calls'], 'App calls');
		same([
			'CLI cache warmed (env: dev):',
			'  cfg       /app/var/cache/cfg.cli.php',
			'  dispatch  /app/var/cache/commands.cli.php',
			'  services  /app/var/cache/services.cli.php',
		], $r['stdout'], 'stdout');
		same([], $r['stderr'], 'stderr');
	},

	'cache:warm without citomni/http warms CLI and reports HTTP as skipped' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm');
		same(0, $r['exit'], 'exit code');
		same([['warm', 'cli', null]], $r['calls'], 'App calls');
		same('HTTP cache: skipped (citomni/http is not installed)', $r['stdout'][\array_key_last($r['stdout'])] ?? null, 'last stdout line');
		same([], $r['stderr'], 'stderr (no OPcache note without HTTP)');
	},

	'cache:warm --json without citomni/http reports "skipped" for HTTP' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm', '--json');
		same(0, $r['exit'], 'exit code');
		same(['ok' => true, 'env' => 'dev', 'modes' => ['cli' => AppDouble::paths('cli'), 'http' => 'skipped']], jsonOutput($r), 'JSON');
	},

	'cache:warm --mode=http without citomni/http fails before touching App' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm', '--mode=http');
		same(1, $r['exit'], 'exit code');
		same([], $r['calls'], 'App calls');
		same([], $r['stdout'], 'stdout');
		same([HTTP_MISSING], $r['stderr'], 'stderr');
	},

	'cache:warm --mode=http --json without citomni/http prints ok=false and an empty modes object' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm', '--mode=http', '--json');
		same(1, $r['exit'], 'exit code');
		$json = jsonOutput($r, false);
		same([false, HTTP_MISSING], [$json->ok ?? null, $json->error ?? null], 'ok, error');
		expect(($json->modes ?? null) instanceof \stdClass && (array)$json->modes === [], 'modes must be {}: ' . $r['stdout'][0]);
	},

	'cache:clear --mode=http without citomni/http fails before touching App' => static function (): void {
		$r = runCommand(CacheClearCommand::class, 'cache:clear', '--mode=http');
		same(1, $r['exit'], 'exit code');
		same([], $r['calls'], 'App calls');
		same([HTTP_MISSING], $r['stderr'], 'stderr');
	},

	'cache:warm --env= is a usage error, not a cache built without an env overlay' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm', '--env=');
		same(2, $r['exit'], 'exit code');
		same([], $r['calls'], 'App calls');
		same(['Option --env requires a non-empty value', '', 'Usage: cache:warm [options]'], $r['stderr'], 'stderr');
	},

];


// ----------------------------------------------------------------
// Checks with citomni/http
// ----------------------------------------------------------------

$withHttp = [

	'cache:warm warms CLI through the running App, then HTTP through a second App in Mode::HTTP' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm');
		same(0, $r['exit'], 'exit code');
		same([['warm', 'cli', null], ['construct', 'http', '/app/config'], ['warm', 'http', null]], $r['calls'], 'App calls');
		same([
			'CLI cache warmed (env: dev):',
			'  cfg       /app/var/cache/cfg.cli.php',
			'  dispatch  /app/var/cache/commands.cli.php',
			'  services  /app/var/cache/services.cli.php',
			'HTTP cache warmed (env: dev):',
			'  cfg       /app/var/cache/cfg.http.php',
			'  dispatch  /app/var/cache/routes.http.php',
			'  services  /app/var/cache/services.http.php',
		], $r['stdout'], 'stdout');
	},

	'warming HTTP writes the OPcache note to stderr' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm', '--mode=http');
		same(0, $r['exit'], 'exit code');
		same([['construct', 'http', '/app/config'], ['warm', 'http', null]], $r['calls'], 'App calls');
		same(3, \count($r['stderr']), 'note lines on stderr');
		expect(\str_starts_with($r['stderr'][0], NOTE_START), 'note missing: ' . export($r['stderr']));
		expect(\str_contains(\implode(' ', $r['stderr']), '/_system/reset-cache'), 'note does not name /_system/reset-cache');
	},

	'--env is passed to both warmCache() calls and shown in both headers' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm', '--env=prod');
		same(0, $r['exit'], 'exit code');
		same([['warm', 'cli', 'prod'], ['construct', 'http', '/app/config'], ['warm', 'http', 'prod']], $r['calls'], 'App calls');
		same(['CLI cache warmed (env: prod):', 'HTTP cache warmed (env: prod):'], \array_values(\preg_grep('/^\S/', $r['stdout'])), 'headers');
	},

	'cache:warm -j prints one JSON object with ok, env, and both modes' => static function (): void {
		$r = runCommand(CacheWarmCommand::class, 'cache:warm', '-j');
		same(0, $r['exit'], 'exit code');
		same(['ok' => true, 'env' => 'dev', 'modes' => ['cli' => AppDouble::paths('cli'), 'http' => AppDouble::paths('http')]], jsonOutput($r), 'JSON');
		expect(\str_starts_with($r['stderr'][0] ?? '', NOTE_START), 'OPcache note missing from stderr: ' . export($r['stderr']));
	},

	'cache:clear --json reports removed paths and null for files that were not present' => static function (): void {
		$cli  = ['cfg' => '/app/var/cache/cfg.cli.php', 'dispatch' => null, 'services' => null];
		$http = ['cfg' => null, 'dispatch' => null, 'services' => null];
		AppDouble::$clearResults = ['cli' => $cli, 'http' => $http];

		$r = runCommand(CacheClearCommand::class, 'cache:clear', '--json');
		same(0, $r['exit'], 'exit code');
		same([['clear', 'cli'], ['construct', 'http', '/app/config'], ['clear', 'http']], $r['calls'], 'App calls');
		same(['ok' => true, 'modes' => ['cli' => $cli, 'http' => $http]], jsonOutput($r), 'JSON');
	},

	'cache:clear lists removed files and says when a mode had nothing to clear' => static function (): void {
		AppDouble::$clearResults = [
			'cli'  => ['cfg' => '/app/var/cache/cfg.cli.php', 'dispatch' => null, 'services' => '/app/var/cache/services.cli.php'],
			'http' => ['cfg' => null, 'dispatch' => null, 'services' => null],
		];

		$r = runCommand(CacheClearCommand::class, 'cache:clear');
		same(0, $r['exit'], 'exit code');
		same([
			'CLI cache cleared:',
			'  cfg       /app/var/cache/cfg.cli.php',
			'  dispatch  (not present)',
			'  services  /app/var/cache/services.cli.php',
			'HTTP cache: nothing to clear',
		], $r['stdout'], 'stdout');
		expect(\str_starts_with($r['stderr'][0] ?? '', NOTE_START), 'OPcache note missing from stderr: ' . export($r['stderr']));
	},

	'cache:clear --mode=cli clears the running App only and writes no OPcache note' => static function (): void {
		$r = runCommand(CacheClearCommand::class, 'cache:clear', '--mode=cli');
		same(0, $r['exit'], 'exit code');
		same([['clear', 'cli']], $r['calls'], 'App calls');
		same([], $r['stderr'], 'stderr');
	},

	'an exception from App reaches the caller, and the CLI cache warmed before it stays' => static function (): void {
		$failure = new \RuntimeException('disk full');
		AppDouble::$warmFailures = ['http' => $failure];

		$caught = null;
		try {
			runCommand(CacheWarmCommand::class, 'cache:warm');
		} catch (\RuntimeException $e) {
			$caught = $e;
		}

		expect($caught === $failure, 'expected the exception from App::warmCache(), got ' . ($caught === null ? 'none' : $caught::class . ': ' . $caught->getMessage()));
		same([['warm', 'cli', null], ['construct', 'http', '/app/config'], ['warm', 'http', null]], AppDouble::$calls, 'App calls');
	},

];


// ----------------------------------------------------------------
// Runner
// ----------------------------------------------------------------

$passed = 0;
$failed = 0;

$run = static function (string $name, \Closure $check) use (&$passed, &$failed): void {
	AppDouble::reset();
	try {
		$check();
		$passed++;
		\fwrite(\STDOUT, "PASS {$name}\n");
	} catch (CheckFailed $e) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$name} - {$e->getMessage()}\n");
	} catch (\Throwable $e) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$name} - " . $e::class . ': ' . $e->getMessage() . "\n");
	}
};

foreach ($withoutHttp as $name => $check) {
	$run($name, $check);
}

// From here on citomni/http counts as installed. An alias cannot be removed again,
// which is why the checks without it run first.
\class_alias(HttpRegistryDouble::class, 'CitOmni\Http\Boot\Registry');

foreach ($withHttp as $name => $check) {
	$run($name, $check);
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
