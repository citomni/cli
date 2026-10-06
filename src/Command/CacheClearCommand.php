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

namespace CitOmni\Cli\Command;

use CitOmni\Kernel\App;
use CitOmni\Kernel\Command\BaseCommand;
use CitOmni\Kernel\Mode;

/**
 * Remove the compiled config, dispatch, and service caches.
 *
 * CLI adapter over App::clearCache() for deploy scripts. The CLI cache is
 * cleared through the running CLI App. The HTTP cache is cleared through a
 * second App constructed in Mode::HTTP from the same config directory. Later
 * boots of a cleared mode rebuild from sources until the cache is warmed again.
 *
 * Behavior:
 * - --mode=cli removes cfg.cli.php, commands.cli.php, and services.cli.php.
 * - --mode=http removes cfg.http.php, routes.http.php, and services.http.php.
 *   It requires citomni/http (\CitOmni\Http\Boot\Registry); without it the
 *   command returns FAILURE.
 * - --mode=all (default) clears CLI first, then HTTP when citomni/http is
 *   installed. Otherwise HTTP is reported as skipped.
 * - A file that is not present is reported as such; that is not an error.
 * - Prints the removed files per mode on stdout. With --json it prints one
 *   object instead: {ok, modes: {cli: {...}, http: {...} | "skipped"}}.
 * - Writes a fixed OPcache note to stderr whenever the HTTP cache is cleared.
 *
 * Notes:
 * - Exceptions from App construction and clearCache() are not caught; the CLI
 *   error handler renders them and exits 1. That includes a cache file that
 *   exists but cannot be removed.
 * - The running CLI App keeps what it loaded; clearing affects later boots.
 * - Building the HTTP App loads its cache when present. Otherwise it evaluates
 *   the app's HTTP config files in this CLI process (see CacheWarmCommand for
 *   the \CITOMNI_PUBLIC_ROOT_URL caveat in dev).
 *
 * Typical usage:
 *   php bin/citomni cache:clear
 *   php bin/citomni cache:clear --mode=http --json
 */
final class CacheClearCommand extends BaseCommand {

	/** Written to stderr whenever the HTTP cache was cleared. */
	private const OPCACHE_NOTE = [
		'Note: OPcache invalidation from the CLI does not reach the web server\'s OPcache (PHP-FPM, mod_php).',
		'With opcache.validate_timestamps=0, reload PHP-FPM or the web server, or POST /_system/reset-cache,',
		'so it stops serving compiled copies of the old cache and config files.',
	];


	/**
	 * Define the command options.
	 *
	 * @return array<string, mixed> Command signature.
	 */
	protected function signature(): array {
		return [
			'options' => [
				'mode' => [
					'type'        => 'string',
					'description' => 'Which caches to clear',
					'default'     => 'all',
					'allowed'     => ['cli', 'http', 'all'],
				],
				'json' => [
					'short'       => 'j',
					'type'        => 'bool',
					'description' => 'Print one JSON object on stdout',
					'default'     => false,
				],
			],
		];
	}


	/**
	 * Clear the selected caches and report the removed files.
	 *
	 * @return int Command exit code.
	 */
	protected function execute(): int {
		$mode = $this->getString('mode');
		$json = $this->getBool('json');

		// -- 1. Validate input ------------------------------------------------
		$httpInstalled = \class_exists(\CitOmni\Http\Boot\Registry::class);

		if ($mode === 'http' && !$httpInstalled) {
			$message = '--mode=http requires citomni/http, which is not installed.';
			if ($json) {
				$this->stdout($this->encodeJson(['ok' => false, 'error' => $message, 'modes' => (object)[]]));
			}
			$this->error($message);
			return self::FAILURE;
		}

		$modes = [];

		// -- 2. CLI cache through the running App ------------------------------
		if ($mode !== 'http') {
			$modes['cli'] = $this->app->clearCache();
			if (!$json) {
				$this->renderCleared('CLI', $modes['cli']);
			}
		}

		// -- 3. HTTP cache through a second App in Mode::HTTP ------------------
		if ($mode !== 'cli') {
			if ($httpInstalled) {
				$httpApp = new App($this->app->getConfigDir(), Mode::HTTP);
				$modes['http'] = $httpApp->clearCache();
				if (!$json) {
					$this->renderCleared('HTTP', $modes['http']);
				}
			} else {
				$modes['http'] = 'skipped';
				if (!$json) {
					$this->stdout('HTTP cache: skipped (citomni/http is not installed)');
				}
			}
		}

		// -- 4. Report --------------------------------------------------------
		if ($json) {
			$this->stdout($this->encodeJson(['ok' => true, 'modes' => (object)$modes]));
		}

		if (\is_array($modes['http'] ?? null)) {
			foreach (self::OPCACHE_NOTE as $line) {
				$this->warning($line);
			}
		}

		return self::SUCCESS;
	}


	/**
	 * Print the files removed for one mode.
	 *
	 * @param  string                                                     $label  Mode label ('CLI' or 'HTTP').
	 * @param  array{cfg: ?string, dispatch: ?string, services: ?string}  $files  Result of App::clearCache().
	 * @return void
	 */
	private function renderCleared(string $label, array $files): void {
		if (\array_filter($files) === []) {
			$this->stdout("{$label} cache: nothing to clear");
			return;
		}

		$this->stdout("{$label} cache cleared:");
		foreach ($files as $key => $path) {
			$this->stdout('  ' . \str_pad($key, 10) . ($path ?? '(not present)'));
		}
	}


	/**
	 * Encode a result object and fail on encoding errors.
	 *
	 * @param  array<string, mixed>  $data  Result data.
	 * @return string  Encoded JSON.
	 * @throws \JsonException  When the data cannot be encoded.
	 */
	private function encodeJson(array $data): string {
		return \json_encode(
			$data,
			\JSON_PRETTY_PRINT
			| \JSON_UNESCAPED_SLASHES
			| \JSON_UNESCAPED_UNICODE
			| \JSON_THROW_ON_ERROR,
		);
	}

}
