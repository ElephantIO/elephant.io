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

use ElephantIO\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/../../../vendor/autoload.php';

$version = Client::CLIENT_4X;
$url = 'http://localhost:1337';
$token = 'this_is_peter_token';
$event = 'private_chat_message';

$logfile = __DIR__ . '/socket.log';

if (is_readable($logfile)) {
    @unlink($logfile);
}
// create a log channel
$logger = new Logger('client');
$logger->pushHandler(new StreamHandler($logfile, Logger::DEBUG));

echo sprintf("Creating first socket to %s\n", $url);

// create first instance
$client = new Client(Client::engine($version, $url, [
    'headers' => [
        'X-My-Header: websocket rocks',
        'Authorization: Bearer ' . $token,
        'User: peter',
    ]
]), $logger);
$client->initialize();

$data = [
    'message' => 'How are you?',
    'token' => $token,
];
echo sprintf("Sending message: %s\n", json_encode($data));
$client->emit($event, $data);
if ($retval = $client->wait($event)) {
    echo sprintf("Got a reply: %s\n", json_encode($retval->data));
}
$client->close();

// create second instance
echo sprintf("Creating second socket to %s\n", $url);
$client = new Client(Client::engine($version, $url, [
    'headers' => [
        'X-My-Header: websocket rocks',
        'Authorization: Bearer ' . $token,
        'User: peter',
    ]
]), $logger);
$client->initialize();

$data = [
    'message' => 'Do you remember me?',
    'token' => $token,
];
echo sprintf("Sending message: %s\n", json_encode($data));
$client->emit($event, $data);
if ($retval = $client->wait($event)) {
    echo sprintf("Got a reply: %s\n", json_encode($retval->data));
}

// send message with invalid token
$invalidToken = 'this_is_invalid_peter_token';
$data = [
    'message' => 'Do you remember me?',
    'token' => $invalidToken,
];
echo sprintf("Sending message: %s\n", json_encode($data));
$client->emit($event, $data);
if ($retval = $client->wait($event)) {
    echo sprintf("Got a reply: %s\n", json_encode($retval->data));
}

// close connection
$client->close();
