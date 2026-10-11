// GraphQL API benchmark: the three /v1/graphql endpoints, each running a small
// Users operation of the run's project through the GraphQL schema. See README.md.

import { define, SEED, pick } from './lib.js';

const LIST = 'query { usersList { total users { _id name email } } }';
const GET = 'query Get($userId: String!) { usersGet(userId: $userId) { _id name email status labels } }';
const UPDATE = 'mutation Update($userId: String!, $name: String!) { usersUpdateName(userId: $userId, name: $name) { _id name } }';
const INTROSPECTION = 'query { __schema { queryType { name } mutationType { name } types { name kind } } }';

function ok(api, res) {
    // GraphQL answers 200 with an `errors` list when a resolver fails.
    if (res.status === 200 && res.json('errors') !== null && res.json('errors') !== undefined) {
        api.expect({ status: 0, body: res.body }, [200], `${api.name} errors`);
    }
}

const bench = define({
    service: 'graphql',
    scopes: ['users.read', 'users.write'],
    seed(api, { run }) {
        const users = [];
        for (let i = 0; i < SEED; i++) {
            users.push(api.prep('POST', '/users', { userId: 'unique()', email: `gql-${i}-${run}@example.com`, name: `GraphQL User ${i}` }, [201]).json('$id'));
        }
        return { users };
    },
    scenarios: [
        {
            name: 'graphql.get', route: 'GET /v1/graphql', weight: 4, note: 'usersList (read-only GET)',
            run(api) {
                ok(api, api.call('GET', `/graphql?query=${encodeURIComponent(LIST)}`));
            },
        },
        {
            name: 'graphql.query', route: 'POST /v1/graphql', weight: 6, note: 'usersGet with variables',
            run(api, d) {
                ok(api, api.call('POST', '/graphql', { query: GET, variables: { userId: pick(d.users) } }));
            },
        },
        {
            name: 'graphql.introspection', route: 'POST /v1/graphql', note: 'schema introspection (types, query and mutation roots)',
            run(api) {
                ok(api, api.call('POST', '/graphql', { query: INTROSPECTION }));
            },
        },
        {
            name: 'graphql.mutation', route: 'POST /v1/graphql/mutation', weight: 3, note: 'usersUpdateName with variables',
            run(api, d) {
                ok(api, api.call('POST', '/graphql/mutation', { query: UPDATE, variables: { userId: pick(d.users), name: `GraphQL User ${Math.floor(Math.random() * 1000)}` } }));
            },
        },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
