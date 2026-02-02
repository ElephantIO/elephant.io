<?php

/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright ElephantIO
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

namespace ElephantIO\Test;

use ElephantIO\Client;
use ElephantIO\Engine\EngineInterface;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testIsConnectedDelegatesToEngine(): void
    {
        $engine = $this->createMock(EngineInterface::class);
        $engine->expects($this->any())
            ->method('connected')
            ->willReturn(true);

        $client = new Client($engine);
        $this->assertTrue($client->isConnected());
    }

    public function testIsConnectedReturnsFalseWhenEngineIsNotConnected(): void
    {
        $engine = $this->createMock(EngineInterface::class);
        $engine->expects($this->any())
            ->method('connected')
            ->willReturn(false);

        $client = new Client($engine);
        $this->assertFalse($client->isConnected());
    }
}
