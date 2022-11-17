import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

// A simple counter for http requests
export const requests = new Counter('http_reqs');

// you can specify stages of your test (ramp up/down patterns) through the options object
// target is the number of VUs you are aiming for

export const options = {
    stages: [
      { target: 150, duration: '1m' },
      { target: 150, duration: '1m' },
      { target: 0, duration: '1m' },
    ]
    // thresholds: {
    //     requests: ['count < 100'],
    // },
};

// testing (63766a35b47c0dae6b5a) is any/all

export default function () {
    const config = {
        headers: {
            // 'X-Appwrite-Key': '62115c397d1f1644730ff5df69f7fec6daa898c11e41f314fd6e210cac536a2fbcefa96ca9e5c7b4e2fcb7a535f1e8fc16c27829c1ccab3d6a1726e31d561cdaf8dc64c63e0e9ad0f406a8f9703edfbbf0f9ac6a9271d573b2ea08805220dbede08db1c8e6cf792582ef8452b82bcd96e9890fb79109d05b6fda1b1673d35292',
            'X-Appwrite-Project': '637666b7f1733a4b00a8'
        }
      }

    const resDb = http.get('http://localhost:9501/v1/databases/benchmarking/collections/63766a35b47c0dae6b5a/documents', config);

    check(resDb, {
        'status is 200': (r) => r.status === 200,
        'total': (r) => JSON.parse(r.body).documents.length === 15
    });
}