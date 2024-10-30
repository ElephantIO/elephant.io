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

const port = Number(process.env.PORT) || 14000;
const dir = __dirname;

console.log('Please wait, running servers...');
fs
    .readdirSync(dir)
    .filter(file => file.startsWith('serve-'))
    .map(file => {
        const Svr = require(path.join(dir, file));
        const s = new Svr(io);
        s.name = file.substr(6, file.length - 9);
        return s;
    })
    .sort((a, b) => a.ns.localeCompare(b.ns))
    .forEach(s => {
        if (s.nsp && s.handle()) {
            console.log('Serving %s on %s', s.name, '/' + s.ns);
        }
    });

server.listen(port, () => {
    console.log('Server listening at %d...', port);
});