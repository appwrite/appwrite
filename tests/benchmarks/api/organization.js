// Organization API benchmark: a scenario for every /v1/organization endpoint,
// called with the console session of APPWRITE_ADMIN_EMAIL on behalf of the
// organization it owns (X-Appwrite-Organization). Projects and keys a scenario
// creates are deleted after it; reads and updates act on the run's project.
// See README.md.

import { define, pick, unique } from './lib.js';

const SCOPES = ['users.read', 'teams.read', 'databases.read', 'files.read', 'functions.read'];

function scopes() {
    return SCOPES.filter(() => Math.random() < 0.6).concat('users.read').filter((s, i, all) => all.indexOf(s) === i);
}

const bench = define({
    service: 'organization',
    scopes: [],
    seed(api, { admin, projectId, run }) {
        const teamId = api.control('GET', `/projects/${projectId}`, null, [200], admin).json('teamId');
        const org = { ...admin, 'X-Appwrite-Organization': teamId };
        const keys = [];
        for (let i = 0; i < 10; i++) {
            keys.push(api.control('POST', `/organization/projects/${projectId}/keys`, { keyId: 'unique()', name: `Seed Key ${i}`, scopes: SCOPES }, [201], org).json('$id'));
        }
        return { org, projectId, name: run, keys };
    },
    scenarios: [
        { name: 'organization.projects.list', route: 'GET /v1/organization/projects', weight: 8, run: (api, d) => api.call('GET', '/organization/projects', null, [200], d.org) },
        { name: 'organization.projects.get', route: 'GET /v1/organization/projects/:projectId', weight: 8, run: (api, d) => api.call('GET', `/organization/projects/${d.projectId}`, null, [200], d.org) },
        {
            name: 'organization.projects.create', route: 'POST /v1/organization/projects', note: 'provisions the project\'s database schema',
            run(api, d) {
                const res = api.call('POST', '/organization/projects', { projectId: unique('bench'), name: 'Bench Project' }, [201], d.org);
                if (res.status === 201) {
                    api.prep('DELETE', `/organization/projects/${res.json('$id')}`, null, [204], d.org);
                }
            },
        },
        {
            name: 'organization.projects.update', route: 'PATCH /v1/organization/projects/:projectId', weight: 2, note: 'renames the run\'s project to its own name',
            run: (api, d) => api.call('PATCH', `/organization/projects/${d.projectId}`, { name: d.name }, [200], d.org),
        },
        {
            name: 'organization.projects.delete', route: 'DELETE /v1/organization/projects/:projectId',
            run(api, d) {
                const id = api.prep('POST', '/organization/projects', { projectId: unique('bench'), name: 'Bench Project' }, [201], d.org).json('$id');
                api.call('DELETE', `/organization/projects/${id}`, null, [204], d.org);
            },
        },
        { name: 'organization.projects.keys.list', route: 'GET /v1/organization/projects/:projectId/keys', weight: 5, run: (api, d) => api.call('GET', `/organization/projects/${d.projectId}/keys`, null, [200], d.org) },
        {
            name: 'organization.projects.keys.get', route: 'GET /v1/organization/projects/:projectId/keys/:keyId', weight: 5,
            run: (api, d) => api.call('GET', `/organization/projects/${d.projectId}/keys/${pick(d.keys)}`, null, [200], d.org),
        },
        {
            name: 'organization.projects.keys.create', route: 'POST /v1/organization/projects/:projectId/keys', weight: 2,
            run(api, d) {
                const res = api.call('POST', `/organization/projects/${d.projectId}/keys`, { keyId: 'unique()', name: unique('Key'), scopes: scopes() }, [201], d.org);
                if (res.status === 201) {
                    api.prep('DELETE', `/organization/projects/${d.projectId}/keys/${res.json('$id')}`, null, [204], d.org);
                }
            },
        },
        {
            name: 'organization.projects.keys.update', route: 'PUT /v1/organization/projects/:projectId/keys/:keyId', weight: 2,
            run(api, d) {
                const id = pick(d.keys);
                api.call('PUT', `/organization/projects/${d.projectId}/keys/${id}`, { name: `Seed Key ${Math.floor(Math.random() * 1000)}`, scopes: SCOPES, expire: null }, [200], d.org);
            },
        },
        {
            name: 'organization.projects.keys.delete', route: 'DELETE /v1/organization/projects/:projectId/keys/:keyId', weight: 2,
            run(api, d) {
                const id = api.prep('POST', `/organization/projects/${d.projectId}/keys`, { keyId: 'unique()', name: unique('Key'), scopes: SCOPES }, [201], d.org).json('$id');
                api.call('DELETE', `/organization/projects/${d.projectId}/keys/${id}`, null, [204], d.org);
            },
        },
        {
            name: 'organization.projects.keys.ephemeral.create', route: 'POST /v1/organization/projects/:projectId/keys/ephemeral', weight: 3, note: 'a signed JWT, nothing stored',
            run: (api, d) => api.call('POST', `/organization/projects/${d.projectId}/keys/ephemeral`, { scopes: scopes(), duration: 600 }, [201], d.org),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
