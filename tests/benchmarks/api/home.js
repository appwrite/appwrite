// Home benchmark: `GET /versions`, served at the root of the host, outside /v1.
// Measured with `api.request()` against the host root. See README.md.

import { define, ROOT } from './lib.js';


function root(api, method, path, codes = [200]) {
    return api.request(method, `${ROOT}${path}`, null, codes, { redirects: 0 });
}

const bench = define({
    service: 'home',
    scopes: [],
    scenarios: [
        { name: 'home.versions.get', route: 'GET /versions', run: (api) => root(api, 'GET', '/versions') },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
