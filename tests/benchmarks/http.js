import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

// A simple counter for http requests
export const requests = new Counter('http_reqs');

// you can specify stages of your test (ramp up/down patterns) through the options object
// target is the number of VUs you are aiming for

export const options = {
    stages: [
    { target: 50, duration: '1m' },
    // { target: 15, duration: '1m' },
    // { target: 0, duration: '1m' },
    ],
    thresholds: {
        requests: ['count < 100'],
    },
};

export default function () {
    const config = {
        headers: {
            'X-Appwrite-Key': '24356eb021863f81eb7dd77c7750304d0464e141cad6e9a8befa1f7d2b066fde190df3dab1e8d2639dbb82ee848da30501424923f4cd80d887ee40ad77ded62763ee489448523f6e39667f290f9a54b2ab8fad131a0bc985e6c0f760015f7f3411e40626c75646bb19d2bb2f7bf2f63130918220a206758cbc48845fd725a695',
            'X-Appwrite-Project': '60479fe35d95d'
        }}

    const resDb = http.get('http://localhost:9501/', config);

    check(resDb, {
        'status is 200': (r) => r.status === 200,
    });
}