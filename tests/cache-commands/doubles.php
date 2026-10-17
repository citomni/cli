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

namespace CitOmni\Cli\Tests\CacheCommands;

/*
 * Kernel doubles for the cache command suite. The commands extend
 * \CitOmni\Kernel\Command\BaseCommand and use \CitOmni\Kernel\App, Mode, and
 * HelpFormatter. The App double records what it is asked to do and touches no
 * files; the BaseCommand double captures output instead of writing it.
 */

/**
 * ModeDouble: Test double for \CitOmni\Kernel\Mode.
 */
enum ModeDouble: string {

	/** The App serves HTTP requests. */
	case HTTP = 'http';

	/** The App runs console commands. */
	case CLI  = 'cli';
}


/**
 * AppDouble: Test double for \CitOmni\Kernel\App that records calls and returns cache paths.
 *
 * Lets the suite run the cache commands without an app on disk. A case chooses the results
 * and failures through the static properties and reads the calls back from $calls.
 *
 * Rules:
 * - Keep the signatures of the kernel App methods, so named arguments resolve the same way.
 * - Touch no files.
 */
final class AppDouble {

	/** @var list<list<mixed>> Calls made through any instance, in order. */
	public static array $calls = [];

	/** @var array<string, array<string, ?string>> clearCache() result per mode; by default every file was present. */
	public static array $clearResults = [];

	/** @var array<string, \Throwable> Thrown by warmCache() in the given mode. */
	public static array $warmFailures = [];

	/**
	 * Record the construction.
	 *
	 * @param  string      $configDir  Config directory, returned by getConfigDir().
	 * @param  ModeDouble  $mode       Mode whose cache paths warmCache() and clearCache() return.
	 */
	public function __construct(private string $configDir, private ModeDouble $mode) {
		self::$calls[] = ['construct', $mode->value, $configDir];
	}

	/**
	 * Return the config directory the App was created with.
	 *
	 * @return string
	 */
	public function getConfigDir(): string {
		return $this->configDir;
	}

	/**
	 * Record the call and return the cache paths of this App's mode, or throw the failure set for it.
	 *
	 * @param  bool         $overwrite          Unused.
	 * @param  bool         $opcacheInvalidate  Unused.
	 * @param  string|null  $env                Recorded with the call.
	 * @return array{cfg: string, dispatch: string, services: string}
	 * @throws \Throwable  The failure in $warmFailures for this App's mode.
	 * @see \CitOmni\Kernel\App::warmCache()  The signature this mirrors.
	 */
	public function warmCache(bool $overwrite = true, bool $opcacheInvalidate = true, ?string $env = null): array {
		self::$calls[] = ['warm', $this->mode->value, $env];
		if (isset(self::$warmFailures[$this->mode->value])) {
			throw self::$warmFailures[$this->mode->value];
		}
		return self::paths($this->mode->value);
	}

	/**
	 * Record the call and return the result set for this App's mode, or all its cache paths.
	 *
	 * @param  bool  $opcacheInvalidate  Unused.
	 * @return array<string, ?string>
	 * @see \CitOmni\Kernel\App::clearCache()  The signature this mirrors.
	 */
	public function clearCache(bool $opcacheInvalidate = true): array {
		self::$calls[] = ['clear', $this->mode->value];
		return self::$clearResults[$this->mode->value] ?? self::paths($this->mode->value);
	}

	/**
	 * Cache file paths for one mode, keyed like the result of App::warmCache().
	 *
	 * @param  string  $mode  'cli' or 'http'.
	 * @return array{cfg: string, dispatch: string, services: string}
	 */
	public static function paths(string $mode): array {
		$dispatch = $mode === 'http' ? 'routes' : 'commands';
		return [
			'cfg'      => "/app/var/cache/cfg.{$mode}.php",
			'dispatch' => "/app/var/cache/{$dispatch}.{$mode}.php",
			'services' => "/app/var/cache/services.{$mode}.php",
		];
	}

	/**
	 * Forget the recorded calls and the results and failures a case has set.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$calls        = [];
		self::$clearResults = [];
		self::$warmFailures = [];
	}
}


/**
 * BaseCommandDouble: Test double for \CitOmni\Kernel\Command\BaseCommand.
 *
 * Gives the cache commands the constructor, constants, accessors and IO helpers they use, and
 * captures their output instead of writing it. Validating 'allowed' values belongs to the
 * kernel's ArgvParser.
 *
 * Rules:
 * - Mirror the kernel base wherever the cache commands can tell.
 * - Throw \LogicException on input the cache commands never receive, so a wrong case fails
 *   instead of passing on a parse the kernel would not make.
 */
abstract class BaseCommandDouble {

	/** Exit code: The command did what it was asked to do. */
	public const SUCCESS = 0;

	/** Exit code: A failure that the command handles. */
	public const FAILURE = 1;

	/** Exit code: A usage error. */
	public const USAGE   = 2;

	/** @var list<string> Lines written to stdout. */
	public array $stdoutLines = [];

	/** @var list<string> Lines written to stderr. */
	public array $stderrLines = [];

	/** The App double the command works with. */
	protected AppDouble $app;

	/** Command name as the case registers it. */
	protected string $commandName;

	/** Command description as the case registers it. */
	protected string $commandDescription;

	/** @var array<string,mixed> Options of the command map entry. */
	protected array $options;

	/** @var array<string,mixed> Parsed options by name; empty until run() has parsed argv. */
	private array $parsedOpts = [];

	/**
	 * Keep the App double and the command map entry.
	 *
	 * @param  AppDouble            $app                 App double.
	 * @param  string               $commandName         Command name.
	 * @param  string               $commandDescription  Command description.
	 * @param  array<string,mixed>  $options             Options of the command map entry.
	 * @see \CitOmni\Kernel\Command\BaseCommand::__construct()  The constructor this mirrors.
	 */
	public function __construct(AppDouble $app, string $commandName, string $commandDescription, array $options = []) {
		$this->app = $app;
		$this->commandName = $commandName;
		$this->commandDescription = $commandDescription;
		$this->options = $options;
	}

	/**
	 * Parse options and call execute(), like BaseCommand::run().
	 *
	 * @param  list<string>  $argv  Full argv: binary, command name, then the tokens.
	 * @return int  Exit code from execute().
	 * @throws \LogicException  On a token that parse() does not support.
	 */
	final public function run(array $argv = []): int {
		$this->parsedOpts = self::parse(\array_slice($argv, 2), $this->signature()['options'] ?? []);
		return $this->execute();
	}

	/**
	 * Declare the command interface; none by default.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	protected function signature(): array {
		return [];
	}

	/**
	 * Run the command and return its exit code.
	 *
	 * @return int
	 */
	abstract protected function execute(): int;

	/**
	 * Get an option value (raw).
	 *
	 * @param  string  $name     Option name as declared in signature().
	 * @param  mixed   $default  Returned when the parsed options have no entry for $name.
	 * @return mixed
	 */
	protected function opt(string $name, mixed $default = null): mixed {
		return \array_key_exists($name, $this->parsedOpts) ? $this->parsedOpts[$name] : $default;
	}

	/**
	 * Get an option as string.
	 *
	 * @param  string  $name  Option name as declared in signature().
	 * @return string
	 * @throws \RuntimeException  When the value is null.
	 */
	protected function getString(string $name): string {
		$v = $this->opt($name);
		if ($v === null) {
			throw new \RuntimeException("Option --{$name} is null; no value provided and no default in signature.");
		}
		return (string)$v;
	}

	/**
	 * Get an option as bool.
	 *
	 * @param  string  $name  Option name as declared in signature().
	 * @return bool
	 * @throws \RuntimeException  When the value is null.
	 */
	protected function getBool(string $name): bool {
		$v = $this->opt($name);
		if ($v === null) {
			throw new \RuntimeException("Option --{$name} is null (defective signature - bool options must default to false).");
		}
		return (bool)$v;
	}

	/**
	 * Capture a line written to stdout.
	 *
	 * @param  string  $line  Text without the line ending.
	 * @return void
	 */
	protected function stdout(string $line): void {
		$this->stdoutLines[] = $line;
	}

	/**
	 * Capture a line written to stderr.
	 *
	 * @param  string  $line  Text without the line ending.
	 * @return void
	 */
	protected function stderr(string $line): void {
		$this->stderrLines[] = $line;
	}

	/**
	 * Capture a warning as a stderr line, without colors.
	 *
	 * @param  string  $message  Text without the line ending.
	 * @return void
	 */
	protected function warning(string $message): void {
		$this->stderr($message);
	}

	/**
	 * Capture an error as a stderr line, without colors.
	 *
	 * @param  string  $message  Text without the line ending.
	 * @return void
	 */
	protected function error(string $message): void {
		$this->stderr($message);
	}

	/**
	 * Resolve option tokens against the signature's option definitions.
	 *
	 * Behavior:
	 * - Understands only what the cache commands receive: --name=value, --flag, and -x for bool
	 *   options, plus signature defaults.
	 *
	 * @param  list<string>                 $tokens  Tokens after the command name.
	 * @param  array<string, array<mixed>>  $defs    Option definitions from signature().
	 * @return array<string, mixed>  Value per declared option.
	 * @throws \LogicException  On input the cache commands never receive; the check itself is wrong then.
	 */
	private static function parse(array $tokens, array $defs): array {
		$short = [];
		foreach ($defs as $name => $def) {
			if (isset($def['short'])) {
				$short[$def['short']] = $name;
			}
		}

		$opts = [];
		foreach ($tokens as $token) {
			if (\str_starts_with($token, '--')) {
				[$name, $value] = \str_contains($token, '=') ? \explode('=', \substr($token, 2), 2) : [\substr($token, 2), true];
			} elseif (\strlen($token) === 2 && $token[0] === '-' && isset($short[$token[1]])) {
				[$name, $value] = [$short[$token[1]], true];
			} else {
				throw new \LogicException("Unsupported token in BaseCommandDouble: {$token}");
			}
			if (!isset($defs[$name])) {
				throw new \LogicException("Unknown option in BaseCommandDouble: --{$name}");
			}
			$opts[$name] = $value;
		}

		foreach ($defs as $name => $def) {
			$bool = ($def['type'] ?? 'string') === 'bool';
			if (!\array_key_exists($name, $opts)) {
				$opts[$name] = \array_key_exists('default', $def) ? $def['default'] : ($bool ? false : null);
			} elseif ($bool !== ($opts[$name] === true)) {
				throw new \LogicException("BaseCommandDouble takes --{$name} for bool options and --{$name}=<value> for the others");
			}
		}

		return $opts;
	}
}


/**
 * HelpFormatterDouble: Test double for \CitOmni\Kernel\Command\HelpFormatter.
 */
final class HelpFormatterDouble {

	/**
	 * Return a fixed usage line with the command name.
	 *
	 * @param  string                $commandName  Command name.
	 * @param  array<string, mixed>  $signature    Unused.
	 * @return string
	 */
	public static function usage(string $commandName, array $signature): string {
		return 'Usage: ' . $commandName . ' [options]';
	}
}


/**
 * HttpRegistryDouble: Test double for \CitOmni\Http\Boot\Registry.
 *
 * Its presence under that name means citomni/http is installed.
 *
 * Rules:
 * - Alias it only for the checks that need citomni/http, after the others: An alias cannot be
 *   removed again.
 */
class HttpRegistryDouble {
}


\class_alias(ModeDouble::class, 'CitOmni\Kernel\Mode');
\class_alias(AppDouble::class, 'CitOmni\Kernel\App');
\class_alias(BaseCommandDouble::class, 'CitOmni\Kernel\Command\BaseCommand');
\class_alias(HelpFormatterDouble::class, 'CitOmni\Kernel\Command\HelpFormatter');
