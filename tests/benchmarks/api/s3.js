// S3 compatibility API benchmark: the /v1/s3 surface (one route per method,
// with the bucket and object key in the path), each request signed locally with
// AWS Signature V4 the way an S3 SDK signs it: the access key ID is the project
// ID, the secret the run's API key. They are sent with `api.request()`.
// See README.md.

import crypto from 'k6/crypto';
import { define, ENDPOINT, SEED, pick, unique } from './lib.js';

const HOST = ENDPOINT.split('://')[1].split('/')[0];
const PREFIX = ENDPOINT.slice(ENDPOINT.indexOf(HOST) + HOST.length); // "/v1"
const REGION = 'us-east-1';

function encode(value) {
    // PHP's rawurlencode, which the server canonicalizes the query with.
    return encodeURIComponent(value).split('!').join('%21').split('\'').join('%27').split('(').join('%28').split(')').join('%29').split('*').join('%2A');
}

function amzDate(now) {
    const iso = now.toISOString(); // YYYY-MM-DDTHH:MM:SS.sssZ
    return `${iso.slice(0, 4)}${iso.slice(5, 7)}${iso.slice(8, 10)}T${iso.slice(11, 13)}${iso.slice(14, 16)}${iso.slice(17, 19)}Z`;
}

/** An S3 request signed with AWS Signature V4 (header authentication). */
function request(api, d, method, path, query, body, codes, measure) {
    const date = amzDate(new Date());
    const day = date.slice(0, 8);
    const payload = body || '';
    const hash = crypto.sha256(payload, 'hex');
    const uri = `${PREFIX}/s3${path}`;
    const canonicalQuery = Object.keys(query).sort().map((key) => `${encode(key)}=${encode(query[key])}`).join('&');
    const headers = { host: HOST, 'x-amz-content-sha256': hash, 'x-amz-date': date };
    const signed = Object.keys(headers).sort();
    const canonical = [method, uri, canonicalQuery, signed.map((h) => `${h}:${headers[h]}\n`).join(''), signed.join(';'), hash].join('\n');
    const scope = `${day}/${REGION}/s3/aws4_request`;
    const toSign = `AWS4-HMAC-SHA256\n${date}\n${scope}\n${crypto.sha256(canonical, 'hex')}`;
    let key = crypto.hmac('sha256', `AWS4${d.secret}`, day, 'binary');
    for (const part of [REGION, 's3', 'aws4_request']) {
        key = crypto.hmac('sha256', key, part, 'binary');
    }
    const signature = crypto.hmac('sha256', key, toSign, 'hex');
    return api.request(method, `${ENDPOINT}/s3${path}${canonicalQuery ? `?${canonicalQuery}` : ''}`, body === undefined ? null : payload, codes, {
        measure,
        headers: {
            'X-Amz-Date': date,
            'X-Amz-Content-Sha256': hash,
            Authorization: `AWS4-HMAC-SHA256 Credential=${d.projectId}/${scope}, SignedHeaders=${signed.join(';')}, Signature=${signature}`,
            ...(body === undefined ? {} : { 'Content-Type': 'text/plain' }),
        },
    });
}

function call(api, d, method, path, query = {}, body = undefined, codes = [200]) {
    return request(api, d, method, path, query, body, codes, true);
}

function prep(api, d, method, path, body = undefined, codes = [200, 204]) {
    return request(api, d, method, path, {}, body, codes, false);
}

function text(i) {
    return `Benchmark object ${i}\n`.repeat(64);
}

const bench = define({
    service: 's3',
    scopes: ['buckets.read', 'buckets.write', 'files.read', 'files.write'],
    seed(api) {
        const d = { projectId: api.headers['X-Appwrite-Project'], secret: api.headers['X-Appwrite-Key'] };
        // S3 access needs a bucket without encryption and compression.
        d.bucket = api.prep('POST', '/storage/buckets', {
            bucketId: 'unique()', name: 'S3 Bench', fileSecurity: false, encryption: false, compression: 'none', antivirus: false,
        }, [201]).json('$id');
        d.objects = [];
        for (let i = 0; i < Math.min(SEED, 50); i++) {
            const key = `seed/object-${i}.txt`;
            prep(api, d, 'PUT', `/${d.bucket}/${key}`, text(i), [200]);
            d.objects.push(key);
        }
        return d;
    },
    scenarios: [
        { name: 's3.buckets.list', route: 'GET /v1/s3', weight: 2, note: 'ListBuckets', run: (api, d) => call(api, d, 'GET', '') },
        { name: 's3.objects.list', route: 'GET /v1/s3', weight: 3, note: 'ListObjectsV2 of a bucket', run: (api, d) => call(api, d, 'GET', `/${d.bucket}`, { 'list-type': '2', prefix: 'seed/' }) },
        { name: 's3.objects.get', route: 'GET /v1/s3', weight: 8, note: 'GetObject (1.4 kB)', run: (api, d) => call(api, d, 'GET', `/${d.bucket}/${pick(d.objects)}`) },
        {
            name: 's3.objects.put', route: 'PUT /v1/s3', weight: 2, note: 'PutObject (1.4 kB)',
            run(api, d) {
                const key = `new/${unique('object')}.txt`;
                const res = call(api, d, 'PUT', `/${d.bucket}/${key}`, {}, text(0));
                if (res.status === 200) {
                    prep(api, d, 'DELETE', `/${d.bucket}/${key}`, undefined, [204]);
                }
            },
        },
        {
            name: 's3.objects.delete', route: 'DELETE /v1/s3', weight: 2, note: 'DeleteObject',
            run(api, d) {
                const key = `gone/${unique('object')}.txt`;
                prep(api, d, 'PUT', `/${d.bucket}/${key}`, text(0), [200]);
                call(api, d, 'DELETE', `/${d.bucket}/${key}`, {}, undefined, [204]);
            },
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
