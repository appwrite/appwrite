import http from 'k6/http';
import { check } from 'k6';

/**
 * @typedef {Object} AuthHeaders
 * @property {string} 'Content-Type' - Content type header
 * @property {string} 'Cookie' - Session cookie
 * @property {string} 'X-Appwrite-Project' - Project ID header
 */

/**
 * @typedef {Object} ApiHeaders
 * @property {string} 'Content-Type' - Content type header
 * @property {string} 'X-Appwrite-Project' - Project ID header
 * @property {string} 'X-Appwrite-Key' - API key header
 */

/**
 * @typedef {Object} ProvisionedResources
 * @property {string} userId - The ID of the created user
 * @property {string} teamId - The ID of the created team
 * @property {string} projectId - The ID of the created project
 * @property {string} cookies - Session cookies for authentication
 * @property {AuthHeaders} headers - Headers for cookie-based authentication
 * @property {string} apiKey - The API key secret
 * @property {ApiHeaders} apiHeaders - Headers for API key authentication
 */

function assert(response, checkName, condition) {
    const result = check(response, {
        [checkName]: condition
    });
    if (!result) {
        console.error(`Assertion failed: ${checkName}`);
        console.error(`Response status: ${response.status}`);
        console.error(`Response body: ${response.body}`);
        throw new Error(`Assertion failed: ${checkName}`);
    }
}

/**
 * Provisions an Appwrite project setup including:
 * - Account creation
 * - Session creation
 * - Team creation
 * - Project creation
 * - API Key creation
 * 
 * @param {Object} config Configuration object
 * @param {string} config.endpoint Base endpoint URL (e.g., 'http://localhost:80/v1')
 * @param {string} config.email Email for account creation
 * @param {string} config.password Password for account creation
 * @param {string} config.name Name for account creation
 * @param {string} config.projectName Name for the project
 * @returns {ProvisionedResources} Object containing all created resource IDs and session information
 */
export function provisionProject(config) {
    const {
        endpoint,
        email,
        password,
        name,
        projectName,
    } = config;

    // Step 1: Create Account
    const accountResponse = http.post(`${endpoint}/account`, JSON.stringify({
        userId: 'unique()',
        email,
        password,
        name
    }), {
        headers: {
            'Content-Type': 'application/json',
        }
    });

    assert(accountResponse, 'account created successfully', (r) => r.status === 201 || r.status === 409);

    const userId = accountResponse.json('$id');

    // Step 2: Create Session
    const sessionResponse = http.post(`${endpoint}/account/sessions/email`, JSON.stringify({
        email,
        password
    }), {
        headers: {
            'Content-Type': 'application/json',
        }
    });

    assert(sessionResponse, 'session created successfully', (r) => r.status === 201);

    // Keep manual control of the cookies to allow for simultaneous requests
    const jar = http.cookieJar();
    jar.clear(`${endpoint}`);

    // Extract cookies for subsequent requests
    const cookies = sessionResponse.headers['Set-Cookie'];

    // Common headers for authenticated requests
    const authHeaders = {
        'Content-Type': 'application/json',
        'Cookie': cookies
    };

    // Step 3: Create Team
    const teamResponse = http.post(`${endpoint}/teams`, JSON.stringify({
        teamId: 'unique()',
        name: `${projectName} Team`
    }), {
        headers: authHeaders
    });

    assert(teamResponse, 'team created successfully', (r) => r.status === 201);

    const teamId = teamResponse.json('$id');

    // Step 4: Create Project
    const projectResponse = http.post(`${endpoint}/projects`, JSON.stringify({
        projectId: 'unique()',
        name: projectName,
        teamId: teamId
    }), {
        headers: authHeaders
    });

    assert(projectResponse, 'project created successfully', (r) => r.status === 201);

    const projectId = projectResponse.json('$id');

    // Step 5: Create API Key
    const apiKeyResponse = http.post(`${endpoint}/projects/${projectId}/keys`, JSON.stringify({
        name: 'Test API Key',
        scopes: SCOPES, // All permissions
    }), {
        headers: authHeaders
    });

    assert(apiKeyResponse, 'api key created successfully', (r) => r.status === 201);

    const apiKey = apiKeyResponse.json('secret');

    // Create a new headers object for API key authentication
    const apiHeaders = {
        'Content-Type': 'application/json',
        'X-Appwrite-Project': projectId,
        'X-Appwrite-Key': apiKey
    };

    // Return all created resources and session info
    return {
        endpoint,
        userId,
        teamId,
        projectId,
        cookies,
        headers: authHeaders,
        apiKey,
        apiHeaders
    };
}

/**
 * Example usage:
 * 
 * const config = {
 *     endpoint: 'http://localhost:80/v1',
 *     email: 'test@example.com',
 *     password: 'complex-password',
 *     name: 'Test User',
 *     projectName: 'Test Project'
 * };
 * 
 * const resources = provisionProject(config);
 */

const SCOPES = [
    "sessions.write",
    "users.read",
    "users.write",
    "teams.read",
    "teams.write",
    "databases.read",
    "databases.write",
    "collections.read",
    "collections.write",
    "attributes.read",
    "attributes.write",
    "indexes.read",
    "indexes.write",
    "documents.read",
    "documents.write",
    "files.read",
    "files.write",
    "buckets.read",
    "buckets.write",
    "functions.read",
    "functions.write",
    "execution.read",
    "execution.write",
    "targets.read",
    "targets.write",
    "providers.read",
    "providers.write",
    "messages.read",
    "messages.write",
    "topics.read",
    "topics.write",
    "subscribers.read",
    "subscribers.write",
    "locale.read",
    "avatars.read",
    "health.read",
    "migrations.read",
    "migrations.write"
]

export function provisionDatabase(config) {
    const {
        endpoint,
        apiHeaders
    } = config;

    // Create database
    const databaseResponse = http.post(
        `${endpoint}/databases`,
        JSON.stringify({
            databaseId: 'unique()',
            name: 'Bulk Test DB'
        }),
        { headers: apiHeaders }
    );

    assert(databaseResponse, 'database created successfully', (r) => r.status === 201);

    const databaseId = databaseResponse.json('$id');

    // Create collection
    const collectionResponse = http.post(
        `${endpoint}/databases/${databaseId}/collections`,
        JSON.stringify({
            collectionId: 'unique()',
            name: 'Bulk Test Collection',
            permissions: ['read("any")', 'write("any")'],
            documentSecurity: false
        }),
        { headers: apiHeaders }
    );

    assert(collectionResponse, 'collection created successfully', (r) => r.status === 201);

    const collectionId = collectionResponse.json('$id');

    // Create name attribute
    const nameAttributeResponse = http.post(
        `${endpoint}/databases/${databaseId}/collections/${collectionId}/attributes/string`,
        JSON.stringify({
            key: 'name',
            size: 100,
            required: false,
            default: null,
            array: false,
            encrypt: false
        }),
        { headers: apiHeaders }
    );

    assert(nameAttributeResponse, 'name attribute created successfully', (r) => r.status === 202);

    // Create age attribute
    const ageAttributeResponse = http.post(
        `${endpoint}/databases/${databaseId}/collections/${collectionId}/attributes/integer`,
        JSON.stringify({
            key: 'age',
            required: false,
        }),
        { headers: apiHeaders }
    );

    assert(ageAttributeResponse, 'age attribute created successfully', (r) => r.status === 202);

    // Create email attribute
    const emailAttributeResponse = http.post(
        `${endpoint}/databases/${databaseId}/collections/${collectionId}/attributes/email`,
        JSON.stringify({
            key: 'email',
            required: false,
        }),
        { headers: apiHeaders }
    );

    assert(emailAttributeResponse, 'email attribute created successfully', (r) => r.status === 202);

    // Create height attribute
    const heightAttributeResponse = http.post(
        `${endpoint}/databases/${databaseId}/collections/${collectionId}/attributes/float`,
        JSON.stringify({
            key: 'height',
            required: false,
        }),
        { headers: apiHeaders }
    );

    assert(heightAttributeResponse, 'height attribute created successfully', (r) => r.status === 202);

    return {
        databaseId,
        collectionId
    };
}

export function cleanup(config) {
    const {
        endpoint,
        teamId,
        headers
    } = config;

    // Delete Organization
    const organizationResponse = http.del(
        `${endpoint}/teams/${teamId}`,
        null,
        {
            headers
        }
    );

    assert(organizationResponse, 'organization deleted successfully', (r) => r.status === 204);
}

export function unique() {
    const timestamp = Date.now().toString(36);
    const randomPart = Math.random().toString(36).substring(2, 15);
    return `${timestamp}-${randomPart}`;
}