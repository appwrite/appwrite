// Mock routes benchmark: the test-only /v1/mock routes the dev stack enables
// (_APP_ENV=development), a scenario for each, as declared. Redirects are
// measured as the redirect itself, not followed. See README.md.

import { define, ENDPOINT, unique } from './lib.js';


/** A measured request that does not follow redirects (the harness's `call()` does). */
function raw(api, method, path, codes, headers) {
    return api.request(method, `${ENDPOINT}${path}`, null, codes, { headers, redirects: 0 });
}

const OAUTH2 = '/mock/tests/general/oauth2';
const CALLBACK = 'http://localhost/v1/mock/tests/general/oauth2/success';

const bench = define({
    service: 'mock',
    scopes: [],
    seed(api, { admin, projectId }) {
        // The routes are public: guest requests of the run's project, or of the
        // console (the console session) for the ones in the `projects` group.
        return { admin, projectId, guest: { 'Content-Type': 'application/json', 'X-Appwrite-Project': projectId } };
    },
    scenarios: [
        {
            name: 'mock.oauth2.get', route: 'GET /v1/mock/tests/general/oauth2', weight: 2, note: 'redirects (301, not followed) to the callback with a code',
            run: (api, d) => raw(api, 'GET', `${OAUTH2}?client_id=1&redirect_uri=${encodeURIComponent(CALLBACK)}&scope=email&state=${unique('state')}`, [301], d.guest),
        },
        {
            name: 'mock.oauth2.token.get', route: 'GET /v1/mock/tests/general/oauth2/token', weight: 2,
            run: (api, d) => api.call('GET', `${OAUTH2}/token?client_id=1&client_secret=123456&grant_type=authorization_code&code=abcdef`, null, [200], d.guest),
        },
        { name: 'mock.oauth2.user.get', route: 'GET /v1/mock/tests/general/oauth2/user', weight: 2, run: (api, d) => api.call('GET', `${OAUTH2}/user?token=123456`, null, [200], d.guest) },
        { name: 'mock.oauth2.userUnverified.get', route: 'GET /v1/mock/tests/general/oauth2/user-unverified', run: (api, d) => api.call('GET', `${OAUTH2}/user-unverified?token=123456`, null, [200], d.guest) },
        { name: 'mock.oauth2.userNoEmail.get', route: 'GET /v1/mock/tests/general/oauth2/user-no-email', run: (api, d) => api.call('GET', `${OAUTH2}/user-no-email?token=123456`, null, [200], d.guest) },
        { name: 'mock.oauth2.photo.get', route: 'GET /v1/mock/tests/general/oauth2/photo', run: (api, d) => api.call('GET', `${OAUTH2}/photo`, null, [200], d.guest) },
        { name: 'mock.oauth2.photoRefreshed.get', route: 'GET /v1/mock/tests/general/oauth2/photo-refreshed', run: (api, d) => api.call('GET', `${OAUTH2}/photo-refreshed`, null, [200], d.guest) },
        { name: 'mock.oauth2.jwks.get', route: 'GET /v1/mock/tests/general/oauth2/jwks', run: (api, d) => api.call('GET', `${OAUTH2}/jwks`, null, [200], d.guest) },
        {
            name: 'mock.oauth2.idToken.get', route: 'GET /v1/mock/tests/general/oauth2/id-token', note: 'signs an RS256 ID token',
            run(api, d) {
                const claims = JSON.stringify({ iss: 'http://localhost', sub: unique('sub'), aud: '1', email: `${unique('id')}@example.com`, iat: Math.floor(Date.now() / 1000), exp: Math.floor(Date.now() / 1000) + 600 });
                api.call('GET', `${OAUTH2}/id-token?claims=${encodeURIComponent(claims)}`, null, [200], d.guest);
            },
        },
        { name: 'mock.oauth2.success.get', route: 'GET /v1/mock/tests/general/oauth2/success', run: (api, d) => api.call('GET', `${OAUTH2}/success`, null, [200], d.guest) },
        { name: 'mock.oauth2.failure.get', route: 'GET /v1/mock/tests/general/oauth2/failure', note: 'answers 400 by design', run: (api, d) => api.call('GET', `${OAUTH2}/failure`, null, [400], d.guest) },
        {
            name: 'mock.oauth2.native.update', route: 'PATCH /v1/mock/tests/general/oauth2/native', note: 'keeps native ID token sign-in disabled on the run\'s project',
            run: (api, d) => api.call('PATCH', `${OAUTH2}/native`, { projectId: d.projectId, enabled: false }, [204], d.admin),
        },
        { name: 'mock.locale.get', route: 'GET /v1/mock/tests/locale', run: (api, d) => api.call('GET', '/mock/tests/locale?locale=fr', null, [200], d.guest) },
        {
            name: 'mock.apiKeyUnprefixed.create', route: 'POST /v1/mock/api-key-unprefixed',
            run(api, d) {
                const res = api.call('POST', '/mock/api-key-unprefixed', { projectId: d.projectId }, [201], d.admin);
                if (res.status === 201) {
                    api.control('DELETE', `/projects/${d.projectId}/keys/${res.json('$id')}`, null, [204], d.admin);
                }
            },
        },
        {
            name: 'mock.github.callback.get', route: 'GET /v1/mock/github/callback',
            note: 'measured on its project-not-found path: an installation looks up the owner on the production GitHub API',
            run: (api, d) => api.call('GET', `/mock/github/callback?providerInstallationId=${unique('inst')}&projectId=${unique('missing')}`, null, [404], d.guest),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
