import { check, sleep } from "k6";
import http from "k6/http";
import { provisionProject, provisionDatabase, cleanup, unique } from "./utils.js";

const millionRecords = 1_000_000;
const batchSize = 10_000;
const numBatches = millionRecords / batchSize;

export function setup() {
    const resources = provisionProject({
        endpoint: 'http://localhost/v1',
        email: 'test@test.com',
        password: 'password123',
        name: 'Test User',
        projectName: 'Large Document Creation Test'
    });

    const { databaseId, collectionId } = provisionDatabase({
        endpoint: 'http://localhost/v1',
        apiHeaders: resources.apiHeaders
    });

    // Wait to ensure that provisioning is complete
    sleep(5);

    // Create an index for the collection
    const index = {
        key: "name",
        type: "fulltext",
        orders: ["ASC"],
        attributes: ["name", "email"]
    };

    const indexRes = http.post(`http://localhost/v1/databases/${databaseId}/collections/${collectionId}/indexes`,
        JSON.stringify(index), {
        headers: resources.apiHeaders
    });

    console.log(indexRes.status);

    check(indexRes, {
        "status is 202": (r) => r.status === 202,
    });

    console.log(`----- Inserting ${millionRecords} documents in ${numBatches} batches of ${batchSize} -----`);

    const timeStart = new Date();

    const requests = [];
    for (let i = 0; i < numBatches; i++) {
        const docs = Array.from({ length: batchSize }, () => ({
            $id: unique(),
            name: "bulk_document",
            age: Math.floor(Math.random() * 100),
            email: `${unique()}@test.com`,
            height: Math.random() * 100
        }));
        requests.push({
            method: "POST",
            url: `http://localhost/v1/databases/${databaseId}/collections/${collectionId}/documents`,
            body: JSON.stringify({ documents: docs }),
            params: {
                headers: resources.apiHeaders,
                timeout: '300s'
            }
        });
    }

    const responses = http.batch(requests);
    responses.forEach((res, index) => {
        if (res.status !== 201) {
            throw new Error(`Batch ${index + 1} failed with status ${res.status}`);
        }
    });

    const timeEnd = new Date();
    const timeTaken = timeEnd - timeStart;
    console.log(`Created 1 million documents in ${timeTaken} milliseconds`);

    return {
        databaseId,
        collectionId,
        apiHeaders: resources.apiHeaders,
        resources
    };
}

export default function (data) {
    const docs = Array.from({ length: 10000 }, () => ({
        $id: unique(),
        name: "performance_document",
        age: Math.floor(Math.random() * 100),
        email: `${unique()}@test.com`,
        height: Math.random() * 100
    }));

    const payload = JSON.stringify({ documents: docs });
    const res = http.post(
        `http://localhost/v1/databases/${data.databaseId}/collections/${data.collectionId}/documents`,
        payload,
        {
            headers: data.apiHeaders,
            timeout: '300s'
        }
    );

    check(res, {
        "status is 201": (r) => r.status === 201
    });

    sleep(1);
}

export function teardown(data) {
    cleanup(data.resources);
}

export const options = {
    scenarios: {
        large_document_creation: {
            executor: 'per-vu-iterations',
            vus: 1,
            iterations: 20,
            exec: 'default'
        }
    }
}; 