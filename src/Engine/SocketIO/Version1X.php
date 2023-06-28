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
use RuntimeException;
use stdClass;

use ElephantIO\SequenceReader;
use ElephantIO\Yeast;
use ElephantIO\Engine\AbstractSocketIO;
use ElephantIO\Engine\Session;
use ElephantIO\Exception\SocketException;
use ElephantIO\Exception\UnsupportedTransportException;
use ElephantIO\Exception\ServerConnectionFailureException;
use ElephantIO\Payload\Encoder;
use ElephantIO\Stream\AbstractStream;

/**
 * Implements the dialog with Socket.IO version 1.x
 *
 * Based on the work of Mathieu Lallemand (@lalmat)
 *
 * @author Baptiste Clavié <baptiste@wisembly.com>
 * @link https://tools.ietf.org/html/rfc6455#section-5.2 Websocket's RFC
 */
class Version1X extends AbstractSocketIO
{
    const PROTO_OPEN    = 0;
    const PROTO_CLOSE   = 1;
    const PROTO_PING    = 2;
    const PROTO_PONG    = 3;
    const PROTO_MESSAGE = 4;
    const PROTO_UPGRADE = 5;
    const PROTO_NOOP    = 6;

    const TRANSPORT_POLLING   = 'polling';
    const TRANSPORT_WEBSOCKET = 'websocket';

    /**
     * Last socket connect time.
     *
     * @var float
     */
    protected $ctime = null;

    /**
     * Wait time before creating a new socket.
     *
     * @var integer
     */
    protected $cwait = 50;

    /** {@inheritDoc} */
    public function connect()
    {
        if ($this->isConnected()) {
            return;
        }

        $this->handshake();
        $this->connectNamespace();
        $this->upgradeTransport();
    }

    /** {@inheritDoc} */
    public function close()
    {
        if (!$this->isConnected()) {
            return;
        }

        $this->write(static::PROTO_CLOSE);

        $this->stream->close();
        $this->stream = null;
        $this->session = null;
        $this->cookies = [];
    }

    /** {@inheritDoc} */
    public function emit($event, array $args)
    {
        $namespace = $this->namespace;

        if (!in_array($namespace, ['', '/'])) {
            $namespace .= ',';
        }

        return $this->write(static::PROTO_MESSAGE, static::PACKET_EVENT . $namespace . json_encode([$event, $args]));
    }

    /** {@inheritDoc} */
    public function wait($event)
    {
        while (true) {
            if ($packet = $this->drain()) {
                if ($packet->proto === static::PROTO_MESSAGE && $packet->type === static::PACKET_EVENT &&
                    $this->matchNamespace($packet->nsp) && $packet->event === $event) {
                    return $packet;
                }
            }
        }
    }

    /** {@inheritDoc} */
    public function drain()
    {
        if ($data = $this->read()) {
            $this->logger->debug(sprintf('Got data: %s', (string) $data));
            $packet = $this->decodePacket($data);
            switch ($packet->proto) {
                case static::PROTO_PING:
                    $this->logger->debug('Sending PONG');
                    $this->write(static::PROTO_PONG);
                    break;
                case static::PROTO_PONG:
                    $this->logger->debug('Got PONG');
                    break;
                case static::PROTO_NOOP:
                    break;
                default:
                    return $packet;
            }
        }
        $this->keepAlive();
    }

    /** {@inheritDoc} */
    public function of($namespace)
    {
        $oldns = $this->namespace ? $this->namespace : '/';
        if ($oldns != $namespace) {
            parent::of($namespace);

            $this->write(static::PROTO_MESSAGE, static::PACKET_CONNECT . $namespace);

            return $this->drain();
        }
    }

    /** {@inheritDoc} */
    public function write($code, $message = null)
    {
        if (!$this->isConnected()) {
            return;
        }

        $payload = $this->getPayload($code, $message);
        if (count($fragments = $payload->encode()->getFragments()) > 1) {
            throw new RuntimeException(sprintf('Payload is exceed the maximum allowed length of %d!',
                $this->options['max_payload']));
        }
        $bytes = $this->stream->write($fragments[0]);
        $this->session->resetHeartbeat();

        // wait a little bit of time after this message was sent
        \usleep((int) $this->options['wait']);

        return $bytes;
    }

    /** {@inheritDoc} */
    public function getName()
    {
        return 'SocketIO Version 1.X';
    }

    /** {@inheritDoc} */
    protected function getDefaultOptions()
    {
        return [
            'version' => 2,
            'use_b64' => false,
            'transport' => static::TRANSPORT_POLLING,
            'max_payload' => 10e7,
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
        if (null !== $this->ctime) {
            $delta = (microtime(true) - $this->ctime) * 1000;
            if ($delta < $this->cwait) {
                usleep($this->cwait);
            }
        }
        $this->ctime = microtime(true);
        $this->stream = AbstractStream::create($this->url, $this->context, array_merge($this->options, ['logger' => $this->logger]));
        if ($errors = $this->stream->getErrors()) {
            throw new SocketException($errors[0], $errors[1]);
        }
    }

    /**
     * Create payload.
     *
     * @param int $code
     * @param string $message
     * @throws \InvalidArgumentException
     * @return \ElephantIO\Payload\Encoder
     */
    protected function getPayload($code, $message)
    {
        if (!is_int($code) || static::PROTO_OPEN > $code || static::PROTO_NOOP < $code) {
            throw new InvalidArgumentException('Wrong message type when trying to write on the socket');
        }
        $encoder = new Encoder($code . $message, Encoder::OPCODE_TEXT, true);
        $encoder->setMaxPayload($this->session->maxPayload ? $this->session->maxPayload : $this->options['max_payload']);

        return $encoder;
    }

    /**
     * Decode payload data.
     *
     * @param string $data
     * @return \stdClass[]
     */
    protected function decodeData($data)
    {
        $result = [];
        $seq = new SequenceReader($data);
        while (!$seq->isEof()) {
            if (null === ($len = $this->options['version'] >= 4 ? strlen($seq->getData()) : $seq->readUntil(':'))) {
                throw new RuntimeException('Data delimiter not found!');
            }

            $dseq = new SequenceReader($seq->read((int) $len));
            $type = (int) $dseq->read();
            $packet = $dseq->getData();
            switch ($type) {
                case static::PACKET_CONNECT:
                  $packet = json_decode($packet, true);
                  break;
            }
            $item = new stdClass();
            $item->type = $type;
            $item->data = $packet;
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Pick data which has a type.
     *
     * @param array $data
     * @param int $type
     * @return \stdClass
     */
    protected function pickData($data, $type)
    {
        foreach ($data as $item) {
            if (isset($item->type) && $item->type === $type) {
                return $item;
            }
        }
    }

    /**
     * Decode a packet.
     *
     * @param string $data
     * @return \stdClass
     */
    protected function decodePacket($data)
    {
        $seq = new SequenceReader($data);
        $proto = (int) $seq->read();
        if ($proto >= static::PROTO_OPEN && $proto <= static::PROTO_NOOP) {
            $packet = new stdClass();
            $packet->proto = $proto;
            $packet->type = (int) $seq->read();
            $packet->nsp = $seq->readUntil(',[{', ['[', '{']);

            switch ($packet->proto) {
                case static::PROTO_MESSAGE:
                    $data = $seq->getData();
                    // make sure it really is valid JSON
                    preg_match('#\{(?:[^{}]|(?R))*\}#s', $data, $matches);
                    if(\count($matches) && $matches[0]){
                        $data = $matches[0];
                    }

                    if (null !== ($data = json_decode($data, true))) {
                        switch ($packet->type) {
                            case static::PACKET_EVENT:
                                $packet->event = array_shift($data);
                                $packet->args = $data;
                                $packet->data = count($data) ? $data[0] : null;
                                break;
                            default:
                                $packet->data = $data;
                                break;
                        }
                    }
                    break;
            }

            return $packet;
        }
    }

    protected function matchNamespace($namespace)
    {
        if ($namespace === $this->namespace || (substr($this->namespace, 1) === $namespace)) {
            return true;
        }
    }

    /**
     * Get URI.
     *
     * @param array $query
     * @return string
     */
    protected function getUri($query)
    {
        $url = $this->stream->getUrl()->getParsed();
        if (isset($url['query']) && $url['query']) {
            $query = array_replace($query, $url['query']);
        }
        return sprintf('/%s/?%s', trim($url['path'], '/'), http_build_query($query));
    }

    /**
     * Perform connection namespace request.
     */
    protected function requestNamespace()
    {
        $this->logger->debug('Requesting namespace');

        $this->createSocket();

        $uri = $this->getUri([
            'EIO'       => $this->options['version'],
            'transport' => $this->options['transport'],
            't'         => Yeast::yeast(),
            'sid'       => $this->session->id,
        ]);
        $payload = static::PROTO_MESSAGE . static::PACKET_CONNECT;

        $this->stream->request($uri, ['Connection: close'], ['method' => 'POST', 'payload' => $payload]);
        if ($this->stream->getStatusCode() != 200) {
            throw new ServerConnectionFailureException('unable to perform namespace request');
        }

        $this->logger->debug('Requesting namespace completed');
    }

    /**
     * Perform connection namespace confirmation.
     */
    protected function confirmNamespace()
    {
        $this->logger->debug('Confirm namespace');

        $this->createSocket();

        $uri = $this->getUri([
            'EIO'       => $this->options['version'],
            'transport' => $this->options['transport'],
            't'         => Yeast::yeast(),
            'sid'       => $this->session->id,
        ]);

        $sid = null;
        $this->stream->request($uri, ['Connection: close']);
        if (($packet = $this->decodePacket($this->stream->getBody())) && $packet->data && isset($packet->data['sid'])) {
            $sid = $packet->data['sid'];
        }
        if (!$sid) {
            throw new ServerConnectionFailureException('unable to perform namespace confirmation');
        }

        $this->logger->debug('Confirm namespace completed');
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

        $query = [
            'EIO'       => $this->options['version'],
            'transport' => $this->options['transport'],
            't'         => Yeast::yeast(),
        ];
        if ($this->options['use_b64']) {
            $query['b64'] = 1;
        }
        $uri = $this->getUri($query);

        $this->stream->request($uri, ['Connection: close']);
        if ($this->stream->getStatusCode() != 200) {
            throw new ServerConnectionFailureException('unable to perform handshake');
        }

        $handshake = null;
        if (count($data = $this->decodeData($this->stream->getBody()))) {
            if ($data = $this->pickData($data, static::PACKET_CONNECT)) {
                $handshake = $data->data;
            }
        }

        if (null === $handshake || !in_array('websocket', $handshake['upgrades'])) {
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
        $this->session = new Session(
            $handshake['sid'],
            $handshake['pingInterval'] / 1000,
            $handshake['pingTimeout'] / 1000,
            $handshake['upgrades'],
            isset($handshake['maxPayload']) ? $handshake['maxPayload'] : null
        );

        $this->logger->debug(sprintf('Handshake finished with %s', (string) $this->session));
    }

    /**
     * Connect to namespace for protocol version 4.
     */
    protected function connectNamespace()
    {
        if ($this->options['version'] < 4) {
            return;
        }

        $this->logger->debug('Starting namespace connect');

        // set timeout based on handshake response
        $this->options['timeout'] = $this->session->getTimeout();

        $this->requestNamespace();
        $this->confirmNamespace();

        $this->logger->debug('Namespace connect completed');
    }

    /**
     * Upgrades the transport to WebSocket
     *
     * FYI:
     * Version "2" is used for the EIO param by socket.io v1
     * Version "3" is used by socket.io v2
     * Version "4" is used by socket.io v3
     */
    protected function upgradeTransport()
    {
        $this->logger->debug('Starting websocket upgrade');

        // set timeout based on handshake response
        $this->options['timeout'] = $this->session->getTimeout();

        $this->createSocket();

        $query = [
            'EIO'       => $this->options['version'],
            'transport' => static::TRANSPORT_WEBSOCKET,
            't'         => Yeast::yeast(),
            'sid'       => $this->session->id,
        ];

        if ($this->options['version'] === 2 && $this->options['use_b64']) {
            $query['b64'] = 1;
        }

        $uri = $this->getUri($query);

        $hash = sha1(uniqid(mt_rand(), true), true);

        if ($this->options['version'] > 2) {
            $hash = substr($hash, 0, 16);
        }

        $key = base64_encode($hash);

        $origin = '*';
        $headers = isset($this->context['headers']) ? (array) $this->context['headers'] : [];

        foreach ($headers as $header) {
            $matches = [];
            if (preg_match('`^Origin:\s*(.+?)$`', $header, $matches)) {
                $origin = $matches[1];
                break;
            }
        }

        $headers = [
            'Upgrade: websocket',
            'Connection: Upgrade',
            sprintf('Sec-WebSocket-Key: %s', $key),
            'Sec-WebSocket-Version: 13',
            sprintf('Origin: %s', $origin),
        ];

        if (!empty($this->cookies)) {
            $headers[] = sprintf('Cookie: %s', implode('; ', $this->cookies));
        }
        $this->stream->request($uri, $headers, ['skip_body' => true]);
        if ($this->stream->getStatusCode() != 101) {
            throw new ServerConnectionFailureException('unable to upgrade to WebSocket');
        }

        $this->write(static::PROTO_UPGRADE);

        //remove message '40' from buffer, emmiting by socket.io after receiving static::PROTO_UPGRADE
        if ($this->options['version'] === 2) {
            $this->read();
        }

        $this->logger->debug('Websocket upgrade completed');
    }

    /**
     * {@inheritDoc}
     */
    public function keepAlive()
    {
        if ($this->options['version'] <= 3 && $this->session->needsHeartbeat()) {
            $this->logger->debug('Sending PING');
            $this->write(static::PROTO_PING);
        }
    }
}
