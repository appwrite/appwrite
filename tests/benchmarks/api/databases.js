// Databases API benchmark: a scenario for every endpoint of the databases
// service, in a mix modelled on server-side database traffic (document and row
// reads dominate). The legacy Databases API (collections, attributes, documents)
// and TablesDB (tables, columns, rows) run the same scenarios, each on its own
// database. See README.md.

import { sleep } from 'k6';
import { define, SEED, pick, unique } from './lib.js';

// The two APIs over the same engine: the names of their resources and params.
const LEGACY = {
    ns: 'databases', root: '/databases', set: 'collections', setId: 'collectionId', field: 'attributes', item: 'documents', itemId: 'documentId',
    security: 'documentSecurity', related: 'relatedCollectionId',
};
const TABLES = {
    ns: 'tablesdb', root: '/tablesdb', set: 'tables', setId: 'tableId', field: 'columns', item: 'rows', itemId: 'rowId',
    security: 'rowSecurity', related: 'relatedTableId',
};

// One attribute (column) of each type: the body that creates it and the one that updates it.
const TYPES = {
    bigint: { create: { required: false }, update: { required: false, default: 7 } },
    boolean: { create: { required: false }, update: { required: false, default: true } },
    datetime: { create: { required: false }, update: { required: false, default: '2024-01-01T00:00:00.000+00:00' } },
    email: { create: { required: false }, update: { required: false, default: 'bench@example.com' } },
    enum: { create: { elements: ['a', 'b', 'c'], required: false }, update: { elements: ['a', 'b', 'c'], required: false, default: 'a' } },
    float: { create: { required: false }, update: { required: false, default: 1.5 } },
    integer: { create: { required: false }, update: { required: false, default: 7 } },
    ip: { create: { required: false }, update: { required: false, default: '192.0.2.1' } },
    line: { create: { required: false }, update: { required: false, default: [[0, 0], [1, 1]] } },
    longtext: { create: { required: false }, update: { required: false, default: 'hello' } },
    mediumtext: { create: { required: false }, update: { required: false, default: 'hello' } },
    point: { create: { required: false }, update: { required: false, default: [1, 2] } },
    polygon: { create: { required: false }, update: { required: false, default: [[[0, 0], [0, 1], [1, 1], [0, 0]]] } },
    string: { create: { size: 64, required: false }, update: { required: false, default: 'hello' } },
    text: { create: { required: false }, update: { required: false, default: 'hello' } },
    url: { create: { required: false }, update: { required: false, default: 'https://example.com' } },
    varchar: { create: { size: 64, required: false }, update: { required: false, default: 'hello' } },
};

// Seeded documents (rows): their attributes (columns), created inline with the collection.
const FIELDS = [
    { key: 'name', type: 'varchar', size: 64, required: false },
    { key: 'count', type: 'integer', required: false },
    { key: 'score', type: 'double', required: false },
    { key: 'active', type: 'boolean', required: false },
];

const DISABLED = (product, env) => `${product} is off on the dev stack (${env} is not enabled): measured on its service-disabled path`;
const DOCUMENTSDB = DISABLED('DocumentsDB', '_APP_DOCUMENTSDB');
const VECTORSDB = DISABLED('VectorsDB', '_APP_VECTORSDB');

function query(method, attribute, values) {
    const q = { method };
    if (attribute !== undefined) {
        q.attribute = attribute;
    }
    if (values !== undefined) {
        q.values = values;
    }
    return JSON.stringify(q);
}

function qs(...queries) {
    return '?' + queries.map((q) => 'queries[]=' + encodeURIComponent(q)).join('&');
}

function record(i) {
    return { name: `Seed ${i % 100}`, count: i, score: i / 10, active: i % 2 === 0 };
}

const db = (k, d) => `${k.root}/${d.db}`;
const sets = (k, d) => `${k.root}/${d.db}/${k.set}`;
const items = (k, d, set = d.docs) => `${sets(k, d)}/${set}/${k.item}`;

/** A fresh collection (table), with attributes (columns) and indexes created inline, so available at once. */
function scratch(api, k, d, fields = [], indexes = []) {
    return api.prep('POST', sets(k, d), { [k.setId]: 'unique()', name: 'Scratch', [k.field]: fields, indexes }, [201]).json('$id');
}

function drop(api, k, d, set) {
    api.prep('DELETE', `${sets(k, d)}/${set}`, null, [204]);
}

/** Up to `n` new documents (rows) in the seeded collection, all named `tag`; returns their ids. */
function batch(api, k, d, tag, n = 5) {
    const list = Array.from({ length: n }, (_, i) => ({ $id: unique('b'), name: tag, count: i, score: i, active: true }));
    api.prep('POST', items(k, d), { [k.item]: list }, [201]);
    return list.map((x) => x.$id);
}

function sweep(api, k, d, tag) {
    api.prep('DELETE', items(k, d), { queries: [query('equal', 'name', [tag])] }, [200]);
}

function waitAvailable(api, path) {
    for (let i = 0; i < 300; i++) {
        const res = api.prep('GET', `${path}?queries[]=${encodeURIComponent(query('limit', undefined, [100]))}`, null, [200]);
        const list = res.json(Object.keys(res.json()).find((key) => key !== 'total')) || [];
        const failed = list.filter((x) => x.status === 'failed' || x.status === 'stuck');
        if (failed.length) {
            throw new Error(`${path}: ${failed.map((x) => `${x.key} ${x.error}`).join(', ')}`);
        }
        if (list.every((x) => x.status === 'available')) {
            return;
        }
        sleep(1);
    }
    throw new Error(`${path}: not available after 300s`);
}

/** One database of an API, with what the scenarios act on. */
function seedApi(api, k, run) {
    const d = { db: api.prep('POST', k.root, { databaseId: 'unique()', name: `Bench ${run}` }, [201]).json('$id') };
    const make = (name, extra = {}) => api.prep('POST', sets(k, d), { [k.setId]: 'unique()', name, ...extra }, [201]).json('$id');

    // Documents (rows) to read and write, with an index the filtered list uses.
    d.docs = make('Docs', { [k.field]: FIELDS, indexes: [{ key: 'count', type: 'key', attributes: ['count'] }] });
    const total = SEED * 5;
    d.items = [];
    for (let i = 0; i < total; i += 100) {
        const list = [];
        for (let j = i; j < Math.min(i + 100, total); j++) {
            list.push({ $id: `doc${j}`, ...record(j) });
        }
        api.prep('POST', items(k, d), { [k.item]: list }, [201]);
        d.items.push(...list.map((x) => x.$id));
    }

    // One attribute (column) of each type, through its own endpoint, to read and update.
    d.fields = make('Fields');
    d.target = make('Target');
    for (const [type, { create }] of Object.entries(TYPES)) {
        api.prep('POST', `${sets(k, d)}/${d.fields}/${k.field}/${type}`, { key: `f_${type}`, ...create }, [202]);
    }
    api.prep('POST', `${sets(k, d)}/${d.fields}/${k.field}/relationship`, {
        [k.related]: d.target, type: 'oneToOne', twoWay: false, key: 'rel', onDelete: 'setNull',
    }, [202]);
    d.keys = Object.keys(TYPES).map((type) => `f_${type}`);

    // Indexes to read.
    d.indexed = make('Indexed', { [k.field]: FIELDS, indexes: ['name', 'count', 'score'].map((key) => ({ key: `i_${key}`, type: 'key', attributes: [key] })) });
    d.indexes = ['i_name', 'i_count', 'i_score'];

    // A collection (table) to rename, and transactions to read.
    d.spare = make('Spare');
    d.transactions = Array.from({ length: 5 }, () => api.prep('POST', `${k.root}/transactions`, { ttl: 3600 }, [201]).json('$id'));

    waitAvailable(api, `${sets(k, d)}/${d.fields}/${k.field}`);
    return d;
}

// Scenarios shared by both APIs, parameterised by `k` (LEGACY or TABLES); `d` is that API's seed.

function createField(k) {
    return ({ type, route }) => ({
        name: `${k.ns}.${k.field}.${type}.create`,
        route,
        run(api, data) {
            const d = data[k.ns];
            const set = scratch(api, k, d);
            api.call('POST', `${sets(k, d)}/${set}/${k.field}/${type}`, { key: 'field', ...TYPES[type].create }, [202]);
            drop(api, k, d, set);
        },
    });
}

function updateField(k) {
    return ({ type, route }) => ({
        name: `${k.ns}.${k.field}.${type}.update`,
        route,
        run(api, data) {
            const d = data[k.ns];
            api.call('PATCH', `${sets(k, d)}/${d.fields}/${k.field}/${type}/f_${type}`, TYPES[type].update);
        },
    });
}

function transaction(api, k, d) {
    return api.prep('POST', `${k.root}/transactions`, { ttl: 60 }, [201]).json('$id');
}

function stage(api, k, d, txn, id) {
    api.prep('POST', `${k.root}/transactions/${txn}/operations`, {
        operations: [{ action: 'create', databaseId: d.db, [k.setId]: d.docs, [k.itemId]: id, data: { name: 'Staged', count: 1, score: 1, active: true } }],
    }, [201]);
}

const ops = {
    // Databases
    listDatabases: (k) => (api) => api.call('GET', `${k.root}${qs(query('limit', undefined, [25]))}`),
    createDatabase: (k) => (api) => {
        const res = api.call('POST', k.root, { databaseId: 'unique()', name: 'New' }, [201]);
        if (res.status === 201) {
            api.prep('DELETE', `${k.root}/${res.json('$id')}`, null, [204]);
        }
    },
    getDatabase: (k) => (api, data) => api.call('GET', db(k, data[k.ns])),
    updateDatabase: (k) => (api, data) => api.call('PUT', db(k, data[k.ns]), { name: `Bench ${Math.floor(Math.random() * 1000)}`, enabled: true }),
    deleteDatabase: (k) => (api) => {
        const id = api.prep('POST', k.root, { databaseId: 'unique()', name: 'Gone' }, [201]).json('$id');
        api.call('DELETE', `${k.root}/${id}`, null, [204]);
    },

    // Collections (tables)
    listSets: (k) => (api, data) => api.call('GET', `${sets(k, data[k.ns])}${qs(query('limit', undefined, [25]))}`),
    createSet: (k) => (api, data) => {
        const d = data[k.ns];
        const res = api.call('POST', sets(k, d), { [k.setId]: 'unique()', name: 'New' }, [201]);
        if (res.status === 201) {
            drop(api, k, d, res.json('$id'));
        }
    },
    createSetInline: (k) => (api, data) => {
        const d = data[k.ns];
        const res = api.call('POST', sets(k, d), { [k.setId]: 'unique()', name: 'New', [k.field]: FIELDS, indexes: [{ key: 'count', type: 'key', attributes: ['count'] }] }, [201]);
        if (res.status === 201) {
            drop(api, k, d, res.json('$id'));
        }
    },
    getSet: (k) => (api, data) => api.call('GET', `${sets(k, data[k.ns])}/${data[k.ns].docs}`),
    updateSet: (k) => (api, data) => {
        const d = data[k.ns];
        api.call('PUT', `${sets(k, d)}/${d.spare}`, { name: `Spare ${Math.floor(Math.random() * 1000)}`, [k.security]: false, enabled: true });
    },
    deleteSet: (k) => (api, data) => {
        const d = data[k.ns];
        api.call('DELETE', `${sets(k, d)}/${scratch(api, k, d)}`, null, [204]);
    },

    // Attributes (columns)
    listFields: (k) => (api, data) => api.call('GET', `${sets(k, data[k.ns])}/${data[k.ns].fields}/${k.field}`),
    getField: (k) => (api, data) => api.call('GET', `${sets(k, data[k.ns])}/${data[k.ns].fields}/${k.field}/${pick(data[k.ns].keys)}`),
    deleteField: (k) => (api, data) => {
        const d = data[k.ns];
        const set = scratch(api, k, d, [{ key: 'field', type: 'integer', required: false }]);
        api.call('DELETE', `${sets(k, d)}/${set}/${k.field}/field`, null, [204]);
        drop(api, k, d, set);
    },
    createRelationship: (k) => (api, data) => {
        const d = data[k.ns];
        const one = scratch(api, k, d);
        const two = scratch(api, k, d);
        api.call('POST', `${sets(k, d)}/${one}/${k.field}/relationship`, { [k.related]: two, type: 'manyToOne', twoWay: false, key: 'rel', onDelete: 'setNull' }, [202]);
        drop(api, k, d, one);
        drop(api, k, d, two);
    },
    updateRelationship: (k) => (api, data) => {
        const d = data[k.ns];
        api.call('PATCH', `${sets(k, d)}/${d.fields}/${k.field}/relationship/rel`, { onDelete: pick(['setNull', 'cascade', 'restrict']) });
    },

    // Indexes
    listIndexes: (k) => (api, data) => api.call('GET', `${sets(k, data[k.ns])}/${data[k.ns].indexed}/indexes`),
    getIndex: (k) => (api, data) => api.call('GET', `${sets(k, data[k.ns])}/${data[k.ns].indexed}/indexes/${pick(data[k.ns].indexes)}`),
    createIndex: (k) => (api, data) => {
        const d = data[k.ns];
        const set = scratch(api, k, d, FIELDS);
        api.call('POST', `${sets(k, d)}/${set}/indexes`, { key: 'ix', type: 'key', [k.field]: ['name', 'count'], orders: ['ASC', 'DESC'] }, [202]);
        drop(api, k, d, set);
    },
    deleteIndex: (k) => (api, data) => {
        const d = data[k.ns];
        const set = scratch(api, k, d, FIELDS, [{ key: 'ix', type: 'key', attributes: ['count'] }]);
        api.call('DELETE', `${sets(k, d)}/${set}/indexes/ix`, null, [204]);
        drop(api, k, d, set);
    },

    // Documents (rows)
    listItems: (k) => (api, data) => api.call('GET', `${items(k, data[k.ns])}${qs(query('limit', undefined, [25]), query('offset', undefined, [Math.floor(Math.random() * 100)]))}`),
    filterItems: (k) => (api, data) => api.call('GET', `${items(k, data[k.ns])}${qs(
        query('equal', 'active', [true]),
        query('greaterThan', 'count', [Math.floor(Math.random() * SEED * 4)]),
        query('orderAsc', 'count'),
        query('limit', undefined, [25]),
    )}`),
    selectItems: (k) => (api, data) => api.call('GET', `${items(k, data[k.ns])}${qs(query('select', undefined, ['name', 'count']), query('equal', 'name', [`Seed ${Math.floor(Math.random() * 100)}`]))}`),
    getItem: (k) => (api, data) => api.call('GET', `${items(k, data[k.ns])}/${pick(data[k.ns].items)}`),
    createItem: (k) => (api, data) => {
        const d = data[k.ns];
        const res = api.call('POST', items(k, d), { [k.itemId]: 'unique()', data: record(Math.floor(Math.random() * 1000)) }, [201]);
        if (res.status === 201) {
            api.prep('DELETE', `${items(k, d)}/${res.json('$id')}`, null, [204]);
        }
    },
    createItems: (k) => (api, data) => {
        const d = data[k.ns];
        const tag = unique('bulk');
        api.call('POST', items(k, d), { [k.item]: Array.from({ length: 10 }, (_, i) => ({ $id: unique('b'), name: tag, count: i, score: i, active: true })) }, [201]);
        sweep(api, k, d, tag);
    },
    upsertItems: (k) => (api, data) => {
        const d = data[k.ns];
        const tag = unique('bulk');
        const existing = batch(api, k, d, tag, 5);
        const list = [...existing, unique('b'), unique('b'), unique('b'), unique('b'), unique('b')].map((id, i) => ({ $id: id, name: tag, count: i, score: i * 2, active: false }));
        api.call('PUT', items(k, d), { [k.item]: list });
        sweep(api, k, d, tag);
    },
    updateItems: (k) => (api, data) => {
        const d = data[k.ns];
        const tag = unique('bulk');
        batch(api, k, d, tag, 10);
        api.call('PATCH', items(k, d), { data: { score: Math.random(), active: false }, queries: [query('equal', 'name', [tag])] });
        sweep(api, k, d, tag);
    },
    deleteItems: (k) => (api, data) => {
        const d = data[k.ns];
        const tag = unique('bulk');
        batch(api, k, d, tag, 10);
        api.call('DELETE', items(k, d), { queries: [query('equal', 'name', [tag])] });
    },
    upsertItem: (k) => (api, data) => {
        const d = data[k.ns];
        const id = pick(d.items);
        api.call('PUT', `${items(k, d)}/${id}`, { data: record(parseInt(id.slice(3), 10)) });
    },
    upsertNewItem: (k) => (api, data) => {
        const d = data[k.ns];
        const id = unique('up');
        api.call('PUT', `${items(k, d)}/${id}`, { data: record(1) }, [200, 201]);
        api.prep('DELETE', `${items(k, d)}/${id}`, null, [204]);
    },
    updateItem: (k) => (api, data) => {
        const d = data[k.ns];
        api.call('PATCH', `${items(k, d)}/${pick(d.items)}`, { data: { score: Math.random() * 100 } });
    },
    deleteItem: (k) => (api, data) => {
        const d = data[k.ns];
        const id = api.prep('POST', items(k, d), { [k.itemId]: 'unique()', data: record(1) }, [201]).json('$id');
        api.call('DELETE', `${items(k, d)}/${id}`, null, [204]);
    },
    increment: (k) => (api, data) => {
        const d = data[k.ns];
        api.call('PATCH', `${items(k, d)}/${pick(d.items)}/count/increment`, { value: 1 });
    },
    decrement: (k) => (api, data) => {
        const d = data[k.ns];
        api.call('PATCH', `${items(k, d)}/${pick(d.items)}/count/decrement`, { value: 1 });
    },

    // Transactions
    listTransactions: (k) => (api) => api.call('GET', `${k.root}/transactions`),
    getTransaction: (k) => (api, data) => api.call('GET', `${k.root}/transactions/${pick(data[k.ns].transactions)}`),
    createTransaction: (k) => (api) => {
        const res = api.call('POST', `${k.root}/transactions`, { ttl: 60 }, [201]);
        if (res.status === 201) {
            api.prep('DELETE', `${k.root}/transactions/${res.json('$id')}`, null, [204]);
        }
    },
    deleteTransaction: (k) => (api, data) => api.call('DELETE', `${k.root}/transactions/${transaction(api, k, data[k.ns])}`, null, [204]),
    createOperations: (k) => (api, data) => {
        const d = data[k.ns];
        const txn = transaction(api, k, d);
        api.call('POST', `${k.root}/transactions/${txn}/operations`, {
            operations: [
                { action: 'create', databaseId: d.db, [k.setId]: d.docs, [k.itemId]: unique('tx'), data: { name: 'Staged', count: 1, score: 1, active: true } },
                { action: 'update', databaseId: d.db, [k.setId]: d.docs, [k.itemId]: pick(d.items), data: { score: Math.random() } },
            ],
        }, [201]);
        api.prep('PATCH', `${k.root}/transactions/${txn}`, { rollback: true });
    },
    commit: (k) => (api, data) => {
        const d = data[k.ns];
        const txn = transaction(api, k, d);
        const id = unique('tx');
        stage(api, k, d, txn, id);
        api.call('PATCH', `${k.root}/transactions/${txn}`, { commit: true });
        api.prep('DELETE', `${items(k, d)}/${id}`, null, [204]);
    },
    rollback: (k) => (api, data) => {
        const d = data[k.ns];
        const txn = transaction(api, k, d);
        stage(api, k, d, txn, unique('tx'));
        api.call('PATCH', `${k.root}/transactions/${txn}`, { rollback: true });
    },
};

const L = (op) => ops[op](LEGACY);
const T = (op) => ops[op](TABLES);

/** An endpoint of a product the dev stack runs with off: one request that answers 403 general_service_disabled. */
function disabled(note) {
    return ({ name, route }) => {
        const [method, path] = route.split(' ');
        const url = path.slice(3).split('/').map((part) => (part.startsWith(':') ? 'bench' : part)).join('/');
        return {
            name, route, note,
            run: (api) => api.call(method, url, method === 'GET' || method === 'DELETE' ? null : {}, [403]),
        };
    };
}

const bench = define({
    service: 'databases',
    scopes: [
        'databases.read', 'databases.write', 'collections.read', 'collections.write', 'attributes.read', 'attributes.write',
        'indexes.read', 'indexes.write', 'documents.read', 'documents.write', 'tables.read', 'tables.write',
        'columns.read', 'columns.write', 'rows.read', 'rows.write',
        'documentsdb.read', 'documentsdb.write', 'documentsdb.collections.read', 'documentsdb.collections.write',
        'documentsdb.documents.read', 'documentsdb.documents.write', 'documentsdb.indexes.read', 'documentsdb.indexes.write',
        'vectorsdb.read', 'vectorsdb.write', 'vectorsdb.collections.read', 'vectorsdb.collections.write',
        'vectorsdb.documents.read', 'vectorsdb.documents.write', 'vectorsdb.indexes.read', 'vectorsdb.indexes.write',
        'embeddings.write',
    ],
    seed(api, { run }) {
        return { databases: seedApi(api, LEGACY, run), tablesdb: seedApi(api, TABLES, run) };
    },
    scenarios: [
        // Databases (legacy API)
        { name: 'databases.list', route: 'GET /v1/databases', run: L('listDatabases') },
        { name: 'databases.create', route: 'POST /v1/databases', run: L('createDatabase') },
        { name: 'databases.get', route: 'GET /v1/databases/:databaseId', weight: 2, run: L('getDatabase') },
        { name: 'databases.update', route: 'PUT /v1/databases/:databaseId', run: L('updateDatabase') },
        { name: 'databases.delete', route: 'DELETE /v1/databases/:databaseId', run: L('deleteDatabase') },

        { name: 'databases.collections.list', route: 'GET /v1/databases/:databaseId/collections', weight: 2, run: L('listSets') },
        { name: 'databases.collections.create', route: 'POST /v1/databases/:databaseId/collections', run: L('createSet') },
        {
            name: 'databases.collections.createInline', route: 'POST /v1/databases/:databaseId/collections', note: 'with four attributes and an index created inline',
            run: L('createSetInline'),
        },
        { name: 'databases.collections.get', route: 'GET /v1/databases/:databaseId/collections/:collectionId', weight: 3, run: L('getSet') },
        { name: 'databases.collections.update', route: 'PUT /v1/databases/:databaseId/collections/:collectionId', run: L('updateSet') },
        { name: 'databases.collections.delete', route: 'DELETE /v1/databases/:databaseId/collections/:collectionId', run: L('deleteSet') },

        { name: 'databases.attributes.list', route: 'GET /v1/databases/:databaseId/collections/:collectionId/attributes', weight: 2, run: L('listFields') },
        { name: 'databases.attributes.get', route: 'GET /v1/databases/:databaseId/collections/:collectionId/attributes/:key', weight: 2, run: L('getField') },
        { name: 'databases.attributes.delete', route: 'DELETE /v1/databases/:databaseId/collections/:collectionId/attributes/:key', run: L('deleteField') },
        ...[
            { type: 'bigint', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/bigint' },
            { type: 'boolean', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/boolean' },
            { type: 'datetime', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/datetime' },
            { type: 'email', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/email' },
            { type: 'enum', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/enum' },
            { type: 'float', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/float' },
            { type: 'integer', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/integer' },
            { type: 'ip', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/ip' },
            { type: 'line', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/line' },
            { type: 'longtext', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/longtext' },
            { type: 'mediumtext', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/mediumtext' },
            { type: 'point', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/point' },
            { type: 'polygon', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/polygon' },
            { type: 'string', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/string' },
            { type: 'text', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/text' },
            { type: 'url', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/url' },
            { type: 'varchar', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/varchar' },
        ].map(createField(LEGACY)),
        ...[
            { type: 'bigint', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/bigint/:key' },
            { type: 'boolean', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/boolean/:key' },
            { type: 'datetime', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/datetime/:key' },
            { type: 'email', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/email/:key' },
            { type: 'enum', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/enum/:key' },
            { type: 'float', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/float/:key' },
            { type: 'integer', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/integer/:key' },
            { type: 'ip', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/ip/:key' },
            { type: 'line', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/line/:key' },
            { type: 'longtext', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/longtext/:key' },
            { type: 'mediumtext', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/mediumtext/:key' },
            { type: 'point', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/point/:key' },
            { type: 'polygon', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/polygon/:key' },
            { type: 'string', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/string/:key' },
            { type: 'text', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/text/:key' },
            { type: 'url', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/url/:key' },
            { type: 'varchar', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/varchar/:key' },
        ].map(updateField(LEGACY)),
        { name: 'databases.attributes.relationship.create', route: 'POST /v1/databases/:databaseId/collections/:collectionId/attributes/relationship', run: L('createRelationship') },
        { name: 'databases.attributes.relationship.update', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/attributes/relationship/:key', run: L('updateRelationship') },

        { name: 'databases.indexes.list', route: 'GET /v1/databases/:databaseId/collections/:collectionId/indexes', run: L('listIndexes') },
        { name: 'databases.indexes.get', route: 'GET /v1/databases/:databaseId/collections/:collectionId/indexes/:key', run: L('getIndex') },
        { name: 'databases.indexes.create', route: 'POST /v1/databases/:databaseId/collections/:collectionId/indexes', run: L('createIndex') },
        { name: 'databases.indexes.delete', route: 'DELETE /v1/databases/:databaseId/collections/:collectionId/indexes/:key', run: L('deleteIndex') },

        { name: 'databases.documents.list', route: 'GET /v1/databases/:databaseId/collections/:collectionId/documents', weight: 12, run: L('listItems') },
        {
            name: 'databases.documents.filter', route: 'GET /v1/databases/:databaseId/collections/:collectionId/documents', weight: 8, note: 'equal, greaterThan on an index, orderAsc',
            run: L('filterItems'),
        },
        {
            name: 'databases.documents.select', route: 'GET /v1/databases/:databaseId/collections/:collectionId/documents', weight: 4, note: 'select two attributes, equal on an unindexed one',
            run: L('selectItems'),
        },
        { name: 'databases.documents.get', route: 'GET /v1/databases/:databaseId/collections/:collectionId/documents/:documentId', weight: 25, run: L('getItem') },
        { name: 'databases.documents.create', route: 'POST /v1/databases/:databaseId/collections/:collectionId/documents', weight: 4, run: L('createItem') },
        {
            name: 'databases.documents.createBulk', route: 'POST /v1/databases/:databaseId/collections/:collectionId/documents', note: 'a batch of 10 documents',
            run: L('createItems'),
        },
        {
            name: 'databases.documents.upsertBulk', route: 'PUT /v1/databases/:databaseId/collections/:collectionId/documents', note: 'a batch of 10: 5 updated, 5 created',
            run: L('upsertItems'),
        },
        {
            name: 'databases.documents.updateBulk', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/documents', note: '10 documents matched by a query',
            run: L('updateItems'),
        },
        {
            name: 'databases.documents.deleteBulk', route: 'DELETE /v1/databases/:databaseId/collections/:collectionId/documents', note: '10 documents matched by a query',
            run: L('deleteItems'),
        },
        { name: 'databases.documents.upsert', route: 'PUT /v1/databases/:databaseId/collections/:collectionId/documents/:documentId', weight: 2, run: L('upsertItem') },
        {
            name: 'databases.documents.upsertNew', route: 'PUT /v1/databases/:databaseId/collections/:collectionId/documents/:documentId', note: 'creates the document',
            run: L('upsertNewItem'),
        },
        { name: 'databases.documents.update', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/documents/:documentId', weight: 5, run: L('updateItem') },
        { name: 'databases.documents.delete', route: 'DELETE /v1/databases/:databaseId/collections/:collectionId/documents/:documentId', weight: 2, run: L('deleteItem') },
        { name: 'databases.documents.decrement', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/documents/:documentId/:attribute/decrement', weight: 2, run: L('decrement') },
        { name: 'databases.documents.increment', route: 'PATCH /v1/databases/:databaseId/collections/:collectionId/documents/:documentId/:attribute/increment', weight: 2, run: L('increment') },

        { name: 'databases.transactions.list', route: 'GET /v1/databases/transactions', run: L('listTransactions') },
        { name: 'databases.transactions.create', route: 'POST /v1/databases/transactions', run: L('createTransaction') },
        { name: 'databases.transactions.get', route: 'GET /v1/databases/transactions/:transactionId', run: L('getTransaction') },
        { name: 'databases.transactions.commit', route: 'PATCH /v1/databases/transactions/:transactionId', note: 'commits one staged create', run: L('commit') },
        { name: 'databases.transactions.rollback', route: 'PATCH /v1/databases/transactions/:transactionId', note: 'rolls back one staged create', run: L('rollback') },
        { name: 'databases.transactions.delete', route: 'DELETE /v1/databases/transactions/:transactionId', run: L('deleteTransaction') },
        {
            name: 'databases.transactions.operations.create', route: 'POST /v1/databases/transactions/:transactionId/operations', note: 'stages a create and an update',
            run: L('createOperations'),
        },

        // TablesDB
        { name: 'tablesdb.list', route: 'GET /v1/tablesdb', run: T('listDatabases') },
        { name: 'tablesdb.create', route: 'POST /v1/tablesdb', run: T('createDatabase') },
        { name: 'tablesdb.get', route: 'GET /v1/tablesdb/:databaseId', weight: 2, run: T('getDatabase') },
        { name: 'tablesdb.update', route: 'PUT /v1/tablesdb/:databaseId', run: T('updateDatabase') },
        { name: 'tablesdb.delete', route: 'DELETE /v1/tablesdb/:databaseId', run: T('deleteDatabase') },

        { name: 'tablesdb.tables.list', route: 'GET /v1/tablesdb/:databaseId/tables', weight: 2, run: T('listSets') },
        { name: 'tablesdb.tables.create', route: 'POST /v1/tablesdb/:databaseId/tables', run: T('createSet') },
        { name: 'tablesdb.tables.createInline', route: 'POST /v1/tablesdb/:databaseId/tables', note: 'with four columns and an index created inline', run: T('createSetInline') },
        { name: 'tablesdb.tables.get', route: 'GET /v1/tablesdb/:databaseId/tables/:tableId', weight: 3, run: T('getSet') },
        { name: 'tablesdb.tables.update', route: 'PUT /v1/tablesdb/:databaseId/tables/:tableId', run: T('updateSet') },
        { name: 'tablesdb.tables.delete', route: 'DELETE /v1/tablesdb/:databaseId/tables/:tableId', run: T('deleteSet') },

        { name: 'tablesdb.columns.list', route: 'GET /v1/tablesdb/:databaseId/tables/:tableId/columns', weight: 2, run: T('listFields') },
        { name: 'tablesdb.columns.get', route: 'GET /v1/tablesdb/:databaseId/tables/:tableId/columns/:key', weight: 2, run: T('getField') },
        { name: 'tablesdb.columns.delete', route: 'DELETE /v1/tablesdb/:databaseId/tables/:tableId/columns/:key', run: T('deleteField') },
        ...[
            { type: 'bigint', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/bigint' },
            { type: 'boolean', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/boolean' },
            { type: 'datetime', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/datetime' },
            { type: 'email', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/email' },
            { type: 'enum', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/enum' },
            { type: 'float', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/float' },
            { type: 'integer', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/integer' },
            { type: 'ip', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/ip' },
            { type: 'line', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/line' },
            { type: 'longtext', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/longtext' },
            { type: 'mediumtext', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/mediumtext' },
            { type: 'point', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/point' },
            { type: 'polygon', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/polygon' },
            { type: 'string', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/string' },
            { type: 'text', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/text' },
            { type: 'url', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/url' },
            { type: 'varchar', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/varchar' },
        ].map(createField(TABLES)),
        ...[
            { type: 'bigint', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/bigint/:key' },
            { type: 'boolean', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/boolean/:key' },
            { type: 'datetime', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/datetime/:key' },
            { type: 'email', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/email/:key' },
            { type: 'enum', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/enum/:key' },
            { type: 'float', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/float/:key' },
            { type: 'integer', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/integer/:key' },
            { type: 'ip', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/ip/:key' },
            { type: 'line', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/line/:key' },
            { type: 'longtext', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/longtext/:key' },
            { type: 'mediumtext', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/mediumtext/:key' },
            { type: 'point', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/point/:key' },
            { type: 'polygon', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/polygon/:key' },
            { type: 'string', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/string/:key' },
            { type: 'text', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/text/:key' },
            { type: 'url', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/url/:key' },
            { type: 'varchar', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/varchar/:key' },
        ].map(updateField(TABLES)),
        { name: 'tablesdb.columns.relationship.create', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/columns/relationship', run: T('createRelationship') },
        { name: 'tablesdb.columns.relationship.update', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/columns/relationship/:key', run: T('updateRelationship') },

        { name: 'tablesdb.indexes.list', route: 'GET /v1/tablesdb/:databaseId/tables/:tableId/indexes', run: T('listIndexes') },
        { name: 'tablesdb.indexes.get', route: 'GET /v1/tablesdb/:databaseId/tables/:tableId/indexes/:key', run: T('getIndex') },
        { name: 'tablesdb.indexes.create', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/indexes', run: T('createIndex') },
        { name: 'tablesdb.indexes.delete', route: 'DELETE /v1/tablesdb/:databaseId/tables/:tableId/indexes/:key', run: T('deleteIndex') },

        { name: 'tablesdb.rows.list', route: 'GET /v1/tablesdb/:databaseId/tables/:tableId/rows', weight: 12, run: T('listItems') },
        { name: 'tablesdb.rows.filter', route: 'GET /v1/tablesdb/:databaseId/tables/:tableId/rows', weight: 8, note: 'equal, greaterThan on an index, orderAsc', run: T('filterItems') },
        { name: 'tablesdb.rows.select', route: 'GET /v1/tablesdb/:databaseId/tables/:tableId/rows', weight: 4, note: 'select two columns, equal on an unindexed one', run: T('selectItems') },
        { name: 'tablesdb.rows.get', route: 'GET /v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId', weight: 25, run: T('getItem') },
        { name: 'tablesdb.rows.create', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/rows', weight: 4, run: T('createItem') },
        { name: 'tablesdb.rows.createBulk', route: 'POST /v1/tablesdb/:databaseId/tables/:tableId/rows', note: 'a batch of 10 rows', run: T('createItems') },
        { name: 'tablesdb.rows.upsertBulk', route: 'PUT /v1/tablesdb/:databaseId/tables/:tableId/rows', note: 'a batch of 10: 5 updated, 5 created', run: T('upsertItems') },
        { name: 'tablesdb.rows.updateBulk', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/rows', note: '10 rows matched by a query', run: T('updateItems') },
        { name: 'tablesdb.rows.deleteBulk', route: 'DELETE /v1/tablesdb/:databaseId/tables/:tableId/rows', note: '10 rows matched by a query', run: T('deleteItems') },
        { name: 'tablesdb.rows.upsert', route: 'PUT /v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId', weight: 2, run: T('upsertItem') },
        { name: 'tablesdb.rows.upsertNew', route: 'PUT /v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId', note: 'creates the row', run: T('upsertNewItem') },
        { name: 'tablesdb.rows.update', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId', weight: 5, run: T('updateItem') },
        { name: 'tablesdb.rows.delete', route: 'DELETE /v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId', weight: 2, run: T('deleteItem') },
        { name: 'tablesdb.rows.decrement', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId/:column/decrement', weight: 2, run: T('decrement') },
        { name: 'tablesdb.rows.increment', route: 'PATCH /v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId/:column/increment', weight: 2, run: T('increment') },

        { name: 'tablesdb.transactions.list', route: 'GET /v1/tablesdb/transactions', run: T('listTransactions') },
        { name: 'tablesdb.transactions.create', route: 'POST /v1/tablesdb/transactions', run: T('createTransaction') },
        { name: 'tablesdb.transactions.get', route: 'GET /v1/tablesdb/transactions/:transactionId', run: T('getTransaction') },
        { name: 'tablesdb.transactions.commit', route: 'PATCH /v1/tablesdb/transactions/:transactionId', note: 'commits one staged create', run: T('commit') },
        { name: 'tablesdb.transactions.rollback', route: 'PATCH /v1/tablesdb/transactions/:transactionId', note: 'rolls back one staged create', run: T('rollback') },
        { name: 'tablesdb.transactions.delete', route: 'DELETE /v1/tablesdb/transactions/:transactionId', run: T('deleteTransaction') },
        {
            name: 'tablesdb.transactions.operations.create', route: 'POST /v1/tablesdb/transactions/:transactionId/operations', note: 'stages a create and an update',
            run: T('createOperations'),
        },

        // DocumentsDB (MongoDB only) and VectorsDB (PostgreSQL only) are off unless
        // an operator provisions their engine, and the dev stack does not.
        ...[
            { name: 'documentsdb.list', route: 'GET /v1/documentsdb' },
            { name: 'documentsdb.create', route: 'POST /v1/documentsdb' },
            { name: 'documentsdb.get', route: 'GET /v1/documentsdb/:databaseId' },
            { name: 'documentsdb.update', route: 'PUT /v1/documentsdb/:databaseId' },
            { name: 'documentsdb.delete', route: 'DELETE /v1/documentsdb/:databaseId' },
            { name: 'documentsdb.collections.list', route: 'GET /v1/documentsdb/:databaseId/collections' },
            { name: 'documentsdb.collections.create', route: 'POST /v1/documentsdb/:databaseId/collections' },
            { name: 'documentsdb.collections.get', route: 'GET /v1/documentsdb/:databaseId/collections/:collectionId' },
            { name: 'documentsdb.collections.update', route: 'PUT /v1/documentsdb/:databaseId/collections/:collectionId' },
            { name: 'documentsdb.collections.delete', route: 'DELETE /v1/documentsdb/:databaseId/collections/:collectionId' },
            { name: 'documentsdb.documents.list', route: 'GET /v1/documentsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'documentsdb.documents.create', route: 'POST /v1/documentsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'documentsdb.documents.upsertBulk', route: 'PUT /v1/documentsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'documentsdb.documents.updateBulk', route: 'PATCH /v1/documentsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'documentsdb.documents.deleteBulk', route: 'DELETE /v1/documentsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'documentsdb.documents.get', route: 'GET /v1/documentsdb/:databaseId/collections/:collectionId/documents/:documentId' },
            { name: 'documentsdb.documents.upsert', route: 'PUT /v1/documentsdb/:databaseId/collections/:collectionId/documents/:documentId' },
            { name: 'documentsdb.documents.update', route: 'PATCH /v1/documentsdb/:databaseId/collections/:collectionId/documents/:documentId' },
            { name: 'documentsdb.documents.delete', route: 'DELETE /v1/documentsdb/:databaseId/collections/:collectionId/documents/:documentId' },
            { name: 'documentsdb.documents.decrement', route: 'PATCH /v1/documentsdb/:databaseId/collections/:collectionId/documents/:documentId/:attribute/decrement' },
            { name: 'documentsdb.documents.increment', route: 'PATCH /v1/documentsdb/:databaseId/collections/:collectionId/documents/:documentId/:attribute/increment' },
            { name: 'documentsdb.indexes.list', route: 'GET /v1/documentsdb/:databaseId/collections/:collectionId/indexes' },
            { name: 'documentsdb.indexes.create', route: 'POST /v1/documentsdb/:databaseId/collections/:collectionId/indexes' },
            { name: 'documentsdb.indexes.get', route: 'GET /v1/documentsdb/:databaseId/collections/:collectionId/indexes/:key' },
            { name: 'documentsdb.indexes.delete', route: 'DELETE /v1/documentsdb/:databaseId/collections/:collectionId/indexes/:key' },
            { name: 'documentsdb.transactions.list', route: 'GET /v1/documentsdb/transactions' },
            { name: 'documentsdb.transactions.create', route: 'POST /v1/documentsdb/transactions' },
            { name: 'documentsdb.transactions.get', route: 'GET /v1/documentsdb/transactions/:transactionId' },
            { name: 'documentsdb.transactions.update', route: 'PATCH /v1/documentsdb/transactions/:transactionId' },
            { name: 'documentsdb.transactions.delete', route: 'DELETE /v1/documentsdb/transactions/:transactionId' },
            { name: 'documentsdb.transactions.operations.create', route: 'POST /v1/documentsdb/transactions/:transactionId/operations' },
        ].map(disabled(DOCUMENTSDB)),
        ...[
            { name: 'vectorsdb.list', route: 'GET /v1/vectorsdb' },
            { name: 'vectorsdb.create', route: 'POST /v1/vectorsdb' },
            { name: 'vectorsdb.get', route: 'GET /v1/vectorsdb/:databaseId' },
            { name: 'vectorsdb.update', route: 'PUT /v1/vectorsdb/:databaseId' },
            { name: 'vectorsdb.delete', route: 'DELETE /v1/vectorsdb/:databaseId' },
            { name: 'vectorsdb.collections.list', route: 'GET /v1/vectorsdb/:databaseId/collections' },
            { name: 'vectorsdb.collections.create', route: 'POST /v1/vectorsdb/:databaseId/collections' },
            { name: 'vectorsdb.collections.get', route: 'GET /v1/vectorsdb/:databaseId/collections/:collectionId' },
            { name: 'vectorsdb.collections.update', route: 'PUT /v1/vectorsdb/:databaseId/collections/:collectionId' },
            { name: 'vectorsdb.collections.delete', route: 'DELETE /v1/vectorsdb/:databaseId/collections/:collectionId' },
            { name: 'vectorsdb.documents.list', route: 'GET /v1/vectorsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'vectorsdb.documents.create', route: 'POST /v1/vectorsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'vectorsdb.documents.upsertBulk', route: 'PUT /v1/vectorsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'vectorsdb.documents.updateBulk', route: 'PATCH /v1/vectorsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'vectorsdb.documents.deleteBulk', route: 'DELETE /v1/vectorsdb/:databaseId/collections/:collectionId/documents' },
            { name: 'vectorsdb.documents.get', route: 'GET /v1/vectorsdb/:databaseId/collections/:collectionId/documents/:documentId' },
            { name: 'vectorsdb.documents.upsert', route: 'PUT /v1/vectorsdb/:databaseId/collections/:collectionId/documents/:documentId' },
            { name: 'vectorsdb.documents.update', route: 'PATCH /v1/vectorsdb/:databaseId/collections/:collectionId/documents/:documentId' },
            { name: 'vectorsdb.documents.delete', route: 'DELETE /v1/vectorsdb/:databaseId/collections/:collectionId/documents/:documentId' },
            { name: 'vectorsdb.documents.query', route: 'POST /v1/vectorsdb/:databaseId/collections/:collectionId/documents/query' },
            { name: 'vectorsdb.indexes.list', route: 'GET /v1/vectorsdb/:databaseId/collections/:collectionId/indexes' },
            { name: 'vectorsdb.indexes.create', route: 'POST /v1/vectorsdb/:databaseId/collections/:collectionId/indexes' },
            { name: 'vectorsdb.indexes.get', route: 'GET /v1/vectorsdb/:databaseId/collections/:collectionId/indexes/:key' },
            { name: 'vectorsdb.indexes.delete', route: 'DELETE /v1/vectorsdb/:databaseId/collections/:collectionId/indexes/:key' },
            { name: 'vectorsdb.transactions.list', route: 'GET /v1/vectorsdb/transactions' },
            { name: 'vectorsdb.transactions.create', route: 'POST /v1/vectorsdb/transactions' },
            { name: 'vectorsdb.transactions.get', route: 'GET /v1/vectorsdb/transactions/:transactionId' },
            { name: 'vectorsdb.transactions.update', route: 'PATCH /v1/vectorsdb/transactions/:transactionId' },
            { name: 'vectorsdb.transactions.delete', route: 'DELETE /v1/vectorsdb/transactions/:transactionId' },
            { name: 'vectorsdb.transactions.operations.create', route: 'POST /v1/vectorsdb/transactions/:transactionId/operations' },
        ].map(disabled(VECTORSDB)),
        ...[
            { name: 'embeddings.text.create', route: 'POST /v1/embeddings/text' },
        ].map(disabled('the embedding model container runs behind a Compose profile and the dev stack sets _APP_EMBEDDING=disabled: measured on its service-disabled path')),
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
