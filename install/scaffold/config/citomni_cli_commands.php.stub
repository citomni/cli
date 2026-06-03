<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: MIT
 * Copyright (C) 2012-present Lars Grove Mortensen
 *
 * CitOmni CLI Skeleton - Minimal starter for high-performance CitOmni CLI apps.
 * Source:  https://github.com/citomni/cli-skeleton
 * License: See the LICENSE file for full terms.
 */

/*
 * ------------------------------------------------------------------
 * COMMAND TABLE (BASE LAYER) (CLI)
 * ------------------------------------------------------------------
 * This file defines CLI-facing commands for your CitOmni app.
 *
 * RESPONSIBILITY:
 *   - citomni_cli_cfg.php      is for CLI config.
 *   - citomni_cli_commands.php is for commands.
 *
 *   The array returned by this file is merged into $this->app->commands at boot.
 *   The Runner uses $this->app->commands to dispatch CLI commands.
 *
 * MERGE MODEL (deterministic "last wins" per command key):
 *   1) Vendor baseline:
 *        \CitOmni\Cli\Boot\Registry::COMMANDS_CLI
 *        (if defined)
 *
 *   2) Provider commands:
 *        Each provider in /config/providers.php may define
 *        public const COMMANDS_CLI = [ ... ];
 *        Providers are processed in the order they appear
 *        in providers.php.
 *
 *   3) /config/citomni_cli_commands.php
 *        (this file)
 *
 *   4) /config/citomni_cli_commands.{ENV}.php
 *        merged last, where {ENV} = CITOMNI_ENVIRONMENT
 *        ('dev' | 'stage' | 'prod')
 *
 * IMPORTANT:
 *   - "Last wins per key" means: if multiple layers define the same
 *     command name (e.g. 'cache:warm'), the later layer overrides the
 *     earlier one for that key only.
 *
 *   - EMPTY ARRAYS ARE SKIPPED:
 *     If this file returns [], the app does NOT treat that as
 *     "wipe all commands". It simply means "no overrides from app base".
 *     Same for providers with COMMANDS_CLI = [] and for the {ENV} file.
 *
 * RUNTIME ACCESS:
 *   - After merge, the final command table lives on the App instance:
 *
 *         $this->app->commands['cache:warm']
 *         $this->app->commands['auth:create-identity']
 *
 * COMMAND NAME CONVENTION:
 *   - Use lowercase identifiers with colon-separated namespaces.
 *   - Examples:
 *       'cache:warm'
 *       'auth:create-identity'
 *       'auth:prune-expired-tokens'
 *
 * COMMAND DEFINITION SHAPE:
 *   'cache:warm' => [
 *       'command'     => \App\Cli\Command\CacheWarmCommand::class,
 *       'description' => 'Build and write config, dispatch, and service caches.',
 *       'options'     => [], // optional, passed to the command constructor
 *   ],
 *
 * CONTRACT:
 *   - 'command' is required and must be a FQCN string.
 *   - The class must extend \CitOmni\Kernel\Command\BaseCommand.
 *   - 'description' is optional but recommended for Runner::listCommands().
 *   - 'options' is optional and must be an array when present.
 *
 * DEV-ONLY COMMANDS:
 *   - Put debug or maintenance-only commands into
 *     /config/citomni_cli_commands.dev.php
 *     (or .stage.php, etc.). That file is merged LAST, but only if it:
 *       a) exists, and
 *       b) returns a non-empty array.
 *
 *     Typical examples:
 *       'app:info'             (dump cfg/services/commands in dev)
 *       'cache:clear-dev'      (nuke cache during local development)
 *       'debug:test-mail'      (local-only smoke test command)
 *
 *     This avoids shipping development-only command overrides to stage/prod
 *     by accident. Terminal mistakes are still mistakes.
 */
return [

	// -----------------------------------------------------------------
	// Base app commands
	// -----------------------------------------------------------------

	/*
	'cache:warm' => [
		'command'     => \App\Cli\Command\CacheWarmCommand::class,
		'description' => 'Build and write config, dispatch, and service caches.',
	],
	*/

	/*
	'cache:clear' => [
		'command'     => \App\Cli\Command\CacheClearCommand::class,
		'description' => 'Delete generated cache files.',
	],
	*/

	/*
	'auth:create-identity' => [
		'command'     => \App\Cli\Command\Auth\CreateIdentityCommand::class,
		'description' => 'Create a new authentication identity.',
	],
	*/

	/*
	'auth:set-password' => [
		'command'     => \App\Cli\Command\Auth\SetPasswordCommand::class,
		'description' => 'Set or replace the password for an identity.',
	],
	*/

	/*
	'auth:prune-expired-tokens' => [
		'command'     => \App\Cli\Command\Auth\PruneExpiredTokensCommand::class,
		'description' => 'Delete expired authentication tokens.',
	],
	*/

];
