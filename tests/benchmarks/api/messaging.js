// Messaging API benchmark: a scenario for every /v1/messaging endpoint, in a mix
// modelled on server-side Messaging traffic (reads dominate). See README.md.
//
// Nothing is ever delivered: every provider is created disabled with fake
// credentials (a provider record never contacts its service), and every message
// is a draft, which the API stores without queueing it for the messaging worker.

import { define, SEED, pick, unique, random } from './lib.js';

const SERVICE_ACCOUNT = {
    type: 'service_account',
    project_id: 'bench-project',
    private_key_id: 'bench-private-key-id',
    client_email: 'bench@bench-project.iam.gserviceaccount.com',
    token_uri: 'https://oauth2.googleapis.com/token',
    private_key: '-----BEGIN PRIVATE KEY-----\nbench\n-----END PRIVATE KEY-----',
};

// A provider of each type: the body that creates it (disabled, fake credentials) and one that updates it.
const PROVIDERS = {
    apns: {
        create: { authKey: 'bench-auth-key', authKeyId: 'bench-key-id', teamId: 'bench-team', bundleId: 'io.example.bench', sandbox: true },
        update: () => ({ bundleId: `io.example.bench${Math.floor(random() * 1000)}` }),
    },
    appwrite: {
        create: { qos: 1, expiry: 3600 },
        update: () => ({ qos: pick([0, 1]), expiry: 60 * Math.floor(1 + random() * 60) }),
    },
    fcm: {
        create: { serviceAccountJSON: SERVICE_ACCOUNT },
        update: () => ({ serviceAccountJSON: SERVICE_ACCOUNT }),
    },
    mailgun: {
        create: { apiKey: 'bench-key', domain: 'example.com', isEuRegion: false, fromName: 'Bench', fromEmail: 'bench@example.com' },
        update: () => ({ isEuRegion: pick([true, false]), fromName: `Bench ${Math.floor(random() * 1000)}` }),
    },
    msg91: {
        create: { templateId: 'bench-template', senderId: 'bench-sender', authKey: 'bench-auth-key' },
        update: () => ({ templateId: `bench-template-${Math.floor(random() * 1000)}` }),
    },
    resend: {
        create: { apiKey: 'bench-key', fromName: 'Bench', fromEmail: 'bench@example.com' },
        update: () => ({ fromName: `Bench ${Math.floor(random() * 1000)}` }),
    },
    sendgrid: {
        create: { apiKey: 'bench-key', fromName: 'Bench', fromEmail: 'bench@example.com' },
        update: () => ({ fromName: `Bench ${Math.floor(random() * 1000)}` }),
    },
    ses: {
        create: { accessKey: 'bench-access-key', secretKey: 'bench-secret-key', region: 'us-east-1', fromName: 'Bench', fromEmail: 'bench@example.com' },
        update: () => ({ region: pick(['us-east-1', 'eu-west-1']) }),
    },
    smtp: {
        // The dev stack's mail catcher, should a provider ever be enabled.
        create: { host: 'maildev', port: 1025, username: 'bench', password: 'bench-password', encryption: 'none', autoTLS: false, fromName: 'Bench', fromEmail: 'bench@example.com' },
        update: () => ({ host: 'maildev', port: 1025, mailer: `bench-${Math.floor(random() * 1000)}` }),
    },
    telesign: {
        create: { from: '+15555550100', customerId: 'bench-customer', apiKey: 'bench-key' },
        update: () => ({ customerId: `bench-customer-${Math.floor(random() * 1000)}` }),
    },
    textmagic: {
        create: { from: '+15555550100', username: 'bench', apiKey: 'bench-key' },
        update: () => ({ username: `bench-${Math.floor(random() * 1000)}` }),
    },
    twilio: {
        create: { from: '+15555550100', accountSid: 'bench-account-sid', authToken: 'bench-auth-token' },
        update: () => ({ accountSid: `bench-account-sid-${Math.floor(random() * 1000)}` }),
    },
    vonage: {
        create: { from: '+15555550100', apiKey: 'bench-key', apiSecret: 'bench-secret' },
        update: () => ({ apiKey: `bench-key-${Math.floor(random() * 1000)}` }),
    },
};

const LIMIT = '?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}');

function provider(api, type, name = 'Bench') {
    return api.prep('POST', `/messaging/providers/${type}`, { providerId: 'unique()', name, enabled: false, ...PROVIDERS[type].create }, [201]).json('$id');
}

// A draft of each message type, addressed to `targets`: stored, never queued for delivery.
const MESSAGES = {
    email: (targets) => ({ subject: 'Bench subject', content: 'Bench content', targets, draft: true }),
    sms: (targets) => ({ content: 'Bench content', targets, draft: true }),
    push: (targets) => ({ title: 'Bench title', body: 'Bench body', data: { bench: 'true' }, targets, draft: true }),
};

function message(api, type, targets) {
    return api.prep('POST', `/messaging/messages/${type}`, { messageId: 'unique()', ...MESSAGES[type](targets) }, [201]).json('$id');
}

function topic(api, name = 'Bench Topic') {
    return api.prep('POST', '/messaging/topics', { topicId: 'unique()', name }, [201]).json('$id');
}

const createProvider = ({ type, route }) => ({
    name: `messaging.providers.${type}.create`,
    route,
    run(api) {
        const res = api.call('POST', `/messaging/providers/${type}`, { providerId: 'unique()', name: 'Bench New', enabled: false, ...PROVIDERS[type].create }, [201]);
        if (res.status === 201) {
            api.prep('DELETE', `/messaging/providers/${res.json('$id')}`, null, [204]);
        }
    },
});

const updateProvider = ({ type, route }) => ({
    name: `messaging.providers.${type}.update`,
    route,
    run: (api, d) => api.call('PATCH', `/messaging/providers/${type}/${pick(d.providers[type])}`, {
        name: `Bench ${Math.floor(random() * 1000)}`, enabled: false, ...PROVIDERS[type].update(),
    }),
});

function createMessage({ type, route }) {
    return {
        name: `messaging.messages.${type}.create`,
        route,
        note: 'a draft: stored, not queued for delivery',
        run(api, d) {
            const res = api.call('POST', `/messaging/messages/${type}`, { messageId: 'unique()', ...MESSAGES[type](d.targets[type].slice(0, 5)) }, [201]);
            if (res.status === 201) {
                api.prep('DELETE', `/messaging/messages/${res.json('$id')}`, null, [204]);
            }
        },
    };
}

const bench = define({
    service: 'messaging',
    scopes: [
        'providers.read', 'providers.write', 'messages.read', 'messages.write', 'topics.read', 'topics.write',
        'subscribers.read', 'subscribers.write', 'targets.read', 'targets.write', 'users.read', 'users.write',
    ],
    seed(api) {
        // A few providers of every type for the updates (and the email, SMS and push targets).
        const providers = {};
        for (const type of Object.keys(PROVIDERS)) {
            providers[type] = Array.from({ length: 3 }, (_, i) => provider(api, type, `Bench ${type} ${i}`));
        }
        const via = { email: providers.sendgrid[0], sms: providers.twilio[0], push: providers.fcm[0] };

        // Users, each with an email, an SMS and a push target, created through the Users API.
        const count = Math.min(SEED, 50);
        const targets = { email: [], sms: [], push: [] };
        for (let i = 0; i < count; i++) {
            const userId = api.control('POST', '/users', { userId: 'unique()', name: `Bench Recipient ${i}` }, [201]).json('$id');
            const identifiers = {
                email: `${unique('r')}@example.com`,
                sms: `+1555${String(Math.floor(random() * 1e7)).padStart(7, '0')}`,
                push: unique('device-token'),
            };
            for (const type of Object.keys(targets)) {
                targets[type].push(api.control('POST', `/users/${userId}/targets`, {
                    targetId: 'unique()', providerType: type, identifier: identifiers[type], providerId: via[type],
                }, [201]).json('$id'));
            }
        }

        // Topics, each with subscribers.
        const topics = [];
        const subscribers = [];
        for (let i = 0; i < Math.min(SEED, 20); i++) {
            const id = topic(api, `Bench Topic ${i}`);
            topics.push(id);
            for (let j = 0; j < 5; j++) {
                const target = targets[['email', 'sms', 'push'][j % 3]][(i * 5 + j) % count];
                const subscriber = api.prep('POST', `/messaging/topics/${id}/subscribers`, { subscriberId: 'unique()', targetId: target }, [201]).json('$id');
                subscribers.push([id, subscriber]);
            }
        }

        // Draft messages of every type.
        const messages = { email: [], sms: [], push: [] };
        for (let i = 0; i < SEED; i++) {
            const type = ['email', 'sms', 'push'][i % 3];
            const start = i % count;
            messages[type].push(message(api, type, [targets[type][start], targets[type][(start + 1) % count]]));
        }
        return { providers, targets, topics, subscribers, messages, all: [...messages.email, ...messages.sms, ...messages.push] };
    },
    scenarios: [
        // Messages
        { name: 'messaging.messages.list', route: 'GET /v1/messaging/messages', weight: 8, run: (api) => api.call('GET', `/messaging/messages${LIMIT}`) },
        { name: 'messaging.messages.get', route: 'GET /v1/messaging/messages/:messageId', weight: 12, run: (api, d) => api.call('GET', `/messaging/messages/${pick(d.all)}`) },
        {
            name: 'messaging.messages.delete', route: 'DELETE /v1/messaging/messages/:messageId',
            run: (api, d) => api.call('DELETE', `/messaging/messages/${message(api, 'email', d.targets.email.slice(0, 2))}`, null, [204]),
        },
        { name: 'messaging.messages.targets.list', route: 'GET /v1/messaging/messages/:messageId/targets', weight: 4, run: (api, d) => api.call('GET', `/messaging/messages/${pick(d.all)}/targets`) },
        createMessage({ type: 'email', route: 'POST /v1/messaging/messages/email' }),
        createMessage({ type: 'push', route: 'POST /v1/messaging/messages/push' }),
        createMessage({ type: 'sms', route: 'POST /v1/messaging/messages/sms' }),
        {
            name: 'messaging.messages.email.update', route: 'PATCH /v1/messaging/messages/email/:messageId', weight: 2, note: 'updates a draft',
            run: (api, d) => api.call('PATCH', `/messaging/messages/email/${pick(d.messages.email)}`, { subject: `Bench subject ${Math.floor(random() * 1000)}`, html: pick([true, false]) }),
        },
        {
            name: 'messaging.messages.push.update', route: 'PATCH /v1/messaging/messages/push/:messageId', weight: 2, note: 'updates a draft',
            run: (api, d) => api.call('PATCH', `/messaging/messages/push/${pick(d.messages.push)}`, { title: `Bench title ${Math.floor(random() * 1000)}`, badge: Math.floor(random() * 10) }),
        },
        {
            name: 'messaging.messages.sms.update', route: 'PATCH /v1/messaging/messages/sms/:messageId', weight: 2, note: 'updates a draft',
            run: (api, d) => api.call('PATCH', `/messaging/messages/sms/${pick(d.messages.sms)}`, { content: `Bench content ${Math.floor(random() * 1000)}` }),
        },

        // Providers
        { name: 'messaging.providers.list', route: 'GET /v1/messaging/providers', weight: 6, run: (api) => api.call('GET', `/messaging/providers${LIMIT}`) },
        {
            name: 'messaging.providers.get', route: 'GET /v1/messaging/providers/:providerId', weight: 8,
            run: (api, d) => api.call('GET', `/messaging/providers/${pick(d.providers[pick(Object.keys(PROVIDERS))])}`),
        },
        {
            name: 'messaging.providers.delete', route: 'DELETE /v1/messaging/providers/:providerId',
            run: (api) => api.call('DELETE', `/messaging/providers/${provider(api, 'sendgrid', 'Gone')}`, null, [204]),
        },
        createProvider({ type: 'apns', route: 'POST /v1/messaging/providers/apns' }),
        updateProvider({ type: 'apns', route: 'PATCH /v1/messaging/providers/apns/:providerId' }),
        createProvider({ type: 'appwrite', route: 'POST /v1/messaging/providers/appwrite' }),
        updateProvider({ type: 'appwrite', route: 'PATCH /v1/messaging/providers/appwrite/:providerId' }),
        createProvider({ type: 'fcm', route: 'POST /v1/messaging/providers/fcm' }),
        updateProvider({ type: 'fcm', route: 'PATCH /v1/messaging/providers/fcm/:providerId' }),
        createProvider({ type: 'mailgun', route: 'POST /v1/messaging/providers/mailgun' }),
        updateProvider({ type: 'mailgun', route: 'PATCH /v1/messaging/providers/mailgun/:providerId' }),
        createProvider({ type: 'msg91', route: 'POST /v1/messaging/providers/msg91' }),
        updateProvider({ type: 'msg91', route: 'PATCH /v1/messaging/providers/msg91/:providerId' }),
        createProvider({ type: 'resend', route: 'POST /v1/messaging/providers/resend' }),
        updateProvider({ type: 'resend', route: 'PATCH /v1/messaging/providers/resend/:providerId' }),
        createProvider({ type: 'sendgrid', route: 'POST /v1/messaging/providers/sendgrid' }),
        updateProvider({ type: 'sendgrid', route: 'PATCH /v1/messaging/providers/sendgrid/:providerId' }),
        createProvider({ type: 'ses', route: 'POST /v1/messaging/providers/ses' }),
        updateProvider({ type: 'ses', route: 'PATCH /v1/messaging/providers/ses/:providerId' }),
        createProvider({ type: 'smtp', route: 'POST /v1/messaging/providers/smtp' }),
        updateProvider({ type: 'smtp', route: 'PATCH /v1/messaging/providers/smtp/:providerId' }),
        createProvider({ type: 'telesign', route: 'POST /v1/messaging/providers/telesign' }),
        updateProvider({ type: 'telesign', route: 'PATCH /v1/messaging/providers/telesign/:providerId' }),
        createProvider({ type: 'textmagic', route: 'POST /v1/messaging/providers/textmagic' }),
        updateProvider({ type: 'textmagic', route: 'PATCH /v1/messaging/providers/textmagic/:providerId' }),
        createProvider({ type: 'twilio', route: 'POST /v1/messaging/providers/twilio' }),
        updateProvider({ type: 'twilio', route: 'PATCH /v1/messaging/providers/twilio/:providerId' }),
        createProvider({ type: 'vonage', route: 'POST /v1/messaging/providers/vonage' }),
        updateProvider({ type: 'vonage', route: 'PATCH /v1/messaging/providers/vonage/:providerId' }),

        // Topics
        { name: 'messaging.topics.list', route: 'GET /v1/messaging/topics', weight: 6, run: (api) => api.call('GET', `/messaging/topics${LIMIT}`) },
        {
            name: 'messaging.topics.create', route: 'POST /v1/messaging/topics', weight: 2,
            run(api) {
                const res = api.call('POST', '/messaging/topics', { topicId: 'unique()', name: 'Bench New Topic', subscribe: ['users'] }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/messaging/topics/${res.json('$id')}`, null, [204]);
                }
            },
        },
        { name: 'messaging.topics.get', route: 'GET /v1/messaging/topics/:topicId', weight: 10, run: (api, d) => api.call('GET', `/messaging/topics/${pick(d.topics)}`) },
        {
            name: 'messaging.topics.update', route: 'PATCH /v1/messaging/topics/:topicId', weight: 2,
            run: (api, d) => api.call('PATCH', `/messaging/topics/${pick(d.topics)}`, { name: `Bench Topic ${Math.floor(random() * 1000)}` }),
        },
        { name: 'messaging.topics.delete', route: 'DELETE /v1/messaging/topics/:topicId', run: (api) => api.call('DELETE', `/messaging/topics/${topic(api, 'Gone')}`, null, [204]) },

        // Subscribers
        { name: 'messaging.subscribers.list', route: 'GET /v1/messaging/topics/:topicId/subscribers', weight: 6, run: (api, d) => api.call('GET', `/messaging/topics/${pick(d.topics)}/subscribers`) },
        {
            name: 'messaging.subscribers.create', route: 'POST /v1/messaging/topics/:topicId/subscribers', weight: 2,
            run(api, d) {
                // A topic of its own, so concurrent iterations never subscribe the same target twice.
                const id = topic(api, 'Bench Subscribe');
                const res = api.call('POST', `/messaging/topics/${id}/subscribers`, { subscriberId: 'unique()', targetId: pick(d.targets.email) }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/messaging/topics/${id}/subscribers/${res.json('$id')}`, null, [204]);
                }
                api.prep('DELETE', `/messaging/topics/${id}`, null, [204]);
            },
        },
        {
            name: 'messaging.subscribers.get', route: 'GET /v1/messaging/topics/:topicId/subscribers/:subscriberId', weight: 8,
            run(api, d) {
                const [id, subscriber] = pick(d.subscribers);
                api.call('GET', `/messaging/topics/${id}/subscribers/${subscriber}`);
            },
        },
        {
            name: 'messaging.subscribers.delete', route: 'DELETE /v1/messaging/topics/:topicId/subscribers/:subscriberId',
            run(api, d) {
                const id = topic(api, 'Bench Unsubscribe');
                const subscriber = api.prep('POST', `/messaging/topics/${id}/subscribers`, { subscriberId: 'unique()', targetId: pick(d.targets.sms) }, [201]).json('$id');
                api.call('DELETE', `/messaging/topics/${id}/subscribers/${subscriber}`, null, [204]);
                api.prep('DELETE', `/messaging/topics/${id}`, null, [204]);
            },
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
