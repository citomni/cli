<?php
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

return [
	'package' => 'citomni/cli',
	'version' => 1,
	'files' => [
		[
			'target' => 'bin/citomni',
			'source' => 'install/scaffold/bin/citomni.stub',
			'type' => 'entrypoint',
			'policy' => 'managed',
		],
		[
			'target' => 'config/citomni_cli_cfg.php',
			'source' => 'install/scaffold/config/citomni_cli_cfg.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cli_cfg.dev.php',
			'source' => 'install/scaffold/config/citomni_cli_cfg.dev.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cli_cfg.stage.php',
			'source' => 'install/scaffold/config/citomni_cli_cfg.stage.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cli_cfg.prod.php',
			'source' => 'install/scaffold/config/citomni_cli_cfg.prod.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/services_cli.php',
			'source' => 'install/scaffold/config/services_cli.php.stub',
			'type' => 'service-map',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cli_commands.php',
			'source' => 'install/scaffold/config/citomni_cli_commands.php.stub',
			'type' => 'commands',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cli_commands.dev.php',
			'source' => 'install/scaffold/config/citomni_cli_commands.dev.php.stub',
			'type' => 'commands',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cli_commands.stage.php',
			'source' => 'install/scaffold/config/citomni_cli_commands.stage.php.stub',
			'type' => 'commands',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cli_commands.prod.php',
			'source' => 'install/scaffold/config/citomni_cli_commands.prod.php.stub',
			'type' => 'commands',
			'policy' => 'create-only',
		],

		[
			'target' => 'src/Cli/Command/HelloCommand.php',
			'source' => 'install/scaffold/src/Cli/Command/HelloCommand.php.stub',
			'type' => 'starter-code',
			'policy' => 'create-only',
		],
		[
			'target' => 'src/Cli/Exception/.gitkeep',
			'source' => 'install/scaffold/src/Cli/Exception/.gitkeep',
			'type' => 'directory-placeholder',
			'policy' => 'create-only',
		],
	],
];
