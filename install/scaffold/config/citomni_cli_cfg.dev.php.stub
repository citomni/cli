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

/*
 * ------------------------------------------------------------------
 * CLI CONFIGURATION (dev)
 * ------------------------------------------------------------------
 * The app's cfg for CLI mode in the dev environment.
 *
 * Rules:
 * - Set only the keys where CLI in dev deliberately differs from the
 *   layers before this file. Dev keys shared with HTTP belong in
 *   citomni_cfg.dev.php.
 * - Leave a key out to keep the earlier value. Never write an empty
 *   array as a placeholder; it replaces the earlier value.
 * - Comment why a key deviates when the value does not show it. What a
 *   key means is documented where the key is defined.
 *
 * Merge order and file roles: CONFIGURATION.md in this directory and the
 * citomni/cli README. The keys of citomni/cli and their baseline:
 * \CitOmni\Cli\Boot\Registry::CFG_CLI.
 * ------------------------------------------------------------------
 */
return [

	/*
	 * ------------------------------------------------------------------
	 * ERROR HANDLER
	 * ------------------------------------------------------------------
	 * The developer who runs a command sees its non-fatal PHP errors and
	 * the stack traces of an uncaught exception in the terminal.
	 */
	'error_handler' => [
		'render' => [
			'trigger' => \E_WARNING | \E_NOTICE | \E_CORE_WARNING | \E_COMPILE_WARNING
				| \E_USER_WARNING | \E_USER_NOTICE | \E_RECOVERABLE_ERROR
				| \E_DEPRECATED | \E_USER_DEPRECATED,
			'detail' => [
				'level' => 1,
			],
		],
	],

];
