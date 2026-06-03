<?php
declare(strict_types=1);

namespace App\Cli\Command;

use CitOmni\Kernel\Command\BaseCommand;

/**
 * Hello World — smoke test for CLI command infrastructure.
 *
 * Exercises: signature DSL, positional arguments, typed options,
 * bool flags, bool negation, defaults, typed accessors, output helpers,
 * --help generation, and parse error handling.
 *
 * Typical usage:
 *   php bin/citomni hello John
 *   php bin/citomni hello John --greeting=Howdy
 *   php bin/citomni hello John --shout
 *   php bin/citomni hello John --repeat=3 --greeting=Hey --shout
 *   php bin/citomni hello --help
 */
final class HelloCommand extends BaseCommand {

	protected function signature(): array {
		return [
			'arguments' => [
				'name' => [
					'description' => 'Name to greet',
					'required'    => false,
					'default'     => 'World',
				],
			],
			'options' => [
				'greeting' => [
					'short'       => 'g',
					'type'        => 'string',
					'description' => 'Greeting word',
					'default'     => 'Hello',
				],
				'repeat' => [
					'short'       => 'r',
					'type'        => 'int',
					'description' => 'Number of times to repeat the greeting',
					'default'     => 1,
				],
				'shout' => [
					'short'       => 's',
					'type'        => 'bool',
					'description' => 'Uppercase the output',
				],
			],
		];
	}

	protected function execute(): int {
		$name     = $this->argString('name');
		$greeting = $this->getString('greeting');
		$repeat   = $this->getInt('repeat');
		$shout    = $this->getBool('shout');

		if ($repeat < 1) {
			$this->error("--repeat must be at least 1, got {$repeat}.");
			return self::FAILURE;
		}

		$line = "{$greeting}, {$name}!";

		if ($shout) {
			$line = \mb_strtoupper($line);
		}

		for ($i = 0; $i < $repeat; $i++) {
			$this->stdout($line);
		}

		$this->info("Greeted {$name} {$repeat} time(s).");

		return self::SUCCESS;
	}
}
