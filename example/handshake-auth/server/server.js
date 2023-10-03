/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

const server = require('http').createServer();
const io = require('socket.io')(server);

const port = 1334;


// set up initialization and authorization method
io.use((socket, next) => {
  const user = socket.handshake.auth.user;
  const token = socket.handshake.auth.token;
  if(user && token) {
    console.log('auth token', token);
    // do some security check with token
    // for example:
    if (user === 'random@example.com' && token === 'my-secret-token') {
      console.log('Successfully authenticated');
      return next();
    }

    return next(new Error('invalid credentials'));
  } else{
    return next(new Error('missing auth from the handshake'));
  }
});

io.on('connection', socket => {
  socket.on('echo', message => {
    socket.emit('echo', message);
  });
  socket.on('disconnect', () => {
    console.log('SocketIO > Disconnected socket');
  });
});

server.listen(port, () => {
  console.log('Server listening at %d...', port);
});
