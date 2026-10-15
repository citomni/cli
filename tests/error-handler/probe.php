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

namespace CitOmni\Cli\Tests\ErrorHandler;

use CitOmni\Cli\Boot\Registry;
use CitOmni\Cli\Service\ErrorHandler;

/*
 * Child process for run.php. Installs the real CLI ErrorHandler on top of the
 * kernel doubles, then runs one case. Several cases end the process the way a
 * real command would, through the handler's exit(1). The out-of-memory case
 * lives in oom.php.
 *
 * Usage (run.php builds the command line):
 *   php -n probe.php <case> <logDir> <env> [arg]
 *
 * <env> becomes CITOMNI_ENVIRONMENT. [arg] is the worker id for "parallel", for "fault"
 * the operations to fail, joined with "+", then "@" and the number of records to seed, and
 * for "long_argv" any long string, which only makes the command line long.
 *
 * The "fault" case logs through faulty://logs (faults.php), with <logDir> as the real
 * directory behind it. The "retention" case seeds <logDir> with rotations of its own log and
 * other files, then logs one record that rotates the live log.
 *
 * The cases that end in a fatal error, "user_error" and "compile", register a shutdown
 * function after install() that prints "[later]" on stderr.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast during setup. install() replaces this handler.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

$case   = (string)($argv[1] ?? '');
$logDir = (string)($argv[2] ?? '');
$env    = (string)($argv[3] ?? 'dev');
$arg    = (string)($argv[4] ?? '');

$cases = [
	'exception' => static function (): void {
		throw new \RuntimeException('outer failure', 7, new \LogicException('inner cause', 3));
	},

	// E_USER_ERROR must halt. PHP 8.4+ also raises E_DEPRECATED for passing it.
	'user_error' => static function (): void {
		\trigger_error('boom', \E_USER_ERROR);
		echo "STILL RUNNING\n";
	},

	'suppressed' => static function () use ($logDir): void {
		@\file_get_contents($logDir . '/missing.txt');
		echo 'last: ' . (\error_get_last()['message'] ?? 'none') . "\n";
	},

	'error_reporting' => static function (): void {
		\error_reporting(\E_ALL & ~\E_USER_WARNING);
		\trigger_error('excluded warning', \E_USER_WARNING);
		echo 'last: ' . (\error_get_last()['message'] ?? 'none') . "\n";
	},

	'render_only' => static function (): void {
		\trigger_error('render-only notice', \E_USER_NOTICE);
	},

	'log_only' => static function (): void {
		\trigger_error('log-only warning', \E_USER_WARNING);
	},

	'neither' => static function (): void {
		\trigger_error('unhandled notice', \E_USER_NOTICE);
		echo 'last: ' . (\error_get_last()['message'] ?? 'none') . "\n";
	},

	'utf8' => static function (): void {
		\trigger_error("bad \xB1 byte", \E_USER_WARNING);
	},

	'deprecation' => static function (): void {
		\trigger_error('old api', \E_USER_DEPRECATED);
	},

	// E_COMPILE_ERROR never reaches the error handler; only the shutdown handler sees it.
	'compile' => static function (): void {
		eval('function citomni_probe_dup(): void {} function citomni_probe_dup(): void {}');
	},

	'rotation' => static function (): void {
		for ($i = 0; $i < 30; $i++) {
			\trigger_error("rotation record {$i}", \E_USER_WARNING);
		}
	},

	'fault' => static function (): void {
		\trigger_error('fault record', \E_USER_WARNING);
	},

	'retention' => static function (): void {
		\trigger_error('retention record', \E_USER_WARNING);
	},

	'long_argv' => static function (): void {
		\trigger_error('long argv record', \E_USER_WARNING);
	},

	// Waits for run.php's start gate (<logDir>/../go), then writes 100 records
	// (PARALLEL_RECORDS in run.php).
	'parallel' => static function () use ($logDir, $arg): void {
		$gate     = \dirname($logDir) . '/go';
		$deadline = \microtime(true) + 10;
		while (!\is_file($gate)) {
			if (\microtime(true) > $deadline) {
				\fwrite(\STDERR, "Start gate timeout\n");
				exit(3);
			}
			\usleep(1_000);
		}
		for ($i = 0; $i < 100; $i++) {
			\trigger_error("parallel record {$arg}-{$i}", \E_USER_WARNING);
		}
	},
];

// Handler options per case, over a dev-like base that logs and renders everything.
$overrides = [
	'render_only' => ['log' => ['trigger' => \E_ALL & ~\E_USER_NOTICE]],
	'log_only'    => ['render' => ['trigger' => 0]],
	'neither'     => ['log' => ['trigger' => \E_ALL & ~\E_USER_NOTICE], 'render' => ['trigger' => \E_ALL & ~\E_USER_NOTICE]],
	'rotation'    => ['log' => ['max_bytes' => 600, 'max_files' => 2], 'render' => ['trigger' => 0]],
	'parallel'    => ['log' => ['max_bytes' => 4_096, 'max_files' => 0], 'render' => ['trigger' => 0]], // 0: keep every rotated file
	'retention'   => ['log' => ['max_bytes' => 1_000, 'max_files' => 2], 'render' => ['trigger' => 0]],
	'long_argv'   => ['render' => ['trigger' => 0]],
];

if (!isset($cases[$case]) || $logDir === '') {
	\fwrite(\STDERR, "Usage: php -n probe.php <case> <logDir> <env> [arg]\n");
	exit(2);
}

\define('CITOMNI_ENVIRONMENT', $env);

require __DIR__ . '/doubles.php';
require __DIR__ . '/faults.php';
require __DIR__ . '/../../src/Boot/Registry.php';
require __DIR__ . '/../../src/Service/ErrorHandler.php';

/** $count valid JSONL records of about 200 bytes each. */
function seedLines(int $count): string {
	$out = '';
	for ($i = 1; $i <= $count; $i++) {
		$out .= \json_encode(['seed' => $i, 'pad' => \str_repeat('s', 180)]) . "\n";
	}
	return $out;
}

if ($case === 'fault') {
	// "<fault>+<fault>@<records>": Ten records rotate at 1000 bytes, fewer are appended to.
	[$faults, $records] = \explode('@', $arg, 2) + ['', '0'];
	\mkdir($logDir);
	\file_put_contents($logDir . '/cli_err_phperror.jsonl', seedLines((int)$records));
	FaultWrapper::$root = $logDir;
	FaultWrapper::$faults = \explode('+', $faults);
	\stream_wrapper_register('faulty', FaultWrapper::class);
	$overrides['fault'] = ['log' => ['path' => FaultWrapper::DIR, 'max_bytes' => ((int)$records >= 10) ? 1_000 : 2_000_000], 'render' => ['trigger' => 0]];
}

if ($case === 'retention') {
	// Five rotations of the live log, files that only look like rotations, and another log's
	// rotation; the oldest mtimes go to the files that are not rotations of this log.
	\mkdir($logDir);
	foreach ([1, 2, 3, 4, 5] as $day) {
		\touch($logDir . "/cli_err_phperror.2026010{$day}T120000.000000Z.jsonl", 1_760_000_000 + $day);
	}
	foreach (['cli_err_phperror.backup.jsonl', 'cli_err_phperror.jsonl.lock', 'cli_err_phperror.20260109T120000.000000Z.jsonl.tmp', 'cli_err_exception.20260101T120000.000000Z.jsonl'] as $name) {
		\touch($logDir . '/' . $name, 1_700_000_000);
	}
	\file_put_contents($logDir . '/cli_err_phperror.jsonl', seedLines(10));
}

$options = \array_replace_recursive([
	'log'    => ['path' => $logDir, 'trigger' => \E_ALL],
	'render' => ['trigger' => \E_ALL, 'detail' => ['level' => 1]],
], $overrides[$case] ?? []);

// The handler reads the shipped cfg->error_handler and puts $options on top, as in an app.
$app = (object)['cfg' => (object)['error_handler' => Registry::CFG_CLI['error_handler']]];

(new ErrorHandler($app, $options))->install();

if ($case === 'user_error' || $case === 'compile') {
	\register_shutdown_function(static function (): void {
		\fwrite(\STDERR, "[later]\n");
	});
}

$cases[$case]();

echo "end\n";
