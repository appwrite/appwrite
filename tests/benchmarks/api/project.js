// Project API benchmark: a scenario for every /v1/project endpoint (the
// settings of the project the request is for: keys, platforms, policies,
// OAuth2 providers, SMTP, templates, variables and mock phones), in a mix where
// reads dominate. See README.md.
//
// Calls go with the project's API key, except creating a key (only a console
// user may) and deleting a project (a throwaway one, as its console owner).
// Policies and other project-wide settings are always set to the values a new
// project has, so every write is idempotent and the project stays as found.

import { define, SEED, pick, unique } from './lib.js';

const REGION = __ENV._APP_REGION || 'default';

// The dev stack's mail catcher (and its login, as in .env), so SMTP checks and test mails never leave it.
const SMTP = { host: 'maildev', port: 1025, username: 'user', password: 'password', senderEmail: 'bench@example.com', senderName: 'Bench', enabled: true };

const PLATFORMS = {
    android: { field: 'applicationId', value: 'io.appwrite.bench' },
    apple: { field: 'bundleIdentifier', value: 'io.appwrite.bench' },
    linux: { field: 'packageName', value: 'io.appwrite.bench' },
    web: { field: 'hostname', value: 'bench.example.com' },
    windows: { field: 'packageIdentifierName', value: 'io.appwrite.bench' },
};

// What a new project has: every policy update sets these, so a run leaves them as found.
const POLICIES = [
    { name: 'project.policies.denyAliasedEmail.update', route: 'PATCH /v1/project/policies/deny-aliased-email', path: 'deny-aliased-email', body: { enabled: false } },
    { name: 'project.policies.denyCorporateEmail.update', route: 'PATCH /v1/project/policies/deny-corporate-email', path: 'deny-corporate-email', body: { enabled: false } },
    { name: 'project.policies.denyDisposableEmail.update', route: 'PATCH /v1/project/policies/deny-disposable-email', path: 'deny-disposable-email', body: { enabled: false } },
    { name: 'project.policies.denyFreeEmail.update', route: 'PATCH /v1/project/policies/deny-free-email', path: 'deny-free-email', body: { enabled: false } },
    {
        name: 'project.policies.membershipPrivacy.update', route: 'PATCH /v1/project/policies/membership-privacy', path: 'membership-privacy',
        body: { userId: false, userEmail: false, userPhone: false, userName: false, userMFA: false, userAccessedAt: false },
    },
    { name: 'project.policies.mfaFactors.update', route: 'PATCH /v1/project/policies/mfa-factors', path: 'mfa-factors', body: { totp: true, email: true, phone: true, custom: false } },
    { name: 'project.policies.passkey.update', route: 'PATCH /v1/project/policies/passkey', path: 'passkey', body: { rpId: '' } },
    { name: 'project.policies.passwordDictionary.update', route: 'PATCH /v1/project/policies/password-dictionary', path: 'password-dictionary', body: { enabled: false } },
    { name: 'project.policies.passwordHistory.update', route: 'PATCH /v1/project/policies/password-history', path: 'password-history', body: { total: null } },
    { name: 'project.policies.passwordPersonalData.update', route: 'PATCH /v1/project/policies/password-personal-data', path: 'password-personal-data', body: { enabled: false } },
    { name: 'project.policies.passwordPwned.update', route: 'PATCH /v1/project/policies/password-pwned', path: 'password-pwned', body: { enabled: true, sessions: false, users: false } },
    {
        name: 'project.policies.passwordStrength.update', route: 'PATCH /v1/project/policies/password-strength', path: 'password-strength',
        body: { min: 8, uppercase: false, lowercase: false, number: false, symbols: false },
    },
    { name: 'project.policies.sessionAlert.update', route: 'PATCH /v1/project/policies/session-alert', path: 'session-alert', body: { enabled: false } },
    { name: 'project.policies.sessionDuration.update', route: 'PATCH /v1/project/policies/session-duration', path: 'session-duration', body: { duration: 31536000 } },
    { name: 'project.policies.sessionInvalidation.update', route: 'PATCH /v1/project/policies/session-invalidation', path: 'session-invalidation', body: { enabled: true } },
    { name: 'project.policies.sessionLimit.update', route: 'PATCH /v1/project/policies/session-limit', path: 'session-limit', body: { total: 10 } },
    { name: 'project.policies.userLimit.update', route: 'PATCH /v1/project/policies/user-limit', path: 'user-limit', body: { total: null } },
];

// Every provider, by the name of its client ID param. `enabled: false` keeps the
// update from verifying the credentials against the provider.
const OAUTH2 = [
    { id: 'amazon', route: 'PATCH /v1/project/oauth2/amazon' },
    { id: 'apple', route: 'PATCH /v1/project/oauth2/apple', clientId: 'serviceId' },
    { id: 'appwrite', route: 'PATCH /v1/project/oauth2/appwrite' },
    { id: 'auth0', route: 'PATCH /v1/project/oauth2/auth0' },
    { id: 'authentik', route: 'PATCH /v1/project/oauth2/authentik' },
    { id: 'autodesk', route: 'PATCH /v1/project/oauth2/autodesk' },
    { id: 'bitbucket', route: 'PATCH /v1/project/oauth2/bitbucket', clientId: 'key' },
    { id: 'bitly', route: 'PATCH /v1/project/oauth2/bitly' },
    { id: 'box', route: 'PATCH /v1/project/oauth2/box' },
    { id: 'cloudflare', route: 'PATCH /v1/project/oauth2/cloudflare' },
    { id: 'dailymotion', route: 'PATCH /v1/project/oauth2/dailymotion', clientId: 'apiKey' },
    { id: 'discord', route: 'PATCH /v1/project/oauth2/discord' },
    { id: 'disqus', route: 'PATCH /v1/project/oauth2/disqus', clientId: 'publicKey' },
    { id: 'dropbox', route: 'PATCH /v1/project/oauth2/dropbox', clientId: 'appKey' },
    { id: 'etsy', route: 'PATCH /v1/project/oauth2/etsy', clientId: 'keyString' },
    { id: 'facebook', route: 'PATCH /v1/project/oauth2/facebook', clientId: 'appId' },
    { id: 'figma', route: 'PATCH /v1/project/oauth2/figma' },
    { id: 'fusionauth', route: 'PATCH /v1/project/oauth2/fusionauth' },
    { id: 'github', route: 'PATCH /v1/project/oauth2/github' },
    { id: 'gitlab', route: 'PATCH /v1/project/oauth2/gitlab', clientId: 'applicationId' },
    { id: 'google', route: 'PATCH /v1/project/oauth2/google' },
    { id: 'huggingface', route: 'PATCH /v1/project/oauth2/huggingface' },
    { id: 'kakao', route: 'PATCH /v1/project/oauth2/kakao' },
    { id: 'keycloak', route: 'PATCH /v1/project/oauth2/keycloak' },
    { id: 'kick', route: 'PATCH /v1/project/oauth2/kick' },
    { id: 'linkedin', route: 'PATCH /v1/project/oauth2/linkedin' },
    { id: 'microsoft', route: 'PATCH /v1/project/oauth2/microsoft', clientId: 'applicationId' },
    { id: 'notion', route: 'PATCH /v1/project/oauth2/notion', clientId: 'oauthClientId' },
    { id: 'oidc', route: 'PATCH /v1/project/oauth2/oidc' },
    { id: 'okta', route: 'PATCH /v1/project/oauth2/okta' },
    { id: 'paypal', route: 'PATCH /v1/project/oauth2/paypal' },
    { id: 'paypalSandbox', route: 'PATCH /v1/project/oauth2/paypalSandbox' },
    { id: 'podio', route: 'PATCH /v1/project/oauth2/podio' },
    { id: 'resend', route: 'PATCH /v1/project/oauth2/resend' },
    { id: 'salesforce', route: 'PATCH /v1/project/oauth2/salesforce', clientId: 'customerKey' },
    { id: 'slack', route: 'PATCH /v1/project/oauth2/slack' },
    { id: 'spotify', route: 'PATCH /v1/project/oauth2/spotify' },
    { id: 'stripe', route: 'PATCH /v1/project/oauth2/stripe' },
    { id: 'tiktok', route: 'PATCH /v1/project/oauth2/tiktok' },
    { id: 'tradeshift', route: 'PATCH /v1/project/oauth2/tradeshift', clientId: 'oauth2ClientId' },
    { id: 'tradeshiftBox', route: 'PATCH /v1/project/oauth2/tradeshiftBox', clientId: 'oauth2ClientId' },
    { id: 'twitch', route: 'PATCH /v1/project/oauth2/twitch' },
    { id: 'webflow', route: 'PATCH /v1/project/oauth2/webflow' },
    { id: 'wordpress', route: 'PATCH /v1/project/oauth2/wordpress' },
    { id: 'x', route: 'PATCH /v1/project/oauth2/x', clientId: 'customerKey' },
    { id: 'yahoo', route: 'PATCH /v1/project/oauth2/yahoo' },
    { id: 'yandex', route: 'PATCH /v1/project/oauth2/yandex' },
    { id: 'zoho', route: 'PATCH /v1/project/oauth2/zoho' },
    { id: 'zoom', route: 'PATCH /v1/project/oauth2/zoom' },
];

// Mock phones live in the project's `auths`, which every policy update rewrites
// from the project as read: a concurrent write can drop a number just added.
const RACE = 'a mock phone shares the project\'s `auths` with the policies, written read-modify-write: a concurrent policy update can drop the number first, then this answers 404';

function phone() {
    return `+1${2000000000 + Math.floor(Math.random() * 7999999999)}`;
}

function platform(api, type) {
    const { field, value } = PLATFORMS[type];
    return api.prep('POST', `/project/platforms/${type}`, { platformId: 'unique()', name: `Bench ${type}`, [field]: value }, [201]).json('$id');
}

function variable(api, secret = true) {
    return api.prep('POST', '/project/variables', { variableId: 'unique()', key: `BENCH_${unique('v').replace(/-/g, '_')}`, value: 'bench', secret }, [201]).json('$id');
}

const bench = define({
    service: 'project',
    scopes: [
        'project.read', 'project.write', 'keys.read', 'keys.write', 'platforms.read', 'platforms.write', 'mocks.read', 'mocks.write',
        'project.policies.read', 'project.policies.write', 'project.oauth2.read', 'project.oauth2.write', 'templates.read', 'templates.write',
    ],
    seed(api, { admin, projectId }) {
        // The project's console user, for the routes an API key may not call.
        const owner = { ...admin, 'X-Appwrite-Project': projectId, 'X-Appwrite-Mode': 'admin' };
        const teamId = api.prep('GET', '/project', null, [200]).json('teamId');
        const n = Math.min(SEED, 50);
        const keys = Array.from({ length: n }, (_, i) => api.prep('POST', '/project/keys', { keyId: 'unique()', name: `Seed Key ${i}`, scopes: ['users.read'] }, [201], owner).json('$id'));
        const platforms = Object.fromEntries(Object.keys(PLATFORMS).map((type) => [type, Array.from({ length: 5 }, () => platform(api, type))]));
        const variables = Array.from({ length: n }, () => variable(api, false));
        const phones = Array.from({ length: 5 }, () => {
            const number = phone();
            api.prep('POST', '/project/mock-phones', { number, otp: '123456' }, [201]);
            return number;
        });
        // Custom templates need custom SMTP: the mail catcher's.
        api.prep('PATCH', '/project/smtp', SMTP, [200]);
        return { admin, owner, teamId, keys, platforms, variables, phones };
    },
    scenarios: [
        { name: 'project.get', route: 'GET /v1/project', weight: 10, run: (api) => api.call('GET', '/project') },
        {
            name: 'project.delete', route: 'DELETE /v1/project', weight: 0.5,
            note: 'deletes a project created for it (as its console owner); the data is removed in the background',
            run(api, d) {
                const id = api.control('POST', '/projects', { projectId: unique('gone'), name: 'Bench throwaway', teamId: d.teamId, region: REGION }, [201], d.admin).json('$id');
                api.call('DELETE', '/project', null, [204], { ...d.owner, 'X-Appwrite-Project': id });
            },
        },
        { name: 'project.authMethods.update', route: 'PATCH /v1/project/auth-methods/:methodId', run: (api) => api.call('PATCH', `/project/auth-methods/${pick(['email-password', 'anonymous', 'magic-url', 'jwt'])}`, { enabled: true }) },
        { name: 'project.labels.update', route: 'PUT /v1/project/labels', run: (api) => api.call('PUT', '/project/labels', { labels: ['bench', 'benchmark'] }) },
        { name: 'project.protocols.update', route: 'PATCH /v1/project/protocols/:protocolId', run: (api) => api.call('PATCH', `/project/protocols/${pick(['rest', 'graphql', 'websocket'])}`, { enabled: true }) },
        { name: 'project.services.update', route: 'PATCH /v1/project/services/:serviceId', run: (api) => api.call('PATCH', `/project/services/${pick(['locale', 'avatars', 'health', 'graphql'])}`, { enabled: true }) },

        // Keys
        { name: 'project.keys.list', route: 'GET /v1/project/keys', weight: 4, run: (api) => api.call('GET', '/project/keys') },
        { name: 'project.keys.get', route: 'GET /v1/project/keys/:keyId', weight: 4, run: (api, d) => api.call('GET', `/project/keys/${pick(d.keys)}`) },
        {
            name: 'project.keys.create', route: 'POST /v1/project/keys', note: 'as the console user: an API key may not create keys',
            run(api, d) {
                const res = api.call('POST', '/project/keys', { keyId: 'unique()', name: 'Bench Key', scopes: ['users.read', 'users.write'] }, [201], d.owner);
                if (res.status === 201) {
                    api.prep('DELETE', `/project/keys/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'project.keys.update', route: 'PUT /v1/project/keys/:keyId',
            run: (api, d) => api.call('PUT', `/project/keys/${pick(d.keys)}`, { name: `Seed Key ${Math.floor(Math.random() * 1000)}`, scopes: ['users.read'], expire: null }),
        },
        {
            name: 'project.keys.delete', route: 'DELETE /v1/project/keys/:keyId',
            run(api, d) {
                const id = api.prep('POST', '/project/keys', { keyId: 'unique()', name: 'Gone', scopes: ['users.read'] }, [201], d.owner).json('$id');
                api.call('DELETE', `/project/keys/${id}`, null, [204]);
            },
        },
        { name: 'project.keys.ephemeral.create', route: 'POST /v1/project/keys/ephemeral', run: (api) => api.call('POST', '/project/keys/ephemeral', { scopes: ['users.read'], duration: 600 }, [201]) },

        // Mock phones
        { name: 'project.mockPhones.list', route: 'GET /v1/project/mock-phones', weight: 2, run: (api) => api.call('GET', '/project/mock-phones') },
        { name: 'project.mockPhones.get', route: 'GET /v1/project/mock-phones/:number', weight: 2, run: (api, d) => api.call('GET', `/project/mock-phones/${pick(d.phones)}`) },
        {
            name: 'project.mockPhones.create', route: 'POST /v1/project/mock-phones',
            run(api) {
                const res = api.call('POST', '/project/mock-phones', { number: phone(), otp: '123456' }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/project/mock-phones/${res.json('number')}`, null, [204, 404]);
                }
            },
        },
        { name: 'project.mockPhones.update', route: 'PUT /v1/project/mock-phones/:number', run: (api, d) => api.call('PUT', `/project/mock-phones/${pick(d.phones)}`, { otp: '123456' }) },
        {
            name: 'project.mockPhones.delete', route: 'DELETE /v1/project/mock-phones/:number', note: RACE,
            run(api) {
                const number = phone();
                api.prep('POST', '/project/mock-phones', { number, otp: '123456' }, [201]);
                api.call('DELETE', `/project/mock-phones/${number}`, null, [204, 404]);
            },
        },

        // OAuth2
        { name: 'project.oauth2.list', route: 'GET /v1/project/oauth2', weight: 3, run: (api) => api.call('GET', '/project/oauth2') },
        { name: 'project.oauth2.get', route: 'GET /v1/project/oauth2/:provider', weight: 3, run: (api) => api.call('GET', `/project/oauth2/${pick(OAUTH2).id}`) },
        ...OAUTH2.map(({ id, route, clientId }) => ({
            name: `project.oauth2.${id}.update`, route, weight: 0.5,
            run: (api) => api.call('PATCH', `/project/oauth2/${id}`, { [clientId || 'clientId']: 'bench-client-id', enabled: false }),
        })),

        // Platforms
        { name: 'project.platforms.list', route: 'GET /v1/project/platforms', weight: 4, run: (api) => api.call('GET', '/project/platforms') },
        { name: 'project.platforms.get', route: 'GET /v1/project/platforms/:platformId', weight: 4, run: (api, d) => api.call('GET', `/project/platforms/${pick(d.platforms[pick(Object.keys(PLATFORMS))])}`) },
        { name: 'project.platforms.delete', route: 'DELETE /v1/project/platforms/:platformId', run: (api) => api.call('DELETE', `/project/platforms/${platform(api, pick(Object.keys(PLATFORMS)))}`, null, [204]) },
        ...[
            { type: 'android', route: 'POST /v1/project/platforms/android' },
            { type: 'apple', route: 'POST /v1/project/platforms/apple' },
            { type: 'linux', route: 'POST /v1/project/platforms/linux' },
            { type: 'web', route: 'POST /v1/project/platforms/web' },
            { type: 'windows', route: 'POST /v1/project/platforms/windows' },
        ].map(({ type, route }) => ({
            name: `project.platforms.${type}.create`, route,
            run(api) {
                const { field, value } = PLATFORMS[type];
                const res = api.call('POST', `/project/platforms/${type}`, { platformId: 'unique()', name: `Bench ${type}`, [field]: value }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/project/platforms/${res.json('$id')}`, null, [204]);
                }
            },
        })),
        ...[
            { type: 'android', route: 'PUT /v1/project/platforms/android/:platformId' },
            { type: 'apple', route: 'PUT /v1/project/platforms/apple/:platformId' },
            { type: 'linux', route: 'PUT /v1/project/platforms/linux/:platformId' },
            { type: 'web', route: 'PUT /v1/project/platforms/web/:platformId' },
            { type: 'windows', route: 'PUT /v1/project/platforms/windows/:platformId' },
        ].map(({ type, route }) => ({
            name: `project.platforms.${type}.update`, route,
            run(api, d) {
                const { field, value } = PLATFORMS[type];
                api.call('PUT', `/project/platforms/${type}/${pick(d.platforms[type])}`, { name: `Bench ${type} ${Math.floor(Math.random() * 1000)}`, [field]: value });
            },
        })),

        // Policies
        { name: 'project.policies.list', route: 'GET /v1/project/policies', weight: 4, run: (api) => api.call('GET', '/project/policies') },
        {
            name: 'project.policies.get', route: 'GET /v1/project/policies/:policyId', weight: 4,
            run: (api) => api.call('GET', `/project/policies/${pick(POLICIES).path}`),
        },
        ...POLICIES.map(({ name, route, path, body }) => ({ name, route, run: (api) => api.call('PATCH', `/project/policies/${path}`, body) })),

        // SMTP
        {
            name: 'project.smtp.update', route: 'PATCH /v1/project/smtp', note: 'checks the server (the dev stack\'s mail catcher) before saving',
            run: (api) => api.call('PATCH', '/project/smtp', SMTP),
        },
        {
            name: 'project.smtp.tests.create', route: 'POST /v1/project/smtp/tests', note: 'queues the test mail; the mails worker sends it to the dev stack\'s mail catcher',
            run: (api) => api.call('POST', '/project/smtp/tests', { emails: ['bench@example.com'] }, [204]),
        },

        // Templates
        { name: 'project.templates.email.list', route: 'GET /v1/project/templates/email', weight: 2, run: (api) => api.call('GET', '/project/templates/email') },
        {
            name: 'project.templates.email.get', route: 'GET /v1/project/templates/email/:templateId', weight: 2,
            run: (api) => api.call('GET', `/project/templates/email/${pick(['verification', 'magicSession', 'recovery', 'invitation'])}?locale=en`),
        },
        {
            name: 'project.templates.email.update', route: 'PATCH /v1/project/templates/email',
            run: (api) => api.call('PATCH', '/project/templates/email', {
                templateId: pick(['verification', 'recovery']), locale: 'en', subject: 'Bench subject', message: '<p>Bench message {{url}}</p>',
            }),
        },

        // Variables
        { name: 'project.variables.list', route: 'GET /v1/project/variables', weight: 3, run: (api) => api.call('GET', '/project/variables') },
        { name: 'project.variables.get', route: 'GET /v1/project/variables/:variableId', weight: 3, run: (api, d) => api.call('GET', `/project/variables/${pick(d.variables)}`) },
        {
            name: 'project.variables.create', route: 'POST /v1/project/variables',
            run(api) {
                const res = api.call('POST', '/project/variables', { variableId: 'unique()', key: `BENCH_${unique('v').replace(/-/g, '_')}`, value: 'bench' }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/project/variables/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'project.variables.update', route: 'PUT /v1/project/variables/:variableId',
            run: (api, d) => api.call('PUT', `/project/variables/${pick(d.variables)}`, { value: `bench-${Math.floor(Math.random() * 1000)}` }),
        },
        { name: 'project.variables.delete', route: 'DELETE /v1/project/variables/:variableId', run: (api) => api.call('DELETE', `/project/variables/${variable(api)}`, null, [204]) },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
