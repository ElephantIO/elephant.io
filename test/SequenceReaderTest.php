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
use ElephantIO\SequenceReader;

class SequenceReaderTest extends TestCase
{
    public function testRead(): void
    {
        $seq = new SequenceReader('1234567890');

        $this->assertSame('1', $seq->read(), 'Read one character each');
        $this->assertSame('2', $seq->readData(), 'Can read 1 remaining characters');
        $this->assertSame('234', $seq->readData(3), 'Can read 3 remaining characters');
        $this->assertSame('234567890', $seq->getData(), 'Data contains remaining characters');
        $this->assertSame(false, $seq->isEof(), 'No EOF if remaining data is exist');
        $this->assertSame('234567890', $seq->read(null), 'Can read remaining characters');
        $this->assertSame(true, $seq->isEof(), 'EOF reached once remaining characters read');
        $this->assertSame('', $seq->readData(), 'An empty string returned when remanining characters unavailable');
    }

    public function testReadUntil(): void
    {
        $seq = new SequenceReader('1::3');

        $this->assertSame('1', $seq->readUntil(':'), 'Read using delimiter');
        $this->assertSame(':3', $seq->getData(), 'Data contains remaining characters');
        $this->assertSame(false, $seq->isEof(), 'No EOF if remaining data is exist');
        $this->assertSame('', $seq->readUntil(':'), 'Can read empty');
        $this->assertSame('3', $seq->getData(), 'Remaining characters matched');
    }

    public function testReadUntilNoSkip(): void
    {
        $seq = new SequenceReader('1,["A",3]');

        $this->assertSame('1', $seq->readUntil(',[', ['[']), 'Read using delimiter with no skip character');
        $this->assertSame('["A",3]', $seq->getData(), 'Remaining characters exclude delimiter');
        $this->assertSame('["A",3]', $seq->read(null), 'Read remaining characters');
        $this->assertSame(true, $seq->isEof(), 'No more characters');
    }

    public function testReadWithin(): void
    {
        $seq = new SequenceReader('0,["A",2]');

        $this->assertSame('0', $seq->readWithin(',', ['[']), 'Read using delimiter within boundary');
        $this->assertSame('["A",2]', $seq->getData(), 'Remaining characters exclude delimiter');
        $this->assertSame(null, $seq->readWithin(',', ['[']), 'Null returned when delimiter is beyond boundary');
        $this->assertSame('["A",2]', $seq->read(null), 'Read remaining characters');
        $this->assertSame(true, $seq->isEof(), 'No more characters');
    }
}
