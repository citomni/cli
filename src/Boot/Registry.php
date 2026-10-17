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

/**
 * Registry: Boot contributions of citomni/cli, the CLI mode package.
 *
 * Declares the baseline that every CLI app starts from as constant arrays: services, cfg and
 * commands. Merging them with the providers' and the app's own maps, and deciding what wins,
 * belongs to the kernel.
 *
 * Rules:
 * - Constants only: No IO, no runtime branching and no environment reads.
 * - Ship a baseline for every cfg path the package reads, with a comment on what each key
 *   controls and its unit.
 * - Ship defaults that are safe in every environment. Output meant for developers stays off
 *   until the app turns it on.
 */
class Registry {

	/** Services for CLI mode. */
	public const array MAP_CLI = [
		'runner' => \CitOmni\Cli\Service\Runner::class,
		'errorHandler' => \CitOmni\Cli\Service\ErrorHandler::class,
	];






	/** Cfg baseline for CLI mode. */
	public const array CFG_CLI = [

		'error_handler' => [
			'render' => [
				'force_error_reporting' => null,	// PHP error_reporting level set when the error handler is installed, as a bitmask such as E_ALL. null keeps PHP's level.
				'trigger' => 0,						// Non-fatal PHP error levels written to stderr, as a bitmask; 0 writes none. Uncaught exceptions and fatal errors are always written.
				'detail' => [
					'level' => 0,					// 1 or more adds stack traces to the stderr report of an uncaught exception when CITOMNI_ENVIRONMENT is dev; 0 keeps the report compact.
					'trace' => [
						'max_frames'      => 120,	// Maximum number of frames after the throw site in a stack trace, in log records and on stderr.
						'max_arg_strlen'  => 512,	// Maximum length in bytes of each string in a trace frame's arguments, nested ones included.
						'max_array_items' => 20,	// Maximum number of arguments in a trace frame, and of items in an array argument.
						'max_depth'       => 3,		// Maximum nesting depth of an array argument in a trace frame.
						'ellipsis'        => '...',	// Marker for what a trace frame leaves out of its arguments, and for the cut end of the command line in a log record.
					],
				],
			],
			'log' => [
				'trigger'   => \E_ALL,				// Non-fatal PHP error levels written to the log, as a bitmask. Uncaught exceptions and fatal errors are always logged.
				'path'      => '',					// Directory of the JSONL log files. Empty means CITOMNI_APP_PATH . '/var/logs'.
				'max_bytes' => 2_000_000,			// Size in bytes past which a log file is rotated before the next record is appended.
				'max_files' => 10,					// Number of rotated files kept per log; 0 or less keeps them all.
			],
		],



	];
	
	
	
	
	

	/** CLI commands. */
	public const array COMMANDS_CLI = [
		'app:info' => [
			'command'     => \CitOmni\Cli\Command\AppInfoCommand::class,
			'description' => 'Show application and runtime information.',
		],
		'cache:warm' => [
			'command'     => \CitOmni\Cli\Command\CacheWarmCommand::class,
			'description' => 'Build and write the compiled config, dispatch, and service caches.',
		],
		'cache:clear' => [
			'command'     => \CitOmni\Cli\Command\CacheClearCommand::class,
			'description' => 'Remove the compiled config, dispatch, and service caches.',
		],
	];

}
