// Health API benchmark: a scenario for every /v1/health endpoint, each reading
// the local service it checks (database, cache, pub/sub, queues, storage, geo,
// ClickHouse). See README.md.

import { define, pick } from './lib.js';

const QUEUES = [
    'v1-database', 'v1-deletes', 'v1-audits', 'v1-mails', 'v1-functions', 'v1-stats-resources', 'v1-stats-usage',
    'v1-webhooks', 'v1-certificates', 'v1-builds', 'v1-screenshots', 'v1-messaging', 'v1-migrations', 'v1-notifications',
];

const bench = define({
    service: 'health',
    scopes: ['health.read'],
    seed(api) {
        // `GET /v1/health/version` is public: a guest request of the project, without the key.
        return { guest: { 'Content-Type': 'application/json', 'X-Appwrite-Project': api.headers['X-Appwrite-Project'] } };
    },
    scenarios: [
        { name: 'health.get', route: 'GET /v1/health', weight: 10, run: (api) => api.call('GET', '/health') },
        { name: 'health.antiVirus.get', route: 'GET /v1/health/anti-virus', note: 'ClamAV is disabled in the dev stack', run: (api) => api.call('GET', '/health/anti-virus') },
        { name: 'health.cache.get', route: 'GET /v1/health/cache', weight: 3, run: (api) => api.call('GET', '/health/cache') },
        { name: 'health.db.get', route: 'GET /v1/health/db', weight: 3, run: (api) => api.call('GET', '/health/db') },
        { name: 'health.geo.get', route: 'GET /v1/health/geo', run: (api) => api.call('GET', '/health/geo') },
        { name: 'health.pubsub.get', route: 'GET /v1/health/pubsub', run: (api) => api.call('GET', '/health/pubsub') },
        {
            name: 'health.queue.failed.get', route: 'GET /v1/health/queue/failed/:name', weight: 3,
            run: (api) => api.call('GET', `/health/queue/failed/${pick(QUEUES)}?threshold=1000000`),
        },
        {
            name: 'health.stats.get', route: 'GET /v1/health/stats',
            note: 'measured on its unauthorized path: no role or API key carries the `root` scope it requires',
            run: (api) => api.call('GET', '/health/stats', null, [401]),
        },
        { name: 'health.storage.get', route: 'GET /v1/health/storage', run: (api) => api.call('GET', '/health/storage') },
        { name: 'health.storage.local.get', route: 'GET /v1/health/storage/local', run: (api) => api.call('GET', '/health/storage/local') },
        { name: 'health.usage.get', route: 'GET /v1/health/usage', run: (api) => api.call('GET', '/health/usage') },
        { name: 'health.version.get', route: 'GET /v1/health/version', weight: 3, run: (api, d) => api.call('GET', '/health/version', null, [200], d.guest) },
    ],
    skipped: [
        { skipped: 'GET /v1/health/time', reason: 'queries the production NTP server time.google.com' },
        { skipped: 'GET /v1/health/certificate', reason: 'accepts only public domains and opens a TLS connection to the production host on port 443' },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
