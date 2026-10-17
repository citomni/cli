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
 * FaultWrapper: Stream wrapper that puts a real directory behind faulty://logs and fails chosen operations.
 *
 * Lets the log cases make writes, copies, renames, truncations and locks fail at a known point,
 * which a real filesystem does not do on demand. faulty://logs/NAME is NAME in $root, and the
 * operations the log code uses go to the real file or directory unless $faults names them. PHP
 * calls the methods; no code calls them directly.
 *
 * Rules:
 * - Never lock the real file; the cases run in one process.
 */
class FaultWrapper {

	/** URL of the log directory that the wrapper serves from $root. */
	public const DIR = 'faulty://logs';

	/** @var resource|null Stream context; PHP sets it on every wrapper instance. */
	public $context;

	/** Real directory behind faulty://logs. */
	public static string $root = '';

	/** @var list<string> Operations to fail: append-short, copy-short, rename, truncate or lock. */
	public static array $faults = [];

	/** @var resource|null Real file handle. */
	private $handle = null;

	/** @var resource|null Real directory handle. */
	private $dir = null;

	/** Mode the real file was opened with. */
	private string $mode = '';

	/** Whether a short write has used up what this handle takes. */
	private bool $full = false;

	/**
	 * Map a faulty://logs URL to the real path.
	 *
	 * @param  string  $url  URL that starts with DIR.
	 * @return string
	 */
	private static function path(string $url): string {
		return self::$root . \substr($url, \strlen(self::DIR));
	}

	/**
	 * Tell whether an operation is set to fail.
	 *
	 * @param  string  $fault  Operation name, as listed in the class rules.
	 * @return bool
	 */
	private static function fails(string $fault): bool {
		return \in_array($fault, self::$faults, true);
	}

	/**
	 * Stat the real path behind a URL.
	 *
	 * @param  string  $url    faulty://logs URL.
	 * @param  int     $flags  STREAM_URL_STAT_* flags; ignored.
	 * @return array<int|string,int>|false  stat() of the real path, or false when it does not exist.
	 */
	public function url_stat(string $url, int $flags): array|false {
		$path = self::path($url);
		return \file_exists($path) ? \stat($path) : false;
	}

	/**
	 * Create the real directory behind a URL.
	 *
	 * @param  string  $url      faulty://logs URL.
	 * @param  int     $mode     Permission bits.
	 * @param  int     $options  STREAM_MKDIR_* flags; only STREAM_MKDIR_RECURSIVE is honored.
	 * @return bool
	 */
	public function mkdir(string $url, int $mode, int $options): bool {
		return \mkdir(self::path($url), $mode, ($options & \STREAM_MKDIR_RECURSIVE) !== 0);
	}

	/**
	 * Rename a real file, unless rename is set to fail.
	 *
	 * @param  string  $from  faulty://logs URL of the file.
	 * @param  string  $to    faulty://logs URL of the new name.
	 * @return bool
	 */
	public function rename(string $from, string $to): bool {
		return !self::fails('rename') && \rename(self::path($from), self::path($to));
	}

	/**
	 * Delete the real file behind a URL.
	 *
	 * @param  string  $url  faulty://logs URL.
	 * @return bool
	 */
	public function unlink(string $url): bool {
		return \unlink(self::path($url));
	}

	/**
	 * Change the permission bits of the real file; other metadata changes fail.
	 *
	 * @param  string  $url     faulty://logs URL.
	 * @param  int     $option  STREAM_META_* operation.
	 * @param  mixed   $value   Permission bits for STREAM_META_ACCESS.
	 * @return bool
	 */
	public function stream_metadata(string $url, int $option, mixed $value): bool {
		return $option === \STREAM_META_ACCESS && \chmod(self::path($url), (int)$value);
	}

	/**
	 * Open the real directory behind a URL.
	 *
	 * @param  string  $url      faulty://logs URL.
	 * @param  int     $options  Ignored.
	 * @return bool
	 */
	public function dir_opendir(string $url, int $options): bool {
		$dir = \opendir(self::path($url));
		$this->dir = ($dir === false) ? null : $dir;
		return $dir !== false;
	}

	/**
	 * Read the next entry of the real directory.
	 *
	 * @return string|false  Entry name, or false after the last one.
	 */
	public function dir_readdir(): string|false {
		return \readdir($this->dir);
	}

	/**
	 * Rewind the real directory.
	 *
	 * @return bool
	 */
	public function dir_rewinddir(): bool {
		\rewinddir($this->dir);
		return true;
	}

	/**
	 * Close the real directory.
	 *
	 * @return bool
	 */
	public function dir_closedir(): bool {
		\closedir($this->dir);
		return true;
	}

	/**
	 * Open the real file behind a URL.
	 *
	 * @param  string       $url         faulty://logs URL.
	 * @param  string       $mode        fopen() mode; it decides which faults apply.
	 * @param  int          $options     Ignored.
	 * @param  string|null  $openedPath  Left unset.
	 * @return bool
	 */
	public function stream_open(string $url, string $mode, int $options, ?string &$openedPath): bool {
		$handle = @\fopen(self::path($url), $mode);
		if ($handle === false) {
			return false;
		}
		$this->handle = $handle;
		$this->mode = $mode;
		return true;
	}

	/**
	 * Write to the real file, or only part of the data when a short write applies.
	 *
	 * Behavior:
	 * - With append-short on an append handle ('ab'), or copy-short on a rotation copy ('xb' or
	 *   'wb'), the handle takes the first 10 bytes of its first write and nothing after that.
	 *
	 * @param  string  $data
	 * @return int  Bytes written.
	 */
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

	/**
	 * Read from the real file.
	 *
	 * @param  int  $count  Maximum number of bytes.
	 * @return string|false
	 */
	public function stream_read(int $count): string|false {
		return \fread($this->handle, $count);
	}

	/**
	 * Tell whether the real file is at its end.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return \feof($this->handle);
	}

	/**
	 * Seek in the real file.
	 *
	 * @param  int  $offset
	 * @param  int  $whence  SEEK_SET, SEEK_CUR or SEEK_END.
	 * @return bool
	 */
	public function stream_seek(int $offset, int $whence): bool {
		return \fseek($this->handle, $offset, $whence) === 0;
	}

	/**
	 * Return the position in the real file.
	 *
	 * @return int
	 */
	public function stream_tell(): int {
		return (int)\ftell($this->handle);
	}

	/**
	 * Stat the open real file.
	 *
	 * @return array<int|string,int>|false
	 */
	public function stream_stat(): array|false {
		return \fstat($this->handle);
	}

	/**
	 * Truncate the real file; with truncate, a handle opened for rotation ('c+b') fails instead.
	 *
	 * @param  int  $size  New size in bytes.
	 * @return bool
	 */
	public function stream_truncate(int $size): bool {
		return !($this->mode === 'c+b' && self::fails('truncate')) && \ftruncate($this->handle, $size);
	}

	/**
	 * Report a lock as taken, or as failed when lock is set to fail; the real file is never locked.
	 *
	 * @param  int  $operation  LOCK_* operation; ignored.
	 * @return bool
	 */
	public function stream_lock(int $operation): bool {
		return !self::fails('lock');
	}

	/**
	 * Report the buffer as flushed; writes go straight to the real file.
	 *
	 * @return bool
	 */
	public function stream_flush(): bool {
		return true;
	}

	/**
	 * Refuse every stream option.
	 *
	 * @param  int       $option
	 * @param  int       $arg1
	 * @param  int|null  $arg2
	 * @return bool  Always false.
	 */
	public function stream_set_option(int $option, int $arg1, ?int $arg2): bool {
		return false;
	}

	/**
	 * Close the real file.
	 *
	 * @return void
	 */
	public function stream_close(): void {
		\fclose($this->handle);
	}
}
