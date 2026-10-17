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
 * CacheWarmCommand: CLI adapter that writes the compiled caches of the app.
 *
 * Gives deploy scripts a way to build the caches of both modes before the app serves anything,
 * the HTTP cache included, without a web request. Building and writing the files of a mode
 * belongs to App::warmCache(); this command chooses the modes and the environment, creates the
 * HTTP App it needs and reports the result.
 *
 * Rules:
 * - Warm a mode only through App::warmCache() of an App in that mode.
 * - Catch no exception from App: A cache that cannot be built or written must fail the
 *   process, never be reported as warmed.
 * - With --json, write exactly one JSON document to stdout, and nothing else, before execute()
 *   returns SUCCESS or FAILURE.
 */
class CacheWarmCommand extends BaseCommand {

	/** Lines written to stderr after every warm of the HTTP cache. */
	private const OPCACHE_NOTE = [
		'Note: OPcache invalidation from the CLI does not reach the web server\'s OPcache (PHP-FPM, mod_php).',
		'With opcache.validate_timestamps=0, reload PHP-FPM or the web server so it loads the new HTTP cache files,',
		'or POST /_system/reset-cache and run cache:warm again.',
	];


	/**
	 * Declare the command interface.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 * @see \CitOmni\Kernel\Command\BaseCommand::signature()  The signature format.
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
	 * Warm the caches of the chosen modes and report the written files.
	 *
	 * Warms the CLI cache through the running App and the HTTP cache through a second App in
	 * Mode::HTTP from the same config directory.
	 *
	 * Behavior:
	 * - --mode=all, the default, warms CLI and then HTTP. Without citomni/http, HTTP is
	 *   reported as skipped.
	 * - --mode=http without citomni/http writes nothing and fails.
	 * - --env names the environment that cfg and the dispatch map are built for. Without it, the
	 *   target is CITOMNI_ENVIRONMENT, or prod when that is not defined.
	 * - --env with an empty value is a usage error and writes nothing, because it would build
	 *   the cache without any environment overlay.
	 * - Prints the written files per mode on stdout, under the target environment. With
	 *   --json, one JSON object instead:
	 *     - ok true, env and modes, with the result of App::warmCache() per warmed mode, or
	 *       "skipped" for HTTP.
	 *     - ok false, error and an empty modes object when --mode=http finds no citomni/http.
	 *       The error also goes to stderr.
	 * - Writes OPCACHE_NOTE to stderr whenever the HTTP cache was warmed.
	 * - Exit code 0, 1 when --mode=http finds no citomni/http, or 2 for an empty --env.
	 *
	 * Notes:
	 * - An exception from App ends the process with no JSON document on stdout.
	 * - With --mode=all, a CLI cache warmed before the HTTP cache fails stays written.
	 * - Warming HTTP evaluates the app's HTTP config files in this CLI process: For the target
	 *   environment, and first for CITOMNI_ENVIRONMENT when no HTTP cache exists. A file that
	 *   reads CITOMNI_PUBLIC_ROOT_URL fails unless the CLI entry point defines it.
	 * - Cfg values derived from CITOMNI_APP_PATH are written into the cache as literal paths.
	 *   Warm the cache on the host, and at the path, where the app runs.
	 *
	 * @return int  Process exit code.
	 * @throws \RuntimeException  When an App cannot be built from the config directory, the caches for the target environment cannot be built, or a cache file cannot be written.
	 * @see \CitOmni\Kernel\App::warmCache()  Which files a mode has, and how they are written.
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

		// Assumes the fallback of App::buildConfig() for a null env. Only reported, so the
		// output names the target; warmCache() still gets $env.
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
	 * Encode a result as pretty-printed JSON, with slashes and Unicode unescaped.
	 *
	 * @param  array<string, mixed>  $data  Result data.
	 * @return string
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
