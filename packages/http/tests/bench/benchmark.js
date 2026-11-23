import http from 'k6/http';
import { check } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:9501';
const VUS = Number(__ENV.VUS || 200);
const WARMUP = __ENV.WARMUP || '10s';
const DURATION = __ENV.DURATION || '60s';

export const options = {
    scenarios: {
        warmup: {
            executor: 'constant-vus',
            vus: Math.max(1, Math.round(VUS / 4)),
            duration: WARMUP,
            gracefulStop: '0s',
            tags: { phase: 'warmup' },
            exec: 'hit',
        },
        load: {
            executor: 'constant-vus',
            vus: VUS,
            duration: DURATION,
            startTime: WARMUP,
            gracefulStop: '5s',
            tags: { phase: 'load' },
            exec: 'hit',
        },
    },
    thresholds: {
        'http_req_failed{phase:load}': ['rate<0.01'],
    },
};

export function hit() {
    const res = http.get(`${BASE_URL}/work`, { tags: { route: '/work' } });
    check(res, { 'status is 2xx': (r) => r.status >= 200 && r.status < 300 });
}
