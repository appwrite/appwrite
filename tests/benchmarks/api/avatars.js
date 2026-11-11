// Avatars API benchmark: a scenario for every /v1/avatars endpoint. See README.md.
//
// The endpoints that fetch a URL never leave the dev stack:
// - image and favicon fetch from the API container itself (http://127.0.0.1,
//   which _APP_ALLOWED_INTERNAL_ADDRESSES allows): an image of public/images,
//   and an HTML page the seed stores in a public bucket, whose icon link points
//   at that image;
// - screenshots render the stack's fixture page (screenshot.webapp.com, the
//   `webpage` container) in the stack's browser service, as the e2e tests do.
// Each of them adds a unique query string, so the request is fetched and
// rendered rather than served from the avatar cache.

import http from 'k6/http';
import { CONTROL, define, pick, unique } from './lib.js';

const PHOTO = open('../../resources/logo.png', 'b');

const SELF = 'http://127.0.0.1';
const IMAGE = `${SELF}/images/github.png`;
const SCREENSHOT = 'http://screenshot.webapp.com/';

const BROWSERS = ['ch', 'ff', 'sf', 'op', 'ps'];
const CARDS = ['visa', 'mastercard', 'amex', 'discover', 'jcb', 'unionpay'];
const FLAGS = ['us', 'gb', 'de', 'fr', 'il', 'in', 'br', 'jp', 'ng', 'au'];
const NAMES = ['Ada Lovelace', 'Alan Turing', 'Grace Hopper', 'Linus Torvalds', 'Margaret Hamilton', 'Dennis Ritchie'];
const SIZES = [64, 100, 200];

function query(params) {
    return Object.entries(params).map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
}

const bench = define({
    service: 'avatars',
    scopes: ['avatars.read', 'avatars.write', 'users.read', 'users.write', 'buckets.read', 'buckets.write', 'files.read', 'files.write'],
    seed(api, { projectId }) {
        // Users with a session, for the photo routes (they act on the session's user).
        const users = [];
        for (let i = 0; i < 20; i++) {
            const id = api.control('POST', '/users', { userId: 'unique()', name: pick(NAMES) }, [201]).json('$id');
            const secret = api.control('POST', `/users/${id}/sessions`, null, [201]).json('secret');
            const headers = api.session(secret);
            api.form('PUT', '/avatars/photo', { file: http.file(PHOTO, 'logo.png', 'image/png') }, [200], { headers, measure: false });
            users.push({ id, secret });
        }
        // A public page whose icon is IMAGE, for the favicon lookup.
        const bucket = api.control('POST', '/storage/buckets', { bucketId: 'unique()', name: 'Avatars bench', permissions: ['read("any")'] }, [201]).json('$id');
        const html = `<!doctype html><html><head><title>Bench</title><link rel="icon" type="image/png" sizes="64x64" href="${IMAGE}"></head><body></body></html>`;
        const plain = Object.fromEntries(Object.entries(api.headers).filter(([k]) => k.toLowerCase() !== 'content-type'));
        const page = http.post(`${CONTROL}/storage/buckets/${bucket}/files`, {
            fileId: 'unique()',
            file: http.file(html, 'page.html', 'text/html'),
        }, { headers: plain, tags: { name: 'setup' } });
        if (page.status !== 201) {
            throw new Error(`page: ${page.status} ${page.body}`);
        }
        const site = `${SELF}/v1/storage/buckets/${bucket}/files/${page.json('$id')}/view?project=${projectId}`;
        return { users, site };
    },
    scenarios: [
        {
            name: 'avatars.browsers.get', route: 'GET /v1/avatars/browsers/:code', weight: 6,
            run: (api) => api.call('GET', `/avatars/browsers/${pick(BROWSERS)}?${query({ width: pick(SIZES), height: pick(SIZES) })}`),
        },
        {
            name: 'avatars.creditCards.get', route: 'GET /v1/avatars/credit-cards/:code', weight: 6,
            run: (api) => api.call('GET', `/avatars/credit-cards/${pick(CARDS)}?${query({ width: pick(SIZES), height: pick(SIZES) })}`),
        },
        {
            name: 'avatars.flags.get', route: 'GET /v1/avatars/flags/:code', weight: 10,
            run: (api) => api.call('GET', `/avatars/flags/${pick(FLAGS)}?${query({ width: pick(SIZES), height: pick(SIZES) })}`),
        },
        {
            name: 'avatars.initials.get', route: 'GET /v1/avatars/initials', weight: 15,
            run: (api) => api.call('GET', `/avatars/initials?${query({ name: pick(NAMES), width: pick(SIZES), height: pick(SIZES) })}`),
        },
        {
            name: 'avatars.qr.get', route: 'GET /v1/avatars/qr', weight: 6,
            run: (api) => api.call('GET', `/avatars/qr?${query({ text: `https://example.com/invite/${unique('qr')}`, size: 300 })}`),
        },
        {
            name: 'avatars.image.get', route: 'GET /v1/avatars/image', weight: 4,
            note: 'fetches and crops a 300 KB PNG served by the API container itself, under a unique URL (no cache hit)',
            run: (api) => api.call('GET', `/avatars/image?${query({ url: `${IMAGE}?x=${unique('i')}`, width: 200, height: 200 })}`),
        },
        {
            name: 'avatars.favicon.get', route: 'GET /v1/avatars/favicon', weight: 4,
            note: 'fetches a page served by the API container itself, then its PNG icon, under a unique URL (no cache hit)',
            run: (api, d) => api.call('GET', `/avatars/favicon?${query({ url: `${d.site}&x=${unique('f')}` })}`),
        },
        {
            name: 'avatars.screenshots.get', route: 'GET /v1/avatars/screenshots',
            note: 'renders the dev stack\'s fixture page in its headless browser service, under a unique URL (no cache hit)',
            run: (api) => api.call('GET', `/avatars/screenshots?${query({ url: `${SCREENSHOT}?x=${unique('s')}`, width: 640, height: 360 })}`),
        },
        {
            name: 'avatars.photo.get', route: 'GET /v1/avatars/photo', weight: 10, note: 'a user with an uploaded photo',
            run: (api, d) => api.call('GET', `/avatars/photo?${query({ userId: pick(d.users).id, width: pick(SIZES), height: pick(SIZES) })}`),
        },
        {
            name: 'avatars.photo.initials.get', route: 'GET /v1/avatars/photo', weight: 5, note: 'from a name alone: rendered as initials',
            run: (api) => api.call('GET', `/avatars/photo?${query({ name: pick(NAMES), width: pick(SIZES), height: pick(SIZES) })}`),
        },
        {
            name: 'avatars.photo.update', route: 'PUT /v1/avatars/photo', weight: 2, note: 'uploads a 47 KB PNG for the session\'s user',
            run: (api, d) => api.form('PUT', '/avatars/photo', { file: http.file(PHOTO, 'logo.png', 'image/png') }, [200], { headers: api.session(pick(d.users).secret) }),
        },
        {
            name: 'avatars.photo.delete', route: 'DELETE /v1/avatars/photo', note: 'replaces the photo with the rendered placeholder',
            run: (api, d) => api.call('DELETE', '/avatars/photo', null, [204], api.session(pick(d.users).secret)),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
