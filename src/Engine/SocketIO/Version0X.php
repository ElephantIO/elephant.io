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

use InvalidArgumentException;

use ElephantIO\Engine\AbstractSocketIO;
use ElephantIO\Engine\Session;
use ElephantIO\Exception\SocketException;
use ElephantIO\Exception\UnsupportedTransportException;
use ElephantIO\Exception\ServerConnectionFailureException;
use ElephantIO\Payload\Encoder;
use ElephantIO\Stream\AbstractStream;

/**
 * Implements the dialog with Socket.IO version 0.x
 *
 * Based on the work of Baptiste Clavié (@Taluu)
 *
 * @auto ByeoungWook Kim <quddnr145@gmail.com>
 * @link https://tools.ietf.org/html/rfc6455#section-5.2 Websocket's RFC
 */
class Version0X extends AbstractSocketIO
{
    const PROTO_CLOSE         = 0;
    const PROTO_OPEN          = 1;
    const PROTO_HEARTBEAT     = 2;
    const PROTO_MESSAGE       = 3;
    const PROTO_JOIN_MESSAGE  = 4;
    const PROTO_EVENT         = 5;
    const PROTO_ACK           = 6;
    const PROTO_ERROR         = 7;
    const PROTO_NOOP          = 8;

    const TRANSPORT_POLLING   = 'xhr-polling';
    const TRANSPORT_WEBSOCKET = 'websocket';

    /** {@inheritDoc} */
    public function connect()
    {
        if ($this->isConnected()) {
            return;
        }

        $this->handshake();
        $this->upgradeTransport();
    }

    /** {@inheritDoc} */
    public function close()
    {
        if (!$this->isConnected()) {
            return;
        }

        $this->send(static::PROTO_CLOSE);

        $this->stream->close();
        $this->stream = null;
        $this->session = null;
        $this->cookies = [];
    }

    /** {@inheritDoc} */
    public function emit($event, array $args)
    {
        $this->send(static::PACKET_EVENT, json_encode(['name' => $event, 'args' => $args]));
    }

    /** {@inheritDoc} */
    public function wait($event)
    {
    }

    /** {@inheritDoc} */
    public function send($code, $message = null)
    {
        if (!$this->isConnected()) {
            return;
        }
        if (!is_int($code) || 0 > $code || 6 < $code) {
            throw new InvalidArgumentException('Wrong message type to sent to socket');
        }

        $payload = $this->getPayload($code . '::' . $this->namespace . ':' . $message);

        return $this->write((string) $payload);
    }

    /**
     * Write to the stream.
     *
     * @param string $data
     * @return int
     */
    protected function write($data)
    {
        $bytes = $this->stream->write($data);

        // wait a little bit of time after this message was sent
        \usleep((int) $this->options['wait']);

        return $bytes;
    }

    /** {@inheritDoc} */
    public function of($namespace)
    {
        parent::of($namespace);

        $this->send(static::PROTO_OPEN);
    }

    /** {@inheritDoc} */
    public function getName()
    {
        return 'SocketIO Version 0.X';
    }

    /** {@inheritDoc} */
    protected function getDefaultOptions()
    {
        return [
            'protocol' => 1,
            'transport' => static::TRANSPORT_WEBSOCKET,
        ];
    }

    /**
     * Create socket.
     *
     * @throws \ElephantIO\Exception\SocketException
     */
    protected function createSocket()
    {
        if ($this->stream) {
            $this->logger->debug('Closing socket connection');
            $this->stream->close();
            $this->stream = null;
        }
        $this->stream = AbstractStream::create($this->url, $this->context, array_merge($this->options, ['logger' => $this->logger]));
        if ($errors = $this->stream->getErrors()) {
            throw new SocketException($errors[0], $errors[1]);
        }
    }

    /**
     * Create payload.
     *
     * @param string $data
     * @param int $encoding
     * @throws \InvalidArgumentException
     * @return \ElephantIO\Payload\Encoder
     */
    protected function getPayload($data, $encoding = Encoder::OPCODE_TEXT)
    {
        return new Encoder($data, $encoding, true);
    }

    /** Does the handshake with the Socket.io server and populates the `session` value object */
    protected function handshake()
    {
        if (null !== $this->session) {
            return;
        }

        $this->logger->debug('Starting handshake');

        // set timeout to default
        $this->options['timeout'] = $this->defaults['timeout'];

        $this->createSocket();

        $url = $this->stream->getUrl()->getParsed();
        $uri = sprintf('/%s/%d/%s', trim($url['path'], '/'), $this->options['version'],
            $this->options['transport']);
        if (isset($url['query'])) {
            $uri .= '/?' . http_build_query($url['query']);
        }

        $this->stream->request($uri, ['Connection' => 'close']);
        if ($this->stream->getStatusCode() != 200) {
            throw new ServerConnectionFailureException('unable to perform handshake');
        }

        $sess = explode(':', $this->stream->getBody());
        $handshake = [
            'sid' => $sess[0],
            'pingInterval' => $sess[1],
            'pingTimeout' => $sess[2],
            'upgrades' => array_flip(explode(',', $sess[3])),
        ];

        if (!in_array('websocket', $handshake['upgrades'])) {
            throw new UnsupportedTransportException('websocket');
        }

        $cookies = [];
        foreach ($this->stream->getHeaders() as $header) {
            $matches = null;
            if (preg_match('/^Set-Cookie:\s*([^;]*)/i', $header, $matches)) {
                $cookies[] = $matches[1];
            }
        }
        $this->cookies = $cookies;
        $this->session = new Session($handshake['sid'], $handshake['pingInterval'], $handshake['pingTimeout'], $handshake['upgrades']);

        $this->logger->debug(sprintf('Handshake finished with %s', (string) $this->session));
    }

    /** Upgrades the transport to WebSocket */
    protected function upgradeTransport()
    {
        $this->logger->debug('Starting websocket upgrade');

        // set timeout based on handshake response
        $this->options['timeout'] = $this->session->getTimeout();

        $this->createSocket();

        $url = $this->stream->getUrl()->getParsed();
        $uri = sprintf('/%s/%d/%s/%s', trim($url['path'], '/'), $this->options['protocol'], $this->options['transport'], $this->session->id);
        if (isset($url['query'])) {
            $uri .= '/?' . http_build_query($url['query']);
        }

        $key = \base64_encode(\sha1(\uniqid(\mt_rand(), true), true));

        $origin = $this->context['headers']['Origin'] ?? '*';

        $headers = [
            'Upgrade' => 'WebSocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => $key,
            'Sec-WebSocket-Version' => '13',
            'Origin' => $origin,
        ];

        if (!empty($this->cookies)) {
            $headers['Cookie'] = implode('; ', $this->cookies);
        }
        $this->stream->request($uri, $headers, ['skip_body' => true]);
        if ($this->stream->getStatusCode() != 101) {
            throw new ServerConnectionFailureException('unable to upgrade to WebSocket');
        }

        $this->logger->debug('Websocket upgrade completed');
    }
}
