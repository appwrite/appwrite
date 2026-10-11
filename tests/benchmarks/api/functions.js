// Functions API benchmark: a scenario for every /v1/functions endpoint (and the
// open-runtimes jobs callback), in a mix modelled on server-side Functions
// traffic (reads and executions dominate). See README.md.
//
// Deployments upload a code archive built here (a tar.gz of one index.js), so
// the script needs no generated fixture. Builds and executions run in the dev
// stack's executor: setup builds one deployment and waits for it, and the
// scenarios that upload cancel and delete what they create.

import http from 'k6/http';
import { sleep } from 'k6';
import { define, SEED, pick, unique } from './lib.js';
import { archive } from './support/archive.js';

const RUNTIME = __ENV.APPWRITE_BENCHMARK_RUNTIME || 'node-22';
const CODE = 'module.exports = async (context) => context.res.json({ ok: true, path: context.req.path });\n';
const ARCHIVE = archive({ 'index.js': CODE });

const NO_INSTALLATION = 'measured on its not-found path: a VCS deployment needs a Git installation, which only a provider (GitHub) can create';

function code() {
    return http.file(ARCHIVE.buffer, 'code.tar.gz', 'application/gzip');
}

function fn(api, name, extra = {}) {
    return api.prep('POST', '/functions', { functionId: 'unique()', name, runtime: RUNTIME, entrypoint: 'index.js', execute: ['any'], ...extra }, [201]).json('$id');
}

/** Uploads a deployment of `functionId` (unmeasured) and returns its id. */
function upload(api, functionId, activate = false) {
    return api.form('POST', `/functions/${functionId}/deployments`, { code: code(), activate: String(activate) }, [202], { measure: false }).json('$id');
}

/** Cancels a deployment's build (if it is still running) and deletes it. */
function discard(api, functionId, deploymentId) {
    api.prep('PATCH', `/functions/${functionId}/deployments/${deploymentId}/status`, null, [200, 400]);
    api.prep('DELETE', `/functions/${functionId}/deployments/${deploymentId}`, null, [204]);
}

function ready(api, functionId, deploymentId) {
    for (let i = 0; i < 300; i++) {
        const status = api.prep('GET', `/functions/${functionId}/deployments/${deploymentId}`, null, [200]).json('status');
        if (status === 'ready') {
            return;
        }
        if (status === 'failed' || status === 'canceled') {
            throw new Error(`deployment ${deploymentId} of ${functionId}: ${status}`);
        }
        sleep(1);
    }
    throw new Error(`deployment ${deploymentId} of ${functionId} not ready after 300s`);
}

/**
 * Waits until the executions worker has stored a finished execution: the API
 * answers 404 for an execution until its document lands.
 */
function settle(api, functionId, executionId) {
    for (let i = 0; i < 100; i++) {
        const res = api.prep('GET', `/functions/${functionId}/executions/${executionId}`, null, [200, 404]);
        if (res.status === 200 && ['completed', 'failed'].includes(res.json('status'))) {
            return executionId;
        }
        sleep(0.1);
    }
    throw new Error(`execution ${executionId} of ${functionId} not stored after 10s`);
}

/** A finished synchronous execution (unmeasured). */
function execute(api, functionId) {
    const id = api.prep('POST', `/functions/${functionId}/executions`, { async: false, path: '/', method: 'GET' }, [201]).json('$id');
    return settle(api, functionId, id);
}

function forget(api, functionId, executionId) {
    settle(api, functionId, executionId);
    api.prep('DELETE', `/functions/${functionId}/executions/${executionId}`, null, [204]);
}

const bench = define({
    service: 'functions',
    scopes: ['functions.read', 'functions.write', 'executions.read', 'executions.write', 'execution.read', 'execution.write'],
    seed(api) {
        const functions = [];
        for (let i = 0; i < Math.min(SEED, 50); i++) {
            functions.push(fn(api, `Seed Function ${i}`));
        }
        // One function with a ready, active deployment: executions, downloads, activation.
        const live = fn(api, 'Live Function');
        const deployment = upload(api, live, true);
        ready(api, live, deployment);
        for (let i = 0; i < 60 && api.prep('GET', `/functions/${live}`, null, [200]).json('deploymentId') !== deployment; i++) {
            sleep(1);
        }
        const executions = Array.from({ length: 20 }, () => execute(api, live));
        // Deployments created by the scenarios build on their own function, so
        // canceling and deleting them never touches the live one.
        const scratch = fn(api, 'Scratch Function');
        const source = upload(api, scratch);
        ready(api, scratch, source);
        const variables = Array.from({ length: 20 }, (_, i) => api.prep('POST', `/functions/${live}/variables`, {
            variableId: 'unique()', key: `SEED_${i}`, value: `value-${i}`, secret: false,
        }, [201]).json('$id'));
        // Templates and runtimes are public: an API key has no `public` scope.
        const guest = { 'Content-Type': 'application/json', 'X-Appwrite-Project': api.headers['X-Appwrite-Project'] };
        const template = api.prep('GET', '/functions/templates?limit=1', null, [200], guest).json('templates.0.id');
        return { functions, live, deployment, executions, scratch, source, variables, template, guest };
    },
    scenarios: [
        { name: 'functions.get', route: 'GET /v1/functions/:functionId', weight: 10, run: (api, d) => api.call('GET', `/functions/${pick(d.functions)}`) },
        {
            name: 'functions.list', route: 'GET /v1/functions', weight: 6,
            run: (api) => api.call('GET', '/functions?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}')),
        },
        { name: 'functions.search', route: 'GET /v1/functions', weight: 2, note: 'full-text search', run: (api) => api.call('GET', `/functions?search=Seed+Function+${Math.floor(Math.random() * 50)}`) },
        {
            name: 'functions.create', route: 'POST /v1/functions', weight: 2,
            run(api) {
                const res = api.call('POST', '/functions', { functionId: 'unique()', name: 'New Function', runtime: RUNTIME, entrypoint: 'index.js' }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/functions/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'functions.update', route: 'PUT /v1/functions/:functionId', weight: 2,
            run: (api, d) => api.call('PUT', `/functions/${pick(d.functions)}`, {
                name: `Seed Function ${Math.floor(Math.random() * 1000)}`, runtime: RUNTIME, entrypoint: 'index.js', execute: ['any'],
            }),
        },
        { name: 'functions.delete', route: 'DELETE /v1/functions/:functionId', run: (api) => api.call('DELETE', `/functions/${fn(api, 'Gone')}`, null, [204]) },
        {
            name: 'functions.deployment.update', route: 'PATCH /v1/functions/:functionId/deployment',
            run: (api, d) => api.call('PATCH', `/functions/${d.live}/deployment`, { deploymentId: d.deployment }),
        },
        { name: 'functions.deployments.list', route: 'GET /v1/functions/:functionId/deployments', weight: 3, run: (api, d) => api.call('GET', `/functions/${d.live}/deployments`) },
        {
            name: 'functions.deployments.create', route: 'POST /v1/functions/:functionId/deployments',
            note: 'uploads a 1 KB archive in one request; queues a build in the executor',
            run(api, d) {
                const res = api.form('POST', `/functions/${d.scratch}/deployments`, { code: code(), activate: 'false' }, [202]);
                if (res.status === 202) {
                    discard(api, d.scratch, res.json('$id'));
                }
            },
        },
        { name: 'functions.deployments.get', route: 'GET /v1/functions/:functionId/deployments/:deploymentId', weight: 4, run: (api, d) => api.call('GET', `/functions/${d.live}/deployments/${d.deployment}`) },
        {
            name: 'functions.deployments.delete', route: 'DELETE /v1/functions/:functionId/deployments/:deploymentId',
            run(api, d) {
                const id = upload(api, d.scratch);
                api.prep('PATCH', `/functions/${d.scratch}/deployments/${id}/status`, null, [200, 400]);
                api.call('DELETE', `/functions/${d.scratch}/deployments/${id}`, null, [204]);
            },
        },
        {
            name: 'functions.deployments.download', route: 'GET /v1/functions/:functionId/deployments/:deploymentId/download',
            run: (api, d) => api.call('GET', `/functions/${d.live}/deployments/${d.deployment}/download`),
        },
        {
            name: 'functions.deployments.status.update', route: 'PATCH /v1/functions/:functionId/deployments/:deploymentId/status',
            note: 'cancels a build that was just queued',
            run(api, d) {
                const id = upload(api, d.scratch);
                api.call('PATCH', `/functions/${d.scratch}/deployments/${id}/status`);
                api.prep('DELETE', `/functions/${d.scratch}/deployments/${id}`, null, [204]);
            },
        },
        {
            name: 'functions.deployments.duplicate.create', route: 'POST /v1/functions/:functionId/deployments/duplicate',
            note: 'copies the source and queues a build in the executor',
            run(api, d) {
                const res = api.call('POST', `/functions/${d.scratch}/deployments/duplicate`, { deploymentId: d.source }, [202]);
                if (res.status === 202) {
                    discard(api, d.scratch, res.json('$id'));
                }
            },
        },
        {
            name: 'functions.deployments.vcs.create', route: 'POST /v1/functions/:functionId/deployments/vcs', note: NO_INSTALLATION,
            run: (api, d) => api.call('POST', `/functions/${pick(d.functions)}/deployments/vcs`, { type: 'branch', reference: 'main' }, [404]),
        },
        {
            name: 'functions.executions.list', route: 'GET /v1/functions/:functionId/executions', weight: 4,
            run: (api, d) => api.call('GET', `/functions/${d.live}/executions?queries[]=` + encodeURIComponent('{"method":"limit","values":[25]}')),
        },
        {
            name: 'functions.executions.create', route: 'POST /v1/functions/:functionId/executions', weight: 6,
            note: 'synchronous: runs the function in the executor',
            run(api, d) {
                const res = api.call('POST', `/functions/${d.live}/executions`, { async: false, path: '/', method: 'GET' }, [201]);
                if (res.status === 201) {
                    forget(api, d.live, res.json('$id'));
                }
            },
        },
        {
            name: 'functions.executions.create.async', route: 'POST /v1/functions/:functionId/executions', weight: 3,
            note: 'asynchronous: queues the execution for the functions worker',
            run(api, d) {
                const res = api.call('POST', `/functions/${d.live}/executions`, { async: true, path: '/', method: 'GET' }, [202]);
                if (res.status === 202) {
                    forget(api, d.live, res.json('$id'));
                }
            },
        },
        { name: 'functions.executions.get', route: 'GET /v1/functions/:functionId/executions/:executionId', weight: 6, run: (api, d) => api.call('GET', `/functions/${d.live}/executions/${pick(d.executions)}`) },
        {
            name: 'functions.executions.delete', route: 'DELETE /v1/functions/:functionId/executions/:executionId',
            run: (api, d) => api.call('DELETE', `/functions/${d.live}/executions/${execute(api, d.live)}`, null, [204]),
        },
        { name: 'functions.variables.list', route: 'GET /v1/functions/:functionId/variables', weight: 2, run: (api, d) => api.call('GET', `/functions/${d.live}/variables`) },
        {
            name: 'functions.variables.create', route: 'POST /v1/functions/:functionId/variables',
            run(api, d) {
                const res = api.call('POST', `/functions/${d.live}/variables`, { variableId: 'unique()', key: `NEW_${unique('v').replaceAll('-', '_')}`, value: 'value', secret: false }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/functions/${d.live}/variables/${res.json('$id')}`, null, [204]);
                }
            },
        },
        { name: 'functions.variables.get', route: 'GET /v1/functions/:functionId/variables/:variableId', weight: 2, run: (api, d) => api.call('GET', `/functions/${d.live}/variables/${pick(d.variables)}`) },
        {
            name: 'functions.variables.update', route: 'PUT /v1/functions/:functionId/variables/:variableId',
            run: (api, d) => api.call('PUT', `/functions/${d.live}/variables/${pick(d.variables)}`, { value: `value-${Math.floor(Math.random() * 1000)}` }),
        },
        {
            name: 'functions.variables.delete', route: 'DELETE /v1/functions/:functionId/variables/:variableId',
            run(api, d) {
                const id = api.prep('POST', `/functions/${d.live}/variables`, { variableId: 'unique()', key: `GONE_${unique('v').replaceAll('-', '_')}`, value: 'value' }, [201]).json('$id');
                api.call('DELETE', `/functions/${d.live}/variables/${id}`, null, [204]);
            },
        },
        { name: 'functions.runtimes.list', route: 'GET /v1/functions/runtimes', run: (api, d) => api.call('GET', '/functions/runtimes', null, [200], d.guest) },
        { name: 'functions.specifications.list', route: 'GET /v1/functions/specifications', run: (api) => api.call('GET', '/functions/specifications') },
        { name: 'functions.templates.list', route: 'GET /v1/functions/templates', run: (api, d) => api.call('GET', '/functions/templates?limit=25', null, [200], d.guest) },
        { name: 'functions.templates.get', route: 'GET /v1/functions/templates/:templateId', run: (api, d) => api.call('GET', `/functions/templates/${d.template}`, null, [200], d.guest) },
        {
            name: 'jobs.event.create', route: 'POST /v1/jobs/event',
            note: 'measured on its rejected path: an unsigned callback (only the executor holds the jobs signing secret)',
            run: (api, d) => api.call('POST', '/jobs/event', { id: unique('evt'), type: 'build.completed', data: {} }, [401], d.guest),
        },
    ],
    skipped: [
        {
            skipped: 'POST /v1/functions/:functionId/deployments/template',
            reason: 'queues a build that downloads the template source from GitHub (codeload.github.com)',
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
