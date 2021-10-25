// k6 run tests/benchmarks/ws.js

import { URL } from 'https://jslib.k6.io/url/1.0.0/index.js';
import ws from 'k6/ws';
import { check } from 'k6';

export let options = {
    stages: [
        {
            duration: '10s',
            target: 500
        },
        {
            duration: '1m',
            target: 500
        },
    ],
}

export default function () {
    // const url = new URL('wss://appwrite-realtime.monitor-api.com/v1/realtime');
    // url.searchParams.append('project', '604249e6b1a9f');
    const url = new URL('ws://localhost/v1/realtime');
    url.searchParams.append('project', '612625394933c');
    url.searchParams.append('channels[]', 'files');

    const res = ws.connect(url.toString(), function (socket) {
        let connection = false;
        let checked = false;
        let payload = null;
        socket.on('open', () => {
            connection = true;
        });

        socket.on('message', (data) => {
            payload = data;
            checked = true;
        });

        socket.setTimeout(function () {
            check(payload, {
                'connection opened': (r) => connection,
                'message received': (r) => checked,
                'channels are right': (r) => r === JSON.stringify({
                    "type": "connected",
                    "data": {
                        "channels": [
                            "files"
                        ],
                        "user": null
                    }
                })
            })
            socket.close();
        }, 5000);
    });

    check(res, { 'status is 101': (r) => r && r.status === 101 });
}