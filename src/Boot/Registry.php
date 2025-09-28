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

		'error_handler' => [
			'render' => [
				'force_error_reporting' => null,
				'trigger' => 0,
				'detail' => [
					'level' => 0,
					'trace' => [
						'max_frames'      => 120,
						'max_arg_strlen'  => 512,
						'max_array_items' => 20,
						'max_depth'       => 3,
						'ellipsis'        => '...',
					],
				],
			],			
			'log' => [
				'trigger'   => \E_ALL,
				'path'      => '', // Empty: hydrate() falls back to CITOMNI_APP_PATH . '/var/logs'
				'max_bytes' => 2_000_000,
				'max_files' => 10,
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
		// ...
	];

}