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
 * <env> becomes CITOMNI_ENVIRONMENT. [arg] is the worker id for "parallel".
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
];

if (!isset($cases[$case]) || $logDir === '') {
	\fwrite(\STDERR, "Usage: php -n probe.php <case> <logDir> <env> [arg]\n");
	exit(2);
}

\define('CITOMNI_ENVIRONMENT', $env);

require __DIR__ . '/doubles.php';
require __DIR__ . '/../../src/Service/ErrorHandler.php';

$options = \array_replace_recursive([
	'log'    => ['path' => $logDir, 'trigger' => \E_ALL],
	'render' => ['trigger' => \E_ALL, 'detail' => ['level' => 1]],
], $overrides[$case] ?? []);

// The handler reads cfg->error_handler first; an empty cfg leaves only $options.
$app = (object)['cfg' => new \stdClass()];

(new ErrorHandler($app, $options))->install();

$cases[$case]();

echo "end\n";
