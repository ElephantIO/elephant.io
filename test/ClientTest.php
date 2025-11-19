<?php

namespace ElephantIO\Test;

use ElephantIO\Client;
use ElephantIO\Engine\EngineInterface;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testIsConnectedDelegatesToEngine()
    {
        $engine = $this->createMock(EngineInterface::class);
        $engine->expects($this->any())
            ->method('connected')
            ->willReturn(true);

        $client = new Client($engine);
        $this->assertTrue($client->isConnected());
    }

    public function testIsConnectedReturnsFalseWhenEngineIsNotConnected()
    {
        $engine = $this->createMock(EngineInterface::class);
        $engine->expects($this->any())
            ->method('connected')
            ->willReturn(false);

        $client = new Client($engine);
        $this->assertFalse($client->isConnected());
    }
}
