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
use ElephantIO\Engine\Store;
use InvalidArgumentException;

class StoreTest extends TestCase
{
    public function testStore(): void
    {
        /** @var \ElephantIO\Test\TestStore $store */
        $store = TestStore::create([
            'id' => 'ID',
            'number' => 9,
            'disp' => 'disp',
            'empty' => null,
            'nodisp' => 'nodisp',
            'hidden' => 'hidden',
            'non_existant' => true,
        ]);

        $this->assertSame("ID{\"number\":9,\"disp\":\"disp\"}", (string) $store, 'Properly cast store to string');

        $store->id = 'the-id';
        $this->assertEquals('the-id', $store->id, 'Store can retrieve the value by its key');

        $values = $store->getValues();
        $this->assertFalse(isset($values['non_existant']), 'Store excludes non existent key');

        $this->assertNull($store->empty, 'Store can retrieve null value');

        $this->expectException(InvalidArgumentException::class);
        $store->somekey = 'nothing';
        $this->fail('Setting non existent key did not throw an exception!');
    }
}

/**
 * @property string $id
 * @property int $number
 * @property bool|null $empty
 * @property bool|null $disp
 * @property bool|null $nodisp
 * @property bool|null $hidden
 * @property string $somekey
 */
class TestStore extends Store
{
    protected function initialize()
    {
        $this->keys = ['+id', 'number', '!empty', '!disp', '!nodisp', '_hidden'];
    }

    /**
     * Get values.
     *
     * @return mixed[]
     */
    public function getValues()
    {
        return $this->values;
    }
}
