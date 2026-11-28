// Notifications API benchmark: a scenario for every /v1/notifications endpoint.
// See README.md.
//
// Notifications are console alerts for the signed-in console user (the
// benchmark's APPWRITE_ADMIN_EMAIL, who owns the project), so the list and the
// update run with the console session. The seed raises a real alert the way
// the platform does: a webhook pointing at an unreachable port of the dev
// stack's request catcher fails until the webhook worker pauses it and the
// notifications worker alerts the project's owners (a console alert and an
// email to the dev stack's mail catcher).

import { sleep } from 'k6';
import { define, pick, unique } from './lib.js';

const ATTEMPTS = 12; // past _APP_WEBHOOK_MAX_FAILED_ATTEMPTS (10 in the dev stack)
const WAIT = 120; // seconds for the workers to raise the alert

const bench = define({
    service: 'notifications',
    scopes: ['users.read', 'users.write', 'webhooks.read', 'webhooks.write'],
    seed(api, { admin, projectId }) {
        const name = unique('Failing Webhook');
        const webhook = api.control('POST', '/webhooks', {
            webhookId: 'unique()', name, events: ['users.*.create'], url: 'http://request-catcher-webhook:1/', tls: false,
        }, [201]).json('$id');
        const users = [];
        for (let i = 0; i < ATTEMPTS; i++) {
            users.push(api.control('POST', '/users', { userId: 'unique()', name: `Webhook Failure ${i}` }, [201]).json('$id'));
        }
        const query = '?queries[]=' + encodeURIComponent(JSON.stringify({ method: 'equal', attribute: 'projectId', values: [projectId] }));
        let notifications = [];
        for (let waited = 0; waited < WAIT && !notifications.length; waited += 2) {
            sleep(2);
            const res = api.control('GET', `/notifications${query}`, null, [200], admin);
            notifications = (res.json('notifications') || []).filter((n) => String(n.body || '').includes(name)).map((n) => n.$id);
        }
        api.control('DELETE', `/webhooks/${webhook}`, null, [204]);
        for (const id of users) {
            api.control('DELETE', `/users/${id}`, null, [204]);
        }
        if (!notifications.length) {
            throw new Error(`no console alert for webhook ${webhook} after ${WAIT}s: are the webhooks and notifications workers running?`);
        }
        return { admin, notifications };
    },
    scenarios: [
        {
            name: 'notifications.list', route: 'GET /v1/notifications', weight: 10,
            run: (api, d) => api.call('GET', '/notifications?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}'), null, [200], d.admin),
        },
        {
            name: 'notifications.update', route: 'PATCH /v1/notifications/:notificationId', weight: 3, note: 'marks a console alert read or unread',
            run: (api, d) => api.call('PATCH', `/notifications/${pick(d.notifications)}`, { read: pick([true, false]) }, [200], d.admin),
        },
        {
            name: 'notifications.logos.get', route: 'GET /v1/notifications/logos/appwrite', weight: 5, note: 'without a tracking token: serves the SVG',
            run: (api) => api.call('GET', `/notifications/logos/appwrite?theme=${pick(['system', 'light', 'dark'])}`, null, [200], {}),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
