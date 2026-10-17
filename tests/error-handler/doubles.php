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
 * BaseServiceDouble: Test double for \CitOmni\Kernel\Service\BaseService.
 *
 * Gives ErrorHandler the constructor contract of the kernel base without loading
 * citomni/kernel. The app can be any object with a cfg property.
 *
 * Rules:
 * - Mirror the kernel base wherever ErrorHandler can tell.
 */
abstract class BaseServiceDouble {

	/** The app the service works with: An object with a cfg property. */
	protected object $app;

	/** @var array<string,mixed> Service options, as a service map entry would give them. */
	protected array $options;

	/**
	 * Keep the app and the options, then run init().
	 *
	 * @param  object               $app      App stand-in with a cfg property.
	 * @param  array<string,mixed>  $options  Service options.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  The constructor this mirrors.
	 */
	public function __construct(object $app, array $options = []) {
		$this->app = $app;
		$this->options = $options;
		$this->init();
	}

	/**
	 * Set up the service; does nothing unless a subclass overrides it.
	 *
	 * @return void
	 */
	protected function init(): void {
	}
}


/**
 * ArrDouble: Test double for \CitOmni\Kernel\Arr.
 *
 * Assumes options that hold no lists; for those, a recursive replace is enough.
 */
final class ArrDouble {

	/**
	 * Merge two arrays key by key; values of the second win.
	 *
	 * @param  array<string,mixed>  $a  Base.
	 * @param  array<string,mixed>  $b  Overrides.
	 * @return array<string,mixed>
	 * @see \CitOmni\Kernel\Arr::mergeAssocLastWins()  The merge this mirrors for arrays without lists.
	 */
	public static function mergeAssocLastWins(array $a, array $b): array {
		return \array_replace_recursive($a, $b);
	}
}


\class_alias(BaseServiceDouble::class, 'CitOmni\Kernel\Service\BaseService');
\class_alias(ArrDouble::class, 'CitOmni\Kernel\Arr');
