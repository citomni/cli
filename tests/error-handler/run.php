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

/*
 * Standalone suite for \CitOmni\Cli\Service\ErrorHandler.
 *
 * The handler installs process-wide handlers, and many cases end the process,
 * so every case runs in a fresh `php -n` child process: probe.php, or oom.php
 * for the out-of-memory case. Each child gets its own temporary directory,
 * which is removed again afterwards.
 *
 * Usage:
 *   php tests/error-handler/run.php
 *
 * The multi-process check runs only with CITOMNI_TEST_PARALLEL=1.
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/** Prefix of every directory this suite creates; nothing else is ever removed. */
const RUN_DIR_PREFIX = 'citomni_cli_error_handler_';

/** Workers in the multi-process check. */
const PARALLEL_WORKERS = 4;

/** Records each worker writes; must match the "parallel" case in probe.php. */
const PARALLEL_RECORDS = 100;


/**
 * A failed expectation, reported as FAIL with its message.
 */
final class CheckFailed extends \RuntimeException {
}


// ----------------------------------------------------------------
// Assertions and formatting
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


/** One-line, bounded rendering of a value for failure messages. */
function export(mixed $value): string {
	$json = \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
	return squash($json === false ? \var_export($value, true) : $json);
}


/** Collapse line breaks and cap the length, so a message stays on one line. */
function squash(string $text): string {
	$text = \trim((string)\preg_replace('/\s*\R\s*/', ' | ', $text));
	return \strlen($text) > 400 ? \substr($text, 0, 400) . '...' : $text;
}


/** Exit code and output of a child process, appended to failure messages. */
function describe(array $r): string {
	return ' [exit ' . $r['exit'] . '; stdout: ' . squash($r['stdout']) . '; stderr: ' . squash($r['stderr']) . ']';
}


/**
 * Error ids printed on stderr, in order.
 *
 * @return list<string>
 */
function errorIds(string $stderr): array {
	\preg_match_all('/error_id=(e_[0-9a-f]{16})/', $stderr, $m);
	return $m[1];
}


// ----------------------------------------------------------------
// Child processes
// ----------------------------------------------------------------

/** Create a fresh, uniquely named directory under the system temp dir. */
function makeRunDir(): string {
	$dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . RUN_DIR_PREFIX . \bin2hex(\random_bytes(6));
	\mkdir($dir, 0700);
	return $dir;
}


/** Remove a directory created by makeRunDir(). Any other path is left alone. */
function removeRunDir(string $dir): void {
	if (!\str_starts_with(\basename($dir), RUN_DIR_PREFIX) || !\is_dir($dir)) {
		return;
	}

	$items = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach ($items as $item) {
		if ($item->isDir() && !$item->isLink()) {
			\rmdir($item->getPathname());
		} else {
			\unlink($item->getPathname());
		}
	}
	\rmdir($dir);
}


/**
 * Start a PHP child process without waiting. Stdout and stderr go to <tag>.out and <tag>.err in $runDir.
 *
 * Every child runs with -n (no php.ini), error_reporting=-1, and log_errors=0. $ini adds -d
 * settings on top. "{dir}" in an argument or an ini value is replaced with $runDir.
 *
 * @param  list<string>           $args  Script path and its arguments.
 * @param  array<string, string>  $ini
 * @return resource  Process handle for proc_close().
 */
function startPhp(string $runDir, string $tag, array $args, array $ini = []) {
	$cmd = [\PHP_BINARY, '-n', '-d', 'error_reporting=-1', '-d', 'log_errors=0'];
	foreach ($ini as $key => $value) {
		\array_push($cmd, '-d', $key . '=' . \str_replace('{dir}', $runDir, $value));
	}
	foreach ($args as $arg) {
		$cmd[] = \str_replace('{dir}', $runDir, $arg);
	}

	$proc = \proc_open($cmd, [
		0 => ['pipe', 'r'],
		1 => ['file', $runDir . '/' . $tag . '.out', 'w'],
		2 => ['file', $runDir . '/' . $tag . '.err', 'w'],
	], $pipes);
	if (!\is_resource($proc)) {
		throw new \RuntimeException('Cannot start PHP child process: ' . \implode(' ', $args));
	}
	\fclose($pipes[0]);

	return $proc;
}


/**
 * Run a PHP child process to completion in its own temporary directory, then collect what it left.
 *
 * The handler under test writes its logs to {dir}/logs, and PHP's own error log, when enabled
 * through $ini, is expected at {dir}/php.log.
 *
 * @param  list<string>           $args  Script path and its arguments.
 * @param  array<string, string>  $ini
 * @return array{exit: int, stdout: string, stderr: string, logs: array<string, list<array<string, mixed>>>, files: list<string>, phpLog: string}
 */
function runPhp(array $args, array $ini = []): array {
	$runDir = makeRunDir();

	try {
		$exit = \proc_close(startPhp($runDir, 'child', $args, $ini));

		return [
			'exit'   => $exit,
			'stdout' => (string)\file_get_contents($runDir . '/child.out'),
			'stderr' => (string)\file_get_contents($runDir . '/child.err'),
			'logs'   => readLogs($runDir . '/logs'),
			'files'  => listFiles($runDir . '/logs'),
			'phpLog' => \is_file($runDir . '/php.log') ? (string)\file_get_contents($runDir . '/php.log') : '',
		];
	} finally {
		removeRunDir($runDir);
	}
}


/**
 * Run one probe.php case.
 *
 * @param  array<string, string>  $ini
 * @return array{exit: int, stdout: string, stderr: string, logs: array<string, list<array<string, mixed>>>, files: list<string>, phpLog: string}
 */
function probe(string $case, array $ini = [], string $env = 'dev'): array {
	return runPhp([__DIR__ . '/probe.php', $case, '{dir}/logs', $env], $ini);
}


/**
 * Decode every *.jsonl file in a log directory.
 *
 * @return array<string, list<array<string, mixed>>>  Records per file name, sorted by file name.
 */
function readLogs(string $logDir): array {
	$logs = [];
	foreach (listFiles($logDir) as $name) {
		if (!\str_ends_with($name, '.jsonl')) {
			continue;
		}
		$records = [];
		foreach (\file($logDir . '/' . $name, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $i => $line) {
			try {
				$records[] = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
			} catch (\JsonException $e) {
				throw new CheckFailed("{$name} line " . ($i + 1) . ' is not JSON (' . $e->getMessage() . '): ' . squash($line));
			}
		}
		$logs[$name] = $records;
	}
	return $logs;
}


/**
 * @return list<string>  File names in $dir, sorted; empty when $dir does not exist.
 */
function listFiles(string $dir): array {
	if (!\is_dir($dir)) {
		return [];
	}
	$names = \array_values(\array_diff(\scandir($dir), ['.', '..']));
	\sort($names);
	return $names;
}


// ----------------------------------------------------------------
// Checks
// ----------------------------------------------------------------

$checks = [

	'uncaught exception exits 1 and reports class, throw site, error_id, and dev trace on stderr' => static function (): void {
		$r = probe('exception');
		same(1, $r['exit'], 'exit code' . describe($r));
		same('', $r['stdout'], 'stdout');
		expect(\str_contains($r['stderr'], '[RuntimeException] outer failure'), 'header missing' . describe($r));
		expect(\str_contains($r['stderr'], 'probe.php:'), 'throw site missing' . describe($r));
		expect(\str_contains($r['stderr'], '(thrown)'), 'dev trace missing' . describe($r));
		$ids = errorIds($r['stderr']);
		same(1, \count($ids), 'error ids on stderr');
		same($ids[0], $r['logs']['cli_err_exception.jsonl'][0]['error_id'] ?? null, 'logged error_id');
	},

	'uncaught exception is logged with its code and previous-exception chain' => static function (): void {
		$r = probe('exception');
		$records = $r['logs']['cli_err_exception.jsonl'] ?? [];
		same(1, \count($records), 'exception records' . describe($r));
		$rec = $records[0];
		same(['RuntimeException', 7, 'outer failure'], [$rec['class'] ?? null, $rec['code'] ?? null, $rec['message'] ?? null], 'class, code, message');
		$prev = $rec['previous'] ?? null;
		expect(\is_array($prev) && \count($prev) === 1, 'previous: expected one entry, got ' . export($prev));
		same(['LogicException', 3, 'inner cause'], [$prev[0]['class'] ?? null, $prev[0]['code'] ?? null, $prev[0]['message'] ?? null], 'previous[0]');
	},

	'uncaught exception in prod renders the cause chain without traces' => static function (): void {
		$r = probe('exception', [], 'prod');
		same(1, $r['exit'], 'exit code' . describe($r));
		expect(\str_contains($r['stderr'], 'Caused by: [LogicException] inner cause'), 'cause missing' . describe($r));
		expect(!\str_contains($r['stderr'], '(thrown)'), 'trace rendered in prod' . describe($r));
	},

	'E_USER_ERROR halts the process and is reported once by the shutdown handler' => static function (): void {
		$r = probe('user_error');
		same(1, $r['exit'], 'exit code' . describe($r));
		expect(!\str_contains($r['stdout'], 'STILL RUNNING'), 'execution continued after E_USER_ERROR' . describe($r));
		same(1, \substr_count($r['stderr'], '[FATAL E_USER_ERROR] boom'), 'fatal reports on stderr' . describe($r));
		$rec = $r['logs']['cli_err_shutdown.jsonl'][0] ?? [];
		same([\E_USER_ERROR, 'E_USER_ERROR', 'boom'], [$rec['errno'] ?? null, $rec['level'] ?? null, $rec['message'] ?? null], 'shutdown record');
	},

	'@-suppressed warning is left to PHP: not rendered, not logged, seen by error_get_last()' => static function (): void {
		$r = probe('suppressed');
		same(0, $r['exit'], 'exit code' . describe($r));
		same('', $r['stderr'], 'stderr');
		same([], $r['logs'], 'log records');
		expect(\str_contains($r['stdout'], 'last: file_get_contents(') && \str_contains($r['stdout'], 'Failed to open stream'), 'error_get_last() did not see the warning' . describe($r));
	},

	'warning excluded by error_reporting() is left to PHP' => static function (): void {
		$r = probe('error_reporting');
		same(0, $r['exit'], 'exit code' . describe($r));
		same('', $r['stderr'], 'stderr');
		same([], $r['logs'], 'log records');
		expect(\str_contains($r['stdout'], 'last: excluded warning'), 'error_get_last() did not see the warning' . describe($r));
	},

	'level only in render.trigger is rendered without error_id and not logged' => static function (): void {
		$r = probe('render_only');
		same(0, $r['exit'], 'exit code' . describe($r));
		expect(\str_contains($r['stderr'], '[E_USER_NOTICE] render-only notice'), 'not rendered' . describe($r));
		expect(!\str_contains($r['stderr'], 'error_id='), 'error_id printed for an error that was not logged' . describe($r));
		same([], $r['logs'], 'log records');
	},

	'level only in log.trigger is logged with its level name and not rendered' => static function (): void {
		$r = probe('log_only');
		same(0, $r['exit'], 'exit code' . describe($r));
		same('', $r['stderr'], 'stderr');
		$records = $r['logs']['cli_err_phperror.jsonl'] ?? [];
		same(1, \count($records), 'php_error records');
		$rec = $records[0];
		same(['php_error', \E_USER_WARNING, 'E_USER_WARNING', 'log-only warning'], [$rec['type'] ?? null, $rec['errno'] ?? null, $rec['level'] ?? null, $rec['message'] ?? null], 'record');
	},

	'level in neither mask is left to PHP' => static function (): void {
		$r = probe('neither');
		same(0, $r['exit'], 'exit code' . describe($r));
		same('', $r['stderr'], 'stderr');
		same([], $r['logs'], 'log records');
		expect(\str_contains($r['stdout'], 'last: unhandled notice'), 'error_get_last() did not see the notice' . describe($r));
	},

	'invalid UTF-8 in a message is substituted in the log, not turned into null' => static function (): void {
		$r = probe('utf8');
		same("bad \u{FFFD} byte", $r['logs']['cli_err_phperror.jsonl'][0]['message'] ?? null, 'logged message' . describe($r));
	},

	'handling a deprecation raises no PHP diagnostic from the handler itself (E_STRICT)' => static function (): void {
		$r = probe('deprecation', ['log_errors' => '1', 'error_log' => '{dir}/php.log']);
		same(0, $r['exit'], 'exit code' . describe($r));
		same('', $r['phpLog'], "PHP's own error log");
		same('E_USER_DEPRECATED', $r['logs']['cli_err_phperror.jsonl'][0]['level'] ?? null, 'logged level');
	},

	'compile fatal is printed once when PHP has no error_log destination' => static function (): void {
		$r = probe('compile', ['log_errors' => '1']);
		same(1, $r['exit'], 'exit code' . describe($r));
		same('', $r['stdout'], 'stdout');
		same(1, \substr_count($r['stderr'], 'Cannot redeclare'), 'reports on stderr' . describe($r));
	},

	'compile fatal still reaches an explicit error_log, and stderr shows it once' => static function (): void {
		$r = probe('compile', ['log_errors' => '1', 'error_log' => '{dir}/php.log']);
		same(1, $r['exit'], 'exit code' . describe($r));
		expect(\str_contains($r['phpLog'], 'PHP Fatal error:') && \str_contains($r['phpLog'], 'Cannot redeclare'), "PHP's error log lacks the fatal: " . squash($r['phpLog']));
		same(1, \substr_count($r['stderr'], 'Cannot redeclare'), 'reports on stderr' . describe($r));
	},

	'compile fatal is logged by the shutdown handler with errno, level, and error_id' => static function (): void {
		$r = probe('compile');
		$rec = $r['logs']['cli_err_shutdown.jsonl'][0] ?? [];
		same([\E_COMPILE_ERROR, 'E_COMPILE_ERROR'], [$rec['errno'] ?? null, $rec['level'] ?? null], 'shutdown record' . describe($r));
		same([$rec['error_id'] ?? null], errorIds($r['stderr']), 'error ids on stderr');
	},

	'out-of-memory fatal on a full heap is still logged and rendered' => static function (): void {
		foreach ([4, 16, 64] as $mib) {
			$r     = runPhp([__DIR__ . '/oom.php', '{dir}/logs', (string)$mib], ['memory_limit' => $mib . 'M']);
			$where = " (memory_limit={$mib}M)";
			same(1, $r['exit'], 'exit code' . $where . describe($r));
			expect(\str_contains($r['stderr'], '[FATAL E_ERROR] Allowed memory size'), 'not rendered' . $where . describe($r));
			same(\E_ERROR, $r['logs']['cli_err_shutdown.jsonl'][0]['errno'] ?? null, 'shutdown record errno' . $where);
		}
	},

	'rotation keeps the newest record in the live file and prunes to max_files' => static function (): void {
		$r = probe('rotation');
		same(0, $r['exit'], 'exit code' . describe($r));
		$live = $r['logs']['cli_err_phperror.jsonl'] ?? [];
		expect($live !== [], 'live log is missing or empty; files: ' . export($r['files']));
		same('rotation record 29', $live[\array_key_last($live)]['message'] ?? null, 'last live record');
		$rotated = \preg_grep('/^cli_err_phperror\..+\.jsonl$/', $r['files']);
		same(2, \count($rotated), 'rotated files with max_files=2 (' . export($r['files']) . ')');
		same([], \array_values(\preg_grep('/\.tmp$/', $r['files'])), 'leftover temp files');
	},

];

// Multi-process checks; they run only with CITOMNI_TEST_PARALLEL=1.
$parallelChecks = [

	'concurrent writers lose no records across rotations' => static function (): void {
		$runDir = makeRunDir();

		try {
			$logDir = $runDir . '/logs';
			$procs  = [];
			for ($w = 0; $w < PARALLEL_WORKERS; $w++) {
				$procs[$w] = startPhp($runDir, 'worker' . $w, [__DIR__ . '/probe.php', 'parallel', $logDir, 'dev', (string)$w]);
			}

			// Open the start gate only when every worker has been started.
			\touch($runDir . '/go');

			$exits = [];
			foreach ($procs as $w => $proc) {
				$exits[$w] = \proc_close($proc);
			}
			foreach ($exits as $w => $exit) {
				same(0, $exit, "worker {$w} exit code (stderr: " . squash((string)\file_get_contents($runDir . "/worker{$w}.err")) . ')');
			}

			$logs     = readLogs($logDir);
			$messages = [];
			foreach ($logs as $records) {
				foreach ($records as $rec) {
					$messages[] = (string)($rec['message'] ?? '');
				}
			}

			$expected = [];
			for ($w = 0; $w < PARALLEL_WORKERS; $w++) {
				for ($i = 0; $i < PARALLEL_RECORDS; $i++) {
					$expected[] = "parallel record {$w}-{$i}";
				}
			}

			$missing    = \array_diff($expected, $messages);
			$duplicates = \count($messages) - \count(\array_unique($messages));
			same([0, 0], [\count($missing), $duplicates], 'missing and duplicated records (first missing: ' . export(\array_slice(\array_values($missing), 0, 3)) . ')');
			same(\count($expected), \count($messages), 'records across live and rotated files');
			expect(\count($logs) >= 10, 'expected at least 10 log files after rotation, got ' . \count($logs));
			same([], \array_values(\preg_grep('/\.tmp$/', listFiles($logDir))), 'leftover temp files');
		} finally {
			removeRunDir($runDir);
		}
	},

];


// ----------------------------------------------------------------
// Runner
// ----------------------------------------------------------------

$passed  = 0;
$failed  = 0;
$skipped = 0;

$run = static function (string $name, \Closure $check) use (&$passed, &$failed): void {
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

foreach ($checks as $name => $check) {
	$run($name, $check);
}

foreach ($parallelChecks as $name => $check) {
	if (\getenv('CITOMNI_TEST_PARALLEL') === '1') {
		$run($name, $check);
	} else {
		$skipped++;
		\fwrite(\STDOUT, "SKIP {$name}: set CITOMNI_TEST_PARALLEL=1 to run multi-process checks\n");
	}
}

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
exit($failed === 0 ? 0 : 1);
