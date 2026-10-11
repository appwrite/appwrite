// Account API benchmark: a scenario for every /v1/account endpoint, in a mix
// modelled on client-side Account traffic (reads of the signed-in account
// dominate). See README.md.
//
// Every account call is a client request: sessions are created with the
// server key (`POST /users/:userId/sessions`) and sent as X-Appwrite-Session.
// Flows that need a secret the client only gets out of band read it where the
// dev stack delivers it: token secrets from the account API called with the
// key (which has users.write, so the API returns them), mailed links and codes
// from the mail catcher (APPWRITE_BENCHMARK_MAILS, default
// http://localhost:9503). OAuth2 runs against the dev stack's mock provider,
// passkeys against a software WebAuthn authenticator (WebCrypto ES256).
//
// Most create/sign-in endpoints carry IP-keyed abuse limits (10 or 50 per
// hour): the benchmark needs _APP_OPTIONS_ABUSE=disabled, as the dev stack
// sets it. User-keyed limits are spread over the seeded users and throwaway
// users created per iteration.

import http from 'k6/http';
import { sleep } from 'k6';
import { hmac } from 'k6/crypto';
import { b64decode, b64encode } from 'k6/encoding';
import { CONTROL, ENDPOINT, PASSWORD, SEED, define, pick, unique, random } from './lib.js';

const MAILS = __ENV.APPWRITE_BENCHMARK_MAILS || 'http://localhost:9503';
const ORIGIN = 'http://localhost';
const SUCCESS = encodeURIComponent(`${ORIGIN}/auth/success`);
const FAILURE = encodeURIComponent(`${ORIGIN}/auth/failure`);
const UNVERIFIABLE = 'measured on its not-found path: the passkey is pending, and only verified passkeys can be read or renamed';

// Responses that sign in set the session cookie, and k6 keeps cookies per VU:
// a cookie wins over X-Appwrite-Session, so every request forgets them.
function forget() {
    const jar = http.cookieJar();
    jar.clear(ENDPOINT);
    jar.clear(CONTROL);
}

function guarded(api) {
    const wrap = (fn) => (...args) => {
        const res = fn.apply(api, args);
        forget();
        return res;
    };
    return Object.assign(Object.create(api), { call: wrap(api.call), prep: wrap(api.prep), control: wrap(api.control) });
}

/** A failure the harness counts, for checks that are not a status code. */
function fail(api, message) {
    api.expect({ status: 0, body: message }, [-1], `${api.name} check`);
}

function guest(api, extra = {}) {
    return { 'Content-Type': 'application/json', 'X-Appwrite-Project': api.headers['X-Appwrite-Project'], ...extra };
}

function phone() {
    return `+1${String(2 + Math.floor(random() * 8))}${String(Math.floor(random() * 1e9)).padStart(9, '0')}`;
}

function email(prefix) {
    return `${unique(prefix)}@example.com`.toLowerCase();
}

/** A user through the Users API (no password unless given, so no hashing) with a session. */
function member(api, body = {}) {
    const address = body.email || email('acct');
    const id = api.control('POST', '/users', { userId: 'unique()', email: address, name: 'Bench Account', ...body }, [201]).json('$id');
    const session = api.control('POST', `/users/${id}/sessions`, null, [201]);
    return { id, email: address, session: session.json('$id'), secret: session.json('secret') };
}

function drop(api, id) {
    api.control('DELETE', `/users/${id}`, null, [204]);
}

function dropSession(api, userId, sessionId) {
    api.control('DELETE', `/users/${userId}/sessions/${sessionId}`, null, [204]);
}

function cookie(api, res, name) {
    const jar = res.cookies[name];
    return jar && jar.length ? jar[0].value : '';
}

function sessionCookie(api, res) {
    return cookie(api, res, `a_session_${api.headers['X-Appwrite-Project']}`);
}

/** The value of `key` in a URL's query string, still encoded. */
function query(url, key) {
    const start = url.indexOf(`${key}=`);
    if (start < 0) {
        return '';
    }
    const end = url.indexOf('&', start);
    return url.slice(start + key.length + 1, end < 0 ? undefined : end);
}

/** The newest mail to `address` in the mail catcher, once `read` finds what it needs in it. */
function mail(api, address, read) {
    for (let i = 0; i < 60; i++) {
        const res = http.get(`${MAILS}/email?to.address=${encodeURIComponent(address)}`, { tags: { name: 'mail' } });
        const list = res.status === 200 ? res.json() : [];
        if (list.length) {
            const found = read(list[list.length - 1]);
            if (found) {
                return found;
            }
        }
        sleep(0.25);
    }
    fail(api, `no mail for ${address}`);
    return '';
}

/** The characters of `text` after `marker` while `allowed` accepts them. */
function after(text, marker, allowed) {
    const start = text.indexOf(marker);
    if (start < 0) {
        return '';
    }
    let end = start + marker.length;
    while (end < text.length && allowed(text[end])) {
        end++;
    }
    return text.slice(start + marker.length, end);
}

const isHex = (c) => '0123456789abcdef'.includes(c);
const isDigit = (c) => c >= '0' && c <= '9';

/** A phone token's secret: the key gets it encoded with the user ID, as the client cookie stores it. */
function phoneSecret(res) {
    return JSON.parse(b64decode(res.json('secret'), 'std', 's')).secret;
}

// TOTP (RFC 6238, SHA-1, 6 digits, 30 s), as authenticator apps compute it.
function base32(text) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const out = [];
    let bits = 0;
    let value = 0;
    for (const c of text.toUpperCase()) {
        const index = alphabet.indexOf(c);
        if (index < 0) {
            continue;
        }
        value = ((value << 5) | index) & 0xffff;
        bits += 5;
        if (bits >= 8) {
            out.push((value >>> (bits - 8)) & 255);
            bits -= 8;
        }
    }
    return new Uint8Array(out).buffer;
}

function totp(secret) {
    // The server accepts the current window only: never compute a code about to expire.
    const left = 30000 - (Date.now() % 30000);
    if (left < 1500) {
        sleep(left / 1000 + 0.05);
    }
    let counter = Math.floor(Date.now() / 30000);
    const message = new Uint8Array(8);
    for (let i = 7; i >= 0; i--) {
        message[i] = counter % 256;
        counter = Math.floor(counter / 256);
    }
    const digest = hmac('sha1', base32(secret), message.buffer, 'hex');
    const offset = parseInt(digest.slice(38, 40), 16) & 0xf;
    const code = (parseInt(digest.slice(offset * 2, offset * 2 + 8), 16) & 0x7fffffff) % 1000000;
    return String(code).padStart(6, '0');
}

// A software WebAuthn authenticator holding one ES256 passkey (as Utopia\Auth\Tests\Passkeys\Authenticator).
const bytes = (text) => Array.from(text, (c) => c.charCodeAt(0));
const cbor = (major, n) => (n < 24 ? [(major << 5) | n] : n < 256 ? [(major << 5) | 24, n] : [(major << 5) | 25, n >> 8, n & 255]);
const cborBytes = (b) => [...cbor(2, b.length), ...b];
const cborText = (text) => [...cbor(3, text.length), ...bytes(text)];
const encode = (b) => b64encode(new Uint8Array(b).buffer, 'rawurl');

async function sha256(b) {
    return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new Uint8Array(b))));
}

async function authenticator() {
    const key = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign', 'verify']);
    const point = Array.from(new Uint8Array(await crypto.subtle.exportKey('raw', key.publicKey)));
    return { key, x: point.slice(1, 33), y: point.slice(33, 65), id: Array.from(crypto.getRandomValues(new Uint8Array(16))), handle: '', counter: 0 };
}

async function authenticatorData(auth, rpId, flags) {
    // User present and verified, backup eligible and backed up.
    const c = auth.counter;
    return [...(await sha256(bytes(rpId))), flags | 0x01 | 0x04 | 0x08 | 0x10, (c >>> 24) & 255, (c >>> 16) & 255, (c >>> 8) & 255, c & 255];
}

/** PublicKeyCredential.toJSON() of a registration answering `options` (createPasskey's publicKey). */
async function attest(auth, options) {
    auth.handle = options.user.id;
    const cose = [0xa5, 0x01, 0x02, 0x03, 0x26, 0x20, 0x01, 0x21, ...cborBytes(auth.x), 0x22, ...cborBytes(auth.y)];
    const data = [...(await authenticatorData(auth, options.rp.id, 0x40)), ...new Array(16).fill(0), 0, auth.id.length, ...auth.id, ...cose];
    const attestation = [0xa3, ...cborText('fmt'), ...cborText('none'), ...cborText('attStmt'), 0xa0, ...cborText('authData'), ...cborBytes(data)];
    const client = JSON.stringify({ type: 'webauthn.create', challenge: options.challenge, origin: ORIGIN, crossOrigin: false });
    return {
        id: encode(auth.id),
        rawId: encode(auth.id),
        type: 'public-key',
        response: { clientDataJSON: b64encode(client, 'rawurl'), attestationObject: encode(attestation), transports: ['internal'] },
        clientExtensionResults: {},
    };
}

function der(signature) {
    const integer = (b) => {
        let i = 0;
        while (i < b.length - 1 && b[i] === 0) {
            i++;
        }
        const v = b.slice(i);
        if (v[0] & 0x80) {
            v.unshift(0);
        }
        return [0x02, v.length, ...v];
    };
    const r = integer(signature.slice(0, 32));
    const s = integer(signature.slice(32));
    return [0x30, r.length + s.length, ...r, ...s];
}

/** PublicKeyCredential.toJSON() of a sign-in answering `options` (createPasskeyToken's publicKey). */
async function assert(auth, options) {
    auth.counter++;
    const client = JSON.stringify({ type: 'webauthn.get', challenge: options.challenge, origin: ORIGIN, crossOrigin: false });
    const data = await authenticatorData(auth, options.rpId, 0);
    const signed = [...data, ...(await sha256(bytes(client)))];
    const signature = Array.from(new Uint8Array(await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, auth.key.privateKey, new Uint8Array(signed))));
    return {
        id: encode(auth.id),
        rawId: encode(auth.id),
        type: 'public-key',
        response: { clientDataJSON: b64encode(client, 'rawurl'), authenticatorData: encode(data), signature: encode(der(signature)), userHandle: auth.handle },
        clientExtensionResults: {},
    };
}

// A credential no authenticator made: consumes a challenge, then fails verification.
const FORGED = { id: 'Zm9yZ2Vk', rawId: 'Zm9yZ2Vk', type: 'public-key', response: { clientDataJSON: 'e30' }, clientExtensionResults: {} };

/** Pending passkey registration: the passkey ID and its creation options. */
function pending(api, headers) {
    const res = api.prep('POST', '/account/passkeys', { passkeyId: 'unique()', name: 'Bench' }, [201], headers);
    return { id: res.json('passkeyId'), options: res.json('publicKey') };
}

/** Drops a pending passkey and the challenge its registration left. */
function abandon(api, headers, id) {
    api.prep('PUT', `/account/passkeys/${id}/verification`, { credential: FORGED }, [400, 401], headers);
    api.prep('DELETE', `/account/passkeys/${id}`, null, [204], headers);
}

// Per VU: a user holding a verified passkey and the authenticator that made it.
let holder = null;

async function passkeyHolder(api) {
    if (holder) {
        // Registering and deleting passkeys needs a session signed in within 10 minutes.
        if (Date.now() - holder.since > 8 * 60 * 1000) {
            const session = api.control('POST', `/users/${holder.user.id}/sessions`, null, [201]);
            dropSession(api, holder.user.id, holder.user.session);
            holder.user.session = session.json('$id');
            holder.user.secret = session.json('secret');
            holder.headers = { ...api.session(holder.user.secret), Origin: ORIGIN };
            holder.since = Date.now();
        }
        return holder;
    }
    const user = member(api);
    const since = Date.now();
    const headers = { ...api.session(user.secret), Origin: ORIGIN };
    const auth = await authenticator();
    const registration = pending(api, headers);
    const credential = await attest(auth, registration.options);
    api.prep('PUT', `/account/passkeys/${registration.id}/verification`, { credential }, [200], headers);
    holder = { user, headers, auth, since, passkey: registration.id };
    return holder;
}

/** A `custom` factor MFA challenge for the user's session, with the code the server key reads back. */
function challenge(api, m) {
    const id = api.prep('POST', '/account/mfa/challenges', { factor: 'custom' }, [201], api.session(m.secret)).json('$id');
    return { id, code: api.control('GET', `/users/${m.id}/mfa/challenges/${id}`, null, [200]).json('code') };
}

// Per VU: when it last completed an MFA challenge on a member's session.
const challenged = {};

/** Recovery codes and authenticator removal need an MFA challenge completed in the last 30 minutes. */
function mfaFresh(api, m) {
    if (Date.now() - (challenged[m.id] || 0) < 20 * 60 * 1000) {
        return;
    }
    const c = challenge(api, m);
    api.prep('PUT', '/account/mfa/challenges', { challengeId: c.id, otp: c.code }, [200], api.session(m.secret));
    challenged[m.id] = Date.now();
}

function awaited(run) {
    return (api, data) => run(api, data).catch((e) => fail(api, String(e)));
}

/** An ID token for `sub` signed by the mock provider's key (the dev stack's own JWKS). */
function idToken(api, sub) {
    const now = Math.floor(Date.now() / 1000) - 5;
    const claims = { iss: 'https://localhost/v1/mock', aud: '1', iat: now, exp: now + 3600, sub, email: `${sub}@example.com`, email_verified: true };
    return api.control('GET', `/mock/tests/general/oauth2/id-token?claims=${encodeURIComponent(JSON.stringify(claims))}`, null, [200]).json('token');
}

/** Starts the browser OAuth2 flow with the mock provider: the state and the nonce cookie it binds. */
function oauth2(api) {
    const res = api.prep('GET', `/account/sessions/oauth2/mock?success=${SUCCESS}&failure=${FAILURE}`, null, [301], guest(api));
    const name = `a_oauth2_${api.headers['X-Appwrite-Project']}`;
    return { state: query(res.headers.Location || '', 'state'), cookie: `${name}=${cookie(api, res, name)}` };
}

const bench = define({
    service: 'account',
    scopes: ['users.read', 'users.write', 'sessions.read', 'sessions.write', 'targets.read', 'targets.write', 'policies.write', 'platforms.write', 'project.write'],
    seed(api, { run, admin }) {
        api = guarded(api);
        const project = api.headers['X-Appwrite-Project'];
        const pool = Math.max(10, Math.floor(SEED / 4));

        // The mock OAuth2 provider, for the browser flow and for native ID token sign-in.
        api.control('PATCH', `/projects/${project}/oauth2`, { provider: 'mock', appId: '1', secret: '123456', enabled: true }, [200], admin);
        api.control('PATCH', '/mock/tests/general/oauth2/native', { projectId: project, provider: 'mock', enabled: true }, [204], admin);
        // Passkeys on localhost: the auth method and a web platform for the relying party.
        api.control('PATCH', '/project/auth-methods/passkey', { enabled: true }, [200]);
        api.control('POST', '/project/platforms/web', { platformId: 'unique()', name: 'localhost', hostname: 'localhost' }, [201]);
        // The `custom` MFA factor answers challenges without sending anything.
        api.control('PATCH', '/project/policies/mfa-factors', { custom: true }, [200]);

        // Signed-in users the read and idempotent-update scenarios share, each with recovery codes and a push target.
        const members = [];
        for (let i = 0; i < SEED; i++) {
            const m = member(api, { email: `member-${i}-${run}@example.com`.toLowerCase(), name: `Member ${i}` });
            api.control('PATCH', `/users/${m.id}/mfa/recovery-codes`, null, [201]);
            m.target = api.prep('POST', '/account/targets/push', { targetId: 'unique()', identifier: unique('push') }, [201], api.session(m.secret)).json('$id');
            members.push(m);
        }
        // Users with a password (hashed with Argon2) and one session, for the endpoints that check it.
        const passwords = Array.from({ length: pool }, (_, i) => member(api, { email: `password-${i}-${run}@example.com`.toLowerCase(), password: PASSWORD }));
        // Users that only sign in with their password: their sessions never outlive an iteration.
        const logins = Array.from({ length: pool }, (_, i) => {
            const address = `login-${i}-${run}@example.com`.toLowerCase();
            api.control('POST', '/users', { userId: 'unique()', email: address, password: PASSWORD, name: `Login ${i}` }, [201]);
            return address;
        });
        // Users with an email and a phone and no lasting session, for token sign-ins and session lifecycles.
        const spares = Array.from({ length: pool }, (_, i) => {
            const number = phone();
            const res = api.control('POST', '/users', { userId: 'unique()', email: `spare-${i}-${run}@example.com`.toLowerCase(), phone: number, name: `Spare ${i}` }, [201]);
            return { id: res.json('$id'), email: res.json('email'), phone: number };
        });
        // Users signed in through the mock provider's ID tokens: with an identity, and with their sessions.
        const identities = Array.from({ length: Math.max(5, Math.floor(SEED / 8)) }, () => {
            const res = api.prep('POST', '/account/sessions/id-token', { provider: 'mock', idToken: idToken(api, unique('oidc')) }, [201], guest(api));
            return sessionCookie(api, res);
        });
        const natives = Array.from({ length: Math.max(5, Math.floor(SEED / 8)) }, () => {
            const sub = unique('native');
            api.prep('POST', '/account/sessions/id-token', { provider: 'mock', idToken: idToken(api, sub) }, [201], guest(api));
            return sub;
        });
        // The mock provider's browser flow always signs in one user: create it before scenarios race for it.
        const flow = oauth2(api);
        const done = api.prep('GET', `/account/sessions/oauth2/mock/redirect?code=abcdef&state=${flow.state}`, null, [301], guest(api, { Cookie: flow.cookie }));
        api.prep('DELETE', '/account/sessions/current', null, [204], api.session(sessionCookie(api, done)));
        return { members, passwords, logins, spares, identities, natives, state: flow.state };
    },
    scenarios: [
        { name: 'account.get', route: 'GET /v1/account', weight: 12, run: (api, d) => api.call('GET', '/account', null, [200], api.session(pick(d.members).secret)) },
        {
            name: 'account.create', route: 'POST /v1/account', weight: 2, note: 'hashes the password with Argon2',
            run(api) {
                const res = api.call('POST', '/account', { userId: 'unique()', email: email('signup'), password: PASSWORD, name: 'New Account' }, [201], guest(api));
                if (res.status === 201) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'account.delete', route: 'DELETE /v1/account',
            run(api) {
                api.call('DELETE', '/account', null, [204], api.session(member(api).secret));
            },
        },
        {
            name: 'account.email.update', route: 'PATCH /v1/account/email', note: 'verifies the password with Argon2',
            run: (api, d) => api.call('PATCH', '/account/email', { email: email('moved'), password: PASSWORD }, [200], api.session(pick(d.passwords).secret)),
        },
        { name: 'account.identities.list', route: 'GET /v1/account/identities', weight: 2, run: (api, d) => api.call('GET', '/account/identities', null, [200], api.session(pick(d.identities))) },
        {
            name: 'account.identities.delete', route: 'DELETE /v1/account/identities/:identityId',
            run(api) {
                const signin = api.prep('POST', '/account/sessions/id-token', { provider: 'mock', idToken: idToken(api, unique('gone')) }, [201], guest(api));
                const headers = api.session(sessionCookie(api, signin));
                const identity = api.prep('GET', '/account/identities', null, [200], headers).json('identities.0.$id');
                api.call('DELETE', `/account/identities/${identity}`, null, [204], headers);
                drop(api, signin.json('userId'));
            },
        },
        { name: 'account.jwts.create', route: 'POST /v1/account/jwts', weight: 4, run: (api, d) => api.call('POST', '/account/jwts', {}, [201], api.session(pick(d.members).secret)) },
        { name: 'account.mfa.update', route: 'PATCH /v1/account/mfa', run: (api, d) => api.call('PATCH', '/account/mfa', { mfa: false }, [200], api.session(pick(d.members).secret)) },
        {
            name: 'account.mfa.authenticators.create', route: 'POST /v1/account/mfa/authenticators/:type',
            run(api) {
                const m = member(api);
                api.call('POST', '/account/mfa/authenticators/totp', null, [200], api.session(m.secret));
                drop(api, m.id);
            },
        },
        {
            name: 'account.mfa.authenticators.update', route: 'PUT /v1/account/mfa/authenticators/:type', note: 'verifies a TOTP code',
            run(api) {
                const m = member(api);
                const headers = api.session(m.secret);
                const secret = api.prep('POST', '/account/mfa/authenticators/totp', null, [200], headers).json('secret');
                api.call('PUT', '/account/mfa/authenticators/totp', { otp: totp(secret) }, [200], headers);
                drop(api, m.id);
            },
        },
        {
            name: 'account.mfa.authenticators.delete', route: 'DELETE /v1/account/mfa/authenticators/:type',
            run(api) {
                const m = member(api);
                const headers = api.session(m.secret);
                const secret = api.prep('POST', '/account/mfa/authenticators/totp', null, [200], headers).json('secret');
                api.prep('PUT', '/account/mfa/authenticators/totp', { otp: totp(secret) }, [200], headers);
                mfaFresh(api, m);
                api.call('DELETE', '/account/mfa/authenticators/totp', null, [204], headers);
                drop(api, m.id);
            },
        },
        {
            name: 'account.mfa.challenges.create', route: 'POST /v1/account/mfa/challenges', note: 'with the `custom` factor, which sends nothing',
            run(api, d) {
                const m = pick(d.members);
                const headers = api.session(m.secret);
                const res = api.call('POST', '/account/mfa/challenges', { factor: 'custom' }, [201], headers);
                if (res.status === 201) {
                    const code = api.control('GET', `/users/${m.id}/mfa/challenges/${res.json('$id')}`, null, [200]).json('code');
                    api.prep('PUT', '/account/mfa/challenges', { challengeId: res.json('$id'), otp: code }, [200], headers);
                }
            },
        },
        {
            name: 'account.mfa.challenges.update', route: 'PUT /v1/account/mfa/challenges', note: 'with the `custom` factor',
            run(api, d) {
                const m = pick(d.members);
                const c = challenge(api, m);
                api.call('PUT', '/account/mfa/challenges', { challengeId: c.id, otp: c.code }, [200], api.session(m.secret));
            },
        },
        { name: 'account.mfa.factors.list', route: 'GET /v1/account/mfa/factors', weight: 3, run: (api, d) => api.call('GET', '/account/mfa/factors', null, [200], api.session(pick(d.members).secret)) },
        {
            name: 'account.mfa.recoveryCodes.get', route: 'GET /v1/account/mfa/recovery-codes',
            run(api, d) {
                const m = pick(d.members);
                mfaFresh(api, m);
                api.call('GET', '/account/mfa/recovery-codes', null, [200], api.session(m.secret));
            },
        },
        {
            name: 'account.mfa.recoveryCodes.create', route: 'POST /v1/account/mfa/recovery-codes',
            run(api) {
                const m = member(api);
                api.call('POST', '/account/mfa/recovery-codes', null, [201], api.session(m.secret));
                drop(api, m.id);
            },
        },
        {
            name: 'account.mfa.recoveryCodes.update', route: 'PATCH /v1/account/mfa/recovery-codes',
            run(api, d) {
                const m = pick(d.members);
                mfaFresh(api, m);
                api.call('PATCH', '/account/mfa/recovery-codes', null, [200], api.session(m.secret));
            },
        },
        {
            name: 'account.name.update', route: 'PATCH /v1/account/name', weight: 3,
            run: (api, d) => api.call('PATCH', '/account/name', { name: `Member ${Math.floor(random() * 1000)}` }, [200], api.session(pick(d.members).secret)),
        },
        {
            name: 'account.passkeys.list', route: 'GET /v1/account/passkeys', weight: 2,
            run: awaited(async (api) => {
                const h = await passkeyHolder(api);
                api.call('GET', '/account/passkeys', null, [200], h.headers);
            }),
        },
        {
            name: 'account.passkeys.create', route: 'POST /v1/account/passkeys', note: 'starts a registration: returns the WebAuthn creation options',
            run: awaited(async (api) => {
                const h = await passkeyHolder(api);
                const res = api.call('POST', '/account/passkeys', { passkeyId: 'unique()', name: 'Bench' }, [201], h.headers);
                if (res.status === 201) {
                    abandon(api, h.headers, res.json('passkeyId'));
                }
            }),
        },
        {
            name: 'account.passkeys.get', route: 'GET /v1/account/passkeys/:passkeyId',
            run: awaited(async (api) => {
                const h = await passkeyHolder(api);
                api.call('GET', `/account/passkeys/${h.passkey}`, null, [200], h.headers);
            }),
        },
        {
            name: 'account.passkeys.update', route: 'PATCH /v1/account/passkeys/:passkeyId',
            run: awaited(async (api) => {
                const h = await passkeyHolder(api);
                api.call('PATCH', `/account/passkeys/${h.passkey}`, { name: `Passkey ${Math.floor(random() * 1000)}` }, [200], h.headers);
            }),
        },
        {
            name: 'account.passkeys.delete', route: 'DELETE /v1/account/passkeys/:passkeyId', note: 'deletes a pending passkey (its registration abandoned)',
            run: awaited(async (api) => {
                const h = await passkeyHolder(api);
                const registration = pending(api, h.headers);
                api.prep('PUT', `/account/passkeys/${registration.id}/verification`, { credential: FORGED }, [400, 401], h.headers);
                api.call('DELETE', `/account/passkeys/${registration.id}`, null, [204], h.headers);
            }),
        },
        {
            name: 'account.passkeys.verification.update', route: 'PUT /v1/account/passkeys/:passkeyId/verification', note: 'verifies an ES256 attestation',
            run: awaited(async (api) => {
                const h = await passkeyHolder(api);
                const registration = pending(api, h.headers);
                const credential = await attest(await authenticator(), registration.options);
                api.call('PUT', `/account/passkeys/${registration.id}/verification`, { credential }, [200], h.headers);
                api.prep('DELETE', `/account/passkeys/${registration.id}`, null, [204], h.headers);
            }),
        },
        {
            name: 'account.password.update', route: 'PATCH /v1/account/password', note: 'verifies the old password and hashes the new one with Argon2',
            run: (api, d) => api.call('PATCH', '/account/password', { password: PASSWORD, oldPassword: PASSWORD }, [200], api.session(pick(d.passwords).secret)),
        },
        {
            name: 'account.phone.update', route: 'PATCH /v1/account/phone', note: 'verifies the password with Argon2',
            run: (api, d) => api.call('PATCH', '/account/phone', { phone: phone(), password: PASSWORD }, [200], api.session(pick(d.passwords).secret)),
        },
        { name: 'account.prefs.get', route: 'GET /v1/account/prefs', weight: 5, run: (api, d) => api.call('GET', '/account/prefs', null, [200], api.session(pick(d.members).secret)) },
        {
            name: 'account.prefs.update', route: 'PATCH /v1/account/prefs', weight: 4,
            run: (api, d) => api.call('PATCH', '/account/prefs', { prefs: { theme: pick(['dark', 'light']), n: random() } }, [200], api.session(pick(d.members).secret)),
        },
        {
            name: 'account.recovery.create', route: 'POST /v1/account/recovery', note: 'queues the recovery email',
            run(api) {
                const m = member(api);
                api.call('POST', '/account/recovery', { email: m.email, url: `${ORIGIN}/recovery` }, [201], guest(api));
                drop(api, m.id);
            },
        },
        {
            name: 'account.recovery.update', route: 'PUT /v1/account/recovery', note: 'hashes the new password with Argon2',
            run(api) {
                const m = member(api);
                const secret = api.prep('POST', '/account/recovery', { email: m.email, url: `${ORIGIN}/recovery` }, [201]).json('secret');
                api.call('PUT', '/account/recovery', { userId: m.id, secret, password: PASSWORD }, [200], guest(api));
                drop(api, m.id);
            },
        },
        {
            name: 'account.recovery.otp.create', route: 'POST /v1/account/recovery/otp', note: 'queues the recovery email',
            run(api) {
                const m = member(api);
                api.call('POST', '/account/recovery/otp', { email: m.email }, [201], guest(api));
                drop(api, m.id);
            },
        },
        {
            name: 'account.recovery.otp.update', route: 'PUT /v1/account/recovery/otp', note: 'hashes the new password with Argon2',
            run(api) {
                const m = member(api);
                const secret = api.prep('POST', '/account/recovery/otp', { email: m.email }, [201]).json('secret');
                api.call('PUT', '/account/recovery/otp', { userId: m.id, secret, password: PASSWORD }, [200], guest(api));
                drop(api, m.id);
            },
        },
        { name: 'account.sessions.list', route: 'GET /v1/account/sessions', weight: 4, run: (api, d) => api.call('GET', '/account/sessions', null, [200], api.session(pick(d.members).secret)) },
        {
            name: 'account.sessions.deleteAll', route: 'DELETE /v1/account/sessions',
            run(api) {
                const m = member(api);
                api.call('DELETE', '/account/sessions', null, [204], api.session(m.secret));
                drop(api, m.id);
            },
        },
        {
            name: 'account.sessions.get', route: 'GET /v1/account/sessions/:sessionId', weight: 3,
            run(api, d) {
                const m = pick(d.members);
                api.call('GET', `/account/sessions/${m.session}`, null, [200], api.session(m.secret));
            },
        },
        {
            name: 'account.sessions.update', route: 'PATCH /v1/account/sessions/:sessionId', weight: 2, note: 'extends an email session (no OAuth2 refresh)',
            run(api, d) {
                const m = pick(d.members);
                api.call('PATCH', `/account/sessions/${m.session}`, null, [200], api.session(m.secret));
            },
        },
        {
            name: 'account.sessions.delete', route: 'DELETE /v1/account/sessions/:sessionId', weight: 2,
            run(api, d) {
                const spare = pick(d.spares);
                const session = api.control('POST', `/users/${spare.id}/sessions`, null, [201]);
                api.call('DELETE', `/account/sessions/${session.json('$id')}`, null, [204], api.session(session.json('secret')));
            },
        },
        {
            name: 'account.sessions.anonymous.create', route: 'POST /v1/account/sessions/anonymous', weight: 2,
            run(api) {
                const res = api.call('POST', '/account/sessions/anonymous', null, [201], guest(api));
                if (res.status === 201) {
                    drop(api, res.json('userId'));
                }
            },
        },
        {
            name: 'account.sessions.email.create', route: 'POST /v1/account/sessions/email', weight: 3, note: 'verifies the password with Argon2',
            run(api, d) {
                const res = api.call('POST', '/account/sessions/email', { email: pick(d.logins), password: PASSWORD }, [201], guest(api));
                if (res.status === 201) {
                    dropSession(api, res.json('userId'), res.json('$id'));
                }
            },
        },
        {
            name: 'account.sessions.idToken.create', route: 'POST /v1/account/sessions/id-token', note: 'a returning user, with a token of the mock provider (its JWKS served by the dev stack)',
            run(api, d) {
                const res = api.call('POST', '/account/sessions/id-token', { provider: 'mock', idToken: idToken(api, pick(d.natives)) }, [201], guest(api));
                if (res.status === 201) {
                    dropSession(api, res.json('userId'), res.json('$id'));
                }
            },
        },
        {
            name: 'account.sessions.magicUrl.update', route: 'PUT /v1/account/sessions/magic-url',
            run(api, d) {
                const spare = pick(d.spares);
                const secret = api.prep('POST', '/account/tokens/magic-url', { userId: spare.id, email: spare.email, url: `${ORIGIN}/magic` }, [201]).json('secret');
                const res = api.call('PUT', '/account/sessions/magic-url', { userId: spare.id, secret }, [201], guest(api));
                if (res.status === 201) {
                    dropSession(api, spare.id, res.json('$id'));
                }
            },
        },
        {
            name: 'account.sessions.oauth2.create', route: 'GET /v1/account/sessions/oauth2/:provider', note: 'redirects to the mock provider',
            run: (api) => api.call('GET', `/account/sessions/oauth2/mock?success=${SUCCESS}&failure=${FAILURE}`, null, [301], guest(api)),
        },
        {
            name: 'account.sessions.oauth2.redirect.get', route: 'GET /v1/account/sessions/oauth2/:provider/redirect', note: 'exchanges the code with the mock provider (served by the dev stack) and signs in',
            run(api) {
                const flow = oauth2(api);
                const res = api.call('GET', `/account/sessions/oauth2/mock/redirect?code=abcdef&state=${flow.state}`, null, [301], guest(api, { Cookie: flow.cookie }));
                if (res.status !== 301) {
                    return;
                }
                if (!String(res.headers.Location || '').startsWith(decodeURIComponent(SUCCESS))) {
                    fail(api, `redirected to ${res.headers.Location}`);
                    return;
                }
                // Every flow signs in the mock provider's one user; a burst past its session limit can evict this one first.
                api.prep('DELETE', '/account/sessions/current', null, [204, 401], api.session(sessionCookie(api, res)));
            },
        },
        {
            name: 'account.sessions.oauth2.callback.get', route: 'GET /v1/account/sessions/oauth2/callback/:provider/:projectId', note: 'redirects to the redirect endpoint',
            run: (api, d) => api.call('GET', `/account/sessions/oauth2/callback/mock/${api.headers['X-Appwrite-Project']}?code=abcdef&state=${d.state}`, null, [301], guest(api)),
        },
        {
            name: 'account.sessions.oauth2.callback.create', route: 'POST /v1/account/sessions/oauth2/callback/:provider/:projectId', note: 'redirects to the redirect endpoint',
            run: (api, d) => api.call('POST', `/account/sessions/oauth2/callback/mock/${api.headers['X-Appwrite-Project']}`, { code: 'abcdef', state: decodeURIComponent(d.state) }, [301], guest(api)),
        },
        {
            name: 'account.sessions.phone.update', route: 'PUT /v1/account/sessions/phone',
            run(api, d) {
                const spare = pick(d.spares);
                const secret = phoneSecret(api.prep('POST', '/account/tokens/phone', { userId: spare.id, phone: spare.phone }, [201]));
                const res = api.call('PUT', '/account/sessions/phone', { userId: spare.id, secret }, [201], guest(api));
                if (res.status === 201) {
                    dropSession(api, spare.id, res.json('$id'));
                }
            },
        },
        {
            name: 'account.sessions.token.create', route: 'POST /v1/account/sessions/token', weight: 2,
            run(api, d) {
                const spare = pick(d.spares);
                const secret = api.control('POST', `/users/${spare.id}/tokens`, null, [201]).json('secret');
                const res = api.call('POST', '/account/sessions/token', { userId: spare.id, secret }, [201], guest(api));
                if (res.status === 201) {
                    dropSession(api, spare.id, res.json('$id'));
                }
            },
        },
        {
            name: 'account.status.update', route: 'PATCH /v1/account/status',
            run(api) {
                const m = member(api);
                api.call('PATCH', '/account/status', null, [200], api.session(m.secret));
                drop(api, m.id);
            },
        },
        {
            name: 'account.targets.push.create', route: 'POST /v1/account/targets/push',
            run(api, d) {
                const spare = pick(d.spares);
                const session = api.control('POST', `/users/${spare.id}/sessions`, null, [201]);
                const headers = api.session(session.json('secret'));
                const res = api.call('POST', '/account/targets/push', { targetId: 'unique()', identifier: unique('push') }, [201], headers);
                if (res.status === 201) {
                    api.prep('DELETE', `/account/targets/${res.json('$id')}/push`, null, [204], headers);
                }
                dropSession(api, spare.id, session.json('$id'));
            },
        },
        {
            name: 'account.targets.push.update', route: 'PUT /v1/account/targets/:targetId/push',
            run(api, d) {
                const m = pick(d.members);
                api.call('PUT', `/account/targets/${m.target}/push`, { identifier: unique('push') }, [200], api.session(m.secret));
            },
        },
        {
            name: 'account.targets.push.delete', route: 'DELETE /v1/account/targets/:targetId/push',
            run(api, d) {
                const spare = pick(d.spares);
                const session = api.control('POST', `/users/${spare.id}/sessions`, null, [201]);
                const headers = api.session(session.json('secret'));
                const target = api.prep('POST', '/account/targets/push', { targetId: 'unique()', identifier: unique('push') }, [201], headers).json('$id');
                api.call('DELETE', `/account/targets/${target}/push`, null, [204], headers);
                dropSession(api, spare.id, session.json('$id'));
            },
        },
        {
            name: 'account.tokens.email.create', route: 'POST /v1/account/tokens/email', note: 'queues the OTP email',
            run(api) {
                const m = member(api);
                api.call('POST', '/account/tokens/email', { userId: m.id, email: m.email }, [201], guest(api));
                drop(api, m.id);
            },
        },
        {
            name: 'account.tokens.magicUrl.create', route: 'POST /v1/account/tokens/magic-url', note: 'queues the magic URL email',
            run(api) {
                const m = member(api);
                api.call('POST', '/account/tokens/magic-url', { userId: m.id, email: m.email, url: `${ORIGIN}/magic` }, [201], guest(api));
                drop(api, m.id);
            },
        },
        {
            name: 'account.tokens.oauth2.create', route: 'GET /v1/account/tokens/oauth2/:provider', note: 'redirects to the mock provider',
            run: (api) => api.call('GET', `/account/tokens/oauth2/mock?success=${SUCCESS}&failure=${FAILURE}`, null, [301], guest(api)),
        },
        {
            name: 'account.tokens.passkey.create', route: 'POST /v1/account/tokens/passkey', note: 'starts a sign-in: returns the WebAuthn request options',
            run(api) {
                const headers = guest(api, { Origin: ORIGIN });
                const res = api.call('POST', '/account/tokens/passkey', null, [201], headers);
                if (res.status === 201) {
                    // Consume the challenge: a forged credential fails after it is spent.
                    api.prep('PUT', '/account/tokens/passkey', { challengeId: res.json('$id'), credential: FORGED }, [400, 401], headers);
                }
            },
        },
        {
            name: 'account.tokens.passkey.update', route: 'PUT /v1/account/tokens/passkey', note: 'verifies an ES256 assertion',
            run: awaited(async (api) => {
                const h = await passkeyHolder(api);
                const headers = guest(api, { Origin: ORIGIN });
                const challenge = api.prep('POST', '/account/tokens/passkey', null, [201], headers);
                const credential = await assert(h.auth, challenge.json('publicKey'));
                const res = api.call('PUT', '/account/tokens/passkey', { challengeId: challenge.json('$id'), credential }, [201], headers);
                if (res.status === 201) {
                    const session = api.prep('POST', '/account/sessions/token', { userId: h.user.id, secret: res.json('secret') }, [201], guest(api));
                    dropSession(api, h.user.id, session.json('$id'));
                }
            }),
        },
        {
            name: 'account.tokens.phone.create', route: 'POST /v1/account/tokens/phone', note: 'queues the SMS (the dev stack\'s mock provider)',
            run(api) {
                const number = phone();
                const m = member(api, { phone: number });
                api.call('POST', '/account/tokens/phone', { userId: m.id, phone: number }, [201], guest(api));
                drop(api, m.id);
            },
        },
        {
            name: 'account.verifications.email.create', route: 'POST /v1/account/verifications/email', note: 'queues the verification email',
            run(api) {
                const m = member(api);
                api.call('POST', '/account/verifications/email', { url: `${ORIGIN}/verify` }, [201], api.session(m.secret));
                drop(api, m.id);
            },
        },
        {
            name: 'account.verifications.email.update', route: 'PUT /v1/account/verifications/email', note: 'with the secret from the mailed link',
            run(api) {
                const m = member(api);
                const headers = api.session(m.secret);
                api.prep('POST', '/account/verifications/email', { url: `${ORIGIN}/verify` }, [201], headers);
                const secret = mail(api, m.email, (message) => after(String(message.html || ''), 'secret=', isHex));
                if (secret) {
                    api.call('PUT', '/account/verifications/email', { userId: m.id, secret }, [200], headers);
                }
                drop(api, m.id);
            },
        },
        {
            name: 'account.verifications.email.otp.create', route: 'POST /v1/account/verifications/email/otp', note: 'queues the OTP email',
            run(api) {
                const m = member(api);
                api.call('POST', '/account/verifications/email/otp', {}, [201], api.session(m.secret));
                drop(api, m.id);
            },
        },
        {
            name: 'account.verifications.email.otp.update', route: 'PUT /v1/account/verifications/email/otp', note: 'with the code from the mail',
            run(api) {
                const m = member(api);
                const headers = api.session(m.secret);
                api.prep('POST', '/account/verifications/email/otp', {}, [201], headers);
                const otp = mail(api, m.email, (message) => after(String(message.text || ''), 'OTP ', isDigit));
                if (otp) {
                    api.call('PUT', '/account/verifications/email/otp', { userId: m.id, secret: otp }, [200], headers);
                }
                drop(api, m.id);
            },
        },
        {
            name: 'account.verifications.phone.create', route: 'POST /v1/account/verifications/phone', note: 'queues the SMS (the dev stack\'s mock provider)',
            run(api) {
                const m = member(api, { phone: phone() });
                api.call('POST', '/account/verifications/phone', null, [201], api.session(m.secret));
                drop(api, m.id);
            },
        },
        {
            name: 'account.verifications.phone.update', route: 'PUT /v1/account/verifications/phone',
            run(api) {
                const number = phone();
                const m = member(api, { phone: number });
                const secret = phoneSecret(api.prep('POST', '/account/tokens/phone', { userId: m.id, phone: number }, [201]));
                api.call('PUT', '/account/verifications/phone', { userId: m.id, secret }, [200], api.session(m.secret));
                drop(api, m.id);
            },
        },
    ].map((s) => ({ ...s, run: (api, data, run) => s.run(guarded(api), data, run) })),
});

export const options = { ...bench.options, maxRedirects: 0 };
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
