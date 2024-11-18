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
const server = require('http').createServer();
const socketio = require('socket.io');
const io = typeof socketio === 'function' ? socketio(server) : socketio.listen(server);

const port = 14000;
const dir = __dirname;

console.log('Please wait, running servers...');
const files = fs.readdirSync(dir);
files.forEach(file => {
    if (file.startsWith('serve-')) {
        const Svr = require(path.join(dir, file));
        const s = new Svr(io);
        if (s.nsp && s.handle()) {
            console.log('Serve %s...', s.namespace ? s.namespace : '/');
        }
    }
});

server.listen(port, () => {
    console.log('Server listening at %d...', port);
});