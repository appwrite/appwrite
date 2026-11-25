// Alerts benchmark. See README.md.

import { define } from './lib.js';

const bench = define({
    service: 'alerts',
    scopes: [],
    scenarios: [],
    skipped: [
        { skipped: 'GET /v1/organization/escalations', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/organization/escalations', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/organization/escalations/:escalationId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'PATCH /v1/organization/escalations/:escalationId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'DELETE /v1/organization/escalations/:escalationId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/alerts', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/alerts/:alertId', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'PATCH /v1/alerts/:alertId', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/monitors', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/monitors/:monitorId', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'DELETE /v1/monitors/:monitorId', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/monitors/usage', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'PATCH /v1/monitors/usage/:monitorId', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
