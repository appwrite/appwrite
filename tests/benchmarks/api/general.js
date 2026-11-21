// General routes benchmark: the routes app/controllers/general.php declares,
// most of them served at the root of the host, outside /v1, measured with
// `api.request()`. See README.md.

import { define, ROOT, unique } from './lib.js';


function root(api, method, path, codes = [200]) {
    return api.request(method, `${ROOT}${path}`, null, codes, { redirects: 0 });
}

const bench = define({
    service: 'general',
    scopes: [],
    scenarios: [
        { name: 'general.ping.get', route: 'GET /v1/ping', weight: 5, note: 'an API key request; the first ping of a project also records it', run: (api) => api.call('GET', '/ping') },
        { name: 'general.robots.get', route: 'GET /robots.txt', weight: 2, run: (api) => root(api, 'GET', '/robots.txt') },
        { name: 'general.humans.get', route: 'GET /humans.txt', run: (api) => root(api, 'GET', '/humans.txt') },
        {
            name: 'general.acme.get', route: 'GET /.well-known/acme-challenge/*',
            note: 'measured on its not-found path: a challenge file exists only while a certificate is being issued',
            run: (api) => root(api, 'GET', `/.well-known/acme-challenge/${unique('token')}`, [404]),
        },
        {
            name: 'general.authorize.get', route: 'GET /_appwrite/authorize',
            note: 'sets the preview cookie and redirects (301, not followed)',
            run: (api) => root(api, 'GET', `/_appwrite/authorize?jwt=${unique('jwt')}&path=%2F`, [301]),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
