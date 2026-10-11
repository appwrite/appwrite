// Storage API benchmark: a scenario for every /v1/storage endpoint, in a mix
// modelled on Storage traffic (reads and previews dominate). See README.md.
//
// Uploads are multipart (`api.form`): images from tests/resources, a 1 MiB
// binary made in setup, and a 10 MiB file sent in two 5 MiB chunks (above
// 5 MiB, uploads are chunked with Content-Range and X-Appwrite-ID).

import http from 'k6/http';
import { SEED, define, pick, unique } from './lib.js';

const LOGO = open('../../resources/logo.png', 'b'); // 47 KB PNG
const KITTEN = open('../../resources/disk-a/kitten-2.jpg', 'b'); // 132 KB JPEG
const PHOTO = open('../../resources/disk-a/kitten-1.jpg', 'b'); // 600 KB JPEG

const MIB = 1024 * 1024;
const CHUNK = 5 * MIB;

// The 5 MiB chunk of the chunked upload, made on first use: only VUs that run it hold it.
let chunk;

function plain(headers) {
    return Object.fromEntries(Object.entries(headers).filter(([k]) => k.toLowerCase() !== 'content-type'));
}

function image(i) {
    return [
        { bytes: LOGO, name: 'logo.png', type: 'image/png' },
        { bytes: KITTEN, name: 'kitten.jpg', type: 'image/jpeg' },
    ][i % 2];
}

function upload(api, bucket, { bytes, name, type }, measure = false) {
    return api.form('POST', `/storage/buckets/${bucket}/files`, { fileId: 'unique()', file: http.file(bytes, name, type) }, [201], { measure });
}

function bucket(api, name) {
    return api.prep('POST', '/storage/buckets', { bucketId: 'unique()', name }, [201]).json('$id');
}

function query(params) {
    return Object.entries(params).map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&');
}

const bench = define({
    service: 'storage',
    scopes: ['buckets.read', 'buckets.write', 'files.read', 'files.write'],
    seed(api) {
        const buckets = Array.from({ length: 10 }, (_, i) => bucket(api, `Seed Bucket ${i}`));
        const main = bucket(api, 'Bench Files');
        const images = [];
        for (let i = 0; i < SEED; i++) {
            images.push(upload(api, main, image(i)).json('$id'));
        }
        images.push(upload(api, main, { bytes: PHOTO, name: 'photo.jpg', type: 'image/jpeg' }).json('$id'));
        const bytes = new Uint8Array(MIB);
        for (let i = 0; i < bytes.length; i++) {
            bytes[i] = (i * 2654435761) >>> 24;
        }
        const blob = upload(api, main, { bytes: bytes.buffer, name: 'blob.bin', type: 'application/octet-stream' }).json('$id');
        return { buckets, bucket: main, images, photo: images[images.length - 1], blob };
    },
    scenarios: [
        { name: 'storage.buckets.list', route: 'GET /v1/storage/buckets', weight: 3, run: (api) => api.call('GET', '/storage/buckets') },
        { name: 'storage.buckets.get', route: 'GET /v1/storage/buckets/:bucketId', weight: 6, run: (api, d) => api.call('GET', `/storage/buckets/${pick(d.buckets)}`) },
        {
            name: 'storage.buckets.create', route: 'POST /v1/storage/buckets',
            run(api) {
                const res = api.call('POST', '/storage/buckets', { bucketId: 'unique()', name: 'New Bucket' }, [201]);
                if (res.status === 201) {
                    api.prep('DELETE', `/storage/buckets/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'storage.buckets.update', route: 'PUT /v1/storage/buckets/:bucketId', weight: 2,
            run: (api, d) => api.call('PUT', `/storage/buckets/${pick(d.buckets)}`, { name: `Seed Bucket ${Math.floor(Math.random() * 1000)}` }),
        },
        { name: 'storage.buckets.delete', route: 'DELETE /v1/storage/buckets/:bucketId', run: (api) => api.call('DELETE', `/storage/buckets/${bucket(api, 'Gone')}`, null, [204]) },
        {
            name: 'storage.files.list', route: 'GET /v1/storage/buckets/:bucketId/files', weight: 8,
            run: (api, d) => api.call('GET', `/storage/buckets/${d.bucket}/files?queries[]=` + encodeURIComponent('{"method":"limit","values":[25]}')),
        },
        { name: 'storage.files.get', route: 'GET /v1/storage/buckets/:bucketId/files/:fileId', weight: 12, run: (api, d) => api.call('GET', `/storage/buckets/${d.bucket}/files/${pick(d.images)}`) },
        {
            name: 'storage.files.create', route: 'POST /v1/storage/buckets/:bucketId/files', weight: 4, note: 'a 47 KB or 132 KB image',
            run(api, d) {
                const res = upload(api, d.bucket, image(Math.floor(Math.random() * 2)), true);
                if (res.status === 201) {
                    api.prep('DELETE', `/storage/buckets/${d.bucket}/files/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'storage.files.create.photo', route: 'POST /v1/storage/buckets/:bucketId/files', weight: 2, note: 'a 600 KB JPEG',
            run(api, d) {
                const res = upload(api, d.bucket, { bytes: PHOTO, name: 'photo.jpg', type: 'image/jpeg' }, true);
                if (res.status === 201) {
                    api.prep('DELETE', `/storage/buckets/${d.bucket}/files/${res.json('$id')}`, null, [204]);
                }
            },
        },
        {
            name: 'storage.files.create.chunked', route: 'POST /v1/storage/buckets/:bucketId/files',
            note: 'a 10 MiB file in two 5 MiB chunks; each chunk is a sample',
            run(api, d) {
                chunk = chunk || new Uint8Array(CHUNK).fill(97).buffer;
                const id = unique('big');
                const size = 2 * CHUNK;
                for (let i = 0; i < 2; i++) {
                    const headers = { ...plain(api.headers), 'Content-Range': `bytes ${i * CHUNK}-${(i + 1) * CHUNK - 1}/${size}` };
                    if (i > 0) {
                        headers['X-Appwrite-ID'] = id;
                    }
                    const res = api.form('POST', `/storage/buckets/${d.bucket}/files`, { fileId: id, file: http.file(chunk, 'big.bin', 'application/octet-stream') }, [201], { headers });
                    if (res.status !== 201) {
                        return;
                    }
                }
                api.prep('DELETE', `/storage/buckets/${d.bucket}/files/${id}`, null, [204]);
            },
        },
        {
            name: 'storage.files.update', route: 'PUT /v1/storage/buckets/:bucketId/files/:fileId', weight: 3,
            run: (api, d) => api.call('PUT', `/storage/buckets/${d.bucket}/files/${pick(d.images)}`, { name: `file-${Math.floor(Math.random() * 1000)}.png` }),
        },
        {
            name: 'storage.files.delete', route: 'DELETE /v1/storage/buckets/:bucketId/files/:fileId', weight: 2,
            run: (api, d) => api.call('DELETE', `/storage/buckets/${d.bucket}/files/${upload(api, d.bucket, image(0)).json('$id')}`, null, [204]),
        },
        {
            name: 'storage.files.download', route: 'GET /v1/storage/buckets/:bucketId/files/:fileId/download', weight: 6, note: 'a 47 KB or 132 KB image',
            run: (api, d) => api.call('GET', `/storage/buckets/${d.bucket}/files/${pick(d.images)}/download`),
        },
        {
            name: 'storage.files.download.blob', route: 'GET /v1/storage/buckets/:bucketId/files/:fileId/download', weight: 3, note: 'a 1 MiB file',
            run: (api, d) => api.call('GET', `/storage/buckets/${d.bucket}/files/${d.blob}/download`),
        },
        {
            name: 'storage.files.view', route: 'GET /v1/storage/buckets/:bucketId/files/:fileId/view', weight: 10, note: 'a 47 KB or 132 KB image',
            run: (api, d) => api.call('GET', `/storage/buckets/${d.bucket}/files/${pick(d.images)}/view`),
        },
        {
            name: 'storage.files.preview', route: 'GET /v1/storage/buckets/:bucketId/files/:fileId/preview', weight: 15,
            note: 'a few common sizes: after the first request of each, served from the preview cache',
            run(api, d) {
                const size = pick([96, 192, 400]);
                api.call('GET', `/storage/buckets/${d.bucket}/files/${pick(d.images.slice(0, 10))}/preview?${query({ width: size, height: size })}`);
            },
        },
        {
            name: 'storage.files.preview.render', route: 'GET /v1/storage/buckets/:bucketId/files/:fileId/preview', weight: 5,
            note: 'a 600 KB JPEG resized, rounded and converted to WebP with unique parameters: rendered on every request (no cache hit)',
            run(api, d) {
                const params = {
                    width: 200 + Math.floor(Math.random() * 1000),
                    height: 200 + Math.floor(Math.random() * 600),
                    quality: 50 + Math.floor(Math.random() * 50),
                    borderRadius: Math.floor(Math.random() * 32),
                    output: 'webp',
                };
                api.call('GET', `/storage/buckets/${d.bucket}/files/${d.photo}/preview?${query(params)}`);
            },
        },
        {
            name: 'storage.files.push.get', route: 'GET /v1/storage/buckets/:bucketId/files/:fileId/push',
            note: 'measured on its unauthorized path: the JWT is minted by the messaging worker for push attachments, signed with the server key',
            run: (api, d) => api.call('GET', `/storage/buckets/${d.bucket}/files/${pick(d.images)}/push?jwt=${unique('jwt')}`, null, [401]),
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
