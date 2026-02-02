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

const fs = require('fs');
const path = require('path');
const ExampleServer = require('./serve');

/**
 * An example of binary event message server.
 */
class BinaryEventServer extends ExampleServer {

    initialize() {
        this.namespace = 'binary-event';
    }

    handle() {
        this.nsp.on('connection', socket => {
            this.log('connected: %s', socket.id);
            socket
                .on('disconnect', () => {
                    this.log('disconnected: %s', socket.id);
                })
                .on('test-binary-attachment', data => {
                    const res = {success: false};
                    if (data.hash && data.content) {
                        if (this.attachments === undefined) {
                            this.attachments = {};
                        }
                        let buff = Buffer.from(data.content);
                        if (this.attachments[data.hash] !== undefined) {
                            buff = Buffer.concat([this.attachments[data.hash], buff]);
                        }
                        this.attachments[data.hash] = buff;
                        res.success = true;
                        res.length = buff.byteLength;
                        this.log('attachment %s: part %s (%d bytes)...', socket.id, data.hash, res.length);
                    }
                    socket.emit('test-binary-attachment', res);
                })
                .on('test-binary', data => {
                    this.log('receive data: %s', data);
                    const res = {success: false};
                    if (data.hash && this.attachments !== undefined && this.attachments[data.hash]) {
                        // send back attachment to client
                        res.success = true;
                        res.time = Buffer.from(new Date().toString());
                        res.payload = this.attachments[data.hash];
                    }
                    socket.emit('test-binary', res);
                });
        });
        return true;
    }
}

module.exports = BinaryEventServer;