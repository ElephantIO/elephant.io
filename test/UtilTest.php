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

use PHPUnit\Framework\TestCase;
use ElephantIO\Util;

class UtilTest extends TestCase
{
    public function testToStr()
    {
        $values = [
            'string' => 'The string',
            'object' => new Stringable(),
            'resource' => Util::toResource('1234567890'),
            'number' => 49,
            'other' => new NotStringable(),
        ];
        $this->assertSame("{\"string\":\"The string\",\"object\":stringable,\"resource\":<1234567890>,\"number\":49,\"other\":{\"test\":1001.1}}",
            Util::toStr($values), 'Util can represents the values as string');
    }

    public function testToStrWithEnum()
    {
        if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
            require_once 'TestEnum.php';
            $values = [
                'one' => TestEnum::One,
                'two' => TestBackedEnum::Two,
            ];
            $rootPath = version_compare(PHP_VERSION, '8.2.0', '>=') ? '\\' : '';
            $this->assertSame("{\"one\":{$rootPath}ElephantIO\Test\TestEnum::One,\"two\":Two}",
                Util::toStr($values), 'Util can represents enum as string');
        } else {
            $this->markTestSkipped('Test only for PHP >= 8.1.0');
        }
    }
}

class Stringable
{
    public function __toString()
    {
        return 'stringable';
    }
}

class NotStringable
{
    public $test = 1001.1;
}
