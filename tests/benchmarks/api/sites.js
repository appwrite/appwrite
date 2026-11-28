// Sites API benchmark: a scenario for every /v1/sites endpoint, in a mix
// modelled on server-side Sites traffic (reads dominate). See README.md.
//
// Deployments upload a code archive built here (a tar.gz of one index.html),
// so the script needs no generated fixture. Builds run in the dev stack's
// executor: setup builds one deployment and waits for it, and the scenarios
// that upload cancel and delete what they create. Request logs come from
// requests to the site's domain, sent through the PHP API's router.

import http from 'k6/http';
import { sleep } from 'k6';
import { CONTROL, define, SEED, pick, unique, random } from './lib.js';
import { archive } from './support/archive.js';

const RUNTIME = __ENV.APPWRITE_BENCHMARK_RUNTIME || 'node-22';
const SITES_DOMAIN = __ENV.APPWRITE_BENCHMARK_SITES_DOMAIN || 'sites.localhost';
const HTML = '<!doctype html><html><head><title>Bench</title></head><body><h1>Bench</h1></body></html>\n';
const ARCHIVE = archive({ 'index.html': HTML });
const SITE = { framework: 'other', buildRuntime: RUNTIME, adapter: 'static', outputDirectory: './' };

const NO_INSTALLATION = 'measured on its not-found path: a VCS deployment needs a Git installation, which only a provider (GitHub) can create';

function code() {
    return http.file(ARCHIVE.buffer, 'code.tar.gz', 'application/gzip');
}

function site(api, name) {
    return api.prep('POST', '/sites', { siteId: 'unique()', name, ...SITE }, [201]).json('$id');
}

/** Uploads a deployment of `siteId` (unmeasured) and returns its id. */
function upload(api, siteId, activate = false) {
    return api.form('POST', `/sites/${siteId}/deployments`, { code: code(), activate: String(activate) }, [202], { measure: false }).json('$id');
}

/** Cancels a deployment's build (if it is still running) and deletes it. */
function discard(api, siteId, deploymentId) {
    api.prep('PATCH', `/sites/${siteId}/deployments/${deploymentId}/status`, null, [200, 400]);
    api.prep('DELETE', `/sites/${siteId}/deployments/${deploymentId}`, null, [204]);
}

function ready(api, siteId, deploymentId) {
    for (let i = 0; i < 300; i++) {
        const status = api.prep('GET', `/sites/${siteId}/deployments/${deploymentId}`, null, [200]).json('status');
        if (status === 'ready') {
            return;
        }
        if (status === 'failed' || status === 'canceled') {
            throw new Error(`deployment ${deploymentId} of ${siteId}: ${status}`);
        }
        sleep(1);
    }
    throw new Error(`deployment ${deploymentId} of ${siteId} not ready after 300s`);
}

/**
 * A request to the site's domain (unmeasured), through the PHP API's router,
 * which records it as a log; returns the log's id once it is stored (the API
 * answers 404 for a log until the executions worker stores it).
 */
function visit(api, siteId, domain) {
    const res = http.get(`${CONTROL.replace(/\/v1$/, '')}/`, { headers: { Host: domain }, tags: { name: 'prep' } });
    const id = api.expect(res, [200], `${api.name} visit ${domain}`).headers['X-Appwrite-Log-Id'];
    if (!id) {
        throw new Error(`no log id from ${domain}: ${res.status}`);
    }
    for (let i = 0; i < 100; i++) {
        if (api.prep('GET', `/sites/${siteId}/logs/${id}`, null, [200, 404]).status === 200) {
            return id;
        }
        sleep(0.1);
    }
    throw new Error(`log ${id} of ${siteId} not stored after 10s`);
}

const bench = define({
    service: 'sites',
    scopes: ['sites.read', 'sites.write', 'log.read', 'log.write', 'rules.read', 'rules.write'],
    seed(api) {
        const sites = [];
        for (let i = 0; i < Math.min(SEED, 50); i++) {
            sites.push(site(api, `Seed Site ${i}`));
        }
        // One site with a ready, active deployment and a domain: downloads, activation, logs.
        const live = site(api, 'Live Site');
        const deployment = upload(api, live, true);
        ready(api, live, deployment);
        for (let i = 0; i < 60 && api.prep('GET', `/sites/${live}`, null, [200]).json('deploymentId') !== deployment; i++) {
            sleep(1);
        }
        const domain = api.control('POST', '/proxy/rules/site', { domain: `${unique('live')}.${SITES_DOMAIN}`, siteId: live }, [201]).json('domain');
        const logs = Array.from({ length: 20 }, () => visit(api, live, domain));
        // Deployments created by the scenarios build on their own site, so
        // canceling and deleting them never touches the live one.
        const scratch = site(api, 'Scratch Site');
        const source = upload(api, scratch);
        ready(api, scratch, source);
        const variables = Array.from({ length: 20 }, (_, i) => api.prep('POST', `/sites/${live}/variables`, {
            variableId: 'unique()', key: `SEED_${i}`, value: `value-${i}`, secret: false,
        }, [201]).json('$id'));
        // Templates and frameworks are public: an API key has no `public` scope.
        const guest = { 'Content-Type': 'application/json', 'X-Appwrite-Project': api.headers['X-Appwrite-Project'] };
        const template = api.prep('GET', '/sites/templates?limit=1', null, [200], guest).json('templates.0.key');
        return { sites, live, deployment, domain, logs, scratch, source, variables, template, guest };
    },
    scenarios: [
        { name: 'sites.get', route: 'GET /v1/sites/:siteId', weight: 10, run: (api, d) => api.call('GET', `/sites/${pick(d.sites)}`) },
        {
            name: 'sites.list', route: 'GET /v1/sites', weight: 6,
            run: (api) => api.call('GET', '/sites?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}')),
        },
        { name: 'sites.search', route: 'GET /v1/sites', weight: 2, note: 'full-text search', run: (api) => api.call('GET', `/sites?search=Seed+Site+${Math.floor(random() * 50)}`) },
        {
            name: 'sites.create', route: 'POST /v1/sites', weight: 2,
            run(api) {
                const res = api.call('POST', '/sites', { siteId: 'unique()', name: 'New Site', ...SITE }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/sites/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'sites.update', route: 'PUT /v1/sites/:siteId', weight: 2,
            run: (api, d) => api.call('PUT', `/sites/${pick(d.sites)}`, { name: `Seed Site ${Math.floor(random() * 1000)}`, ...SITE }),
        },
        { name: 'sites.delete', route: 'DELETE /v1/sites/:siteId', run: (api) => api.call('DELETE', `/sites/${site(api, 'Gone')}`, null, [204]) },
        {
            name: 'sites.deployment.update', route: 'PATCH /v1/sites/:siteId/deployment',
            run: (api, d) => api.call('PATCH', `/sites/${d.live}/deployment`, { deploymentId: d.deployment }),
        },
        { name: 'sites.deployments.list', route: 'GET /v1/sites/:siteId/deployments', weight: 3, run: (api, d) => api.call('GET', `/sites/${d.live}/deployments`) },
        {
            name: 'sites.deployments.create', route: 'POST /v1/sites/:siteId/deployments',
            note: 'uploads a 1 KB archive in one request; queues a build in the executor',
            run(api, d) {
                const res = api.form('POST', `/sites/${d.scratch}/deployments`, { code: code(), activate: 'false' }, [202]);
                if (res.status === 202) {
                    discard(api, d.scratch, res.json('$id'));
                }
            },
        },
        { name: 'sites.deployments.get', route: 'GET /v1/sites/:siteId/deployments/:deploymentId', weight: 4, run: (api, d) => api.call('GET', `/sites/${d.live}/deployments/${d.deployment}`) },
        {
            name: 'sites.deployments.delete', route: 'DELETE /v1/sites/:siteId/deployments/:deploymentId',
            run(api, d) {
                const id = upload(api, d.scratch);
                api.prep('PATCH', `/sites/${d.scratch}/deployments/${id}/status`, null, [200, 400]);
                api.call('DELETE', `/sites/${d.scratch}/deployments/${id}`, null, [204]);
            },
        },
        {
            name: 'sites.deployments.download', route: 'GET /v1/sites/:siteId/deployments/:deploymentId/download',
            run: (api, d) => api.call('GET', `/sites/${d.live}/deployments/${d.deployment}/download`),
        },
        {
            name: 'sites.deployments.status.update', route: 'PATCH /v1/sites/:siteId/deployments/:deploymentId/status',
            note: 'cancels a build that was just queued',
            run(api, d) {
                const id = upload(api, d.scratch);
                api.call('PATCH', `/sites/${d.scratch}/deployments/${id}/status`);
                api.prep('DELETE', `/sites/${d.scratch}/deployments/${id}`, null, [204]);
            },
        },
        {
            name: 'sites.deployments.duplicate.create', route: 'POST /v1/sites/:siteId/deployments/duplicate',
            note: 'copies the source and queues a build in the executor',
            run(api, d) {
                const res = api.call('POST', `/sites/${d.scratch}/deployments/duplicate`, { deploymentId: d.source }, [202]);
                if (res.status === 202) {
                    discard(api, d.scratch, res.json('$id'));
                }
            },
        },
        {
            name: 'sites.deployments.vcs.create', route: 'POST /v1/sites/:siteId/deployments/vcs', note: NO_INSTALLATION,
            run: (api, d) => api.call('POST', `/sites/${pick(d.sites)}/deployments/vcs`, { type: 'branch', reference: 'main' }, [404]),
        },
        {
            name: 'sites.logs.list', route: 'GET /v1/sites/:siteId/logs', weight: 3,
            run: (api, d) => api.call('GET', `/sites/${d.live}/logs?queries[]=` + encodeURIComponent('{"method":"limit","values":[25]}')),
        },
        { name: 'sites.logs.get', route: 'GET /v1/sites/:siteId/logs/:logId', weight: 3, run: (api, d) => api.call('GET', `/sites/${d.live}/logs/${pick(d.logs)}`) },
        {
            name: 'sites.logs.delete', route: 'DELETE /v1/sites/:siteId/logs/:logId',
            run: (api, d) => api.call('DELETE', `/sites/${d.live}/logs/${visit(api, d.live, d.domain)}`, null, [204]),
        },
        { name: 'sites.variables.list', route: 'GET /v1/sites/:siteId/variables', weight: 2, run: (api, d) => api.call('GET', `/sites/${d.live}/variables`) },
        {
            name: 'sites.variables.create', route: 'POST /v1/sites/:siteId/variables',
            run(api, d) {
                const res = api.call('POST', `/sites/${d.live}/variables`, { variableId: 'unique()', key: `NEW_${unique('v').replaceAll('-', '_')}`, value: 'value', secret: false }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/sites/${d.live}/variables/${res.json('$id')}`, null, [204]);
                }
            },
        },
        { name: 'sites.variables.get', route: 'GET /v1/sites/:siteId/variables/:variableId', weight: 2, run: (api, d) => api.call('GET', `/sites/${d.live}/variables/${pick(d.variables)}`) },
        {
            name: 'sites.variables.update', route: 'PUT /v1/sites/:siteId/variables/:variableId',
            run: (api, d) => api.call('PUT', `/sites/${d.live}/variables/${pick(d.variables)}`, { value: `value-${Math.floor(random() * 1000)}` }),
        },
        {
            name: 'sites.variables.delete', route: 'DELETE /v1/sites/:siteId/variables/:variableId',
            run(api, d) {
                const id = api.prep('POST', `/sites/${d.live}/variables`, { variableId: 'unique()', key: `GONE_${unique('v').replaceAll('-', '_')}`, value: 'value' }, [201]).json('$id');
                api.call('DELETE', `/sites/${d.live}/variables/${id}`, null, [204]);
            },
        },
        { name: 'sites.frameworks.list', route: 'GET /v1/sites/frameworks', run: (api, d) => api.call('GET', '/sites/frameworks', null, [200], d.guest) },
        { name: 'sites.specifications.list', route: 'GET /v1/sites/specifications', run: (api) => api.call('GET', '/sites/specifications') },
        { name: 'sites.templates.list', route: 'GET /v1/sites/templates', run: (api, d) => api.call('GET', '/sites/templates?limit=25', null, [200], d.guest) },
        { name: 'sites.templates.get', route: 'GET /v1/sites/templates/:templateId', run: (api, d) => api.call('GET', `/sites/templates/${d.template}`, null, [200], d.guest) },
    ],
    skipped: [
        {
            skipped: 'POST /v1/sites/:siteId/deployments/template',
            reason: 'queues a build that downloads the template source from GitHub (codeload.github.com)',
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
