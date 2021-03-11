import http from 'k6/http';
import { sleep, check } from 'k6';
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
    const res = http.get('http://localhost:9501/v1/health/version?project=console');

    const checkRes = check(res, {
        'status is 200': (r) => r.status === 200,
        'response body': (r) => r.body.indexOf('0.7.0') !== -1,
    });
}