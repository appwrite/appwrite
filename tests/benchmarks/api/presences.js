// Presences API benchmark: a scenario for every /v1/presences endpoint, in a mix
// modelled on presence traffic (frequent heartbeats and reads). See README.md.
//
// A user has at most one presence: an upsert for a user who has one updates it.

import { define, SEED, pick, unique } from './lib.js';

const LIMIT = '?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}');

// Well past any run, within the API's 30-day limit.
function expiry() {
    return new Date(Date.now() + 7 * 24 * 3600 * 1000).toISOString();
}

// A new presence id: the route's UID takes no hyphens, nor `unique()`.
function id() {
    return unique('presence').replace(/-/g, '_');
}

function user(api, name) {
    return api.control('POST', '/users', { userId: 'unique()', name }, [201]).json('$id');
}

function presence(api, userId, status = 'online') {
    return api.prep('PUT', `/presences/${id()}`, { userId, status, expiresAt: expiry(), metadata: { device: 'bench' } }, [200]).json('$id');
}

const bench = define({
    service: 'presences',
    scopes: ['presences.read', 'presences.write', 'users.read', 'users.write'],
    seed(api) {
        const presences = [];
        for (let i = 0; i < SEED; i++) {
            const userId = user(api, `Bench Presence ${i}`);
            presences.push([userId, presence(api, userId)]);
        }
        return { presences };
    },
    scenarios: [
        { name: 'presences.list', route: 'GET /v1/presences', weight: 10, run: (api) => api.call('GET', `/presences${LIMIT}`) },
        {
            name: 'presences.list.cached', route: 'GET /v1/presences', weight: 4, note: 'with a 60-second list cache (ttl)',
            run: (api) => api.call('GET', `/presences${LIMIT}&ttl=60`),
        },
        { name: 'presences.get', route: 'GET /v1/presences/:presenceId', weight: 15, run: (api, d) => api.call('GET', `/presences/${pick(d.presences)[1]}`) },
        {
            name: 'presences.upsert', route: 'PUT /v1/presences/:presenceId', weight: 10, note: 'a heartbeat: updates the user\'s presence',
            run(api, d) {
                const [userId, presenceId] = pick(d.presences);
                api.call('PUT', `/presences/${presenceId}`, { userId, status: pick(['online', 'away', 'busy']), expiresAt: expiry(), metadata: { device: 'bench', n: Math.random() } });
            },
        },
        {
            name: 'presences.upsert.create', route: 'PUT /v1/presences/:presenceId', note: 'creates the presence of a user who has none',
            run(api) {
                const userId = user(api, 'Bench Presence New');
                const res = api.call('PUT', `/presences/${id()}`, { userId, status: 'online', expiresAt: expiry() });
                if (res.status === 200) {
                    api.prep('DELETE', `/presences/${res.json('$id')}`, null, [204]);
                }
                api.control('DELETE', `/users/${userId}`, null, [204]);
            },
        },
        {
            name: 'presences.update', route: 'PATCH /v1/presences/:presenceId', weight: 5,
            run: (api, d) => api.call('PATCH', `/presences/${pick(d.presences)[1]}`, { status: pick(['online', 'away', 'busy']), metadata: { device: 'bench', n: Math.random() } }),
        },
        {
            name: 'presences.delete', route: 'DELETE /v1/presences/:presenceId',
            run(api) {
                const userId = user(api, 'Bench Presence Gone');
                api.call('DELETE', `/presences/${presence(api, userId)}`, null, [204]);
                api.control('DELETE', `/users/${userId}`, null, [204]);
            },
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
