// Console API benchmark: the /v1/console routes, read with the console session
// of APPWRITE_ADMIN_EMAIL, and the console redirects the API host serves (`/`,
// `/login`, ...), measured as the redirect itself, not followed. See README.md.

import { define, ROOT, pick, unique } from './lib.js';


// On the console's own host the redirects answer 404 (the proxy serves those
// paths there), so they are requested for another host name of the API.
const HOST = { Host: '127.0.0.1' };

function redirect(api, path) {
    return api.request('GET', `${ROOT}${path}`, null, [301], { headers: HOST, redirects: 0 });
}

const TEMPLATES = ['verification', 'magicSession', 'recovery', 'invitation', 'mfaChallenge', 'sessionAlert', 'otpSession', 'otpVerification', 'otpRecovery'];
const LOCALES = ['en', 'de', 'fr', 'es', 'pt-br', 'ja'];
const NOTE = 'redirects (301, not followed) to the console, for a host other than the console\'s';

const bench = define({
    service: 'console',
    scopes: [],
    seed(api, { admin }) {
        return { admin };
    },
    scenarios: [
        { name: 'console.redirects.root.get', route: 'GET /', note: NOTE, run: (api) => redirect(api, '/') },
        { name: 'console.redirects.auth.get', route: 'GET /auth/*', note: NOTE, run: (api) => redirect(api, `/auth/oauth2/success?state=${unique('s')}`) },
        { name: 'console.redirects.invite.get', route: 'GET /invite', note: NOTE, run: (api) => redirect(api, `/invite?membershipId=${unique('m')}&userId=${unique('u')}&secret=bench&teamId=${unique('t')}`) },
        { name: 'console.redirects.login.get', route: 'GET /login', note: NOTE, run: (api) => redirect(api, '/login') },
        { name: 'console.redirects.mfa.get', route: 'GET /mfa', note: NOTE, run: (api) => redirect(api, '/mfa') },
        { name: 'console.redirects.recover.get', route: 'GET /recover', note: NOTE, run: (api) => redirect(api, `/recover?userId=${unique('u')}&secret=bench`) },
        { name: 'console.redirects.register.get', route: 'GET /register/*', note: NOTE, run: (api) => redirect(api, '/register/invite') },
        { name: 'console.oauth2Providers.list', route: 'GET /v1/console/oauth2-providers', weight: 3, run: (api, d) => api.call('GET', '/console/oauth2-providers', null, [200], d.admin) },
        {
            name: 'console.resources.get', route: 'GET /v1/console/resources', weight: 3, note: 'an available custom domain (204)',
            run: (api, d) => api.call('GET', `/console/resources?type=rules&value=${unique('bench')}.example.com`, null, [204], d.admin),
        },
        { name: 'console.scopes.organization.list', route: 'GET /v1/console/scopes/organization', weight: 2, run: (api, d) => api.call('GET', '/console/scopes/organization', null, [200], d.admin) },
        { name: 'console.scopes.project.list', route: 'GET /v1/console/scopes/project', weight: 2, run: (api, d) => api.call('GET', '/console/scopes/project', null, [200], d.admin) },
        {
            name: 'console.templates.email.get', route: 'GET /v1/console/templates/email/:templateId', weight: 3,
            run: (api, d) => api.call('GET', `/console/templates/email/${pick(TEMPLATES)}?locale=${pick(LOCALES)}`, null, [200], d.admin),
        },
        { name: 'console.variables.get', route: 'GET /v1/console/variables', weight: 5, run: (api, d) => api.call('GET', '/console/variables', null, [200], d.admin) },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
