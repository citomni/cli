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
 * Expose the shared Kernel AppInfo snapshot through the CLI.
 *
 * AppInfo owns application and runtime introspection. This Command owns only
 * CLI options and terminal presentation. Output is secret-masked by default;
 * --raw requests unredacted configuration, --env-configs requests fresh
 * environment configuration projections, and --json emits the AppInfo
 * snapshot directly for machine consumption.
 */
final class AppInfoCommand extends BaseCommand {


	/**
	 * Define the command options.
	 *
	 * @return array<string, mixed> Command signature.
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
	 * Emit the AppInfo snapshot in JSON or human-readable form.
	 *
	 * @return int Command exit code.
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
	 * Render the snapshot for terminal inspection.
	 *
	 * @param array<string, mixed> $snapshot AppInfo snapshot.
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
	 * Write a compact scalar section.
	 *
	 * @param array<string, mixed> $rows Label-value rows.
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
	 * Write a structured section as readable JSON.
	 *
	 * @param array<mixed> $data Structured snapshot data.
	 * @return void
	 */
	private function writeJsonSection(string $title, array $data): void {
		$this->stdout($title);
		$this->stdout($this->encodeJson($data));
		$this->stdout('');
	}


	/**
	 * Encode snapshot data consistently and fail on encoding errors.
	 *
	 * @param array<mixed> $data Snapshot data.
	 * @return string Encoded JSON.
	 * @throws \JsonException When the data cannot be encoded.
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
	 * Format a scalar snapshot value for human-readable output.
	 *
	 * @param mixed $value Snapshot value.
	 * @return string Readable scalar value.
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
