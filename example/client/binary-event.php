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

require __DIR__ . '/common.php';

$namespace = 'binary-event';
$event = 'test-binary';
$event_attachment = 'test-binary-attachment';

$logger = setup_logger();

if (false === ($content = file_get_contents(__DIR__ . '/../../test/Websocket/data/payload-100k.txt'))) {
    echo "Payload file is not found!\n";
    exit(1);
}
if (false === ($payload = fopen('php://memory', 'w+'))) {
    echo "Unable to create payload resource!\n";
    exit(1);
}
// create 2MB binary payload
$n = 20;
for ($i = 0; $i < $n; $i++) {
    fwrite($payload, $content);
}
$size = 512 * 1024; // 512k

foreach ([
    'websocket' => ['transport' => 'websocket'],
    'polling' => ['transports' => 'polling']
] as $transport => $options) {
    echo sprintf("Sending binary data using %s transport...\n", $transport);
    $client = setup_client($namespace, $logger, $options);
    // send big payload as parts
    $hash = uniqid();
    fseek($payload, 0);
    while (!feof($payload)) {
        if (false === $part = fread($payload, $size)) {
            throw new Error('Unable to read attachment part!');
        }
        if (false === $res = fopen('php://memory', 'r+')) {
            throw new Error('Unable to allocate attachment resource!');
        }
        fwrite($res, $part);
        if ($client->emit($event_attachment, ['hash' => $hash, 'content' => $res])) {
            if (is_object($packet = $client->wait($event_attachment))) {
                if (!$packet->data['success']) {
                    throw new Error('Send attachment part failed!');
                }
            }
        } else {
            throw new Error('Unable to send attachment part!');
        }
    }
    $client->emit($event, ['hash' => $hash]);
    if (is_object($retval = $client->wait($event))) {
        echo sprintf("Got a reply: %s\n", $retval->inspect());
    }
    $client->disconnect();
}
