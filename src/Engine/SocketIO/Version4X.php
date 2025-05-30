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

namespace ElephantIO\Engine\SocketIO;

/**
 * Implements the dialog with socket.io server 4.x.
 *
 * @author Toha <tohenk@yahoo.com>
 */
class Version4X extends Version1X
{
    protected function initialize(&$options)
    {
        parent::initialize($options);
        $this->name = 'SocketIO Version 4.X';
        $this->setDefaults(['version' => static::EIO_V4, 'max_payload' => 1e6]);
    }
}
