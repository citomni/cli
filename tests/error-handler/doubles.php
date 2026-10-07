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

namespace CitOmni\Cli\Tests\ErrorHandler;

/*
 * Kernel doubles for the CLI ErrorHandler suite. ErrorHandler extends
 * \CitOmni\Kernel\Service\BaseService and merges its options through
 * \CitOmni\Kernel\Arr; nothing else from the kernel is needed.
 */

/**
 * Stand-in for \CitOmni\Kernel\Service\BaseService: stores app and options, then calls init().
 */
abstract class BaseServiceDouble {

	protected object $app;

	protected array $options;

	public function __construct(object $app, array $options = []) {
		$this->app = $app;
		$this->options = $options;
		$this->init();
	}

	protected function init(): void {
	}
}


/**
 * Stand-in for \CitOmni\Kernel\Arr. The suite's options contain no lists, so a recursive replace is enough.
 */
final class ArrDouble {

	public static function mergeAssocLastWins(array $a, array $b): array {
		return \array_replace_recursive($a, $b);
	}
}


\class_alias(BaseServiceDouble::class, 'CitOmni\Kernel\Service\BaseService');
\class_alias(ArrDouble::class, 'CitOmni\Kernel\Arr');
