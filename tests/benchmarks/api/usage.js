// Usage API benchmark: the two /v1/usage endpoints, read from the dev stack's
// ClickHouse for the run's project. See README.md.

import { define, pick } from './lib.js';

const EVENTS = ['network.requests', 'network.inbound', 'network.outbound', 'executions', 'users'];
const GAUGES = ['files.storage', 'users', 'databases', 'buckets'];

function query(params) {
    return params.map(([key, value]) => `${key}=${encodeURIComponent(value)}`).join('&');
}

const bench = define({
    service: 'usage',
    scopes: ['usage.read'],
    scenarios: [
        {
            name: 'usage.events.list', route: 'GET /v1/usage/events', weight: 3,
            note: 'one metric over the default window, aggregated per day',
            run: (api) => api.call('GET', `/usage/events?${query([['metrics[]', pick(EVENTS)], ['interval', '1d']])}`),
        },
        {
            name: 'usage.events.aggregate', route: 'GET /v1/usage/events',
            note: 'flat aggregate of one metric over the default window',
            run: (api) => api.call('GET', `/usage/events?${query([['metrics[]', pick(EVENTS)]])}`),
        },
        {
            name: 'usage.gauges.list', route: 'GET /v1/usage/gauges', weight: 3,
            note: 'one gauge over the default window, latest reading per day',
            run: (api) => api.call('GET', `/usage/gauges?${query([['metrics[]', pick(GAUGES)], ['interval', '1d']])}`),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
