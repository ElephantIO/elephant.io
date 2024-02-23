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

use ElephantIO\Engine\SocketIO;
use ElephantIO\Exception\ServerConnectionFailureException;
use ElephantIO\Exception\UnsuccessfulOperationException;
use ElephantIO\Payload\Encoder;
use ElephantIO\SequenceReader;
use ElephantIO\Util;
use ElephantIO\Yeast;
use InvalidArgumentException;
use RuntimeException;

/**
 * Implements the dialog with socket.io server 1.x.
 *
 * Based on the work of Mathieu Lallemand (@lalmat)
 *
 * @author Baptiste Clavié <baptiste@wisembly.com>
 * @link https://tools.ietf.org/html/rfc6455#section-5.2 Websocket's RFC
 */
class Version1X extends SocketIO
{
    public const PROTO_OPEN = 0;
    public const PROTO_CLOSE = 1;
    public const PROTO_PING = 2;
    public const PROTO_PONG = 3;
    public const PROTO_MESSAGE = 4;
    public const PROTO_UPGRADE = 5;
    public const PROTO_NOOP = 6;

    public const PACKET_CONNECT = 0;
    public const PACKET_DISCONNECT = 1;
    public const PACKET_EVENT = 2;
    public const PACKET_ACK = 3;
    public const PACKET_ERROR = 4;
    public const PACKET_BINARY_EVENT = 5;
    public const PACKET_BINARY_ACK = 6;

    public const SEPARATOR = "\x1e";

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
            'max_payload' => 10e7,
        ];
    }

    /** {@inheritDoc} */
    protected function processData($data)
    {
        // @see https://socket.io/docs/v4/engine-io-protocol/
        /** @var \ElephantIO\Engine\Packet $result */
        $result = null;
        if ($this->transport === static::TRANSPORT_POLLING && false !== strpos($data, static::SEPARATOR)) {
            $packets = explode(static::SEPARATOR, $data);
        } else {
            $packets = [$data];
        }
        while (count($packets)) {
            $data = array_shift($packets);
            $packet = $this->decodePacket($data);
            if ($packet->proto === static::PROTO_MESSAGE &&
                $packet->type === static::PACKET_BINARY_EVENT) {
                $packet->type = static::PACKET_EVENT;
                for ($i = 0; $i < $packet->count; $i++) {
                    $bindata = null;
                    switch ($this->transport) {
                        case static::TRANSPORT_POLLING:
                            $bindata = array_shift($packets);
                            $prefix = substr($bindata, 0, 1);
                            if ($prefix !== 'b') {
                                throw new RuntimeException(sprintf('Unable to decode binary data with prefix "%s"!', $prefix));
                            }
                            $bindata = base64_decode(substr($bindata, 1));
                            break;
                        case static::TRANSPORT_WEBSOCKET:
                            $bindata = (string) $this->_transport()->recv();
                            break;
                    }
                    if (null === $bindata) {
                        throw new RuntimeException(sprintf('Binary data unavailable for index %d!', $i));
                    }
                    $packet->data = $this->replaceAttachment($packet->data, $i, $bindata);
                }
            }
            switch ($packet->proto) {
                case static::PROTO_CLOSE:
                    $this->logger->debug('Connection closed by server');
                    $this->reset();
                    throw new RuntimeException('Connection closed by server!');
                case static::PROTO_PING:
                    $this->logger->debug('Got PING, sending PONG');
                    $this->send(static::PROTO_PONG);
                    break;
                case static::PROTO_PONG:
                    $this->logger->debug('Got PONG');
                    break;
                case static::PROTO_NOOP:
                    break;
                default:
                    if (null === $result) {
                        $result = $packet;
                    } else {
                        $result->add($packet);
                    }
                    break;
            }
        }

        return $result;
    }

    /** {@inheritDoc} */
    protected function matchEvent($packet, $event)
    {
        if (($found = $packet->peek(static::PROTO_MESSAGE)) && $this->matchNamespace($found->nsp) && $found->event === $event) {
            return $found;
        }
    }

    /** {@inheritDoc} */
    protected function createEvent($event, $args, $ack = null)
    {
        $attachments = [];
        $this->getAttachments($args, $attachments);
        $type = count($attachments) ? static::PACKET_BINARY_EVENT : static::PACKET_EVENT;
        $data = ($ack ? $this->getAckId(true) : '') . json_encode([$event, $args]);
        $data = Util::concatNamespace($this->namespace, $data);
        if ($type === static::PACKET_BINARY_EVENT) {
            $data = sprintf('%d-%s', count($attachments), $data);
            $this->logger->debug(sprintf('Binary event arguments %s', Util::toStr($args)));
        }

        $raws = null;
        if (count($attachments)) {
            switch ($this->transport) {
                case static::TRANSPORT_POLLING:
                    foreach ($attachments as $attachment) {
                        $data .= static::SEPARATOR;
                        $data .= 'b' . base64_encode($attachment);
                    }
                    break;
                case static::TRANSPORT_WEBSOCKET:
                    $raws = [];
                    /** @var \ElephantIO\Engine\Transport\Websocket $transport */
                    $transport = $this->_transport();
                    foreach ($attachments as $attachment) {
                        $raws[] = $transport->getPayload($attachment, Encoder::OPCODE_BINARY);
                    }
                    break;
            }
        }

        return [static::PROTO_MESSAGE, $type . $data, $raws];
    }

    /** {@inheritDoc} */
    protected function matchAck($packet)
    {
        if (($found = $packet->peek(static::PROTO_MESSAGE)) &&
            in_array($found->type, [static::PACKET_ACK, static::PACKET_BINARY_ACK]) &&
            $this->matchNamespace($found->nsp) &&
            $found->ack == $this->getAckId()) {
            return $found;
        }
    }

    /** {@inheritDoc} */
    protected function createAck($packet, $data)
    {
        $type = $packet->count ? static::PACKET_BINARY_ACK : static::PACKET_ACK;
        $data = Util::concatNamespace($this->namespace, $packet->ack . json_encode($data));

        return [static::PROTO_MESSAGE, $type . $data];
    }

    /** {@inheritDoc} */
    protected function getPacketMaps()
    {
        return [
            'proto' => [
                static::PROTO_CLOSE => 'close',
                static::PROTO_OPEN => 'open',
                static::PROTO_PING => 'ping',
                static::PROTO_PONG => 'pong',
                static::PROTO_MESSAGE => 'message',
                static::PROTO_UPGRADE => 'upgrade',
                static::PROTO_NOOP => 'noop',
            ],
            'type' => [
                static::PACKET_CONNECT => 'connect',
                static::PACKET_DISCONNECT => 'disconnect',
                static::PACKET_EVENT => 'event',
                static::PACKET_ACK => 'ack',
                static::PACKET_ERROR => 'error',
                static::PACKET_BINARY_EVENT => 'binary-event',
                static::PACKET_BINARY_ACK => 'binary-ack',
            ]
        ];
    }

    /**
     * Decode payload data.
     *
     * @param string $data
     * @return \ElephantIO\Engine\Packet
     */
    protected function decodeData($data)
    {
        /** @var \ElephantIO\Engine\Packet $result */
        $result = null;
        $seq = new SequenceReader($data);
        while (!$seq->isEof()) {
            $len = null;
            switch (true) {
                case $this->options->version >= 4:
                    $len = strlen($seq->getData());
                    break;
                case $this->options->version >= 3:
                    $len = (int) $seq->readUntil(':');
                    break;
                case $this->options->version >= 2:
                    $prefix = $seq->read();
                    if (ord($prefix) === 0) {
                        $len = 0;
                        $sizes = $seq->readUntil("\xff");
                        $n = strlen($sizes) - 1;
                        for ($i = 0; $i <= $n; $i++) {
                            $len += ord($sizes[$i]) * pow(10, $n - $i);
                        }
                    } else {
                        throw new RuntimeException('Unsupported encoding detected!');
                    }
                    break;
            }
            if (null === $len) {
                throw new RuntimeException('Data delimiter not found!');
            }

            $packet = $this->decodePacket($seq->read($len));
            if (null === $result) {
                $result = $packet;
            } else {
                $result->add($packet);
            }
        }

        return $result;
    }

    /**
     * Decode a packet.
     *
     * @param string $data
     * @return \ElephantIO\Engine\Packet
     */
    protected function decodePacket($data)
    {
        $seq = new SequenceReader($data);
        $proto = (int) $seq->read();
        if ($proto >= static::PROTO_OPEN && $proto <= static::PROTO_NOOP) {
            $packet = $this->createPacket($proto);
            $packet->data = null;
            switch ($packet->proto) {
                case static::PROTO_OPEN:
                    if (!$seq->isEof()) {
                        $packet->data = json_decode($seq->getData(), true);
                    }
                    break;
                case static::PROTO_MESSAGE:
                    $packet->type = (int) $seq->read();
                    if ($packet->type === static::PACKET_BINARY_EVENT) {
                        $packet->count = (int) $seq->readUntil('-');
                    }
                    $openings = ['[', '{'];
                    $stops = implode(array_merge([','], $openings));
                    $packet->nsp = $seq->readUntil($stops, $openings);
                    // check for ack
                    if (!in_array(substr($seq->getData(), 0, 1), $openings)) {
                        $packet->ack = $seq->readUntil(implode($openings), $openings);
                    }
                    if (null !== ($data = json_decode($seq->getData(), true))) {
                        switch ($packet->type) {
                            case static::PACKET_EVENT:
                            case static::PACKET_BINARY_EVENT:
                                $packet->event = array_shift($data);
                                $packet->setArgs($data);
                                break;
                            case static::PACKET_ACK:
                            case static::PACKET_BINARY_ACK:
                                $packet->setArgs($data);
                                break;
                            default:
                                $packet->data = $data;
                                break;
                        }
                    }
                    break;
                default:
                    if (!$seq->isEof()) {
                        $packet->data = $seq->getData();
                    }
                    break;
            }
            $this->logger->info(sprintf('Got packet: %s', Util::truncate((string) $packet)));

            return $packet;
        }
    }

    /**
     * Get attachment from packet data. A packet data considered as attachment
     * if it's a resource and it has content.
     *
     * @param array $array
     * @param array $result
     */
    protected function getAttachments(&$array, &$result)
    {
        if (is_array($array)) {
            foreach ($array as &$value) {
                if (is_resource($value)) {
                    fseek($value, 0);
                    if ($content = stream_get_contents($value)) {
                        $idx = count($result);
                        $result[] = $content;
                        $value = ['_placeholder' => true, 'num' => $idx];
                    } else {
                        $value = null;
                    }
                }
                if (is_array($value)) {
                    $this->getAttachments($value, $result);
                }
            }
        }
    }

    /**
     * Replace binary attachment.
     *
     * @param array $array
     * @param int $index
     * @param string $data
     * @return array
     */
    protected function replaceAttachment($array, $index, $data)
    {
        if (is_array($array)) {
            foreach ($array as $key => &$value) {
                if (is_array($value)) {
                    if (isset($value['_placeholder']) && $value['_placeholder'] && $value['num'] === $index) {
                        if ($this->options->binary_as_resource) {
                            $value = Util::toResource($data);
                        } else {
                            $value = $data;
                        }
                        $this->logger->debug(sprintf('Replacing binary attachment for %d (%s)', $index, $key));
                    } else {
                        $value = $this->replaceAttachment($value, $index, $data);
                    }
                }
            }
        }
        return $array;
    }

    /**
     * Get authentication payload handshake.
     *
     * @return string
     */
    protected function getAuthPayload()
    {
        if (!isset($this->options->auth) || !$this->options->auth || $this->options->version < 4) {
            return '';
        }
        if (($authData = json_encode($this->options->auth)) === false) {
            throw new InvalidArgumentException(sprintf('Can\'t parse auth option JSON: %s!', json_last_error_msg()));
        }

        return $authData;
    }

    /**
     * Get confirmed namespace result. Namespace is confirmed if the returned
     * value is true, otherwise failed. If the return value is a string, it's
     * indicated an error message.
     *
     * @param \ElephantIO\Engine\Packet $packet
     * @return bool|string
     */
    protected function getConfirmedNamespace($packet)
    {
        if ($packet && $packet->proto === static::PROTO_MESSAGE) {
            if ($packet->type === static::PACKET_CONNECT) {
                return true;
            }
            if ($packet->type === static::PACKET_ERROR) {
                return isset($packet->data['message']) ? $packet->data['message'] : false;
            }
        }
    }

    protected function isProtocol($proto)
    {
        if ($proto < static::PROTO_OPEN || $proto > static::PROTO_NOOP) {
            throw new InvalidArgumentException('Wrong protocol type to sent to server');
        }

        return true;
    }

    protected function formatProtocol($proto, $data = null)
    {
        return $proto . $data;
    }

    public function buildQueryParameters($transport)
    {
        $parameters = [
            'EIO' => $this->options->version,
            'transport' => $transport ?? $this->transport,
            't' => Yeast::yeast(),
        ];
        if ($this->session) {
            $parameters['sid'] = $this->session->id;
        }

        return $parameters;
    }

    public function buildQuery($query)
    {
        $url = $this->stream->getUrl()->getParsed();
        if (isset($url['query']) && $url['query']) {
            $query = array_replace($query, $url['query']);
        }

        return sprintf('/%s/?%s', trim($url['path'], '/'), http_build_query($query));
    }

    protected function doHandshake()
    {
        if (null !== $this->session) {
            return;
        }

        $this->logger->info('Starting handshake');

        // set timeout to default
        $this->setTimeout($this->defaults['timeout']);

        /** @var \ElephantIO\Engine\Transport\Polling $transport */
        $transport = $this->_transport();
        if (null === ($data = $transport->recv(0, ['upgrade' => $this->transport === static::TRANSPORT_WEBSOCKET]))) {
            throw new ServerConnectionFailureException('unable to perform handshake');
        }

        if ($this->transport === static::TRANSPORT_WEBSOCKET) {
            $this->stream->upgrade();
            $packet = $this->drain();
        } else {
            $packet = $this->decodeData($data);
        }

        $handshake = null;
        if ($packet && ($packet = $packet->peek(static::PROTO_OPEN))) {
            $handshake = $packet->data;
        }
        if (null === $handshake) {
            throw new RuntimeException('Handshake is successful but without data!');
        }
        array_walk($handshake, function(&$value, $key) {
            if (in_array($key, ['pingInterval', 'pingTimeout'])) {
                $value /= 1000;
            }
        });
        $this->storeSession($handshake, $transport->getHeaders());

        $this->logger->info(sprintf('Handshake finished with %s', (string) $this->session));
    }

    protected function doAfterHandshake()
    {
        // connect to namespace for protocol version 4 and later
        if ($this->options->version < 4) {
            return;
        }

        $this->logger->info('Starting namespace connect');

        // set timeout based on handshake response
        $this->setTimeout($this->session->getTimeout());

        $this->doChangeNamespace();

        $this->logger->info('Namespace connect completed');
    }

    protected function doUpgrade()
    {
        $this->logger->info('Starting websocket upgrade');

        // set timeout based on handshake response
        $this->setTimeout($this->session->getTimeout());

        if (null !== $this->_transport()->recv(0, ['transport' => static::TRANSPORT_WEBSOCKET, 'upgrade' => true])) {
            $this->setTransport(static::TRANSPORT_WEBSOCKET);
            $this->stream->upgrade();

            $this->send(static::PROTO_UPGRADE);

            // ensure got packet connect on socket.io 1.x
            if ($this->options->version === 2 && $packet = $this->drain()) {
                if ($packet->proto === static::PROTO_MESSAGE && $packet->type === static::PACKET_CONNECT) {
                    $this->logger->debug('Upgrade successfully confirmed');
                } else {
                    $this->logger->debug('Upgrade not confirmed');
                }
            }
    
            $this->logger->info('Websocket upgrade completed');
        } else {
            $this->logger->info('Upgrade failed, skipping websocket');
        }
    }

    protected function doChangeNamespace()
    {
        if (!$this->session) {
            throw new RuntimeException('To switch namespace, a session must has been established!');
        }

        $this->send(static::PROTO_MESSAGE, static::PACKET_CONNECT . Util::concatNamespace($this->namespace, $this->getAuthPayload()));

        $packet = $this->drain();
        if (true === ($result = $this->getConfirmedNamespace($packet))) {
            return $packet;
        }
        if (is_string($result)) {
            throw new UnsuccessfulOperationException(sprintf('Unable to switch namespace: %s!', $result));
        } else {
            throw new UnsuccessfulOperationException('Unable to switch namespace!');
        }
    }

    protected function doClose()
    {
        $this->send(static::PROTO_CLOSE);
    }
}
