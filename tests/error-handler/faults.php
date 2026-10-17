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
 * Fault injection for the log cases in probe.php. Not a suite of its own.
 */

/**
 * Stream wrapper that puts a real directory behind faulty://logs and fails chosen operations.
 *
 * Behavior:
 * - faulty://logs/<name> is <FaultWrapper::$root>/<name>. Every operation goes to the real
 *   file, except the ones FaultWrapper::$faults names:
 *     - append-short: an append handle ('ab') takes 10 bytes of a write, then nothing,
 *     - copy-short: a rotation copy ('xb' or 'wb') takes 10 bytes, then nothing,
 *     - rename: rename() fails,
 *     - truncate: the live file opened for rotation ('c+b') cannot be truncated,
 *     - lock: flock() fails.
 * - flock() never locks the real file; the cases run in one process.
 */
class FaultWrapper {

	public const DIR = 'faulty://logs';

	/** @var resource|null Set by PHP on every wrapper instance. */
	public $context;

	/** Real directory behind faulty://logs. */
	public static string $root = '';

	/** @var list<string> Operations to fail. */
	public static array $faults = [];

	/** @var resource|null Real file handle. */
	private $handle = null;

	/** @var resource|null Real directory handle. */
	private $dir = null;

	private string $mode = '';

	/** Whether a short write has used up what this handle takes. */
	private bool $full = false;

	private static function path(string $url): string {
		return self::$root . \substr($url, \strlen(self::DIR));
	}

	private static function fails(string $fault): bool {
		return \in_array($fault, self::$faults, true);
	}

	public function url_stat(string $url, int $flags): array|false {
		$path = self::path($url);
		return \file_exists($path) ? \stat($path) : false;
	}

	public function mkdir(string $url, int $mode, int $options): bool {
		return \mkdir(self::path($url), $mode, ($options & \STREAM_MKDIR_RECURSIVE) !== 0);
	}

	public function rename(string $from, string $to): bool {
		return !self::fails('rename') && \rename(self::path($from), self::path($to));
	}

	public function unlink(string $url): bool {
		return \unlink(self::path($url));
	}

	public function stream_metadata(string $url, int $option, mixed $value): bool {
		return $option === \STREAM_META_ACCESS && \chmod(self::path($url), (int)$value);
	}

	public function dir_opendir(string $url, int $options): bool {
		$dir = \opendir(self::path($url));
		$this->dir = ($dir === false) ? null : $dir;
		return $dir !== false;
	}

	public function dir_readdir(): string|false {
		return \readdir($this->dir);
	}

	public function dir_rewinddir(): bool {
		\rewinddir($this->dir);
		return true;
	}

	public function dir_closedir(): bool {
		\closedir($this->dir);
		return true;
	}

	public function stream_open(string $url, string $mode, int $options, ?string &$openedPath): bool {
		$handle = @\fopen(self::path($url), $mode);
		if ($handle === false) {
			return false;
		}
		$this->handle = $handle;
		$this->mode = $mode;
		return true;
	}

	public function stream_write(string $data): int {
		$short = ($this->mode === 'ab' && self::fails('append-short'))
			|| (($this->mode === 'xb' || $this->mode === 'wb') && self::fails('copy-short'));
		if ($short && $this->full) {
			return 0;
		}
		if ($short) {
			$this->full = true;
			$data = \substr($data, 0, 10);
		}
		return (int)\fwrite($this->handle, $data);
	}

	public function stream_read(int $count): string|false {
		return \fread($this->handle, $count);
	}

	public function stream_eof(): bool {
		return \feof($this->handle);
	}

	public function stream_seek(int $offset, int $whence): bool {
		return \fseek($this->handle, $offset, $whence) === 0;
	}

	public function stream_tell(): int {
		return (int)\ftell($this->handle);
	}

	public function stream_stat(): array|false {
		return \fstat($this->handle);
	}

	public function stream_truncate(int $size): bool {
		return !($this->mode === 'c+b' && self::fails('truncate')) && \ftruncate($this->handle, $size);
	}

	public function stream_lock(int $operation): bool {
		return !self::fails('lock');
	}

	public function stream_flush(): bool {
		return true;
	}

	public function stream_set_option(int $option, int $arg1, ?int $arg2): bool {
		return false;
	}

	public function stream_close(): void {
		\fclose($this->handle);
	}
}
