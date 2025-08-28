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

$logger = setup_logger();

if (false === ($content = file_get_contents(__DIR__ . '/../../test/Websocket/data/payload-100k.txt'))) {
    echo "Payload file is not found!\n";
    exit(1);
}
if (false === ($payload = fopen('php://memory', 'w+'))) {
    echo "Unable to create payload resource!\n";
    exit(1);
}
// create binary payload
$n = 1;
for ($i = 0; $i < $n; $i++) {
    fwrite($payload, $content);
}

foreach ([
    'websocket' => ['transport' => 'websocket'],
    'polling' => ['transports' => 'polling']
] as $transport => $options) {
    echo sprintf("Sending binary data using %s transport...\n", $transport);
    $client = setup_client($namespace, $logger, $options);
    $client->emit($event, ['data1' => ['test' => $payload]]);
    if (is_object($retval = $client->wait($event))) {
        echo sprintf("Got a reply: %s\n", $retval->inspect());
    }
    $client->disconnect();
}
