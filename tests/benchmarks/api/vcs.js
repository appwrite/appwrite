// VCS API benchmark: a scenario for every /v1/vcs endpoint that runs without a
// Git provider, in a mix modelled on console traffic. See README.md.
//
// An installation only exists once a provider (GitHub, GitLab, Bitbucket,
// Gitea, Origin) completes its install flow, so the dev stack has none: the
// installation endpoints that only read Appwrite's database are measured on
// their not-found path, and those that call the provider are skipped. Webhooks
// are measured on their rejected-signature path (the provider holds the
// secret), and the authorize endpoints on their redirect, which k6 does not
// follow (`maxRedirects: 0`).

import { define, unique } from './lib.js';

const NOT_FOUND = 'measured on its not-found path: an installation needs a Git provider to complete its install flow';
const UNSIGNED = 'measured on its rejected path: an unsigned delivery (only the provider can sign one)';
const AUTHORIZE = 'the redirect to the provider\'s install page, not followed; a stack without the provider configured (_APP_VCS_*) answers 500';

function authorize(provider) {
    return {
        name: `vcs.${provider}.authorize`,
        note: AUTHORIZE,
        run: (api) => api.call('GET', `/vcs/${provider}/authorize?success=${encodeURIComponent('http://localhost/success')}&failure=${encodeURIComponent('http://localhost/failure')}`, null, [301, 500]),
    };
}

function events(provider) {
    return {
        name: `vcs.${provider}.events.create`,
        note: UNSIGNED,
        run: (api, d) => api.call('POST', `/vcs/${provider}/events`, { zen: 'Benchmark', hook_id: 1 }, [401], { ...d.guest, 'X-GitHub-Event': 'ping' }),
    };
}

const bench = define({
    service: 'vcs',
    scopes: ['vcs.read', 'vcs.write'],
    seed(api) {
        // Webhooks come from the provider, without a key.
        return { guest: { 'Content-Type': 'application/json', 'X-Appwrite-Project': api.headers['X-Appwrite-Project'] } };
    },
    scenarios: [
        { name: 'vcs.installations.list', route: 'GET /v1/vcs/installations', weight: 10, run: (api) => api.call('GET', '/vcs/installations') },
        { name: 'vcs.installations.get', route: 'GET /v1/vcs/installations/:installationId', weight: 6, note: NOT_FOUND, run: (api) => api.call('GET', `/vcs/installations/${unique('in')}`, null, [404]) },
        { name: 'vcs.installations.delete', route: 'DELETE /v1/vcs/installations/:installationId', note: NOT_FOUND, run: (api) => api.call('DELETE', `/vcs/installations/${unique('in')}`, null, [404]) },
        { ...authorize('github'), route: 'GET /v1/vcs/github/authorize' },
        { ...authorize('gitlab'), route: 'GET /v1/vcs/gitlab/authorize' },
        { ...authorize('bitbucket'), route: 'GET /v1/vcs/bitbucket/authorize' },
        { ...authorize('gitea'), route: 'GET /v1/vcs/gitea/authorize' },
        { ...authorize('origin'), route: 'GET /v1/vcs/origin/authorize' },
        { ...events('github'), route: 'POST /v1/vcs/github/events', weight: 4 },
        { ...events('gitlab'), route: 'POST /v1/vcs/gitlab/events' },
        { ...events('bitbucket'), route: 'POST /v1/vcs/bitbucket/events' },
        { ...events('gitea'), route: 'POST /v1/vcs/gitea/events' },
        // Without a webhook-id header, Origin's delivery is rejected before its signing keys are fetched.
        { ...events('origin'), route: 'POST /v1/vcs/origin/events' },
    ],
    skipped: [
        { skipped: 'GET /v1/vcs/github/callback', reason: 'completes the install flow with the provider (exchanges its code or verifies its receipt)' },
        { skipped: 'GET /v1/vcs/gitlab/callback', reason: 'completes the install flow with the provider (exchanges its code or verifies its receipt)' },
        { skipped: 'GET /v1/vcs/bitbucket/callback', reason: 'completes the install flow with the provider (exchanges its code or verifies its receipt)' },
        { skipped: 'GET /v1/vcs/gitea/callback', reason: 'completes the install flow with the provider (exchanges its code or verifies its receipt)' },
        { skipped: 'GET /v1/vcs/origin/callback', reason: 'completes the install flow with the provider (exchanges its code or verifies its receipt)' },
        { skipped: 'GET /v1/vcs/installations/:installationId/namespaces', reason: 'lists the installation\'s namespaces from its provider' },
        { skipped: 'POST /v1/vcs/github/installations/:installationId/detections', reason: 'reads the repository\'s files from GitHub to detect its runtime or framework' },
        { skipped: 'GET /v1/vcs/github/installations/:installationId/providerRepositories', reason: 'reads the installation\'s repositories from GitHub' },
        { skipped: 'POST /v1/vcs/github/installations/:installationId/providerRepositories', reason: 'creates a repository on GitHub' },
        { skipped: 'GET /v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId', reason: 'reads the installation\'s repositories from GitHub' },
        { skipped: 'GET /v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/branches', reason: 'lists the repository\'s branches from GitHub' },
        { skipped: 'GET /v1/vcs/github/installations/:installationId/providerRepositories/:providerRepositoryId/contents', reason: 'reads the repository\'s contents from GitHub' },
        { skipped: 'PATCH /v1/vcs/github/installations/:installationId/repositories/:repositoryId', reason: 'reads the pull request from GitHub and deploys it' },
    ],
});

export const options = { ...bench.options, maxRedirects: 0 };
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
