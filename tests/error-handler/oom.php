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
 *   php -n -d memory_limit=<mib>M oom.php <logDir> <mib>
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

if ($logDir === '' || $mib <= 0) {
	\fwrite(\STDERR, "Usage: php -n -d memory_limit=<mib>M oom.php <logDir> <mib>\n");
	exit(2);
}

\define('CITOMNI_ENVIRONMENT', 'dev');

require __DIR__ . '/doubles.php';
require __DIR__ . '/../../src/Service/ErrorHandler.php';

$app = (object)['cfg' => new \stdClass()];

(new ErrorHandler($app, [
	'log'    => ['path' => $logDir, 'trigger' => \E_ALL],
	'render' => ['trigger' => \E_ALL, 'detail' => ['level' => 1]],
]))->install();

// gc_mem_caches() hands every reclaimable page back first, and the loop then allocates
// nothing but one-page strings. Without that, pages the engine reclaims on its way to the
// fatal are usually still free when the shutdown handler runs, and the check would pass
// even without the reserve. The loop stops after memory_limit + 4 MiB in case the limit
// is not enforced (USE_ZEND_ALLOC=0).
(static function (int $mib): void {
	$keep = \array_fill(0, $mib * 256 + 1_024, null);
	\gc_mem_caches();
	for ($i = 0, $n = \count($keep); $i < $n; $i++) {
		$keep[$i] = \str_repeat('x', 4_000);
	}
})($mib);

echo "end\n";
