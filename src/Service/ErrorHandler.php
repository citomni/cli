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
 * - Terminate with exit code 1 on fatal errors and uncaught exceptions.
 *
 * Differences from Http\ErrorHandler:
 * - No HTTP response rendering, content negotiation, or output buffer management.
 * - No request correlation (X-Request-Id) - CLI has no inbound request.
 * - No templates - stderr output is plain text.
 * - Terminates with exit(1), not exit(0) - CLI convention for error.
 * - Log files use cli_err_* prefix instead of http_err_*.
 * - Much simpler by design: CLI errors go to stderr, not to a client.
 *
 * Configuration keys (cfg.error_handler):
 * - error_handler.log.trigger (int bitmask, default E_ALL) - Which PHP errors to log.
 * - error_handler.log.path (string) - Directory for JSONL logs.
 * - error_handler.log.max_bytes (int, default 2_000_000) - Rotation threshold.
 * - error_handler.log.max_files (int, default 10) - Max rotated siblings to retain.
 * - error_handler.render.trigger (int bitmask, default 0) - Non-fatal PHP errors to show on stderr.
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
 * - E_USER_ERROR is treated as fatal (shutdown path, same as HTTP).
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
 */
final class ErrorHandler extends BaseService {

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
	 * - Swallows cfg read failures and falls back to an empty config.
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
			$raw = $this->app->cfg->error_handler ?? [];
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
		$dir = (string)($this->opt['log']['path'] ?? (\CITOMNI_APP_PATH . '/var/logs'));
		$this->logDir   = \rtrim($dir, '/\\');
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
	 * - Logs a structured JSONL record with trace.
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
				'class'   => $e::class,
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
				'trace'   => $this->traceArray($e->getTrace(), $e->getFile(), $e->getLine()),
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
	 * Handle non-fatal PHP errors (warnings/notices/etc.).
	 *
	 * Behavior:
	 * - Short-circuits for fatal-class errors (shutdown path).
	 * - Logs when the log mask matches.
	 * - Optionally renders to stderr when the render mask matches.
	 * - Returns true to suppress PHP's internal handler.
	 *
	 * @return bool  True to indicate the error was handled.
	 */
	public function handlePhpError(int $errno, string $errstr, string $errfile, int $errline): bool {
		if ($this->isFatal($errno)) {
			return true;
		}

		if (($errno & $this->logMask) === 0) {
			// Not in our log mask: Let PHP's internal handler decide.
			return false;
		}

		$errorId = $this->newErrorId();
		$rec = $this->baseRecord('php_error', $errorId) + [
			'errno'   => $errno,
			'message' => $errstr,
			'file'    => $errfile,
			'line'    => $errline,
		];
		$this->writeJsonl($this->logDir . '/cli_err_phperror.jsonl', $rec);

		if (($errno & $this->renderMask) !== 0) {
			$label = $this->errorLevelLabel($errno);
			$this->stderr("[{$label}] {$errstr}");
			$this->stderr("  in {$errfile}:{$errline}");
			$this->stderr("  error_id={$errorId}");
		}

		return true;
	}


	/**
	 * Shutdown handler: Detects fatal engine errors, logs, and terminates.
	 *
	 * Behavior:
	 * - Acts only on fatal-class errors (E_ERROR, E_PARSE, etc.).
	 * - Writes a JSONL log entry and outputs to stderr.
	 * - Terminates with exit(1).
	 *
	 * @return void
	 */
	public function handleShutdown(): void {
		$e = \error_get_last();
		if ($e === null) {
			return;
		}

		$errno = (int)($e['type'] ?? 0);
		if (!$this->isFatal($errno)) {
			return;
		}

		$errorId = $this->newErrorId();

		$rec = $this->baseRecord('shutdown', $errorId) + [
			'errno'   => $errno,
			'message' => (string)($e['message'] ?? ''),
			'file'    => (string)($e['file'] ?? ''),
			'line'    => (int)($e['line'] ?? 0),
		];

		$this->writeJsonl($this->logDir . '/cli_err_shutdown.jsonl', $rec);

		$label = $this->errorLevelLabel($errno);
		$this->stderr('');
		$this->stderr("[FATAL {$label}] " . (string)($e['message'] ?? 'Unknown fatal error'));
		$this->stderr("  in " . (string)($e['file'] ?? '?') . ':' . (int)($e['line'] ?? 0));
		$this->stderr("  error_id={$errorId}");

		exit(1);
	}









	// ----------------------------------------------------------------
	// Stderr rendering
	// ----------------------------------------------------------------

	/**
	 * Render an exception to stderr with bounded output.
	 *
	 * Behavior:
	 * - Always shows exception class, message, and throw site.
	 * - In dev with detail enabled, also shows a formatted stack trace.
	 * - Previous exceptions are chained with "Caused by:" prefix.
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

			// Chain previous exceptions
			$prev = $e->getPrevious();
			$depth = 0;
			while ($prev !== null && $depth++ < 5) {
				$this->stderr('');
				$this->stderr('Caused by: [' . $prev::class . '] ' . $prev->getMessage());
				$this->stderr('  in ' . $prev->getFile() . ':' . $prev->getLine());
				$this->renderTrace($prev->getTrace(), $prev->getFile(), $prev->getLine());
				$prev = $prev->getPrevious();
			}
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
	 * - Rotates when the next line would exceed $this->maxBytes.
	 * - Never throws; failures go to PHP's error_log.
	 *
	 * @param  string                $file    Absolute path to the live JSONL file.
	 * @param  array<string, mixed>  $record  Event payload to encode and append.
	 * @return void
	 */
	private function writeJsonl(string $file, array $record): void {
		try {
			if (!\is_dir($this->logDir) && !@\mkdir($this->logDir, 0775, true)) {
				throw new \RuntimeException('log dir create failed');
			}

			$encoded = \json_encode(
				$record,
				\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR
			);
			if ($encoded === false) {
				$encoded = '{"encode_error":true}';
			}
			$line = $encoded . "\n";

			$threshold = \max(1, (int)$this->maxBytes);

			$fh = @\fopen($file, 'ab');
			if ($fh === false) {
				throw new \RuntimeException('open failed');
			}

			try {
				@\flock($fh, \LOCK_EX);

				\clearstatcache(true, $file);
				$size = @\filesize($file) ?: 0;

				if (($size + \strlen($line)) > $threshold) {
					@\flock($fh, \LOCK_UN);
					@\fclose($fh);

					$this->rotate($file);

					$fh = @\fopen($file, 'ab');
					if ($fh === false) {
						throw new \RuntimeException('reopen failed');
					}
					@\flock($fh, \LOCK_EX);
				}

				@\fwrite($fh, $line);
				@\fflush($fh);

			} finally {
				@\flock($fh, \LOCK_UN);
				@\fclose($fh);
			}

		} catch (\Throwable $t) {
			@\error_log('[CitOmni CLI] writeJsonl failed: ' . $t->getMessage());
		}
	}


	/**
	 * Rotate a JSONL log using a sidecar lock and copy-and-truncate strategy.
	 *
	 * Behavior:
	 * - Serializes rotations per file using a sidecar lock file.
	 * - Copies current content to a UTC-timestamped sibling, then truncates.
	 * - Prunes old siblings beyond $this->maxFiles.
	 * - Never throws; errors go to PHP's error_log.
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
				@\flock($main, \LOCK_EX);

				\clearstatcache(true, $file);
				$stat = @\fstat($main);
				$size = (int)($stat['size'] ?? 0);
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
						return;
					}
				}

				try {
					@\rewind($main);
					@\stream_copy_to_stream($main, $tmpH);
					@\fflush($tmpH);

					@\ftruncate($main, 0);
					@\fflush($main);
				} finally {
					@\fclose($tmpH);
				}

				@\rename($tmp, $rotated);
				@\chmod($rotated, 0644);

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
	 * @param  string  $file  Base live JSONL path.
	 * @param  int     $max   Maximum rotated files to keep (0 disables pruning).
	 * @return void
	 */
	private function prune(string $file, int $max): void {
		if ($max <= 0) {
			return;
		}

		[$prefix, $ext] = (static function (string $path): array {
			$dot = \strrpos($path, '.');
			return ($dot === false) ? [$path, ''] : [\substr($path, 0, $dot), \substr($path, $dot)];
		})($file);

		$pattern = $prefix . '.*' . $ext;
		$list = \glob($pattern, \GLOB_NOSORT) ?: [];
		$list = \array_values(\array_filter($list, static fn(string $p) => $p !== $file && \is_file($p)));

		\usort($list, static function (string $a, string $b): int {
			$ma = @\filemtime($a) ?: 0;
			$mb = @\filemtime($b) ?: 0;
			return $mb <=> $ma;
		});

		$toDelete = \array_slice($list, $max);
		foreach ($toDelete as $path) {
			@\unlink($path);
		}
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
	 */
	private function baseRecord(string $type, string $errorId): array {
		return [
			'ts'       => \date('c'),
			'error_id' => $errorId,
			'type'     => $type,
			'mode'     => 'cli',
			'argv'     => $_SERVER['argv'] ?? [],
			'cwd'      => @\getcwd() ?: '',
			'pid'      => \getmypid() ?: 0,
		];
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
			E_STRICT            => 'E_STRICT',
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
			$items[] = \json_encode($k) . '=>' . $this->dumpArg($v, $depth);
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
