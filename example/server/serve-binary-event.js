/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
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
        this.providePayload = true;
    }

    handle() {
        this.nsp.on('connection', socket => {
            this.log('connected: %s', socket.id);
            socket
                .on('disconnect', () => {
                    this.log('disconnected: %s', socket.id);
                })
                .on('test-binary', data => {
                    this.log('receive data: %s', data);
                    const payload = [];
                    const f = function(p) {
                        if (typeof p === 'object') {
                            Object.keys(p).forEach(k => {
                                if (p[k] instanceof Buffer) {
                                    payload.push(p[k]);
                                } else if (typeof p[k] === 'object' && p[k].constructor.name === 'Object') {
                                    f(p[k]);
                                }
                            });
                        }
                    }
                    if (this.providePayload) {
                        const payload100k = path.resolve(`${__dirname}/../../test/Websocket/data/payload-100k.txt`);
                        const buff100k = fs.readFileSync(payload100k);
                        let buff, n = 10;
                        for (let i = 0; i < n; i++) {
                            if (buff) {
                                buff = Buffer.concat([buff, buff100k]);
                            } else {
                                buff = buff100k;
                            }
                        }
                        payload.push(buff);
                    } else {
                        f(data);
                    }
                    socket.emit('test-binary', {success: true, time: Buffer.from(new Date().toString()), payload});
                });
        });
        return true;
    }
}

module.exports = BinaryEventServer;