// Users API benchmark: a scenario for every /v1/users endpoint, in a mix
// modelled on server-side Users traffic (reads dominate). See README.md.

import { define, HASHING, PASSWORD, SEED, pick, unique } from './lib.js';

const MD5 = '144fa7eaa4904e8ee120651997f70dcc';

// Hashes of "appwrite" in each format the import endpoints accept (as in UsersBase).
const IMPORTS = {
    argon2: { password: '$argon2i$v=19$m=20,t=3,p=2$YXBwd3JpdGU$A/54i238ed09ZR4NwlACU5XnkjNBZU9QeOEuhjLiexI' },
    bcrypt: { password: '$2a$15$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq' },
    md5: { password: MD5 },
    phpass: { password: '$P$Br387rwferoKN7uwHZqNMu98q3U8RO.' },
    scrypt: {
        password: '3fdef49701bc4cfaacd551fe017283513284b4731e6945c263246ef948d3cf63b5d269c31fd697246085111a428245e24a4ddc6b64c687bc60a8910dbafc1d5b',
        passwordSalt: 'appwrite', passwordCpu: 16384, passwordMemory: 13, passwordParallel: 2, passwordLength: 64,
    },
    'scrypt-modified': {
        password: 'UlM7JiXRcQhzAGlaonpSqNSLIz475WMddOgLjej5De9vxTy48K6WtqlEzrRFeK4t0COfMhWCb8wuMHgxOFCHFQ==',
        passwordSalt: 'UxLMreBr6tYyjQ==', passwordSaltSeparator: 'Bw==',
        passwordSignerKey: 'XyEKE9RcTDeLEsL/RjwPDBv/RqDl8fb3gpYEOQaPihbxf1ZAtSOHCjuAAa7Q3oHpCYhXSN9tizHgVOwn6krflQ==',
    },
    sha: { password: '4243da0a694e8a2f727c8060fe0507c8fa01ca68146c76d2c190805b638c20c6bf6ba04e21f11ae138785d0bff63c416e6f87badbffad37f6dee50094cc38c70', passwordVersion: 'sha512' },
};

const NOT_FOUND = 'measured on its not-found path: a passkey needs a WebAuthn authenticator to register';

function user(api, extra = {}) {
    const res = api.prep('POST', '/users/md5', { userId: 'unique()', email: `${unique('u')}@example.com`, password: MD5, name: 'Bench User', ...extra }, [201]);
    return res.json('$id');
}

function drop(api, id) {
    api.prep('DELETE', `/users/${id}`, null, [204]);
}

function session(api, id) {
    return api.prep('POST', `/users/${id}/sessions`, null, [201]);
}

function imported({ type, route }) {
    return {
        name: `users.${type}.create`,
        route,
        run(api) {
            const res = api.call('POST', `/users/${type}`, { userId: 'unique()', email: `${unique(type)}@example.com`, name: 'Imported User', ...IMPORTS[type] }, [201]);
            if (res.status === 201) {
                drop(api, res.json('$id'));
            }
        },
    };
}

const bench = define({
    service: 'users',
    scopes: ['users.read', 'users.write', 'sessions.read', 'sessions.write', 'targets.read', 'targets.write', 'providers.write', 'policies.write'],
    seed(api, { run }) {
        const users = [];
        for (let i = 0; i < SEED; i++) {
            const res = api.prep('POST', '/users/md5', { userId: 'unique()', email: `seed-${i}-${run}@example.com`, password: MD5, name: `Seed User ${i}` }, [201]);
            users.push(res.json('$id'));
        }
        // Users only `users.sessions.deleteAll` acts on, so it never races the others' sessions.
        const sessionless = Array.from({ length: 50 }, () => user(api));
        // Users with recovery codes to read and regenerate.
        const recovery = users.slice(0, 50);
        for (const id of recovery) {
            api.prep('PATCH', `/users/${id}/mfa/recovery-codes`, null, [201]);
        }
        const provider = api.control('POST', '/messaging/providers/sendgrid', {
            providerId: 'unique()', name: 'Bench', apiKey: 'bench-key', fromName: 'Bench', fromEmail: 'bench@example.com', enabled: false,
        }, [201]).json('$id');
        const targets = users.slice(0, 50).map((id) => [id, api.prep('POST', `/users/${id}/targets`, {
            targetId: 'unique()', providerType: 'email', identifier: `${unique('t')}@example.com`, providerId: provider,
        }, [201]).json('$id')]);
        // The `custom` MFA factor answers challenges without sending anything.
        api.control('PATCH', '/project/policies/mfa-factors', { custom: true }, [200]);
        return { users, sessionless, recovery, provider, targets };
    },
    scenarios: [
        { name: 'users.get', route: 'GET /v1/users/:userId', weight: 20, run: (api, d) => api.call('GET', `/users/${pick(d.users)}`) },
        {
            name: 'users.list', route: 'GET /v1/users', weight: 10,
            run: (api) => api.call('GET', '/users?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}')),
        },
        {
            name: 'users.search', route: 'GET /v1/users', weight: 4, note: 'full-text search',
            run: (api) => api.call('GET', `/users?search=Seed+User+${Math.floor(Math.random() * 100)}`),
        },
        {
            name: 'users.create', route: 'POST /v1/users', weight: 2,
            run(api) {
                const body = { userId: 'unique()', email: `${unique('new')}@example.com`, name: 'New User' };
                if (HASHING) {
                    body.password = PASSWORD;
                }
                const res = api.call('POST', '/users', body, [201]);
                if (res.status === 201) {
                    drop(api, res.json('$id'));
                }
            },
        },
        { name: 'users.delete', route: 'DELETE /v1/users/:userId', run: (api) => api.call('DELETE', `/users/${user(api)}`, null, [204]) },
        ...[
            { type: 'argon2', route: 'POST /v1/users/argon2' },
            { type: 'bcrypt', route: 'POST /v1/users/bcrypt' },
            { type: 'md5', route: 'POST /v1/users/md5' },
            { type: 'phpass', route: 'POST /v1/users/phpass' },
            { type: 'scrypt', route: 'POST /v1/users/scrypt' },
            { type: 'scrypt-modified', route: 'POST /v1/users/scrypt-modified' },
            { type: 'sha', route: 'POST /v1/users/sha' },
        ].map(imported),
        { name: 'users.email.update', route: 'PATCH /v1/users/:userId/email', run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/email`, { email: `${unique('e')}@example.com` }) },
        { name: 'users.impersonator.update', route: 'PATCH /v1/users/:userId/impersonator', run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/impersonator`, { impersonator: false }) },
        { name: 'users.jwts.create', route: 'POST /v1/users/:userId/jwts', run: (api, d) => api.call('POST', `/users/${pick(d.users)}/jwts`, {}, [201]) },
        { name: 'users.labels.update', route: 'PUT /v1/users/:userId/labels', run: (api, d) => api.call('PUT', `/users/${pick(d.users)}/labels`, { labels: [pick(['admin', 'beta', 'staff']), 'bench'] }) },
        { name: 'users.memberships.list', route: 'GET /v1/users/:userId/memberships', run: (api, d) => api.call('GET', `/users/${pick(d.users)}/memberships`) },
        { name: 'users.mfa.update', route: 'PATCH /v1/users/:userId/mfa', run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/mfa`, { mfa: false }) },
        {
            name: 'users.mfa.authenticators.delete', route: 'DELETE /v1/users/:userId/mfa/authenticators/:type',
            run(api) {
                const id = user(api);
                const secret = session(api, id).json('secret');
                api.control('POST', '/account/mfa/authenticators/totp', null, [200], api.session(secret));
                api.call('DELETE', `/users/${id}/mfa/authenticators/totp`, null, [204]);
                drop(api, id);
            },
        },
        {
            name: 'users.mfa.challenges.get', route: 'GET /v1/users/:userId/mfa/challenges/:challengeId',
            run(api) {
                const id = user(api);
                const secret = session(api, id).json('secret');
                const challenge = api.control('POST', '/account/mfa/challenges', { factor: 'custom' }, [201], api.session(secret)).json('$id');
                api.call('GET', `/users/${id}/mfa/challenges/${challenge}`);
                drop(api, id);
            },
        },
        { name: 'users.mfa.factors.list', route: 'GET /v1/users/:userId/mfa/factors', run: (api, d) => api.call('GET', `/users/${pick(d.users)}/mfa/factors`) },
        { name: 'users.mfa.recoveryCodes.get', route: 'GET /v1/users/:userId/mfa/recovery-codes', run: (api, d) => api.call('GET', `/users/${pick(d.recovery)}/mfa/recovery-codes`) },
        { name: 'users.mfa.recoveryCodes.update', route: 'PUT /v1/users/:userId/mfa/recovery-codes', run: (api, d) => api.call('PUT', `/users/${pick(d.recovery)}/mfa/recovery-codes`) },
        {
            name: 'users.mfa.recoveryCodes.create', route: 'PATCH /v1/users/:userId/mfa/recovery-codes',
            run(api) {
                const id = user(api);
                api.call('PATCH', `/users/${id}/mfa/recovery-codes`, null, [201]);
                drop(api, id);
            },
        },
        {
            name: 'users.name.update', route: 'PATCH /v1/users/:userId/name', weight: 3,
            run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/name`, { name: `Seed User ${Math.floor(Math.random() * 1000)}` }),
        },
        { name: 'users.passkeys.list', route: 'GET /v1/users/:userId/passkeys', run: (api, d) => api.call('GET', `/users/${pick(d.users)}/passkeys`) },
        { name: 'users.passkeys.get', route: 'GET /v1/users/:userId/passkeys/:passkeyId', note: NOT_FOUND, run: (api, d) => api.call('GET', `/users/${pick(d.users)}/passkeys/${unique('pk')}`, null, [404]) },
        {
            name: 'users.passkeys.update', route: 'PATCH /v1/users/:userId/passkeys/:passkeyId', note: NOT_FOUND,
            run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/passkeys/${unique('pk')}`, { name: 'Laptop' }, [404]),
        },
        { name: 'users.passkeys.delete', route: 'DELETE /v1/users/:userId/passkeys/:passkeyId', note: NOT_FOUND, run: (api, d) => api.call('DELETE', `/users/${pick(d.users)}/passkeys/${unique('pk')}`, null, [404]) },
        {
            name: 'users.password.update', route: 'PATCH /v1/users/:userId/password', note: 'hashes the password with Argon2',
            run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/password`, { password: PASSWORD }),
        },
        {
            name: 'users.phone.update', route: 'PATCH /v1/users/:userId/phone',
            run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/phone`, { number: `+1555${String(Math.floor(Math.random() * 1e7)).padStart(7, '0')}` }, [200, 409]),
        },
        { name: 'users.prefs.get', route: 'GET /v1/users/:userId/prefs', weight: 3, run: (api, d) => api.call('GET', `/users/${pick(d.users)}/prefs`) },
        {
            name: 'users.prefs.update', route: 'PATCH /v1/users/:userId/prefs', weight: 4,
            run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/prefs`, { prefs: { theme: pick(['dark', 'light']), n: Math.random() } }),
        },
        { name: 'users.sessions.list', route: 'GET /v1/users/:userId/sessions', run: (api, d) => api.call('GET', `/users/${pick(d.users)}/sessions`) },
        {
            name: 'users.sessions.create', route: 'POST /v1/users/:userId/sessions', weight: 2,
            run(api, d) {
                const id = pick(d.users);
                const res = api.call('POST', `/users/${id}/sessions`, null, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/users/${id}/sessions/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'users.sessions.delete', route: 'DELETE /v1/users/:userId/sessions/:sessionId', weight: 2,
            run(api, d) {
                const id = pick(d.users);
                api.call('DELETE', `/users/${id}/sessions/${session(api, id).json('$id')}`, null, [204]);
            },
        },
        {
            name: 'users.sessions.deleteAll', route: 'DELETE /v1/users/:userId/sessions',
            run(api, d) {
                const id = pick(d.sessionless);
                session(api, id);
                api.call('DELETE', `/users/${id}/sessions`, null, [204]);
            },
        },
        { name: 'users.status.update', route: 'PATCH /v1/users/:userId/status', run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/status`, { status: true }) },
        { name: 'users.targets.list', route: 'GET /v1/users/:userId/targets', weight: 3, run: (api, d) => api.call('GET', `/users/${pick(d.users)}/targets`) },
        {
            name: 'users.targets.create', route: 'POST /v1/users/:userId/targets',
            run(api, d) {
                const id = pick(d.users);
                const res = api.call('POST', `/users/${id}/targets`, {
                    targetId: 'unique()', providerType: 'email', identifier: `${unique('t')}@example.com`, providerId: d.provider,
                }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/users/${id}/targets/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'users.targets.get', route: 'GET /v1/users/:userId/targets/:targetId',
            run(api, d) {
                const [id, target] = pick(d.targets);
                api.call('GET', `/users/${id}/targets/${target}`);
            },
        },
        {
            name: 'users.targets.update', route: 'PATCH /v1/users/:userId/targets/:targetId',
            run(api, d) {
                const [id, target] = pick(d.targets);
                api.call('PATCH', `/users/${id}/targets/${target}`, { name: `Target ${Math.floor(Math.random() * 1000)}` });
            },
        },
        {
            name: 'users.targets.delete', route: 'DELETE /v1/users/:userId/targets/:targetId',
            run(api, d) {
                const id = pick(d.users);
                const target = api.prep('POST', `/users/${id}/targets`, {
                    targetId: 'unique()', providerType: 'email', identifier: `${unique('t')}@example.com`, providerId: d.provider,
                }, [201]).json('$id');
                api.call('DELETE', `/users/${id}/targets/${target}`, null, [204]);
            },
        },
        { name: 'users.tokens.create', route: 'POST /v1/users/:userId/tokens', run: (api, d) => api.call('POST', `/users/${pick(d.users)}/tokens`, null, [201]) },
        { name: 'users.verification.update', route: 'PATCH /v1/users/:userId/verification', run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/verification`, { emailVerification: true }) },
        {
            name: 'users.verification.phone.update', route: 'PATCH /v1/users/:userId/verification/phone',
            run: (api, d) => api.call('PATCH', `/users/${pick(d.users)}/verification/phone`, { phoneVerification: true }),
        },
        { name: 'users.identities.list', route: 'GET /v1/users/identities', run: (api) => api.call('GET', '/users/identities') },
        {
            name: 'users.identities.delete', route: 'DELETE /v1/users/identities/:identityId',
            note: 'measured on its not-found path: an identity needs a sign-in through an OAuth2 provider',
            run: (api) => api.call('DELETE', `/users/identities/${unique('id')}`, null, [404]),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
