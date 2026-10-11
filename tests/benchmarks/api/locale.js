// Locale API benchmark: a scenario for every /v1/locale endpoint. All are
// reads of static, translated catalogs (plus a GeoIP lookup for the caller),
// so the mix leans on the ones apps call on every page load. See README.md.

import { define } from './lib.js';

const bench = define({
    service: 'locale',
    scopes: ['locale.read'],
    scenarios: [
        { name: 'locale.get', route: 'GET /v1/locale', weight: 20, note: 'GeoIP lookup of the caller\'s address', run: (api) => api.call('GET', '/locale') },
        { name: 'locale.codes.list', route: 'GET /v1/locale/codes', weight: 5, run: (api) => api.call('GET', '/locale/codes') },
        { name: 'locale.continents.list', route: 'GET /v1/locale/continents', weight: 5, run: (api) => api.call('GET', '/locale/continents') },
        { name: 'locale.countries.list', route: 'GET /v1/locale/countries', weight: 10, run: (api) => api.call('GET', '/locale/countries') },
        { name: 'locale.countries.eu.list', route: 'GET /v1/locale/countries/eu', weight: 5, run: (api) => api.call('GET', '/locale/countries/eu') },
        { name: 'locale.countries.phones.list', route: 'GET /v1/locale/countries/phones', weight: 10, run: (api) => api.call('GET', '/locale/countries/phones') },
        { name: 'locale.currencies.list', route: 'GET /v1/locale/currencies', weight: 5, run: (api) => api.call('GET', '/locale/currencies') },
        { name: 'locale.languages.list', route: 'GET /v1/locale/languages', weight: 5, run: (api) => api.call('GET', '/locale/languages') },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
