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

use CitOmni\Kernel\Command\BaseCommand;
use CitOmni\Kernel\Support\AppInfo;

/**
 * AppInfoCommand: CLI adapter that prints the AppInfo snapshot of the app.
 *
 * Lets operators and scripts inspect the app's configuration, dispatch maps, packages and PHP
 * runtime from the terminal. Collecting the snapshot and masking its secrets belong to AppInfo;
 * this command turns the options into AppInfo's arguments and presents the result.
 *
 * Rules:
 * - Print only what AppInfo::snapshot() returns; collect nothing itself.
 * - With --json, write the snapshot as one JSON document to stdout, and nothing else.
 */
class AppInfoCommand extends BaseCommand {


	/**
	 * Declare the command interface.
	 *
	 * @return array{arguments?: array<string,array<string,mixed>>, options?: array<string,array<string,mixed>>}
	 * @see \CitOmni\Kernel\Command\BaseCommand::signature()  The signature format.
	 */
	protected function signature(): array {
		return [
			'options' => [
				'json' => [
					'short'       => 'j',
					'type'        => 'bool',
					'description' => 'Emit the complete AppInfo snapshot as JSON',
					'default'     => false,
				],
				'raw' => [
					'type'        => 'bool',
					'description' => 'Request unredacted configuration values',
					'default'     => false,
				],
				'env-configs' => [
					'type'        => 'bool',
					'description' => 'Include fresh dev, stage, and prod configuration projections',
					'default'     => false,
				],
			],
		];
	}


	/**
	 * Print the AppInfo snapshot as JSON or as sections for a person.
	 *
	 * Behavior:
	 * - Cfg values that AppInfo treats as secret are masked, unless --raw is given.
	 * - --env-configs adds the cfg that dev, stage and prod would get, built now in this process.
	 * - With --json: The snapshot as one pretty-printed JSON document on stdout.
	 * - Otherwise: The scalar sections as aligned label and value lines, the structured sections
	 *   as pretty-printed JSON, each under its title.
	 * - Exit code 0.
	 *
	 * @return int  Process exit code.
	 * @throws \RuntimeException  With --env-configs, when the cfg of an environment cannot be built.
	 * @see \CitOmni\Kernel\Support\AppInfo::snapshot()  The snapshot, its masking and the environment cfg.
	 */
	protected function execute(): int {
		$json = $this->getBool('json');
		$raw = $this->getBool('raw');
		$includeEnvironmentConfigs = $this->getBool('env-configs');

		$appInfo = new AppInfo($this->app);
		$snapshot = $appInfo->snapshot(
			unredacted: $raw,
			includeEnvironmentConfigs: $includeEnvironmentConfigs,
		);

		if ($json) {
			$this->stdout($this->encodeJson($snapshot));
			return self::SUCCESS;
		}

		$this->renderHuman($snapshot);

		return self::SUCCESS;
	}


	/**
	 * Print the snapshot as titled sections.
	 *
	 * @param  array<string,mixed>  $snapshot  The result of AppInfo::snapshot().
	 * @return void
	 */
	private function renderHuman(array $snapshot): void {
		$this->writeScalarSection('CitOmni / Application', [
			'Environment' => $snapshot['citomni']['environment'],
			'App name' => $snapshot['app']['name'],
		]);

		$this->writeScalarSection('Runtime', [
			'PHP version'          => $snapshot['runtime']['php_version'],
			'Hostname'             => $snapshot['runtime']['hostname'],
			'Local datetime'       => $snapshot['runtime']['datetime_local'],
			'UTC datetime'         => $snapshot['runtime']['datetime_utc'],
			'Effective timezone'   => $snapshot['runtime']['timezone'],
			'Effective charset'    => $snapshot['runtime']['default_charset'],
			'Effective ICU locale' => $snapshot['runtime']['icu_locale'],
		]);

		$this->writeScalarSection('Metrics', [
			'Runtime duration (s)' => $snapshot['metrics']['time_s'],
			'Current memory (KB)'  => $snapshot['metrics']['memory_usage_current_kb'],
			'Peak memory (KB)'     => $snapshot['metrics']['memory_usage_peak_kb'],
			'Included files'       => $snapshot['metrics']['included_files_count'],
			'Routes'               => $snapshot['metrics']['routes_count'],
			'Commands'             => $snapshot['metrics']['commands_count'],
		]);

		$this->writeScalarSection('OPcache', [
			'Enabled'              => $snapshot['opcache']['enabled'],
			'Timestamp validation' => $snapshot['opcache']['validate_timestamps'],
		]);

		$this->writeJsonSection('Packages', $snapshot['packages']);
		$this->writeJsonSection('Active configuration', $snapshot['cfg']);
		$this->writeJsonSection('Routes', $snapshot['routes']);
		$this->writeJsonSection('Commands', $snapshot['commands']);

		if (\array_key_exists('cfg_by_env', $snapshot)) {
			$this->writeJsonSection('Environment config projections', $snapshot['cfg_by_env']);
		}
	}


	/**
	 * Print a section of scalar values, one aligned label and value per line.
	 *
	 * @param  string               $title  Section title.
	 * @param  array<string,mixed>  $rows   Values by label.
	 * @return void
	 */
	private function writeScalarSection(string $title, array $rows): void {
		$this->stdout($title);

		$width = 0;
		foreach ($rows as $label => $_value) {
			$width = \max($width, \strlen($label));
		}

		foreach ($rows as $label => $value) {
			$this->stdout('  ' . \str_pad($label, $width + 2) . $this->formatScalar($value));
		}

		$this->stdout('');
	}


	/**
	 * Print a section of structured data as pretty-printed JSON.
	 *
	 * @param  string        $title  Section title.
	 * @param  array<mixed>  $data   Structured snapshot data.
	 * @return void
	 */
	private function writeJsonSection(string $title, array $data): void {
		$this->stdout($title);
		$this->stdout($this->encodeJson($data));
		$this->stdout('');
	}


	/**
	 * Encode snapshot data as pretty-printed JSON, with slashes and Unicode unescaped.
	 *
	 * @param  array<mixed>  $data  Snapshot data.
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


	/**
	 * Format a scalar snapshot value for a person: null and booleans as words, the rest as strings.
	 *
	 * @param  mixed  $value  Scalar snapshot value.
	 * @return string
	 */
	private function formatScalar(mixed $value): string {
		if ($value === null) {
			return 'null';
		}

		if (\is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		return (string)$value;
	}


}
