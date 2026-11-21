// Videos benchmark. See README.md.

import { define } from './lib.js';

const bench = define({
    service: 'videos',
    scopes: [],
    scenarios: [],
    skipped: [
        { skipped: 'GET /v1/videos', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/videos', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'PUT /v1/videos/:videoId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'DELETE /v1/videos/:videoId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/captions', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/videos/:videoId/captions', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'PATCH /v1/videos/:videoId/captions/:captionId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'DELETE /v1/videos/:videoId/captions/:captionId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/outputs/:output/captions/:captionId/manifest', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/outputs/:output/captions/:captionId/segments/:segmentId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/outputs/:output/renditions/:renditionId/segments/:segmentId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/outputs/cmaf/master.m3u8', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/outputs/cmaf/master.mpd', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/outputs/cmaf/renditions/:renditionId/streams/:streamId/playlist.m3u8', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/outputs/dash/master.mpd', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/outputs/hls/master.m3u8', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/outputs/hls/renditions/:renditionId/streams/:streamId/playlist.m3u8', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/renditions', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'POST /v1/videos/:videoId/renditions', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/renditions/:renditionId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'DELETE /v1/videos/:videoId/renditions/:renditionId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/thumbnails/:thumbnailId', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/:videoId/timelines', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
        { skipped: 'GET /v1/videos/codecs', reason: 'added on main after the Nov 16 merge; its scenario is pending (task main-nov16-benchmarks)' },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
