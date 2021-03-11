// k6 run tests/benchmarks/ws.js

import { URL } from 'https://jslib.k6.io/url/1.0.0/index.js';
import ws from 'k6/ws';
import { check } from 'k6';

export let options = {
    stages: [
        { duration: '20s', target: 10 },
        { duration: '20s', target: 100 },
        { duration: '20s', target: 0 },
    ],
}

export default function () {
    const url = new URL('ws://localhost/v1/realtime');
    url.searchParams.append('project', '60479391b1c3f');
    url.searchParams.append('channels[]', 'files');
    
    const res = ws.connect(url.toString(), function (socket) {
        socket.on('open', () => {
            console.log('connected')
            });
        
        socket.on('message', (data) => {
            console.log('Message received: ', data)
        });

        socket.on('close', () => console.log('disconnected'));
        
        socket.setTimeout(function () {
            console.log('2 seconds passed, closing the socket');
            socket.close();
        }, 2000);
    });

    check(res, { 'status is 101': (r) => r && r.status === 101 });
}