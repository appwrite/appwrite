// k6: one preview request shape, closed loop (VUS) or open loop (RATE per second).
import http from 'k6/http';
import { check } from 'k6';

const scenario = __ENV.RATE
    ? { executor: 'constant-arrival-rate', rate: Number(__ENV.RATE), timeUnit: '1s', duration: __ENV.DURATION, preAllocatedVUs: 64, maxVUs: 256 }
    : { executor: 'constant-vus', vus: Number(__ENV.VUS), duration: __ENV.DURATION };

export const options = { scenarios: { preview: scenario }, summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'] };

export default function () {
    const response = http.get(__ENV.URL, { responseType: 'binary' });
    check(response, { ok: (r) => r.status === 200 && r.body.byteLength > 0 });
}

export function handleSummary(data) {
    const duration = data.metrics.http_req_duration.values;
    return {
        stdout: JSON.stringify({
            requests: data.metrics.http_reqs.values.count,
            rate: data.metrics.http_reqs.values.rate,
            failed: data.metrics.http_req_failed.values.passes,
            dropped: data.metrics.dropped_iterations ? data.metrics.dropped_iterations.values.count : 0,
            bytes: data.metrics.data_received.values.count,
            med: duration.med, p95: duration['p(95)'], p99: duration['p(99)'], max: duration.max,
        }),
    };
}
