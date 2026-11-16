// Reviews benchmark. See README.md.

import { define } from './lib.js';

const bench = define({
    service: 'reviews',
    scopes: [],
    scenarios: [],
    skipped: [
        { skipped: 'PATCH /v1/reviews/repositories/:repositoryId/strength', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/reviews', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/reviews/:reviewId', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/reviews/:reviewId/events', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/reviews/:reviewId/events', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'PATCH /v1/reviews/:reviewId/status', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'PATCH /v1/reviews/:reviewId/tier', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/reviews/:reviewId/usage', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/reviews/repositories', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/reviews/repositories', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'DELETE /v1/reviews/repositories/:repositoryId', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/reviews/repositories/:repositoryId/learnings', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/reviews/repositories/:repositoryId/learnings', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/reviews/repositories/:repositoryId/pulls/:pullNumber/findings', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'PATCH /v1/reviews/repositories/:repositoryId/pulls/:pullNumber/findings/status', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/reviews/repositories/:repositoryId/pulls/:pullNumber/reviews', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/reviews/repositories/:repositoryId/tokens', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/reviews/repositories/:repositoryId/usage', reason: 'added on main in the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
