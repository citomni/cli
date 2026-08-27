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

namespace CitOmni\Cli\Boot;

final class Registry {
	
	/**
	 * Vendor baseline service map for CLI mode.
	 *
	 * Behavior:
	 * - This is tier 1 in the deterministic CLI service map merge:
	 *   1) Vendor baseline: \CitOmni\Cli\Boot\Registry::MAP_CLI
	 *   2) Providers: /config/providers.php (their MAP_CLI blocks)
	 *   3) App map: /config/services.php
	 * - Service merge semantics use PHP array union (`+`):
	 *   left wins, later layers only fill keys not already defined.
	 *
	 * Notes:
	 * - Service IDs are resolved via $this->app->{id}.
	 * - Services are instantiated lazily and kept as singletons per process.
	 * - Definitions must be either:
	 *   - 'id' => FQCN
	 *   - 'id' => ['class' => FQCN, 'options' => [...]]
	 * - /config/services.php has highest precedence and can override both providers
	 *   and vendor baseline.
	 */
	public const MAP_CLI = [
		'runner' => \CitOmni\Cli\Service\Runner::class,
		'errorHandler' => \CitOmni\Cli\Service\ErrorHandler::class,
	];






	/**
	 * Vendor baseline configuration for CLI mode.
	 *
	 * Behavior:
	 * - This is tier 1 in the deterministic CLI config merge:
	 *   1) Vendor baseline: \CitOmni\Cli\Boot\Registry::CFG_CLI
	 *   2) Providers: /config/providers.php (their CFG_CLI blocks)
	 *   3) App base: /config/citomni_cli_cfg.php
	 *   4) Env overlay: /config/citomni_cli_cfg.{ENV}.php
	 * - Config merge semantics are deep associative merge with last wins.
	 *
	 * Notes:
	 * - The baseline must contain the stable top-level CLI config tree expected by
	 *   CitOmni\Cli services and commands.
	 * - CITOMNI_APP_PATH must be defined before boot.
	 * - Providers may extend or override this baseline, and the application layer
	 *   remains the final authority.
	 */
	public const CFG_CLI = [

		/*
		 *------------------------------------------------------------------
		 * LOCALE
		 *------------------------------------------------------------------
		 */
		'locale' => [
			'language'		=> 'en',
			'icu_locale'	=> 'en_US',
			'timezone'		=> 'UTC',
			'charset'		=> 'UTF-8',
		],		
		
		
		/*
		 *------------------------------------------------------------------
		 * ERROR HANDLER (CLI)
		 *------------------------------------------------------------------
		 *
		 * Guarantees:
		 *   - Always logs (JSONL with size-based rotation).
		 *   - Always renders for:
		 *       * Uncaught exceptions,
		 *       * Shutdown fatals (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR).
		 *     These are NOT configurable (to prevent silent crashes).
		 *
		 * Rendering of non-fatal PHP errors (warnings/notices/etc.) is optional and generally
		 * only desirable in DEV. Therefore the baseline keeps it OFF; enable in your dev overlay.
		 *
		 * Output:
		 *   - Rendered to STDERR in a compact plain-text format with error_id.
		 *   - Optional DEV detail may include a truncated stack trace for faster diagnosis.
		 *
		 * Logging:
		 *   - Writes JSONL records including timestamp, error_id, type, argv, cwd, and pid.
		 *   - Log directory defaults to /var/logs when path is empty.
		 */		
		'error_handler' => [
			'render' => [
				'force_error_reporting' => null,	// Null: keep current PHP error_reporting unchanged. Set an int mask (for example E_ALL) to force a specific runtime level.
				'trigger' => 0,						// Which non-fatal PHP errors to render to STDERR. 0 disables rendering; typical dev choice: E_ALL. Fatal errors and uncaught exceptions are always rendered separately.
				'detail' => [
					'level' => 0,					// Extra render detail level. 0 = compact output only. >=1 = include detailed exception traces in dev. Production/stage should normally keep this at 0.
					'trace' => [
						'max_frames'      => 120,	// Max stack frames to include in detailed traces. Lower = less noise and smaller logs/output; higher = more context for deep call chains.
						'max_arg_strlen'  => 512,	// Max string length per argument when dumping trace args. Lower reduces noise and leakage risk; higher shows more payload context.
						'max_array_items' => 20,	// Max array items shown per dumped argument. Lower keeps traces readable; higher helps when debugging structured payloads.
						'max_depth'       => 3,		// Max nested depth when dumping array arguments. Lower avoids huge recursive dumps; higher reveals more nested data.
						'ellipsis'        => '...',	// Marker used when trace output is truncated. Any short string works, but keep it compact and visually obvious.
					],
				],
			],
			'log' => [
				'trigger'   => \E_ALL,				// Which non-fatal PHP errors to log. Typical choices: E_ALL, E_ALL & ~E_DEPRECATED, or a narrower mask. Fatal shutdowns and uncaught exceptions are logged separately.
				'path'      => '',					// Directory for JSONL log files. Empty: hydrate() falls back to CITOMNI_APP_PATH . '/var/logs'. Set an absolute app-local path if you want a custom location.
				'max_bytes' => 2_000_000,			// Rotate when a log file grows beyond this size in bytes. Lower = more frequent rotation and smaller files; higher = fewer, larger files.
				'max_files' => 10,					// Number of rotated log files to keep per log stream. 0 or less disables pruning; higher retains more history at the cost of disk usage.
			],
		],



	];
	
	
	
	
	

	/**
	 * Vendor baseline route map for CLI mode.
	 *
	 * Behavior:
	 * - This is tier 1 in the deterministic CLI route merge:
	 *   1) Vendor baseline: \CitOmni\Cli\Boot\Registry::COMMANDS_CLI
	 *   2) Providers: /config/providers.php (their COMMANDS_CLI blocks)
	 *   3) App base: /config/citomni_cli_routes.php
	 *   4) Env overlay: /config/citomni_cli_routes.{ENV}.php
	 * - Route merge semantics are deep associative merge with last wins.
	 *
	 * Notes:
	 * - Routes live in the dedicated route map, not inside CFG_CLI.
	 * - The array shape must match the command routing contract used by CitOmni\Cli.
	 * - Providers may contribute additional routes, while the app layer may
	 *   override or replace vendor/provider routes by command key.
	 * - Empty arrays are ignored during merge.
	 */
	public const COMMANDS_CLI = [
		'app:info' => [
			'command'     => \CitOmni\Cli\Command\AppInfoCommand::class,
			'description' => 'Show application and runtime information.',
		],
	];

}
