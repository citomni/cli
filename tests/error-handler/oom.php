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
 * Child process for run.php: an out-of-memory fatal on a heap with no free
 * page left, so the shutdown handler has only its memory reserve to work with.
 *
 * Kept apart from probe.php on purpose. Whether a handler without a reserve
 * gets by depends on the heap layout, which includes everything compiled
 * before the fatal. Inside probe.php the case passed even without the reserve;
 * this small, fixed script fails without it and passes with it.
 *
 * Usage (run.php builds the command line):
 *   php -n -d memory_limit=<mib>M oom.php <logDir> <mib> <bytes> <others> [argvBytes]
 *
 * <bytes> is the length of every string that fills memory. Strings of one length fill one
 * size class of PHP's allocator, and the shutdown handler needs a fresh run of pages for
 * every full size class it allocates from.
 *
 * <others> is -1 for a log directory that does not exist yet. From 0 up, <logDir> holds
 * cli_err_shutdown.jsonl past log.max_bytes, so the record rotates it, ten rotations of it,
 * and <others> files of other logs.
 *
 * [argvBytes] appends an argument of that many bytes to $_SERVER['argv'], which is what the
 * handler logs as the command line. A real one that long cannot be passed on Windows, which
 * refuses a command line over 32767 characters.
 *
 * A shutdown function registered after install() prints "[later]" on stderr.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast during setup. install() replaces this handler.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

$logDir = (string)($argv[1] ?? '');
$mib    = (int)($argv[2] ?? 0);
$bytes  = (int)($argv[3] ?? 0);
$others = (int)($argv[4] ?? -1);
$argvBytes = (int)($argv[5] ?? 0);

if ($logDir === '' || $mib <= 0 || $bytes <= 0) {
	\fwrite(\STDERR, "Usage: php -n -d memory_limit=<mib>M oom.php <logDir> <mib> <bytes> <others> [argvBytes]\n");
	exit(2);
}

\define('CITOMNI_ENVIRONMENT', 'dev');

require __DIR__ . '/doubles.php';
require __DIR__ . '/../../src/Service/ErrorHandler.php';

if ($others >= 0) {
	\mkdir($logDir);
	\file_put_contents($logDir . '/cli_err_shutdown.jsonl', \str_repeat('{"seed":true,"pad":"' . \str_repeat('s', 179) . "\"}\n", 501));
	for ($i = 1; $i <= 10; $i++) {
		\touch(\sprintf('%s/cli_err_shutdown.202601%02dT120000.000000Z.jsonl', $logDir, $i));
	}
	for ($i = 0; $i < $others; $i++) {
		\touch(\sprintf('%s/app_%04d.20260101T120000.000000Z.jsonl', $logDir, $i));
	}
}

if ($argvBytes > 0) {
	$_SERVER['argv'][] = \str_repeat('a', $argvBytes);
}

$app = (object)['cfg' => new \stdClass()];

(new ErrorHandler($app, [
	'log'    => ['path' => $logDir, 'trigger' => \E_ALL, 'max_bytes' => 100_000, 'max_files' => 10],
	'render' => ['trigger' => \E_ALL, 'detail' => ['level' => 1]],
]))->install();

\register_shutdown_function(static function (): void {
	\fwrite(\STDERR, "[later]\n");
});

// gc_mem_caches() hands every reclaimable page back first, and the loop then allocates
// nothing but strings of one length. Without that, pages the engine reclaims on its way to
// the fatal are usually still free when the shutdown handler runs, and the check would pass
// even without the reserve. The loop stops about 1024 strings past memory_limit in case the
// limit is not enforced (USE_ZEND_ALLOC=0).
(static function (int $mib, int $bytes): void {
	$keep = \array_fill(0, \intdiv($mib * 1_048_576, $bytes + 25) + 1_024, null);
	\gc_mem_caches();
	for ($i = 0, $n = \count($keep); $i < $n; $i++) {
		$keep[$i] = \str_repeat('x', $bytes);
	}
})($mib, $bytes);

echo "end\n";
