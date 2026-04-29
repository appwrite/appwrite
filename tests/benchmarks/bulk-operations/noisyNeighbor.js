import { check, sleep } from "k6";
import http from "k6/http";
import { Trend } from "k6/metrics";
import { provisionProject, provisionDatabase, cleanup, unique } from "./utils.js";

// Custom Trend metric for light response time tracking
export const lightResponseTime = new Trend("light_response_time", true);

const BULK_AMOUNT = 100_000; // Heavy operation amount
const LIGHT_AMOUNT = 10; // Light operation amount

export function setup() {
    // Set up two separate projects - one for bulk operations (noisy neighbor) and one for light operations
    const heavyResources = provisionProject({
        endpoint: 'http://localhost/v1',
        email: 'heavy@test.com',
        password: 'password123',
        name: 'Heavy User',
        projectName: 'Noisy Neighbor - Heavy'
    });

    const lightResources = provisionProject({
        endpoint: 'http://localhost/v1',
        email: 'light@test.com',
        password: 'password123',
        name: 'Light User',
        projectName: 'Noisy Neighbor - Light'
    });

    // Set up databases for both projects
    const heavy = provisionDatabase({
        endpoint: 'http://localhost/v1',
        apiHeaders: heavyResources.apiHeaders
    });

    const light = provisionDatabase({
        endpoint: 'http://localhost/v1',
        apiHeaders: lightResources.apiHeaders
    });

    sleep(3); // Await Attributes to be provisioned

    console.log(`----- Heavy operations: ${BULK_AMOUNT} documents | Light operations: ${LIGHT_AMOUNT} document -----`);

    return {
        heavy: {
            databaseId: heavy.databaseId,
            collectionId: heavy.collectionId,
            apiHeaders: heavyResources.apiHeaders,
            resources: heavyResources
        },
        light: {
            databaseId: light.databaseId,
            collectionId: light.collectionId,
            apiHeaders: lightResources.apiHeaders,
            resources: lightResources
        }
    };
}

export function teardown(data) {
    cleanup(data.heavy.resources);
    cleanup(data.light.resources);
}

// Create document payloads
function createDocuments(amount) {
    let documents = Array(amount).fill({
        $id: "unique()",
        name: "test",
    });

    return documents.map((document) => ({
        ...document,
        age: Math.floor(Math.random() * 100),
        email: `${unique()}@test.com`,
        height: Math.random() * 100,
    }));
}

// Heavy operation function
export function heavy(data) {
    const documents = createDocuments(BULK_AMOUNT);
    const payload = JSON.stringify({ documents });

    const res = http.post(
        `http://localhost/v1/databases/${data.heavy.databaseId}/collections/${data.heavy.collectionId}/documents`,
        payload,
        {
            headers: data.heavy.apiHeaders
        }
    );

    check(res, {
        "heavy operation status is 201": (r) => r.status === 201,
    });
}

// Light operation function
export function light(data) {
    const documents = createDocuments(LIGHT_AMOUNT);
    const payload = JSON.stringify({ documents });

    const startTime = new Date();
    const res = http.post(
        `http://localhost/v1/databases/${data.light.databaseId}/collections/${data.light.collectionId}/documents`,
        payload,
        {
            headers: data.light.apiHeaders
        }
    );
    const duration = new Date() - startTime;

    // Record the light operation response time using the custom Trend metric
    lightResponseTime.add(duration);

    check(res, {
        "light operation status is 201": (r) => r.status === 201,
    });
}

export const options = {
    scenarios: {
        // Heavy bulk operations running continuously
        heavy_load: {
            executor: 'constant-vus',
            vus: 5,
            duration: '30s',
            exec: 'heavy'
        },
        // Light operations to measure impact
        light_operations: {
            executor: 'constant-arrival-rate',
            rate: 5,
            timeUnit: '1s',
            duration: '30s',
            preAllocatedVUs: 10,
            exec: 'light'
        }
    },
    thresholds: {
        http_req_duration: ['p(95)<2000'], // 95% of requests should complete within 2s
    }
}; 