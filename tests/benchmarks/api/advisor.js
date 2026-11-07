// Advisor API benchmark: a scenario for every /v1/reports endpoint. Reports and
// their insights are written by the platform's analyzers, not through the API,
// so a fresh project has none: the list is measured on its empty result and the
// rest on their not-found paths. See README.md.

import { define, unique } from './lib.js';

const NOT_FOUND = 'measured on its not-found path: reports are written by the platform analyzers, no API creates one';

const bench = define({
    service: 'advisor',
    scopes: ['reports.read', 'reports.write', 'insights.read'],
    scenarios: [
        { name: 'advisor.reports.list', route: 'GET /v1/reports', weight: 5, note: 'a project without reports', run: (api) => api.call('GET', '/reports') },
        { name: 'advisor.reports.get', route: 'GET /v1/reports/:reportId', weight: 3, note: NOT_FOUND, run: (api) => api.call('GET', `/reports/${unique('report')}`, null, [404]) },
        { name: 'advisor.reports.delete', route: 'DELETE /v1/reports/:reportId', note: NOT_FOUND, run: (api) => api.call('DELETE', `/reports/${unique('report')}`, null, [404]) },
        {
            name: 'advisor.insights.list', route: 'GET /v1/reports/:reportId/insights', weight: 3, note: NOT_FOUND,
            run: (api) => api.call('GET', `/reports/${unique('report')}/insights`, null, [404]),
        },
        {
            name: 'advisor.insights.get', route: 'GET /v1/reports/:reportId/insights/:insightId', weight: 2, note: NOT_FOUND,
            run: (api) => api.call('GET', `/reports/${unique('report')}/insights/${unique('insight')}`, null, [404]),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
