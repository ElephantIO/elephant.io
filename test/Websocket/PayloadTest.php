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

namespace ElephantIO\Test\Websocket;

use ElephantIO\Parser\Websocket\Decoder;
use ElephantIO\Parser\Websocket\Encoder;
use ElephantIO\Parser\Websocket\Payload as BasePayload;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class PayloadTest extends TestCase
{
    public function testMaskData(): void
    {
        $payload = new Payload();

        $refl = new ReflectionProperty(Payload::class, 'maskKey');
        $refl->setAccessible(true);
        $refl->setValue($payload, '?EV!');

        $refl = new ReflectionMethod(Payload::class, 'maskData');
        $refl->setAccessible(true);

        $this->assertSame('592a39', bin2hex($refl->invoke($payload, 'foo')));
    }

    /**
     * Do encode or decode.
     *
     * @param string $sz
     * @param string $filename
     * @return void
     */
    protected function encodeDecode($sz, $filename)
    {
        if ($payload = file_get_contents($filename)) {
            $encoder = new Encoder($payload, Decoder::OPCODE_TEXT, false);
            $encoded = (string) $encoder;
            $decoder = new Decoder($encoded);
            $decoded = (string) $decoder;
            $this->assertEquals($payload, $decoded, 'Properly encode and decode payload '.$sz.' content');
        } else {
            $this->fail(sprintf('Unable to load payload %s!', $filename));
        }
    }

    public function testPayload7D(): void
    {
        $this->encodeDecode('125-bytes', __DIR__.'/data/payload-7d.txt');
    }

    public function testPayloadFFFF(): void
    {
        $this->encodeDecode('64-kilobytes', __DIR__.'/data/payload-ffff.txt');
    }

    public function testPayloadAboveFFFF(): void
    {
        $this->encodeDecode('100-kilobytes', __DIR__.'/data/payload-100k.txt');
    }
}

/** Fixtures for these tests */
class Payload extends BasePayload
{
}
