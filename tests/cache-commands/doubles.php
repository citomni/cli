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
 * Stand-in for \CitOmni\Kernel\Mode.
 */
enum ModeDouble: string {
	case HTTP = 'http';
	case CLI  = 'cli';
}


/**
 * Stand-in for \CitOmni\Kernel\App: records calls and returns cache paths.
 */
final class AppDouble {

	/** @var list<list<mixed>> Calls made through any instance, in order. */
	public static array $calls = [];

	/** @var array<string, array<string, ?string>> clearCache() result per mode; by default every file was present. */
	public static array $clearResults = [];

	/** @var array<string, \Throwable> Thrown by warmCache() in the given mode. */
	public static array $warmFailures = [];

	public function __construct(private string $configDir, private ModeDouble $mode) {
		self::$calls[] = ['construct', $mode->value, $configDir];
	}

	public function getConfigDir(): string {
		return $this->configDir;
	}

	/** Same signature as App::warmCache(), so named arguments resolve the same way. */
	public function warmCache(bool $overwrite = true, bool $opcacheInvalidate = true, ?string $env = null): array {
		self::$calls[] = ['warm', $this->mode->value, $env];
		if (isset(self::$warmFailures[$this->mode->value])) {
			throw self::$warmFailures[$this->mode->value];
		}
		return self::paths($this->mode->value);
	}

	/** Same signature as App::clearCache(). */
	public function clearCache(bool $opcacheInvalidate = true): array {
		self::$calls[] = ['clear', $this->mode->value];
		return self::$clearResults[$this->mode->value] ?? self::paths($this->mode->value);
	}

	/**
	 * Cache file paths for one mode, keyed like the result of App::warmCache().
	 *
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

	public static function reset(): void {
		self::$calls        = [];
		self::$clearResults = [];
		self::$warmFailures = [];
	}
}


/**
 * Stand-in for \CitOmni\Kernel\Command\BaseCommand.
 *
 * Same constructor, constants, and the accessors and IO helpers the cache commands use.
 * run() understands only what those commands receive: --name=value, --flag, and -x for
 * bool options, plus signature defaults. It does not validate 'allowed' values; that is
 * ArgvParser's job, tested in citomni/kernel.
 */
abstract class BaseCommandDouble {

	public const SUCCESS = 0;
	public const FAILURE = 1;
	public const USAGE   = 2;

	/** @var list<string> Lines written to stdout. */
	public array $stdoutLines = [];

	/** @var list<string> Lines written to stderr. */
	public array $stderrLines = [];

	protected AppDouble $app;
	protected string $commandName;
	protected string $commandDescription;
	protected array $options;
	private array $parsedOpts = [];

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
	 */
	final public function run(array $argv = []): int {
		$this->parsedOpts = self::parse(\array_slice($argv, 2), $this->signature()['options'] ?? []);
		return $this->execute();
	}

	protected function signature(): array {
		return [];
	}

	abstract protected function execute(): int;

	protected function opt(string $name, mixed $default = null): mixed {
		return \array_key_exists($name, $this->parsedOpts) ? $this->parsedOpts[$name] : $default;
	}

	protected function getString(string $name): string {
		$v = $this->opt($name);
		if ($v === null) {
			throw new \RuntimeException("Option --{$name} is null; no value provided and no default in signature.");
		}
		return (string)$v;
	}

	protected function getBool(string $name): bool {
		$v = $this->opt($name);
		if ($v === null) {
			throw new \RuntimeException("Option --{$name} is null (defective signature - bool options must default to false).");
		}
		return (bool)$v;
	}

	protected function stdout(string $line): void {
		$this->stdoutLines[] = $line;
	}

	protected function stderr(string $line): void {
		$this->stderrLines[] = $line;
	}

	protected function warning(string $message): void {
		$this->stderr($message);
	}

	protected function error(string $message): void {
		$this->stderr($message);
	}

	/**
	 * Resolve option tokens against the signature's option definitions.
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
 * Stand-in for \CitOmni\Kernel\Command\HelpFormatter.
 */
final class HelpFormatterDouble {

	public static function usage(string $commandName, array $signature): string {
		return 'Usage: ' . $commandName . ' [options]';
	}
}


/**
 * Stand-in for \CitOmni\Http\Boot\Registry. run.php aliases it only for the checks
 * that need citomni/http installed, since an alias cannot be removed again.
 */
final class HttpRegistryDouble {
}


\class_alias(ModeDouble::class, 'CitOmni\Kernel\Mode');
\class_alias(AppDouble::class, 'CitOmni\Kernel\App');
\class_alias(BaseCommandDouble::class, 'CitOmni\Kernel\Command\BaseCommand');
\class_alias(HelpFormatterDouble::class, 'CitOmni\Kernel\Command\HelpFormatter');
