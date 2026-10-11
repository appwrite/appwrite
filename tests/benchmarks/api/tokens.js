// Tokens API benchmark: a scenario for every /v1/tokens endpoint (file
// tokens), against files the seed uploads through the PHP API. See README.md.

import http from 'k6/http';
import { CONTROL, SEED, define, pick } from './lib.js';

const IMAGE = open('../../resources/logo.png', 'b');

function expire(days) {
    return new Date(Date.now() + days * 86400 * 1000).toISOString();
}

/** Uploads a file through the PHP API (a control request: storage is another service). */
function upload(api, bucket, name) {
    const headers = Object.fromEntries(Object.entries(api.headers).filter(([k]) => k.toLowerCase() !== 'content-type'));
    const res = http.post(`${CONTROL}/storage/buckets/${bucket}/files`, { fileId: 'unique()', file: http.file(IMAGE, name, 'image/png') }, { headers, tags: { name: 'setup' } });
    if (res.status !== 201) {
        throw new Error(`upload: ${res.status} ${res.body}`);
    }
    return res.json('$id');
}

function token(api, [bucket, file], codes = [201]) {
    return api.prep('POST', `/tokens/buckets/${bucket}/files/${file}`, { expire: expire(30) }, codes).json('$id');
}

const bench = define({
    service: 'tokens',
    scopes: ['tokens.read', 'tokens.write', 'buckets.read', 'buckets.write', 'files.read', 'files.write'],
    seed(api) {
        const bucket = api.control('POST', '/storage/buckets', { bucketId: 'unique()', name: 'Tokens bench' }, [201]).json('$id');
        const files = Array.from({ length: 10 }, (_, i) => [bucket, upload(api, bucket, `logo-${i}.png`)]);
        const tokens = [];
        for (let i = 0; i < SEED; i++) {
            tokens.push(token(api, files[i % files.length]));
        }
        return { files, tokens };
    },
    scenarios: [
        { name: 'tokens.get', route: 'GET /v1/tokens/:tokenId', weight: 20, run: (api, d) => api.call('GET', `/tokens/${pick(d.tokens)}`) },
        {
            name: 'tokens.list', route: 'GET /v1/tokens/buckets/:bucketId/files/:fileId', weight: 10,
            run(api, d) {
                const [bucket, file] = pick(d.files);
                api.call('GET', `/tokens/buckets/${bucket}/files/${file}?queries[]=` + encodeURIComponent('{"method":"limit","values":[25]}'));
            },
        },
        {
            name: 'tokens.create', route: 'POST /v1/tokens/buckets/:bucketId/files/:fileId', weight: 4,
            run(api, d) {
                const [bucket, file] = pick(d.files);
                const res = api.call('POST', `/tokens/buckets/${bucket}/files/${file}`, { expire: expire(7) }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/tokens/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'tokens.update', route: 'PATCH /v1/tokens/:tokenId', weight: 4,
            run: (api, d) => api.call('PATCH', `/tokens/${pick(d.tokens)}`, { expire: expire(30 + Math.floor(Math.random() * 30)) }),
        },
        {
            name: 'tokens.delete', route: 'DELETE /v1/tokens/:tokenId', weight: 2,
            run: (api, d) => api.call('DELETE', `/tokens/${token(api, pick(d.files))}`, null, [204]),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
