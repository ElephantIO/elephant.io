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

use PHPUnit\Framework\TestCase;
use ElephantIO\Engine\Packet;

class PacketTest extends TestCase
{
    public function testPacket(): void
    {
        /** @var \ElephantIO\Engine\Packet $packet1 */
        $packet1 = Packet::create(['proto' => 1]);
        $packet2 = Packet::create(['proto' => 2]);

        $packet1->add($packet2);
        $this->assertEquals([$packet1, $packet2], $packet1->flatten(), 'Packet can be flattened');
        $this->assertEquals([$packet2], $packet1->peek(2), 'Packet can be picked by its protocol');
    }
}
