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
use CitOmni\Kernel\Command\HelpFormatter;
use CitOmni\Kernel\Mode;

/**
 * Build and write the compiled config, dispatch, and service caches.
 *
 * CLI adapter over App::warmCache() for deploy scripts. The CLI cache is
 * warmed through the running CLI App. The HTTP cache is warmed through a
 * second App constructed in Mode::HTTP from the same config directory, so it
 * can be built without a web request.
 *
 * Behavior:
 * - --mode=cli writes cfg.cli.php, commands.cli.php, and services.cli.php.
 * - --mode=http writes cfg.http.php, routes.http.php, and services.http.php.
 *   It requires citomni/http (\CitOmni\Http\Boot\Registry); without it the
 *   command returns FAILURE.
 * - --mode=all (default) warms CLI first, then HTTP when citomni/http is
 *   installed. Otherwise HTTP is reported as skipped.
 * - --env is passed to App::warmCache(env: ...). Without it the target is
 *   CITOMNI_ENVIRONMENT (default "prod"). An empty value is a usage error,
 *   because it would build the cache without any env overlay.
 * - Prints the written files per mode on stdout. With --json it prints one
 *   object instead: {ok, env, modes: {cli: {...}, http: {...} | "skipped"}}.
 * - Writes a fixed OPcache note to stderr whenever the HTTP cache is warmed.
 *
 * Notes:
 * - Exceptions from App construction and warmCache() are not caught; the CLI
 *   error handler renders them and exits 1. With --mode=all, a CLI cache
 *   warmed before an HTTP failure stays written.
 * - Building the HTTP App evaluates the app's HTTP config files in this CLI
 *   process. A file that references \CITOMNI_PUBLIC_ROOT_URL fails fast in
 *   dev, where bin/citomni does not define it. Without an HTTP cache, the
 *   constructor first builds config for CITOMNI_ENVIRONMENT, so this applies
 *   to that overlay even when --env names another environment.
 * - Cfg values derived from CITOMNI_APP_PATH are written into the cache as
 *   literal paths. Warm the cache on the host and at the path the app runs.
 *
 * Typical usage:
 *   php bin/citomni cache:warm
 *   php bin/citomni cache:warm --env=prod --json
 */
final class CacheWarmCommand extends BaseCommand {

	/** Written to stderr whenever the HTTP cache was warmed. */
	private const OPCACHE_NOTE = [
		'Note: OPcache invalidation from the CLI does not reach the web server\'s OPcache (PHP-FPM, mod_php).',
		'With opcache.validate_timestamps=0, reload PHP-FPM or the web server so it loads the new HTTP cache files,',
		'or POST /_system/reset-cache and run cache:warm again.',
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
					'description' => 'Which caches to warm',
					'default'     => 'all',
					'allowed'     => ['cli', 'http', 'all'],
				],
				'env' => [
					'type'        => 'string',
					'description' => 'Environment to build cfg and dispatch for (default: CITOMNI_ENVIRONMENT)',
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
	 * Warm the selected caches and report the written files.
	 *
	 * @return int Command exit code.
	 */
	protected function execute(): int {
		$mode = $this->getString('mode');
		$env  = $this->opt('env');
		$json = $this->getBool('json');

		// -- 1. Validate input ------------------------------------------------
		if ($env === '') {
			$this->error('Option --env requires a non-empty value');
			$this->stderr('');
			$this->stderr(HelpFormatter::usage($this->commandName, $this->signature()));
			return self::USAGE;
		}

		$httpInstalled = \class_exists(\CitOmni\Http\Boot\Registry::class);

		if ($mode === 'http' && !$httpInstalled) {
			$message = '--mode=http requires citomni/http, which is not installed.';
			if ($json) {
				$this->stdout($this->encodeJson(['ok' => false, 'error' => $message, 'modes' => (object)[]]));
			}
			$this->error($message);
			return self::FAILURE;
		}

		// Same fallback as App::buildConfig(); reported so the log shows the target.
		$targetEnv = $env ?? (\defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod');
		$modes     = [];

		// -- 2. CLI cache through the running App ------------------------------
		if ($mode !== 'http') {
			$modes['cli'] = $this->app->warmCache(env: $env);
			if (!$json) {
				$this->renderWarmed('CLI', $modes['cli'], $targetEnv);
			}
		}

		// -- 3. HTTP cache through a second App in Mode::HTTP ------------------
		if ($mode !== 'cli') {
			if ($httpInstalled) {
				$httpApp = new App($this->app->getConfigDir(), Mode::HTTP);
				$modes['http'] = $httpApp->warmCache(env: $env);
				if (!$json) {
					$this->renderWarmed('HTTP', $modes['http'], $targetEnv);
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
			$this->stdout($this->encodeJson(['ok' => true, 'env' => $targetEnv, 'modes' => (object)$modes]));
		}

		if (\is_array($modes['http'] ?? null)) {
			foreach (self::OPCACHE_NOTE as $line) {
				$this->warning($line);
			}
		}

		return self::SUCCESS;
	}


	/**
	 * Print the files written for one mode.
	 *
	 * @param  string                                                     $label  Mode label ('CLI' or 'HTTP').
	 * @param  array{cfg: ?string, dispatch: ?string, services: ?string}  $files  Result of App::warmCache().
	 * @param  string                                                     $env    Target environment.
	 * @return void
	 */
	private function renderWarmed(string $label, array $files, string $env): void {
		$this->stdout("{$label} cache warmed (env: {$env}):");
		foreach ($files as $key => $path) {
			$this->stdout('  ' . \str_pad($key, 10) . ($path ?? '(not written)'));
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
