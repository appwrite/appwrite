// Webhooks API benchmark: a scenario for every /v1/webhooks endpoint of the
// run's project. Every webhook is created disabled and points at example.com
// (the URL must be a public domain), so the webhooks worker never delivers one.
// See README.md.

import { define, SEED, pick, unique } from './lib.js';

const URL = 'https://example.com/webhook';

function body(name) {
    return { name, url: URL, events: [pick(['users.*.create', 'users.*.update', 'buckets.*.files.*.create'])], enabled: false };
}

function webhook(api, name = 'Bench Webhook') {
    return api.prep('POST', '/webhooks', { webhookId: 'unique()', ...body(name) }, [201]).json('$id');
}

const bench = define({
    service: 'webhooks',
    scopes: ['webhooks.read', 'webhooks.write'],
    seed(api) {
        return { webhooks: Array.from({ length: Math.min(SEED, 50) }, (_, i) => webhook(api, `Seed Webhook ${i}`)) };
    },
    scenarios: [
        { name: 'webhooks.list', route: 'GET /v1/webhooks', weight: 10, run: (api) => api.call('GET', '/webhooks') },
        { name: 'webhooks.get', route: 'GET /v1/webhooks/:webhookId', weight: 10, run: (api, d) => api.call('GET', `/webhooks/${pick(d.webhooks)}`) },
        {
            name: 'webhooks.create', route: 'POST /v1/webhooks', weight: 2,
            run(api) {
                const res = api.call('POST', '/webhooks', { webhookId: 'unique()', ...body(unique('Webhook')) }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/webhooks/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'webhooks.update', route: 'PUT /v1/webhooks/:webhookId', weight: 3,
            run: (api, d) => api.call('PUT', `/webhooks/${pick(d.webhooks)}`, body(`Seed Webhook ${Math.floor(Math.random() * 1000)}`)),
        },
        { name: 'webhooks.delete', route: 'DELETE /v1/webhooks/:webhookId', weight: 2, run: (api) => api.call('DELETE', `/webhooks/${webhook(api)}`, null, [204]) },
        {
            name: 'webhooks.secret.update', route: 'PATCH /v1/webhooks/:webhookId/secret', weight: 2, note: 'generates a new secret',
            run: (api, d) => api.call('PATCH', `/webhooks/${pick(d.webhooks)}/secret`, {}),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
