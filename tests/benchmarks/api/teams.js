// Teams API benchmark: a scenario for every /v1/teams endpoint, in a mix
// modelled on server-side Teams traffic (reads dominate). See README.md.
//
// Memberships created with the API key are confirmed at once. The membership
// status route needs an invitation's secret, which only the invitation email
// carries: its scenario invites an address as a team owner (a client session),
// reads the email from the dev stack's mail catcher (MailDev), and accepts it.
//
//   APPWRITE_MAILDEV_ENDPOINT  MailDev's web API (default http://localhost:9503)

import http from 'k6/http';
import { sleep } from 'k6';
import { SEED, define, pick, unique } from './lib.js';

const MAILDEV = __ENV.APPWRITE_MAILDEV_ENDPOINT || 'http://localhost:9503';
const ROLES = ['member', 'admin', 'editor', 'viewer'];

function user(api, extra = {}) {
    return api.control('POST', '/users', { userId: 'unique()', name: 'Bench Member', ...extra }, [201]).json('$id');
}

function dropUser(api, id) {
    api.control('DELETE', `/users/${id}`, null, [204]);
}

function team(api, name = 'Bench Team') {
    return api.prep('POST', '/teams', { teamId: 'unique()', name }, [201]).json('$id');
}

function membership(api, teamId, userId) {
    return api.prep('POST', `/teams/${teamId}/memberships`, { userId, roles: ['member'] }, [201]).json('$id');
}

/** The secret of the invitation email sent to `email`, polled from MailDev; the email is then deleted. */
function invitation(api, email) {
    for (let i = 0; i < 60; i++) {
        const res = http.get(`${MAILDEV}/email?to.address=${encodeURIComponent(email)}`, { tags: { name: 'prep' } });
        const emails = res.status === 200 ? res.json() : [];
        if (emails.length) {
            const html = emails[0].html || '';
            const start = html.indexOf('secret=');
            http.del(`${MAILDEV}/email/${emails[0].id}`, null, { tags: { name: 'prep' } });
            if (start === -1) {
                break;
            }
            let end = start + 'secret='.length;
            while (end < html.length && !'&"\' <'.includes(html[end])) {
                end++;
            }
            return decodeURIComponent(html.slice(start + 'secret='.length, end));
        }
        sleep(0.25);
    }
    api.expect({ status: 0, body: `no invitation email for ${email} in ${MAILDEV}` }, [200], `${api.name} prep invitation email`);
    return '';
}

const bench = define({
    service: 'teams',
    scopes: ['teams.read', 'teams.write', 'users.read', 'users.write'],
    seed(api) {
        const users = Array.from({ length: Math.max(SEED, 20) }, () => user(api));
        const teams = [];
        for (let i = 0; i < SEED; i++) {
            teams.push(team(api, `Seed Team ${i}`));
            api.prep('PUT', `/teams/${teams[i]}/prefs`, { prefs: { plan: 'free', seats: i } }, [200]);
        }
        // Memberships to read and update: each seeded user in one team.
        const memberships = users.map((id, i) => [teams[i % teams.length], membership(api, teams[i % teams.length], id)]);
        // A team owned by a client session, which sends invitations by email.
        const owner = user(api, { email: `${unique('owner')}@example.com` });
        const secret = api.control('POST', `/users/${owner}/sessions`, null, [201]).json('secret');
        const invites = api.prep('POST', '/teams', { teamId: 'unique()', name: 'Invites' }, [201], api.session(secret)).json('$id');
        return { teams, memberships, owner: { secret, team: invites } };
    },
    scenarios: [
        { name: 'teams.get', route: 'GET /v1/teams/:teamId', weight: 20, run: (api, d) => api.call('GET', `/teams/${pick(d.teams)}`) },
        {
            name: 'teams.list', route: 'GET /v1/teams', weight: 8,
            run: (api) => api.call('GET', '/teams?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}')),
        },
        {
            name: 'teams.search', route: 'GET /v1/teams', weight: 3, note: 'full-text search',
            run: (api) => api.call('GET', `/teams?search=Seed+Team+${Math.floor(Math.random() * 100)}`),
        },
        {
            name: 'teams.create', route: 'POST /v1/teams', weight: 2,
            run(api) {
                const res = api.call('POST', '/teams', { teamId: 'unique()', name: 'New Team' }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/teams/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'teams.name.update', route: 'PUT /v1/teams/:teamId', weight: 3,
            run: (api, d) => api.call('PUT', `/teams/${pick(d.teams)}`, { name: `Seed Team ${Math.floor(Math.random() * 1000)}` }),
        },
        { name: 'teams.delete', route: 'DELETE /v1/teams/:teamId', run: (api) => api.call('DELETE', `/teams/${team(api, 'Gone')}`, null, [204]) },
        { name: 'teams.prefs.get', route: 'GET /v1/teams/:teamId/prefs', weight: 6, run: (api, d) => api.call('GET', `/teams/${pick(d.teams)}/prefs`) },
        {
            name: 'teams.prefs.update', route: 'PUT /v1/teams/:teamId/prefs', weight: 3,
            run: (api, d) => api.call('PUT', `/teams/${pick(d.teams)}/prefs`, { prefs: { plan: pick(['free', 'pro']), seats: Math.floor(Math.random() * 100) } }),
        },
        {
            name: 'teams.memberships.list', route: 'GET /v1/teams/:teamId/memberships', weight: 10,
            run: (api, d) => api.call('GET', `/teams/${pick(d.teams)}/memberships`),
        },
        {
            name: 'teams.memberships.get', route: 'GET /v1/teams/:teamId/memberships/:membershipId', weight: 10,
            run(api, d) {
                const [teamId, id] = pick(d.memberships);
                api.call('GET', `/teams/${teamId}/memberships/${id}`);
            },
        },
        {
            name: 'teams.memberships.create', route: 'POST /v1/teams/:teamId/memberships', weight: 2,
            note: 'with an API key and a user id: confirmed at once, no invitation email',
            run(api, d) {
                const teamId = pick(d.teams);
                const id = user(api);
                const res = api.call('POST', `/teams/${teamId}/memberships`, { userId: id, roles: ['member'] }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/teams/${teamId}/memberships/${res.json('$id')}`, null, [204]);
                }
                dropUser(api, id);
            },
        },
        {
            name: 'teams.memberships.update', route: 'PATCH /v1/teams/:teamId/memberships/:membershipId', weight: 3,
            run(api, d) {
                const [teamId, id] = pick(d.memberships);
                api.call('PATCH', `/teams/${teamId}/memberships/${id}`, { roles: ['member', pick(ROLES)] });
            },
        },
        {
            name: 'teams.memberships.delete', route: 'DELETE /v1/teams/:teamId/memberships/:membershipId',
            run(api, d) {
                const teamId = pick(d.teams);
                const id = user(api);
                api.call('DELETE', `/teams/${teamId}/memberships/${membership(api, teamId, id)}`, null, [204]);
                dropUser(api, id);
            },
        },
        {
            name: 'teams.memberships.status.update', route: 'PATCH /v1/teams/:teamId/memberships/:membershipId/status',
            note: 'accepts an invitation (read from the mail catcher) and creates the invitee\'s session',
            run(api, d) {
                const email = `${unique('invitee')}@example.com`;
                const invite = api.prep('POST', `/teams/${d.owner.team}/memberships`, {
                    email, roles: ['member'], url: 'http://localhost/join-us',
                }, [201], api.session(d.owner.secret));
                if (invite.status !== 201) {
                    return;
                }
                const secret = invitation(api, email);
                const userId = invite.json('userId');
                if (secret) {
                    const client = { 'Content-Type': 'application/json', 'X-Appwrite-Project': api.headers['X-Appwrite-Project'] };
                    api.call('PATCH', `/teams/${d.owner.team}/memberships/${invite.json('$id')}/status`, { userId, secret }, [200], client);
                }
                dropUser(api, userId);
            },
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
