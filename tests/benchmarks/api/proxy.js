// Proxy API benchmark: a scenario for every /v1/proxy endpoint, in a mix
// modelled on console traffic to a project's domains (reads dominate). See
// README.md.
//
// Custom domains are under `custom.localhost`: a test domain is never looked up
// in DNS (verification fails before it), so creating and verifying a rule stays
// local. Rules under the functions domain are Appwrite-owned and verified on
// creation.

import { define, SEED, pick, unique } from './lib.js';

const RUNTIME = __ENV.APPWRITE_BENCHMARK_RUNTIME || 'node-22';
const FUNCTIONS_DOMAIN = __ENV.APPWRITE_BENCHMARK_FUNCTIONS_DOMAIN || 'functions.localhost';
const SITES_DOMAIN = __ENV.APPWRITE_BENCHMARK_SITES_DOMAIN || 'sites.localhost';

function custom(prefix) {
    return `${unique(prefix)}.custom.localhost`;
}

function drop(api, id) {
    api.prep('DELETE', `/proxy/rules/${id}`, null, [204]);
}

const bench = define({
    service: 'proxy',
    scopes: ['rules.read', 'rules.write', 'functions.write', 'sites.write'],
    seed(api) {
        // Rules point at a function and a site; neither needs a deployment.
        const fn = api.control('POST', '/functions', { functionId: 'unique()', name: 'Proxy Function', runtime: RUNTIME, entrypoint: 'index.js' }, [201]).json('$id');
        const site = api.control('POST', '/sites', {
            siteId: 'unique()', name: 'Proxy Site', framework: 'other', buildRuntime: RUNTIME, adapter: 'static', outputDirectory: './',
        }, [201]).json('$id');
        const rules = [];
        for (let i = 0; i < Math.min(SEED, 100); i++) {
            rules.push(api.prep('POST', '/proxy/rules/api', { domain: custom(`seed-${i}`) }, [201]).json('$id'));
        }
        const verified = Array.from({ length: 10 }, () => api.prep('POST', '/proxy/rules/function', {
            domain: `${unique('fn')}.${FUNCTIONS_DOMAIN}`, functionId: fn,
        }, [201]).json('$id'));
        return { fn, site, rules, verified };
    },
    scenarios: [
        { name: 'proxy.rules.get', route: 'GET /v1/proxy/rules/:ruleId', weight: 10, run: (api, d) => api.call('GET', `/proxy/rules/${pick(d.rules)}`) },
        {
            name: 'proxy.rules.list', route: 'GET /v1/proxy/rules', weight: 6,
            run: (api) => api.call('GET', '/proxy/rules?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}')),
        },
        { name: 'proxy.rules.search', route: 'GET /v1/proxy/rules', weight: 2, note: 'full-text search', run: (api) => api.call('GET', '/proxy/rules?search=custom.localhost') },
        { name: 'proxy.rules.delete', route: 'DELETE /v1/proxy/rules/:ruleId', run: (api) => api.call('DELETE', `/proxy/rules/${api.prep('POST', '/proxy/rules/api', { domain: custom('gone') }, [201]).json('$id')}`, null, [204]) },
        {
            name: 'proxy.rules.status.update', route: 'PATCH /v1/proxy/rules/:ruleId/status',
            note: 'on a verified rule (under the functions domain): verifying a custom domain looks up its DNS records',
            run: (api, d) => api.call('PATCH', `/proxy/rules/${pick(d.verified)}/status`),
        },
        {
            name: 'proxy.rules.status.update.unverified', route: 'PATCH /v1/proxy/rules/:ruleId/status',
            note: 'on an unverified custom.localhost rule: verification fails before any DNS lookup (a test domain)',
            run: (api, d) => api.call('PATCH', `/proxy/rules/${pick(d.rules)}/status`, null, [400]),
        },
        {
            name: 'proxy.rules.api.create', route: 'POST /v1/proxy/rules/api', weight: 2,
            run(api) {
                const res = api.call('POST', '/proxy/rules/api', { domain: custom('api') }, [201]);
                if (res.status === 201) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'proxy.rules.function.create', route: 'POST /v1/proxy/rules/function', weight: 2,
            run(api, d) {
                const res = api.call('POST', '/proxy/rules/function', { domain: custom('fn'), functionId: d.fn }, [201]);
                if (res.status === 201) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'proxy.rules.site.create', route: 'POST /v1/proxy/rules/site', weight: 2,
            run(api, d) {
                const res = api.call('POST', '/proxy/rules/site', { domain: `${unique('site')}.${SITES_DOMAIN}`, siteId: d.site }, [201]);
                if (res.status === 201) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'proxy.rules.redirect.create', route: 'POST /v1/proxy/rules/redirect',
            run(api, d) {
                const res = api.call('POST', '/proxy/rules/redirect', {
                    domain: custom('redirect'), url: 'https://example.com/', statusCode: 301, resourceId: d.fn, resourceType: 'function',
                }, [201]);
                if (res.status === 201) {
                    drop(api, res.json('$id'));
                }
            },
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
