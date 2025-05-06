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

namespace ElephantIO\Engine;

use ElephantIO\Parser\Polling\Encoder;
use ElephantIO\Parser\Websocket\Decoder;
use Psr\Log\LoggerAwareTrait;

/**
 * Socket transport.
 *
 * @author Toha <tohenk@yahoo.com>
 */
abstract class Transport
{
    use LoggerAwareTrait;

    /**
     * Socket interface.
     *
     * @var \ElephantIO\Engine\SocketInterface
     */
    protected $sio;

    /**
     * Last operation timed out state.
     *
     * @var bool|null
     */
    protected $timedout;

    /**
     * Constructor.
     *
     * @param \ElephantIO\Engine\SocketInterface $sio
     */
    public function __construct($sio)
    {
        $this->sio = $sio;
    }

    /**
     * Send data.
     *
     * @param string|Encoder $data
     * @param array $parameters
     * @return int|null Number of byte written
     */
    abstract public function send($data, $parameters = []);

    /**
     * Receive data.
     *
     * @param int $timeout
     * @param array $parameters
     * @return string|null|Decoder
     */
    abstract public function recv($timeout = 0, $parameters = []);

    /**
     * Is last operation timed out?
     *
     * @return bool
     */
    public function timedout()
    {
        return $this->timedout;
    }

    /**
     * Set last heartbeat.
     *
     * @return \ElephantIO\Engine\Transport
     */
    protected function setHeartbeat()
    {
        if ($this->sio->getSession()) {
            $this->sio->getSession()->resetHeartbeat();
        }

        return $this;
    }
}
