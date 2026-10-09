# CitOmni CLI

Deterministic command-line boot, command dispatch, diagnostics, and cache management for CitOmni applications.

`citomni/cli` gives an application one command-line entry point, `bin/citomni`. It boots the shared application core in CLI mode, installs CLI error handling, applies runtime settings, and dispatches a named command from an explicit command map.

Commands use the same App, configuration, services, Operations, and Repositories as the rest of the application. CLI delivery adds argument parsing, terminal output, and exit codes around that shared application logic.

---

## Highlights

- **Explicit boot** through `CitOmni\Cli\Kernel`.
- **One command map** exposed as `$app->commands`, separate from configuration and HTTP routes.
- **Provider and application composition** with deterministic override order.
- **Typed arguments and options**, generated help, and output helpers through Kernel's `BaseCommand`.
- **Grouped command listing** without constructing every command.
- **Built-in application diagnostics** through `app:info`, including JSON output.
- **CLI and HTTP cache management** through `cache:warm` and `cache:clear`.
- **Independent CLI error handling** with stderr diagnostics, JSONL logs, and size-based rotation.
- **Low runtime overhead** through compiled maps, lazy services, and direct command lookup.

---

## What this package is

`citomni/cli` is CitOmni's CLI delivery layer. It supports terminal commands, deployment scripts, scheduled jobs, imports, maintenance tasks, and other application work started through PHP CLI.

A command is selected by its registered name. There is no directory scanning, attribute discovery, or dependency on HTTP routing.

## What this package owns

- CLI kernel boot and process exit.
- The `runner` and CLI `errorHandler` services.
- The CLI baseline configuration, service map, and command registrations.
- Dispatch from the process argument list to a command class.
- Listing registered commands.
- The `app:info`, `cache:warm`, and `cache:clear` command adapters.
- Installation metadata and application scaffold files for CLI integration.

## What this package does not own

- **Shared application infrastructure.** `App`, `Cfg`, runtime configuration, composition, and compiled-cache mechanics belong to `citomni/kernel`.
- **The command base and input grammar.** `BaseCommand`, `ArgvParser`, and `HelpFormatter` belong to `citomni/kernel`.
- **Business workflows and persistence.** Application Operations own orchestration; Repositories own SQL and datastore IO.
- **Scheduling and process supervision.** Cron, Windows Task Scheduler, or another external runner decides when to start a command and whether to restart it.
- **HTTP delivery.** Requests, responses, sessions, and HTTP routing belong to `citomni/http` and the application's HTTP adapters.

---

## Relationship to other CitOmni packages

| Package | Relationship |
|---|---|
| `citomni/kernel` | Required application core, command infrastructure, and cache implementation. |
| `citomni/http` | Optional sibling delivery layer. Installing it enables the HTTP mode of the cache commands. |
| `citomni/infrastructure` | Optional application services such as database access, logging, mail, and translation. Register the provider when those services are needed. |
| Application and provider packages | Contribute configuration, services, and commands through explicit files and Registry constants. |

The CLI error handler writes its own logs. It does not depend on the infrastructure `log` service.

---

## Requirements

- PHP **8.5+** (`^8.5`).
- `citomni/kernel` **^1.0.2.5**.
- Composer autoloading.
- A PHP CLI executable for running commands.
- Filesystem permissions for any logs, caches, or application output the command writes.

The CLI kernel does not require `ext-intl`. It applies the ICU locale when that extension is available; individual services may have additional requirements.

OPcache is optional. CLI and web-server OPcache are separate operational concerns; see [Cache operations](#cache-operations).

---

## Installation

Install the package in the application through Composer.

```bash
composer require citomni/cli
```

Keep framework classes in Composer dependencies. The files under `install/scaffold/` are application entry-point, configuration, and starter-code templates.

`App` automatically uses `CitOmni\Cli\Boot\Registry` as its CLI baseline. You do **not** need to add this Registry to `config/providers.php` to obtain the built-in CLI services and commands. Use `providers.php` for additional providers required by your application.

Expose application classes through PSR-4 autoloading. For an application using the `App\` namespace, merge this into its `composer.json`.

```json
{
	"autoload": {
		"psr-4": {
			"App\\": "src/"
		}
	}
}
```

Refresh the autoloader after changing the application's autoload configuration.

```bash
composer dump-autoload -o
```

The package includes `install/manifest.php` for scaffold tooling. Composer installation alone does not create the application's `bin/citomni` or `/config` files. `citomni/installer` materializes `bin/citomni` per environment: The selected stub defines `CITOMNI_ENVIRONMENT`, and on stage and prod also `CITOMNI_PUBLIC_ROOT_URL` from the `STAGE_ROOT_URL` or `PROD_ROOT_URL` placeholder. Switch with `citomni-installer environment <dev|stage|prod>`. Existing applications can use their scaffold tooling or add the minimal files below.

---

## Quick start

### Create the entry point

Create the application's `bin/` and `config/` directories, then save this as `bin/citomni`.

```php
<?php
declare(strict_types=1);

define('CITOMNI_START_NS', hrtime(true));
define('CITOMNI_ENVIRONMENT', 'dev');
define('CITOMNI_APP_PATH', dirname(__DIR__));

require CITOMNI_APP_PATH . '/vendor/autoload.php';

\CitOmni\Cli\Kernel::run(
	CITOMNI_APP_PATH . '/config',
	$_SERVER['argv'] ?? [],
);
```

The first argument is the application's configuration directory. The second is the full PHP argument list, including the script name and command name.

Set `CITOMNI_ENVIRONMENT` deliberately for each deployment. The usual values are `dev`, `stage`, and `prod`.

### Set runtime configuration

Save this as `config/citomni_cli_cfg.php`.

```php
<?php
declare(strict_types=1);

return [
	'locale' => [
		'timezone' => 'Europe/Copenhagen',
		'charset' => 'UTF-8',
		'icu_locale' => 'da_DK',
	],
];
```

Without these overrides, `Runtime` uses `UTC`, `UTF-8`, and `en_US`. Configuration files may contain only the values the application needs to override.

### Run the built-in commands

From the application root, run the following commands.

```bash
php bin/citomni
php bin/citomni list
php bin/citomni app:info
php bin/citomni app:info --help
```

An empty `config/` directory is sufficient for the built-in CLI baseline; the locale file above makes this example's runtime settings explicit. Existing common configuration and providers still participate in boot.

The bare invocation and `list` both print the command list and return exit code `0`. An unknown command writes a diagnostic to stderr and returns `2`.

These examples use `php bin/citomni`, which also works on Windows. Direct execution as `./bin/citomni` additionally requires an appropriate shebang and executable permissions.

---

## Boot and runtime API

### Boot and exit

`Kernel::run()` is the normal entry point.

```php
\CitOmni\Cli\Kernel::run(CITOMNI_APP_PATH . '/config', $_SERVER['argv'] ?? []);
```

It performs these steps in order.

1. Constructs `App` with `Mode::CLI`.
2. Loads configuration, commands, and services from compiled caches or their source layers.
3. Installs the `errorHandler` service when registered.
4. Applies timezone, charset, and available ICU locale settings through `Runtime::configure()`. Invalid values throw a `RuntimeException`, which the installed handler logs and renders before exiting with code `1`.
5. Calls `$app->runner->run($argv)`.
6. Terminates the process with the returned exit code.

The method returns `never`. CLI boot does not start HTTP output buffering, resolve a public URL, configure trusted proxies, or apply the HTTP maintenance guard.

### Boot without dispatch

For scripts that need explicit control after boot, define the same constants and load Composer as in the entry-point example, then use `boot()`.

```php
$app = \CitOmni\Cli\Kernel::boot(CITOMNI_APP_PATH . '/config');

$commands = $app->commands;
$exitCode = $app->runner->run(['bin/citomni', 'app:info', '--json']);
```

`boot()` returns the configured `CitOmni\Kernel\App`. It still changes process-wide runtime settings and installs global error handlers.

`Runner::run()` returns an integer without calling `exit()`. Uncaught exceptions remain subject to the installed global handler, which terminates the process.

### Application state

| Access | Meaning in CLI mode |
|---|---|
| `$app->cfg` | Read-only composed configuration. |
| `$app->commands` | Read-only array containing the composed command map. |
| `$app->routes` | Empty array. HTTP routes are not the CLI dispatch map. |
| `$app->runner` | Lazily resolved command dispatcher. |
| `$app->errorHandler` | CLI error handler, resolved and installed during normal CLI boot. |

Commands are not stored in `$app->cfg` and are not service-map entries.

---

## Writing a command

Application commands normally live under `src/Cli/Command/` and extend `CitOmni\Kernel\Command\BaseCommand`.

Create `src/Cli/Command/GreetCommand.php`.

```php
<?php
declare(strict_types=1);

namespace App\Cli\Command;

use CitOmni\Kernel\Command\BaseCommand;

class GreetCommand extends BaseCommand {
	/**
	 * Declare the accepted command input.
	 *
	 * @return array<string, mixed> Command signature.
	 */
	protected function signature(): array {
		return [
			'arguments' => [
				'name' => [
					'description' => 'Name to greet',
					'default' => 'World',
				],
			],
			'options' => [
				'repeat' => [
					'short' => 'r',
					'type' => 'int',
					'default' => 1,
					'description' => 'Number of greetings',
				],
			],
		];
	}

	/**
	 * Print the greeting after input parsing succeeds.
	 *
	 * @return int Command exit code.
	 */
	protected function execute(): int {
		$name = $this->argString('name');
		$repeat = $this->getInt('repeat');

		if ($repeat < 1) {
			$this->error('--repeat must be at least 1.');
			return self::USAGE;
		}

		for ($i = 0; $i < $repeat; $i++) {
			$this->stdout("Hello, {$name}!");
		}

		return self::SUCCESS;
	}
}
```

Register it in `config/citomni_cli_commands.php`.

```php
<?php
declare(strict_types=1);

return [
	'app:greet' => [
		'command' => \App\Cli\Command\GreetCommand::class,
		'description' => 'Print a greeting.',
	],
];
```

Then run it.

```bash
php bin/citomni app:greet
php bin/citomni app:greet Lars --repeat=3
php bin/citomni app:greet Lars -r 2
php bin/citomni app:greet --help
```

If the application has a compiled command cache, clear or rebuild it before running a newly registered command.

The package's scaffold also includes a `HelloCommand` example. A class file alone does not register a command; add its entry to the application's command map if you use it.

### Command responsibilities

`signature()` declares input. `execute()` runs after parsing and validation succeed. The inherited `run()` method is final; command classes implement `execute()` rather than replacing the input pipeline.

Commands own CLI input, output, and exit-code decisions. Delegate non-trivial business workflows to explicitly instantiated Operations, reusable tools to services, and persistence to Repositories. A command may call a Repository directly for trivial CRUD. SQL belongs in the Repository.

---

## Command registration and dispatch

Each command-map entry uses this shape.

```php
return [
	'app:greet' => [
		'command' => \App\Cli\Command\GreetCommand::class,
		'description' => 'Print a greeting.',
		'options' => [],
	],
];
```

| Key | Contract |
|---|---|
| `command` | Required FQCN string. The class must exist and extend `BaseCommand`. |
| `description` | Optional human-readable string used by the list and help output. |
| `options` | Optional constructor configuration array, available as `$this->options` inside the command. |

**Registration `options` and CLI options are separate.** Registration `options` supply application-owned constructor configuration. The `options` declared by `signature()` describe user input such as `--repeat=3`, read through `getInt()`, `getString()`, `getBool()`, or `opt()`.

Runner reads the command name from `$argv[1]`, performs an exact lookup, validates the selected definition, and constructs the command with the App, name, description, and registration options. It passes the full argument list to `BaseCommand::run()`.

The name `list` is handled directly by Runner. Listing groups command names by the prefix before the first colon; names without a colon appear in `general`. Groups and names are sorted.

### Command composition order

Later layers override earlier values.

1. `CitOmni\Cli\Boot\Registry::COMMANDS_CLI`.
2. Each provider's `COMMANDS_CLI`, in `config/providers.php` order.
3. `config/citomni_cli_commands.php`.
4. `config/citomni_cli_commands.{ENV}.php`.

Command definitions are merged recursively by associative key. For example, a later layer that changes only `description` retains an earlier `command` and its other fields. This differs from service-map replacement.

A whole command-map layer returning `[]` contributes nothing; it does not clear inherited commands. Missing application command files are allowed.

Use environment command files for commands or overrides specific to that environment. The compiled command cache must also correspond to the intended environment.

### Provider commands

A provider can expose a Registry such as this example.

```php
<?php
declare(strict_types=1);

namespace App\Boot;

class Registry {
	public const COMMANDS_CLI = [
		'app:greet' => [
			'command' => \App\Cli\Command\GreetCommand::class,
			'description' => 'Print a greeting.',
		],
	];
}
```

To use this provider example, add its class to `config/providers.php`.

```php
<?php
declare(strict_types=1);

return [
	\App\Boot\Registry::class,
];
```

Preserve any providers the application already registers. The application command file remains the shorter choice for commands used only by that application.

---

## Arguments, options, and help

The shared Kernel parser interprets the tokens after the script name and command name.

| Input definition | Supported fields |
|---|---|
| Positional argument | `description`, `required`, `default`, `type`. |
| Named option | `description`, `short`, `required`, `default`, `type`, `allowed`. |

Positional arguments support `string` and `int`. Options support `string`, `int`, and `bool`. The default type is `string`.

- Argument order in `signature()` defines positional order. Required arguments precede optional arguments.
- Missing optional strings and integers default to `null` unless a default is declared. Boolean options default to `false`.
- An option's `allowed` array restricts its values using the declared PHP type, for example `['cli', 'http', 'all']` for a string option.
- Unknown options, excess positional arguments, missing required input, and invalid typed values produce a usage error.
- Developer mistakes in the signature are validated automatically in `dev`.

### Accepted syntax

The following forms apply to options actually declared by the selected command.

| Form | Meaning |
|---|---|
| `--repeat=3` or `--repeat 3` | Long string/integer option. |
| `-r 3`, `-r=3`, or `-r3` | Short string/integer option. |
| `--flag` or its declared short name | Boolean option set to `true`. |
| `--no-flag` | Boolean option set to `false`. |
| `--` | Ends option parsing; remaining tokens are positional arguments. |
| `--help` or `-h` | Generated help for the selected command. |

Combined short flags such as `-vf` are unsupported. Repeated options use the last occurrence. `--help` and `-h` are reserved and are intercepted before normal input parsing, unless they occur after `--`.

Options belong after the command name. For example, use `php bin/citomni app:info --help`.

### Accessors

| Method | Result |
|---|---|
| `arg($name, $default = null)` | Positional value, or fallback when the key is absent. |
| `argString($name)` | Positional value as a string; throws if `null`. |
| `argInt($name)` | Positional value as an integer; throws if `null`. |
| `opt($name, $default = null)` | Option value, or fallback when the key is absent. |
| `getString($name)` | Option value as a string; throws if `null`. |
| `getInt($name)` | Option value as an integer; throws if `null`. |
| `getBool($name)` | Option value as a boolean. |

Declare defaults in `signature()`. A declared optional input can have an explicit `null` value in the parsed result, so the fallback argument to `arg()` or `opt()` does not replace that `null`.

---

## Output and exit codes

`BaseCommand` provides these line-oriented output helpers.

| Method | Stream | Formatting |
|---|---|---|
| `stdout($line)` | Stdout | Plain text with a trailing newline. |
| `stderr($line)` | Stderr | Plain text with a trailing newline. |
| `info($message)` | Stdout | Informational color when supported. |
| `success($message)` | Stdout | Success color when supported. |
| `warning($message)` | Stderr | Warning color when supported. |
| `error($message)` | Stderr | Error color when supported. |

Colors are enabled for terminal streams, or when `FORCE_COLOR` is set to a non-empty value other than `0`. Use `stdout()` for machine-readable payloads and reserve stderr for diagnostics.

Output helpers do not choose an exit code or terminate execution. In particular, calling `error()` still requires returning an appropriate status.

| Constant | Code | Meaning |
|---|---|---|
| `BaseCommand::SUCCESS` | `0` | Successful execution, listing, or help. |
| `BaseCommand::FAILURE` | `1` | Runtime or operational failure. Uncaught exceptions and handled shutdown fatals also terminate with `1`. |
| `BaseCommand::USAGE` | `2` | Invalid command-line input. Runner also returns `2` for an unknown command. |

Parse errors print a diagnostic and usage text to stderr. Invalid command definitions and missing command classes throw exceptions rather than being treated as user input errors.

---

## Built-in commands

| Command | Purpose |
|---|---|
| `list` | Runner's grouped command list. Also used when no command name is supplied. |
| `app:info` | Application, runtime, configuration, and dispatch-map diagnostics. |
| `cache:warm` | Rebuild compiled configuration, dispatch, and service caches. |
| `cache:clear` | Remove those compiled caches. |

### `app:info`

```bash
php bin/citomni app:info
php bin/citomni app:info --json
php bin/citomni app:info --env-configs --json
```

The command delegates inspection to Kernel's `Support\AppInfo`. Human-readable output includes application identity, runtime settings, memory and timing metrics, OPcache information, detected packages, active configuration, routes, and commands.

| Option | Effect |
|---|---|
| `--json`, `-j` | Prints the complete AppInfo snapshot as JSON. |
| `--env-configs` | Includes freshly built `dev`, `stage`, and `prod` configuration projections. |
| `--raw` | Requests unredacted configuration values. |

Configuration is secret-masked by default through AppInfo. `--raw` is intended for deliberate local diagnosis; its output can contain credentials. Review diagnostic output before sharing it.

Environment projections evaluate configuration sources. They do not reboot the process in another environment or change `CITOMNI_ENVIRONMENT`.

### `cache:warm` and `cache:clear`

```bash
php bin/citomni cache:warm --mode=cli
php bin/citomni cache:warm --mode=all --env=prod --json
php bin/citomni cache:clear --mode=cli
php bin/citomni cache:clear --mode=http --json
```

| Option | Commands | Effect |
|---|---|---|
| `--mode=cli` | Both | Processes CLI caches through the running App. |
| `--mode=http` | Both | Processes HTTP caches through a second App in HTTP mode. Requires `citomni/http`. |
| `--mode=all` | Both | Default. Processes CLI first, then HTTP if installed; otherwise reports HTTP as skipped. |
| `--env=prod` | `cache:warm` | Selects the configuration and command/route overlay environment to compile. Defaults to `CITOMNI_ENVIRONMENT`, or `prod` if undefined. |
| `--json`, `-j` | Both | Emits one result object on stdout after successful processing. |

An empty `--env` is a usage error. `--mode=http` without the HTTP package returns `1`. Clearing a cache file that is already absent is successful.

A successful warm result with `--mode=all --json` in a CLI-only application has this shape.

```json
{
	"ok": true,
	"env": "prod",
	"modes": {
		"cli": {
			"cfg": "/path/to/app/var/cache/cfg.cli.php",
			"dispatch": "/path/to/app/var/cache/commands.cli.php",
			"services": "/path/to/app/var/cache/services.cli.php"
		},
		"http": "skipped"
	}
}
```

A clear result omits `env` and uses `null` for each file that was absent. HTTP processing returns the same three path keys for HTTP files. When HTTP is processed, the commands also write an OPcache note to stderr.

The explicit missing-HTTP-package failure produces an error object when `--json` is requested. Unexpected exceptions are handled by the global error handler on stderr and do **not** produce a JSON error object. Automation must check the exit code as well as stdout.

---

## Cache operations

### Files and freshness

Caches are stored below the application root.

| Mode | Configuration | Dispatch | Services |
|---|---|---|---|
| CLI | `var/cache/cfg.cli.php` | `var/cache/commands.cli.php` | `var/cache/services.cli.php` |
| HTTP | `var/cache/cfg.http.php` | `var/cache/routes.http.php` | `var/cache/services.http.php` |

At boot, App prefers an available compiled array over its source layers. Rebuild or clear affected caches after changing configuration, provider registration, command definitions, service maps, or Registry constants.

`cache:warm` rebuilds from sources and replaces each file through a temporary sibling and rename. A preliminary `cache:clear` is unnecessary. Replacement is atomic **per file**, not a transaction across all files or modes. An error can leave earlier cache files updated, and an HTTP failure after CLI processing does not undo CLI changes.

`cache:clear` affects later boots. The running App retains the configuration and maps it already loaded.

### Environments and paths

Cache filenames are mode-specific, not environment-specific. Warming with `--env=prod` writes the same filenames a later `dev` boot would read. Use separate application deployments for simultaneously active environments.

`--env` selects overlay files for cache generation; it does not redefine process constants or change the environment of the already running CLI App. Configuration code that branches on `CITOMNI_ENVIRONMENT` still sees the entry point's value.

Warm caches on the target host and at the deployed application path. Absolute paths computed by configuration are written as literal values into the cache.

For HTTP cache operations, the second App may need to evaluate HTTP configuration before warming or clearing. Any constants required by that configuration must already exist. CLI does not auto-detect `CITOMNI_PUBLIC_ROOT_URL`.

The supplied entry-point scaffold defines a placeholder public URL outside `dev`. Replace it with the application's actual URL when shared configuration requires it. Define the correct URL explicitly in development as well if HTTP configuration needs it. Shared code may also require `CITOMNI_PUBLIC_PATH`.

### Web-server OPcache

CLI cache invalidation does not invalidate the web server's OPcache. With `opcache.validate_timestamps=0`, reload the relevant PHP-FPM service or web server after changing the HTTP cache files and deployed PHP sources.

With timestamp validation enabled, pickup is subject to that web runtime's revalidation settings. Updating files on disk alone does not guarantee immediate use by every serving process.

### Permissions and deployment

The CLI user needs write access to `var/cache` and to the configured log location. Cache creation attempts file mode `0644`. When HTTP uses the generated files, the web-server user must be able to read them. If both CLI and HTTP tooling manage the same cache directory, both users need appropriate directory permissions.

A deployment using a production entry point can warm both modes with the following commands, run from the deployed application root.

```bash
composer install --no-dev --optimize-autoloader
php bin/citomni cache:warm --mode=all --env=prod
```

Check the exit status before completing the deployment, and handle web-server OPcache as described above.

### Recovering from stale or broken caches

If a new command is missing from `list`, an old `commands.cli.php` may still be hiding its registration. Rebuild or clear the CLI cache with a command that is available in the current map.

If the cache commands themselves are missing, manually remove `var/cache/commands.cli.php` from the intended application deployment, then run the command again.

A cache PHP file that throws during loading can prevent App construction before any command executes. Remove the affected compiled files from that mode's cache set, then correct any source configuration errors and warm again. Do not remove unrelated application data.

---

## Configuration and services

The application uses separate files for configuration, service registration, and command registration.

| File | Purpose |
|---|---|
| `config/providers.php` | Ordered list of additional provider Registry classes. |
| `config/citomni_cfg.php` | Shared application configuration for HTTP and CLI. |
| `config/citomni_cli_cfg.php` | CLI-specific application configuration. |
| `config/citomni_cfg.{ENV}.php` | Shared environment configuration. |
| `config/citomni_cli_cfg.{ENV}.php` | CLI-specific environment configuration. |
| `config/services.php` | Shared application service map. |
| `config/services_cli.php` | CLI-specific service map. |
| `config/citomni_cli_commands.php` | Application command definitions. |
| `config/citomni_cli_commands.{ENV}.php` | Environment-specific command definitions. |

These application files are optional; files that exist must supply the expected configuration or map data. Shared files and providers must be suitable for both modes when used by both entry points.

### Configuration precedence

Configuration is composed in this order, with later values taking precedence.

1. CLI Registry `CFG_CLI`.
2. Each provider's `CFG_COMMON`, then its `CFG_CLI`, in provider-list order.
3. `citomni_cfg.php`.
4. `citomni_cli_cfg.php`.
5. `citomni_cfg.{ENV}.php`.
6. `citomni_cli_cfg.{ENV}.php`.

Associative configuration is merged recursively. Use small application overrides instead of copying the entire provider baseline.

Read composed configuration through `$this->app->cfg`. Direct access to a missing key throws; use `??` for an intentional default.

```php
$timezone = (string)($this->app->cfg->locale->timezone ?? 'UTC');
```

### Service precedence

Service maps are composed in this order.

1. CLI Registry `MAP_CLI`.
2. Each provider's `MAP_COMMON`, then its `MAP_CLI`, in provider-list order.
3. `services.php`.
4. `services_cli.php`.

Later definitions replace earlier definitions **as a whole per service ID**. Service options are not recursively merged across these layers.

A service definition is either an FQCN string or an array containing `class` and optional `options`. Services are resolved lazily through `$app->{id}` and memoized per App. Use `$app->hasService('id')` when an integration is optional.

There are no environment-specific `services_cli.{ENV}.php` files in this loading contract. Environment-specific configuration belongs in the configuration overlays.

---

## Error handling

Normal CLI boot installs `CitOmni\Cli\Service\ErrorHandler` through the `errorHandler` service registration.

It disables PHP's `display_errors` and registers exception, PHP error, and shutdown handlers. Uncaught exceptions and detected fatal shutdown errors are logged, rendered to stderr, and terminate with exit code `1`. Non-fatal PHP errors are not automatically converted into exceptions or failed command statuses.

The handler is installed right after App construction, before runtime configuration, so invalid runtime settings such as an unknown `locale.timezone` are logged and rendered by it. Errors before installation, including an entry-point parse error or a failure while loading configuration, commands, or services, cannot rely on this handler being available.

### Configuration

The baseline is under `error_handler`.

| Key | Default | Meaning |
|---|---|---|
| `render.force_error_reporting` | `null` | Leaves PHP's current reporting mask unchanged; an integer sets it during installation. |
| `render.trigger` | `0` | Non-fatal PHP error levels to render to stderr. |
| `render.detail.level` | `0` | Enables detailed exception traces at `1` or higher, only in `dev`. |
| `log.trigger` | `E_ALL` | Non-fatal PHP error levels handled by the logger. |
| `log.path` | `''` | Empty resolves to `CITOMNI_APP_PATH . '/var/logs'`. |
| `log.max_bytes` | `2_000_000` | Size-based rotation threshold. |
| `log.max_files` | `10` | Rotated files retained per stream; `0` or less disables pruning. |

These keys are relative to `error_handler`. A non-fatal level must be included in `log.trigger` for the handler to reach its optional rendering branch. Uncaught exceptions and shutdown fatals bypass these non-fatal masks.

To enable development diagnostics, use `config/citomni_cli_cfg.dev.php`.

```php
<?php
declare(strict_types=1);

return [
	'error_handler' => [
		'render' => [
			'trigger' => E_ALL,
			'detail' => [
				'level' => 1,
			],
		],
	],
];
```

Trace shaping is configured below `error_handler.render.detail.trace` with `max_frames` (`120`), `max_arg_strlen` (`512`), `max_array_items` (`20`), `max_depth` (`3`), and `ellipsis` (`'...'`). These bounds also shape logged exception traces.

### Logs and sensitive values

The handler uses three JSONL streams.

| File | Contents |
|---|---|
| `cli_err_exception.jsonl` | Uncaught exceptions and bounded traces. |
| `cli_err_phperror.jsonl` | Handled non-fatal PHP errors. |
| `cli_err_shutdown.jsonl` | Detected fatal shutdown errors. |

Records include timestamp, `error_id`, category, process arguments, current working directory, and process ID. The same `error_id` appears in the terminal diagnostic. Logging failures are reported through PHP's `error_log` as a fallback.

**The CLI error handler does not redact the argument list.** Avoid passing passwords or tokens as command-line arguments. Use the application's established secret source. Exception messages and available trace arguments can also contain sensitive data, and compact production output still includes the exception message and file location.

A long-running command must handle genuinely recoverable failures within its own workflow. An exception reaching the global handler terminates the process; the handler does not restart work or retry commands.

---

## Performance notes

- Use optimized Composer autoloading for deployed applications.
- Warm configuration, command, and service caches when the deployment's inputs are stable.
- Register commands explicitly. Runner performs a direct lookup and constructs only the selected command.
- Keep command constructors and optional `init()` hooks lightweight. They run before command-specific help is handled.
- Resolve optional services only when needed. Service registration does not eagerly construct every service.
- Keep expensive work inside the selected command's execution path or the Operation it delegates to.
- Verify the CLI runtime's OPcache configuration separately from the web runtime; installing OPcache alone does not establish how CLI processes use it.

---

## Package structure

| Path | Responsibility |
|---|---|
| `src/Kernel.php` | CLI boot and exit-code propagation. |
| `src/Boot/Registry.php` | CLI configuration, service, and command baselines. |
| `src/Service/Runner.php` | Command lookup, validation, listing, and dispatch. |
| `src/Service/ErrorHandler.php` | CLI diagnostics and independent JSONL error logging. |
| `src/Command/AppInfoCommand.php` | CLI adapter for Kernel's AppInfo snapshot. |
| `src/Command/CacheWarmCommand.php` | CLI adapter for cache generation. |
| `src/Command/CacheClearCommand.php` | CLI adapter for cache removal. |
| `install/manifest.php` | Scaffold targets, source files, and installation policies. |
| `install/scaffold/` | Application entry-point, config, and starter-command templates. |

`BaseCommand`, `ArgvParser`, `HelpFormatter`, `App`, and `Runtime` are supplied by `citomni/kernel`; they are not classes inside this package.

The install manifest marks `bin/citomni` as managed and the supplied configuration and starter-code files as create-only. Check your scaffold tool's handling of those policies when updating an application.

---

## Coding & Documentation Conventions

CitOmni uses PSR-1/PSR-4 naming, tabs, K&R braces, and English PHPDoc and comments. Keep commands as transport adapters, SQL in Repositories, and non-trivial shared orchestration in Operations. Prefer direct, deterministic code with low runtime overhead.

See [CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md). This package's PHP requirement is the one declared in its `composer.json` and the [Requirements](#requirements) above.

---

## License

**CitOmni CLI** is open-source under the **MIT License**. See [LICENSE](LICENSE).

Copyright (c) 2012-present CitOmni.

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**. Factual references must not imply endorsement or affiliation. Name and logo use is governed by [NOTICE](NOTICE) and [TRADEMARKS.md](TRADEMARKS.md).

## Author

Developed by Lars Grove Mortensen.

---

CitOmni - low overhead, high performance, ready for anything.
