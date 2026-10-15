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

use CitOmni\Kernel\Service\BaseService;

/**
 * ErrorHandler: CLI-only global error/exception/shutdown handler for CitOmni.
 *
 * This is the CLI counterpart to \CitOmni\Http\Service\ErrorHandler. Both are
 * self-contained error handlers that own their own JSONL logging and never
 * depend on the log service (which may not be installed or may itself be broken).
 *
 * Responsibilities:
 * - Install process-wide exception, error, and shutdown handlers.
 * - Write structured JSONL logs with size-based rotation.
 * - Output human-readable error information to stderr.
 * - Terminate an uncaught exception with exit code 1. A fatal error is reported from the
 *   shutdown handler without exit(), so PHP's exit status for it, 255, stands.
 *
 * Differences from Http\ErrorHandler:
 * - No HTTP response rendering, content negotiation, or output buffer management.
 * - No request correlation (X-Request-Id) - CLI has no inbound request.
 * - No templates - stderr output is plain text.
 * - Terminates an uncaught exception with exit(1), not exit(0) - CLI convention for error.
 * - Log files use cli_err_* prefix instead of http_err_*.
 * - Much simpler by design: CLI errors go to stderr, not to a client.
 *
 * Configuration keys (cfg.error_handler):
 * - error_handler.log.trigger (int bitmask, default E_ALL) - Which PHP errors to log.
 * - error_handler.log.path (string) - Directory for JSONL logs.
 * - error_handler.log.max_bytes (int, default 2_000_000) - Rotation threshold.
 * - error_handler.log.max_files (int, default 10) - Max rotated siblings to retain.
 * - error_handler.render.trigger (int bitmask, default 0) - Non-fatal PHP errors to show on stderr (independent of log.trigger).
 * - error_handler.render.detail.level (int: 0|1) - 0=minimal, 1=traces (effective only in dev).
 * - error_handler.render.detail.trace.max_frames (int, default 120)
 * - error_handler.render.detail.trace.max_arg_strlen (int, default 512)
 * - error_handler.render.detail.trace.max_array_items (int, default 20)
 * - error_handler.render.detail.trace.max_depth (int, default 3)
 * - error_handler.render.detail.trace.ellipsis (string, default "...")
 *
 * Error handling:
 * - Fail-soft inside handlers: Never throw; unexpected failures go to PHP's error_log.
 * - Reentrancy guard prevents recursive handling.
 * - Honors @-suppression and the active error_reporting() level, like the HTTP handler.
 * - E_USER_ERROR is treated as fatal: it is handed back to PHP, which halts the process,
 *   and handleShutdown() logs and renders it (same as HTTP).
 * - Owns stderr: display_errors is forced off, and log_errors too when PHP has no
 *   error_log destination (the CLI SAPI would otherwise print every fatal twice).
 *
 * Typical usage:
 *   // Cli\Kernel::boot() installs this when registered:
 *   $app->errorHandler->install();
 *
 * Notes:
 * - CLI-only by design. HTTP error handling lives in \CitOmni\Http\Service\ErrorHandler.
 * - Self-contained JSONL logging: Does not depend on the log service, which may not
 *   be installed or may itself be the source of the error.
 * - Designed for request-scoped CLI processes (commands). Long-lived workers must
 *   catch exceptions in their own loop body; any exception that reaches the global
 *   handler is treated as unrecoverable and terminates the process with exit(1).
 * - A log record keeps at most ARGV_MAX_BYTES of the command line, so the record of an
 *   out-of-memory fatal stays within what MEMORY_RESERVE_BYTES can write.
 */
final class ErrorHandler extends BaseService {

	/** JSON flags for log records: invalid UTF-8 is substituted, never turned into null. */
	private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE;

	/** Maximum number of previous exceptions logged and rendered per uncaught exception. */
	private const MAX_PREVIOUS = 5;

	/** Bytes install() reserves and handleShutdown() releases first, so an out-of-memory fatal leaves room to report it. */
	private const MEMORY_RESERVE_BYTES = 65536;

	/** Bytes of the command line a log record keeps; the rest is cut. */
	private const ARGV_MAX_BYTES = 4096;

	/** Frozen options: cfg.error_handler merged with ctor $options. */
	private array $opt = [];

	/** Non-fatal PHP error bitmask that should render to stderr (fatals are shutdown-only). */
	private int $renderMask = 0;

	/** PHP error bitmask to log (applies to non-fatals; fatals logged in shutdown). */
	private int $logMask = 0;

	/** Absolute log directory (no trailing slash). */
	private string $logDir = '';

	/** Max size in bytes of the live JSONL file before rotation. */
	private int $maxBytes = 2_000_000;

	/** How many rotated siblings to keep (newest first). */
	private int $maxFiles = 10;

	/** Reentrancy guard: Prevents recursive handling. */
	private static bool $inHandler = false;

	/** When true, include developer details (only effective in dev). */
	private bool $devDetail = false;

	/** Trace shaping limits. */
	private int $traceMaxFrames = 120;
	private int $traceMaxArgStr = 512;
	private int $traceMaxItems  = 20;
	private int $traceMaxDepth  = 3;
	private string $ellipsis    = '...';

	/** Idempotency flag: Ensures install() runs only once. */
	private bool $installed = false;

	/**
	 * Memory released first in handleShutdown(), so an "Allowed memory size exhausted"
	 * fatal can still be logged and rendered. PHP enforces memory_limit again once
	 * shutdown functions run, and a full heap would otherwise fail a second time.
	 */
	private static ?string $memoryReserve = null;








	// ----------------------------------------------------------------
	// Bootstrap and config
	// ----------------------------------------------------------------

	/**
	 * Initialize configuration from cfg and constructor options, then hydrate fields.
	 *
	 * Behavior:
	 * - Reads cfg->error_handler exactly once and unwraps to a plain array.
	 * - Merges ctor $this->options over cfg using deterministic "last wins".
	 * - Freezes runtime options by clearing $this->options after the merge.
	 * - Calls hydrate() to populate all internal fields from $this->opt.
	 * - Swallows cfg read failures, a missing error_handler node included, logs them to
	 *   PHP's error_log and falls back to an empty config.
	 *
	 * Notes:
	 * - No global side effects: Does not modify INI or resolve other services.
	 * - This method never throws; failures are treated as "no config".
	 *
	 * @return void
	 */
	protected function init(): void {
		$cfgNode = [];

		try {
			$raw = $this->app->cfg->error_handler;
			$cfgNode = ($raw instanceof \CitOmni\Kernel\Cfg)
				? $raw->toArray()
				: (\is_array($raw) ? $raw : []);
		} catch (\Throwable $t) {
			$cfgNode = [];
			@\error_log('[CitOmni CLI] ErrorHandler cfg read failed: ' . $t->getMessage());
		}

		$this->opt = \CitOmni\Kernel\Arr::mergeAssocLastWins($cfgNode, $this->options);
		$this->options = [];

		$this->hydrate();
	}


	/**
	 * Hydrate internal fields from merged options.
	 *
	 * Behavior:
	 * - Reads $this->opt and assigns strongly-typed properties.
	 * - Sanitizes render mask to exclude fatal-class errors.
	 * - Developer details enabled only when level >= 1 AND environment is dev.
	 * - Values are cast to the field types, not validated; a missing key keeps a built-in
	 *   default, so the handler still works when init() fell back to an empty config.
	 *
	 * Notes:
	 * - Pure in-memory: No I/O, no service lookups, no global effects.
	 *
	 * @return void
	 */
	private function hydrate(): void {
		$this->renderMask = (int)($this->opt['render']['trigger'] ?? 0);
		$this->renderMask &= ~(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

		$this->logMask  = (int)($this->opt['log']['trigger'] ?? E_ALL);

		$dir = (string)($this->opt['log']['path'] ?? '');
		$dir = \trim($dir);
		if ($dir === '') $dir = \CITOMNI_APP_PATH . '/var/logs';
		$this->logDir = \rtrim($dir, '/\\');

		$this->maxBytes = (int)($this->opt['log']['max_bytes'] ?? 2_000_000);
		$this->maxFiles = (int)($this->opt['log']['max_files'] ?? 10);

		$level = (int)($this->opt['render']['detail']['level'] ?? 0);
		$this->devDetail = ($level >= 1) && (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev');

		$t = (array)($this->opt['render']['detail']['trace'] ?? []);
		$this->traceMaxFrames = (int)($t['max_frames']      ?? 120);
		$this->traceMaxArgStr = (int)($t['max_arg_strlen']  ?? 512);
		$this->traceMaxItems  = (int)($t['max_array_items'] ?? 20);
		$this->traceMaxDepth  = (int)($t['max_depth']       ?? 3);
		$this->ellipsis       = (string)($t['ellipsis']     ?? '...');
	}


	/**
	 * Install global handlers for the CLI process.
	 *
	 * Behavior:
	 * - Registers exception, error, and shutdown handlers.
	 * - Forces display_errors=0 to prevent PHP from writing to stdout
	 *   (all error output goes to stderr through this handler).
	 * - Forces log_errors=0 when error_log is empty. The CLI SAPI then writes PHP's own
	 *   log lines to stderr, so every fatal would be printed twice. An explicit error_log
	 *   destination (file or syslog) is left alone.
	 * - Reserves MEMORY_RESERVE_BYTES that handleShutdown() releases before doing anything else.
	 * - Idempotent: A second call is a no-op.
	 *
	 * Notes:
	 * - Uses only pre-hydrated options; does not resolve other services.
	 *
	 * @return void
	 */
	public function install(): void {
		if ($this->installed) {
			return;
		}

		if (\is_int($this->opt['render']['force_error_reporting'] ?? null)) {
			@\error_reporting((int)$this->opt['render']['force_error_reporting']);
		}

		@\ini_set('display_errors', '0');

		// Without an error_log destination, PHP's own log lines go to stderr, which
		// this handler already owns.
		if ((string)\ini_get('error_log') === '') {
			@\ini_set('log_errors', '0');
		}

		self::$memoryReserve ??= \str_repeat('x', self::MEMORY_RESERVE_BYTES);

		\set_exception_handler([$this, 'handleException']);
		\set_error_handler([$this, 'handlePhpError']);
		\register_shutdown_function([$this, 'handleShutdown']);

		$this->installed = true;
	}








	// ----------------------------------------------------------------
	// Global handlers
	// ----------------------------------------------------------------

	/**
	 * Handle an uncaught exception: log, render to stderr, and terminate.
	 *
	 * Behavior:
	 * - Re-entrancy-safe via static guard.
	 * - Logs a structured JSONL record with code, trace, and the previous-exception chain.
	 * - Outputs a human-readable summary to stderr.
	 * - Terminates with exit(1).
	 *
	 * @return void
	 */
	public function handleException(\Throwable $e): void {
		if (self::$inHandler) {
			return;
		}
		self::$inHandler = true;

		try {
			$errorId = $this->newErrorId();

			$rec = $this->baseRecord('exception', $errorId) + [
				'class'    => $e::class,
				'code'     => $e->getCode(),
				'message'  => $e->getMessage(),
				'file'     => $e->getFile(),
				'line'     => $e->getLine(),
				'trace'    => $this->traceArray($e->getTrace(), $e->getFile(), $e->getLine()),
				'previous' => $this->previousChain($e),
			];

			$this->writeJsonl($this->logDir . '/cli_err_exception.jsonl', $rec);
			$this->renderException($e, $errorId);
		} catch (\Throwable $t) {
			@\error_log('[CitOmni CLI] handleException failed: ' . $t->getMessage());
		} finally {
			self::$inHandler = false;
		}

		exit(1);
	}


	/**
	 * Handle non-fatal PHP errors (warnings, notices, deprecations).
	 *
	 * Behavior:
	 * - Ignores diagnostics that @ or error_reporting() suppress (returns false), so @
	 *   keeps working and PHP still records them for error_get_last().
	 * - Hands E_USER_ERROR back to PHP (returns false). PHP halts the process, and
	 *   handleShutdown() logs and renders it.
	 * - Logs when the level is in log.trigger and renders to stderr when it is in
	 *   render.trigger. The two masks are independent.
	 * - Returns false when neither mask matches, so PHP's internal handler decides.
	 * - Otherwise returns true, which skips PHP's internal handler.
	 *
	 * Notes:
	 * - Prints error_id only when the error was logged, since the id refers to the log.
	 * - Does not render while the exception handler is running, so its output stays
	 *   intact; the error is still logged.
	 *
	 * @param  int     $errno    PHP error level.
	 * @param  string  $errstr   Error message.
	 * @param  string  $errfile  File where the error was raised.
	 * @param  int     $errline  Line where the error was raised.
	 * @return bool  True when handled here, false to let PHP handle it.
	 */
	public function handlePhpError(int $errno, string $errstr, string $errfile, int $errline): bool {

		// Since PHP 8.0 the handler is still called for @-suppressed diagnostics, with a
		// reduced error_reporting() mask. Returning false keeps @ working.
		if ((\error_reporting() & $errno) === 0) {
			return false;
		}

		// E_USER_ERROR is the only fatal-class level that reaches a user error handler.
		// Returning true would resume execution, and the error would never reach
		// error_get_last(). Returning false lets PHP halt; handleShutdown() reports it.
		if ($this->isFatal($errno)) {
			return false;
		}

		$log    = ($errno & $this->logMask) !== 0;
		$render = ($errno & $this->renderMask) !== 0 && !self::$inHandler;
		if (!$log && !$render) {
			return false;
		}

		$label   = $this->errorLevelLabel($errno);
		$errorId = '';

		if ($log) {
			$errorId = $this->newErrorId();
			$rec = $this->baseRecord('php_error', $errorId) + [
				'errno'   => $errno,
				'level'   => $label,
				'message' => $errstr,
				'file'    => $errfile,
				'line'    => $errline,
			];
			$this->writeJsonl($this->logDir . '/cli_err_phperror.jsonl', $rec);
		}

		if ($render) {
			$this->stderr("[{$label}] {$errstr}");
			$this->stderr("  in {$errfile}:{$errline}");
			if ($errorId !== '') {
				$this->stderr("  error_id={$errorId}");
			}
		}

		return true;
	}


	/**
	 * Shutdown handler: Reports a fatal engine error on stderr and in the log.
	 *
	 * Behavior:
	 * - Releases the memory reserve first, so an out-of-memory fatal can be reported.
	 * - Acts only on fatal-class errors (E_ERROR, E_PARSE, etc.).
	 * - Writes the report to stderr before the JSONL record, so a log write that fails, for
	 *   lack of memory or otherwise, leaves the report in place.
	 * - Returns without exit(): Shutdown functions registered after this one still run.
	 *
	 * Notes:
	 * - PHP ends the process with exit status 255 after a fatal error, unless a later shutdown
	 *   function calls exit() with another status.
	 *
	 * @return void
	 */
	public function handleShutdown(): void {
		self::$memoryReserve = null;

		$e = \error_get_last();
		if ($e === null) {
			return;
		}

		$errno = (int)($e['type'] ?? 0);
		if (!$this->isFatal($errno)) {
			return;
		}

		$errorId = $this->newErrorId();
		$label   = $this->errorLevelLabel($errno);

		// stderr first: It needs little memory, and without an error_log destination it is the
		// only report of the fatal error, because install() turned PHP's own off.
		$this->stderr('');
		$this->stderr("[FATAL {$label}] " . (string)($e['message'] ?? 'Unknown fatal error'));
		$this->stderr("  in " . (string)($e['file'] ?? '?') . ':' . (int)($e['line'] ?? 0));
		$this->stderr("  error_id={$errorId}");

		$rec = $this->baseRecord('shutdown', $errorId) + [
			'errno'   => $errno,
			'level'   => $label,
			'message' => (string)($e['message'] ?? ''),
			'file'    => (string)($e['file'] ?? ''),
			'line'    => (int)($e['line'] ?? 0),
		];

		$this->writeJsonl($this->logDir . '/cli_err_shutdown.jsonl', $rec);

		// No exit(): exit() in a shutdown function cancels every shutdown function registered
		// after this one.
	}









	// ----------------------------------------------------------------
	// Stderr rendering
	// ----------------------------------------------------------------

	/**
	 * Render an exception to stderr with bounded output.
	 *
	 * Behavior:
	 * - Always shows the exception class, message, throw site, and error id.
	 * - Always shows up to MAX_PREVIOUS previous exceptions as "Caused by:" entries.
	 * - With developer details (dev only), also shows a bounded stack trace for the
	 *   exception and for each previous exception.
	 *
	 * @param  \Throwable  $e        The exception to render.
	 * @param  string      $errorId  Correlation id for this error event.
	 * @return void
	 */
	private function renderException(\Throwable $e, string $errorId): void {
		$this->stderr('');
		$this->stderr('[' . $e::class . '] ' . $e->getMessage());
		$this->stderr('  in ' . $e->getFile() . ':' . $e->getLine());
		$this->stderr('  error_id=' . $errorId);

		if ($this->devDetail) {
			$this->stderr('');
			$this->renderTrace($e->getTrace(), $e->getFile(), $e->getLine());
		}

		$prev  = $e->getPrevious();
		$depth = 0;
		while ($prev !== null && $depth++ < self::MAX_PREVIOUS) {
			$this->stderr('');
			$this->stderr('Caused by: [' . $prev::class . '] ' . $prev->getMessage());
			$this->stderr('  in ' . $prev->getFile() . ':' . $prev->getLine());
			if ($this->devDetail) {
				$this->renderTrace($prev->getTrace(), $prev->getFile(), $prev->getLine());
			}
			$prev = $prev->getPrevious();
		}

		$this->stderr('');
	}


	/**
	 * Render a formatted stack trace to stderr.
	 *
	 * @param  array   $trace  Raw trace from Throwable::getTrace().
	 * @param  string  $file   Throw-site file.
	 * @param  int     $line   Throw-site line.
	 * @return void
	 */
	private function renderTrace(array $trace, string $file, int $line): void {
		$frames = $this->traceArray($trace, $file, $line);

		foreach ($frames as $i => $f) {
			if (isset($f['ellipsis'])) {
				$this->stderr("  ... (truncated)");
				break;
			}

			$loc = (string)($f['file'] ?? '?');
			$ln  = (int)($f['line'] ?? 0);
			$call = (string)($f['call'] ?? ($f['function'] ?? ''));

			$prefix = \str_pad('#' . $i, 5);
			if ($call !== '') {
				$this->stderr("  {$prefix} {$loc}:{$ln} {$call}");
			} else {
				$this->stderr("  {$prefix} {$loc}:{$ln}");
			}
		}
	}








	// ----------------------------------------------------------------
	// JSONL logging with rotation
	// ----------------------------------------------------------------

	/**
	 * Append one JSON line to a log with size-guarded rotation.
	 *
	 * Behavior:
	 * - Encodes $record and appends under exclusive file lock.
	 * - Invalid UTF-8 is replaced with U+FFFD instead of turning the value into null.
	 * - Rotates when the next line would exceed $this->maxBytes.
	 * - Cuts off a line the file took only in part, so the next record does not continue it.
	 * - Never throws; a failure goes to PHP's error_log, naming the file and, where PHP gave
	 *   one, the cause.
	 *
	 * Notes:
	 * - Where the lock cannot be taken, the line is still appended, but a partial line stays:
	 *   Cutting it off could cut another writer's record.
	 *
	 * @param  string                $file    Absolute path to the live JSONL file.
	 * @param  array<string, mixed>  $record  Event payload to encode and append.
	 * @return void
	 */
	private function writeJsonl(string $file, array $record): void {
		try {
			if ($this->logDir === '') {
				throw new \RuntimeException('log dir is empty');
			}

			if (\file_exists($this->logDir) && !\is_dir($this->logDir)) {
				throw new \RuntimeException(
					'log path exists but is not a directory: ' . $this->logDir
				);
			}

			if (!\is_dir($this->logDir)) {
				if (!@\mkdir($this->logDir, 0775, true) && !\is_dir($this->logDir)) {
					throw new \RuntimeException(
						'log dir create failed: ' . $this->logDir . $this->lastFailure()
					);
				}
			}

			$encoded = \json_encode($record, self::JSON_FLAGS);
			if ($encoded === false) {
				$encoded = '{"encode_error":true}';
			}

			$line = $encoded . "\n";
			$lineBytes = \strlen($line);
			$threshold = \max(1, (int)$this->maxBytes);
			$fh = @\fopen($file, 'ab');
			if ($fh === false) {
				throw new \RuntimeException('open failed: ' . $file . $this->lastFailure());
			}

			try {
				$locked = @\flock($fh, \LOCK_EX);
				$size = $this->streamSize($fh);

				if (($size + $lineBytes) > $threshold) {
					@\flock($fh, \LOCK_UN);
					@\fclose($fh);
					$fh = false;
					$this->rotate($file);

					$fh = @\fopen($file, 'ab');
					if ($fh === false) {
						throw new \RuntimeException('reopen failed: ' . $file . $this->lastFailure());
					}
					$locked = @\flock($fh, \LOCK_EX);
					$size = $this->streamSize($fh);
				}

				// A full disk or quota takes part of the line, or none.
				$written = @\fwrite($fh, $line);
				if ($written !== $lineBytes) {
					$written = \is_int($written) ? $written : 0;
					$cause   = $this->lastFailure();

					// A partial line would run into the next record. Without the lock, the bytes
					// after $size may already belong to another writer, so they stay.
					$cut = ($written === 0) || ($locked && @\ftruncate($fh, $size));
					throw new \RuntimeException("append failed: {$file}: {$written} of {$lineBytes} bytes written"
						. ($cut ? '' : ', the partial line remains') . $cause);
				}
			} finally {
				if ($fh !== false) {
					@\flock($fh, \LOCK_UN);
					@\fclose($fh);
				}
			}
		} catch (\Throwable $t) {
			@\error_log('[CitOmni CLI] writeJsonl failed: ' . $t->getMessage());
		}
	}


	/**
	 * Rotate a JSONL log using a sidecar lock and copy-and-truncate strategy.
	 *
	 * Behavior:
	 * - Serializes rotations per file using a sidecar lock file, and works under an exclusive
	 *   lock on the live file. Without that lock it does not rotate.
	 * - Copies current content to a UTC-timestamped sibling, then truncates. Truncates only once
	 *   the copy is complete, synced to disk and renamed into place; a failure before that
	 *   removes the copy and leaves the live file as it was, and a failed truncation leaves the
	 *   records in both files.
	 * - The rotated file gets the live file's permission bits.
	 * - Prunes old siblings beyond $this->maxFiles.
	 * - Never throws; errors go to PHP's error_log.
	 *
	 * Notes:
	 * - Where locks or fsync() fail, the live file grows past max_bytes instead of rotating. A
	 *   copy behind a stream wrapper other than plain files is not synced.
	 *
	 * @param  string  $file  Absolute path to the live JSONL file.
	 * @return void
	 */
	private function rotate(string $file): void {
		$lockPath = $file . '.lock';

		$lk = @\fopen($lockPath, 'c+b');
		if ($lk !== false) {
			@\flock($lk, \LOCK_EX);
		}

		try {
			$main = @\fopen($file, 'c+b');
			if ($main === false) {
				return;
			}

			try {
				// Copy and truncate are only safe while no writer can append in between.
				if (!@\flock($main, \LOCK_EX)) {
					throw new \RuntimeException('cannot lock ' . $file . '; not rotated');
				}

				$stat = @\fstat($main);
				$size = \is_array($stat) ? (int)$stat['size'] : 0;
				if ($size <= $this->maxBytes) {
					return;
				}

				[$prefix, $ext] = (static function (string $path): array {
					$dot = \strrpos($path, '.');
					return ($dot === false) ? [$path, ''] : [\substr($path, 0, $dot), \substr($path, $dot)];
				})($file);

				$ts = (static function (): string {
					$mt = \microtime(true);
					$dt = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $mt), new \DateTimeZone('UTC'));
					return $dt ? $dt->format('Ymd\THis.u\Z') : \gmdate('Ymd\THis\Z');
				})();

				$rotated = $prefix . '.' . $ts . $ext;

				$try = 0;
				while (\is_file($rotated) && $try++ < 5) {
					$suffix = '-' . \substr(\bin2hex(\random_bytes(2)), 0, 4);
					$rotated = $prefix . '.' . $ts . $suffix . $ext;
				}

				$tmp = $rotated . '.tmp';
				$tmpH = @\fopen($tmp, 'xb');
				if ($tmpH === false) {
					$tmpH = @\fopen($tmp, 'wb');
					if ($tmpH === false) {
						throw new \RuntimeException('cannot create ' . $tmp . $this->lastFailure() . '; not rotated');
					}
				}

				// The live file is truncated only once its records are safe in the rotated file:
				// complete, synced and published under its own name. Until then, a failure must
				// leave the live file as it was.
				@\rewind($main);
				$copied  = @\stream_copy_to_stream($main, $tmpH);
				$failure = ($copied === $size) ? '' : (($copied === false) ? 'copy failed' : "copied {$copied} of {$size} bytes") . $this->lastFailure();

				// fsync() exists for plain files only; another stream wrapper keeps its own durability.
				if ($failure === '' && \stream_get_meta_data($tmpH)['wrapper_type'] === 'plainfile' && !@\fsync($tmpH)) {
					$failure = 'fsync failed';
				}
				@\fclose($tmpH);

				if ($failure === '') {
					@\chmod($tmp, $stat['mode'] & 0777);
					if (!@\rename($tmp, $rotated)) {
						$failure = 'rename failed' . $this->lastFailure();
					}
				}
				if ($failure !== '') {
					@\unlink($tmp);
					throw new \RuntimeException("cannot publish {$rotated}: {$failure}; {$file} is unchanged");
				}

				if (!@\ftruncate($main, 0)) {
					throw new \RuntimeException("cannot truncate {$file}; its records are also in {$rotated}");
				}

			} finally {
				@\flock($main, \LOCK_UN);
				@\fclose($main);
			}

			$this->prune($file, $this->maxFiles);

		} catch (\Throwable $t) {
			@\error_log('[CitOmni CLI] rotate failed: ' . $t->getMessage());

		} finally {
			if (\is_resource($lk)) {
				@\flock($lk, \LOCK_UN);
				@\fclose($lk);
			}
		}
	}


	/**
	 * Prune oldest rotated JSONL siblings, keeping at most $max.
	 *
	 * Behavior:
	 * - Counts as a rotation only a file next to $file whose name rotate() could have created:
	 *   <base>.<UTC timestamp><ext>, with or without a collision suffix. Other files, the
	 *   rotations of other logs included, are left alone.
	 * - Keeps the $max newest by the timestamp in their names and deletes the rest; a failed
	 *   deletion is ignored.
	 *
	 * Notes:
	 * - Reads the directory one entry at a time and holds only this log's rotations, so its
	 *   memory does not grow with the other files in the directory.
	 *
	 * @param  string  $file  Base live JSONL path.
	 * @param  int     $max   Maximum rotated files to keep (0 disables pruning).
	 * @return void
	 */
	private function prune(string $file, int $max): void {
		if ($max <= 0) {
			return;
		}

		$dir  = \dirname($file);
		$name = \basename($file);
		$dot  = \strrpos($name, '.');
		$base = ($dot === false) ? $name : \substr($name, 0, $dot);
		$ext  = ($dot === false) ? '' : \substr($name, $dot);

		$pattern = '~\A' . \preg_quote($base, '~') . '\.\d{8}T\d{6}(?:\.\d{6})?Z(?:-[0-9a-f]{4})?' . \preg_quote($ext, '~') . '\z~';

		// Entry by entry: A full listing costs memory for every file in the directory, and
		// handleShutdown() may be running on the memory reserve. Not glob(): The directory
		// path may contain glob syntax.
		$dh = @\opendir($dir);
		if ($dh === false) {
			return;
		}
		$rotations = [];
		while (($entry = \readdir($dh)) !== false) {
			if (\preg_match($pattern, $entry) === 1) {
				$rotations[] = $entry;
			}
		}
		\closedir($dh);

		if (\count($rotations) <= $max) {
			return;
		}

		// The UTC timestamps sort chronologically as strings; newest first.
		\rsort($rotations, \SORT_STRING);

		foreach (\array_slice($rotations, $max) as $entry) {
			@\unlink($dir . '/' . $entry);
		}
	}


	/**
	 * Return the size of an open file.
	 *
	 * @param  resource  $handle  Open file.
	 * @return int  Size in bytes; 0 when it cannot be read.
	 */
	private function streamSize(mixed $handle): int {
		$stat = @\fstat($handle);
		return \is_array($stat) ? (int)$stat['size'] : 0;
	}


	/**
	 * Return PHP's last diagnostic when this file raised it, for an error_log breadcrumb.
	 *
	 * Right after a failed operation that raises one, such as fopen(), mkdir(), rename() or a
	 * write to a plain file, that diagnostic is the operation's cause. After an operation that
	 * fails without one, it can be an earlier diagnostic from this file.
	 *
	 * @return string  ": <diagnostic>", or an empty string when there is none or another file raised it.
	 */
	private function lastFailure(): string {
		$last = \error_get_last();
		return ($last !== null && $last['file'] === __FILE__) ? ': ' . $last['message'] : '';
	}








	// ----------------------------------------------------------------
	// Record shaping and identifiers
	// ----------------------------------------------------------------

	/**
	 * Build a canonical baseline for CLI log records.
	 *
	 * @param  string  $type     Category: 'exception'|'shutdown'|'php_error'.
	 * @param  string  $errorId  Correlation id for this error event.
	 * @return array<string, mixed>
	 * @see \CitOmni\Cli\Service\ErrorHandler::argvForRecord()  How the command line is cut.
	 */
	private function baseRecord(string $type, string $errorId): array {
		return [
			'ts'       => \date('c'),
			'error_id' => $errorId,
			'type'     => $type,
			'mode'     => 'cli',
			'argv'     => $this->argvForRecord(),
			'cwd'      => @\getcwd() ?: '',
			'pid'      => \getmypid() ?: 0,
		];
	}


	/**
	 * Return the command line for a log record, cut to ARGV_MAX_BYTES.
	 *
	 * Behavior:
	 * - Keeps the arguments in order while their total length fits ARGV_MAX_BYTES.
	 * - Cuts the first argument that does not fit, ends it with the ellipsis marker and leaves
	 *   out the arguments after it.
	 * - An argument that is not a string, which only code that rewrote $_SERVER['argv'] can
	 *   cause, is logged as its type.
	 *
	 * @return list<string>  Empty when $_SERVER['argv'] is not an array.
	 */
	private function argvForRecord(): array {
		$argv = $_SERVER['argv'] ?? [];
		if (!\is_array($argv)) {
			return [];
		}

		$out  = [];
		$left = self::ARGV_MAX_BYTES;
		foreach ($argv as $arg) {
			$arg = \is_string($arg) ? $arg : \get_debug_type($arg);
			if (\strlen($arg) > $left) {
				$out[] = \substr($arg, 0, $left) . $this->ellipsis;
				break;
			}
			$out[] = $arg;
			$left -= \strlen($arg);
		}

		return $out;
	}


	/**
	 * Summarize the previous-exception chain for a log record.
	 *
	 * @param  \Throwable  $e  The uncaught exception.
	 * @return list<array{class: string, code: int|string, message: string, file: string, line: int}>  Up to MAX_PREVIOUS entries, outermost first.
	 */
	private function previousChain(\Throwable $e): array {
		$chain = [];
		for ($prev = $e->getPrevious(); $prev !== null && \count($chain) < self::MAX_PREVIOUS; $prev = $prev->getPrevious()) {
			$chain[] = [
				'class'   => $prev::class,
				'code'    => $prev->getCode(),
				'message' => $prev->getMessage(),
				'file'    => $prev->getFile(),
				'line'    => $prev->getLine(),
			];
		}
		return $chain;
	}


	/**
	 * Generate a new internal error id.
	 *
	 * @return string  e.g. "e_1a2b3c4d5e6f7a8b"
	 */
	private function newErrorId(): string {
		return 'e_' . \substr(\bin2hex(\random_bytes(8)), 0, 16);
	}








	// ----------------------------------------------------------------
	// Classification and labels
	// ----------------------------------------------------------------

	/**
	 * Whether a PHP error number is a fatal-class error.
	 *
	 * @return bool
	 */
	private function isFatal(int $errno): bool {
		return \in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
	}


	/**
	 * Human-readable label for a single PHP error-level constant.
	 *
	 * Maps individual levels (E_WARNING, E_NOTICE, etc.) to their constant name.
	 * Not intended for bitmasks (E_ALL, E_ALL & ~E_NOTICE, etc.) — PHP calls
	 * error handlers with one level at a time, which is this method's scope.
	 *
	 * E_STRICT is not mapped: PHP no longer raises it, and since PHP 8.4 reading the
	 * constant raises E_DEPRECATED, which would fire from inside the error handler.
	 *
	 * @param  int  $errno  Single PHP error-level constant.
	 * @return string  Constant name or "E_UNKNOWN(N)" for unrecognized levels.
	 */
	private function errorLevelLabel(int $errno): string {
		return match ($errno) {
			E_ERROR             => 'E_ERROR',
			E_WARNING           => 'E_WARNING',
			E_PARSE             => 'E_PARSE',
			E_NOTICE            => 'E_NOTICE',
			E_CORE_ERROR        => 'E_CORE_ERROR',
			E_CORE_WARNING      => 'E_CORE_WARNING',
			E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
			E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
			E_USER_ERROR        => 'E_USER_ERROR',
			E_USER_WARNING      => 'E_USER_WARNING',
			E_USER_NOTICE       => 'E_USER_NOTICE',
			E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			E_DEPRECATED        => 'E_DEPRECATED',
			E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
			default             => 'E_UNKNOWN(' . $errno . ')',
		};
	}







	// ----------------------------------------------------------------
	// Trace capture and safe stringification
	// ----------------------------------------------------------------

	/**
	 * Format a backtrace into a safe, bounded array for logs and stderr.
	 *
	 * @param  array<int, array<string, mixed>>  $trace  Raw trace from Throwable::getTrace().
	 * @param  string  $file  Throw-site file.
	 * @param  int     $line  Throw-site line.
	 * @return array<int, array<string, mixed>>  Bounded, log-friendly frames.
	 */
	private function traceArray(array $trace, string $file, int $line): array {
		$out = [['file' => $file, 'line' => $line, 'function' => '(thrown)']];
		$count = 0;

		foreach ($trace as $f) {
			if ($count++ >= $this->traceMaxFrames) {
				$out[] = ['ellipsis' => true];
				break;
			}

			$out[] = [
				'file' => (string)($f['file'] ?? ''),
				'line' => (int)($f['line'] ?? 0),
				'call' => $this->formatCall($f),
			];
		}

		return $out;
	}


	/**
	 * Render one trace frame's callable with compact argument preview.
	 *
	 * @param  array<string, mixed>  $f  One frame from a backtrace.
	 * @return string  Human-readable call signature.
	 */
	private function formatCall(array $f): string {
		$fn   = (string)($f['function'] ?? 'unknown');
		$cls  = (string)($f['class'] ?? '');
		$type = (string)($f['type'] ?? '');
		$call = ($cls !== '') ? ($cls . $type . $fn) : $fn;

		$args = [];
		if (isset($f['args']) && \is_array($f['args'])) {
			$i = 0;
			foreach ($f['args'] as $a) {
				if ($i++ >= $this->traceMaxItems) {
					$args[] = $this->ellipsis;
					break;
				}
				$args[] = $this->dumpArg($a, 0);
			}
		}

		return $call . '(' . \implode(', ', $args) . ')';
	}


	/**
	 * Format a single argument for trace output with depth and size limits.
	 *
	 * @param  mixed  $v      Value to render.
	 * @param  int    $depth  Current recursion depth.
	 * @return string  Safe, single-line representation.
	 */
	private function dumpArg(mixed $v, int $depth): string {
		if ($depth >= $this->traceMaxDepth) {
			return $this->ellipsis;
		}

		return match (true) {
			\is_string($v) => $this->truncate($v),
			\is_int($v), \is_float($v) => (string)$v,
			\is_bool($v) => $v ? 'true' : 'false',
			\is_null($v) => 'null',
			\is_array($v) => $this->dumpArray($v, $depth + 1),
			\is_object($v) => 'object(' . $v::class . ')',
			default => \gettype($v),
		};
	}


	/**
	 * Quote and truncate a string for trace output.
	 *
	 * @param  string  $s  Input string.
	 * @return string  Quoted, possibly truncated string.
	 */
	private function truncate(string $s): string {
		if (\strlen($s) <= $this->traceMaxArgStr) {
			return "'" . $s . "'";
		}
		return "'" . \substr($s, 0, $this->traceMaxArgStr) . $this->ellipsis . "'";
	}


	/**
	 * Render an array for traces with item and depth limits.
	 *
	 * @param  array<mixed>  $a      Array to render.
	 * @param  int           $depth  Current recursion depth.
	 * @return string  Compact, single-line array representation.
	 */
	private function dumpArray(array $a, int $depth): string {
		$items = [];
		$i = 0;

		foreach ($a as $k => $v) {
			if ($i++ >= $this->traceMaxItems) {
				$items[] = $this->ellipsis;
				break;
			}
			$items[] = \json_encode($k, self::JSON_FLAGS) . '=>' . $this->dumpArg($v, $depth);
		}

		return '[' . \implode(', ', $items) . ']';
	}


	// ----------------------------------------------------------------
	// Output helper
	// ----------------------------------------------------------------

	/**
	 * Write a line to stderr.
	 *
	 * @param  string  $line  Text to write.
	 * @return void
	 */
	private function stderr(string $line): void {
		@\fwrite(\STDERR, $line . \PHP_EOL);
	}

}
