<?php

/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

namespace ElephantIO\Test;

enum TestEnum
{
    case One;
    case Two;
}

enum TestBackedEnum: string
{
    case One = 'One';
    case Two = 'Two';
}