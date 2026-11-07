// Migrations API benchmark: a scenario for every /v1/migrations endpoint that
// can run without a production third party. Appwrite migrations and reports
// point at the dev stack itself (http://appwrite/v1, the run's own project and
// key); CSV and JSON imports and exports use a local table and bucket; the
// Supabase routes point at local hosts with nothing listening, so the worker
// fails locally. Every migration a scenario creates is deleted after it.
// See README.md.

import http from 'k6/http';
import { sleep } from 'k6';
import { CONTROL, define, pick, unique } from './lib.js';

// The dev stack's API as the migrations worker reaches it (an address
// _APP_ALLOWED_INTERNAL_ADDRESSES admits).
const SOURCE = 'http://appwrite/v1';
// A local host with nothing listening on the port, for sources the stack has no instance of.
const UNREACHABLE = { endpoint: 'http://appwrite', databaseHost: '127.0.0.1', port: 9 };

const ROWS = Array.from({ length: 20 }, (_, i) => ({ $id: `row${i}`, name: `Imported Row ${i}`, age: 18 + i }));
const CSV = `$id,name,age\n${ROWS.map((r) => `${r.$id},${r.name},${r.age}`).join('\n')}\n`;
const JSON_ROWS = JSON.stringify(ROWS);

// Firebase service account without a private key: rejected before the source is contacted.
const FIREBASE = JSON.stringify({ type: 'service_account', project_id: 'bench', client_email: 'bench@example.com' });
const FIREBASE_NOTE = 'measured on its invalid-credentials path: a valid service account makes the source contact Google (production)';

function query(params) {
    return params.map(([key, value]) => `${key}=${encodeURIComponent(value)}`).join('&');
}

function drop(api, id) {
    // A migration the worker is running may answer 404 once it is gone already.
    api.prep('DELETE', `/migrations/${id}`, null, [204, 404]);
}

/** A migration that fails in the worker: the source rejects the API key. */
function failing(api, d) {
    return api.prep('POST', '/migrations/appwrite', { resources: ['user'], endpoint: SOURCE, projectId: d.projectId, apiKey: 'invalid-key' }, [202]).json('$id');
}

function upload(api, bucketId, name, type, content) {
    const res = http.post(`${CONTROL}/storage/buckets/${bucketId}/files`, { fileId: 'unique()', file: http.file(content, name, type) }, {
        headers: { 'X-Appwrite-Project': api.headers['X-Appwrite-Project'], 'X-Appwrite-Key': api.headers['X-Appwrite-Key'] },
        tags: { name: 'setup' },
    });
    return api.expect(res, [201], `setup upload ${name}`).json('$id');
}

function until(api, path, field, values, tries = 60) {
    for (let i = 0; i < tries; i++) {
        const value = api.control('GET', path, null, [200]).json(field);
        if (values.includes(value)) {
            return value;
        }
        sleep(0.5);
    }
    throw new Error(`${path}: ${field} never reached ${values.join('|')}`);
}

const bench = define({
    service: 'migrations',
    scopes: [
        'migrations.read', 'migrations.write', 'users.read', 'users.write', 'teams.read',
        'databases.read', 'databases.write', 'tables.read', 'tables.write', 'columns.read', 'columns.write', 'rows.read', 'rows.write',
        'collections.read', 'collections.write', 'attributes.read', 'attributes.write', 'documents.read', 'documents.write',
        'buckets.read', 'buckets.write', 'files.read', 'files.write',
    ],
    seed(api) {
        const d = { projectId: api.headers['X-Appwrite-Project'], key: api.headers['X-Appwrite-Key'] };
        d.databaseId = api.control('POST', '/tablesdb', { databaseId: 'unique()', name: 'Migrations Bench' }, [201]).json('$id');
        d.tableId = api.control('POST', `/tablesdb/${d.databaseId}/tables`, { tableId: 'unique()', name: 'Rows' }, [201]).json('$id');
        api.control('POST', `/tablesdb/${d.databaseId}/tables/${d.tableId}/columns/string`, { key: 'name', size: 256, required: true }, [202]);
        api.control('POST', `/tablesdb/${d.databaseId}/tables/${d.tableId}/columns/integer`, { key: 'age', min: 18, max: 65, required: true }, [202]);
        for (const column of ['name', 'age']) {
            until(api, `/tablesdb/${d.databaseId}/tables/${d.tableId}/columns/${column}`, 'status', ['available']);
        }
        d.bucketId = api.control('POST', '/storage/buckets', {
            bucketId: 'unique()', name: 'Migrations Bench', allowedFileExtensions: ['csv', 'json'], encryption: false, compression: 'none',
        }, [201]).json('$id');
        d.csv = upload(api, d.bucketId, 'rows.csv', 'text/csv', CSV);
        d.json = upload(api, d.bucketId, 'rows.json', 'application/json', JSON_ROWS);
        // Import the rows once, so the exports have something to export.
        const first = api.prep('POST', '/migrations/csv/imports', { bucketId: d.bucketId, fileId: d.csv, databaseId: d.databaseId, collectionId: d.tableId, onDuplicate: 'overwrite' }, [202]).json('$id');
        until(api, `/migrations/${first}`, 'status', ['completed', 'failed']);
        // Failed migrations to read and retry.
        d.failed = Array.from({ length: 10 }, () => failing(api, d));
        for (const id of d.failed) {
            until(api, `/migrations/${id}`, 'status', ['failed']);
        }
        return d;
    },
    scenarios: [
        { name: 'migrations.list', route: 'GET /v1/migrations', weight: 6, run: (api) => api.call('GET', '/migrations') },
        { name: 'migrations.get', route: 'GET /v1/migrations/:migrationId', weight: 6, run: (api, d) => api.call('GET', `/migrations/${pick(d.failed)}`) },
        {
            name: 'migrations.update', route: 'PATCH /v1/migrations/:migrationId', note: 'retries a failed migration (409 while the worker is retrying it)',
            run: (api, d) => api.call('PATCH', `/migrations/${pick(d.failed)}`, null, [204, 409]),
        },
        { name: 'migrations.delete', route: 'DELETE /v1/migrations/:migrationId', run: (api, d) => api.call('DELETE', `/migrations/${failing(api, d)}`, null, [204]) },
        {
            name: 'migrations.appwrite.create', route: 'POST /v1/migrations/appwrite', note: 'users of the run\'s own project, from the local stack',
            run(api, d) {
                const res = api.call('POST', '/migrations/appwrite', { resources: ['user'], endpoint: SOURCE, projectId: d.projectId, apiKey: d.key, onDuplicate: 'skip' }, [202]);
                if (res.status === 202) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'migrations.appwrite.report.get', route: 'GET /v1/migrations/appwrite/report', weight: 2, note: 'reads the run\'s own project through the local stack\'s API',
            run: (api, d) => api.call('GET', `/migrations/appwrite/report?${query([['resources[]', 'user'], ['resources[]', 'team'], ['endpoint', SOURCE], ['projectID', d.projectId], ['key', d.key]])}`),
        },
        {
            name: 'migrations.csv.exports.create', route: 'POST /v1/migrations/csv/exports', note: 'the worker writes the export to the platform\'s internal bucket',
            run(api, d) {
                const res = api.call('POST', '/migrations/csv/exports', { databaseId: d.databaseId, collectionId: d.tableId, filename: unique('export'), notify: false }, [202]);
                if (res.status === 202) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'migrations.csv.imports.create', route: 'POST /v1/migrations/csv/imports', note: '20 rows, overwriting the same ids',
            run(api, d) {
                const res = api.call('POST', '/migrations/csv/imports', { bucketId: d.bucketId, fileId: d.csv, databaseId: d.databaseId, collectionId: d.tableId, onDuplicate: 'overwrite' }, [202]);
                if (res.status === 202) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'migrations.json.exports.create', route: 'POST /v1/migrations/json/exports', note: 'the worker writes the export to the platform\'s internal bucket',
            run(api, d) {
                const res = api.call('POST', '/migrations/json/exports', { databaseId: d.databaseId, collectionId: d.tableId, filename: unique('export'), notify: false }, [202]);
                if (res.status === 202) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'migrations.json.imports.create', route: 'POST /v1/migrations/json/imports', note: '20 rows, overwriting the same ids',
            run(api, d) {
                const res = api.call('POST', '/migrations/json/imports', { bucketId: d.bucketId, fileId: d.json, databaseId: d.databaseId, collectionId: d.tableId, onDuplicate: 'overwrite' }, [202]);
                if (res.status === 202) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'migrations.firebase.create', route: 'POST /v1/migrations/firebase', note: FIREBASE_NOTE,
            run: (api) => api.call('POST', '/migrations/firebase', { resources: ['user'], serviceAccount: FIREBASE }, [400]),
        },
        {
            name: 'migrations.firebase.report.get', route: 'GET /v1/migrations/firebase/report', note: FIREBASE_NOTE,
            run: (api) => api.call('GET', `/migrations/firebase/report?${query([['resources[]', 'user'], ['serviceAccount', FIREBASE]])}`, null, [400]),
        },
        {
            name: 'migrations.supabase.create', route: 'POST /v1/migrations/supabase', note: 'a local source with nothing listening: the worker fails without leaving the stack',
            run(api) {
                const res = api.call('POST', '/migrations/supabase', {
                    resources: ['user'], endpoint: UNREACHABLE.endpoint, apiKey: 'bench', databaseHost: UNREACHABLE.databaseHost, username: 'postgres', password: 'benchmark-password', port: UNREACHABLE.port,
                }, [202]);
                if (res.status === 202) {
                    drop(api, res.json('$id'));
                }
            },
        },
        {
            name: 'migrations.supabase.report.get', route: 'GET /v1/migrations/supabase/report',
            note: 'measured on its unreachable-source path: a local host with nothing listening, the stack has no Supabase instance',
            run: (api) => api.call('GET', `/migrations/supabase/report?${query([
                ['resources[]', 'user'], ['endpoint', UNREACHABLE.endpoint], ['apiKey', 'bench'], ['databaseHost', UNREACHABLE.databaseHost],
                ['username', 'postgres'], ['password', 'benchmark-password'], ['port', String(UNREACHABLE.port)],
            ])}`, null, [400]),
        },
    ],
    skipped: [
        { skipped: 'POST /v1/migrations/nhost', reason: 'the worker connects to the source database at <subdomain>.db.<region>.nhost.run (production NHost)' },
        { skipped: 'GET /v1/migrations/nhost/report', reason: 'connects to the source database at <subdomain>.db.<region>.nhost.run (production NHost)' },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
