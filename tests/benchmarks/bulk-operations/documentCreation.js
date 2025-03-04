import { check, sleep } from "k6";
import http from "k6/http";
import { provisionProject, provisionDatabase, cleanup, unique } from "./utils.js";

const amount = 10_000;

export function setup() {
    const resources = provisionProject({
        endpoint: 'http://localhost/v1',
        email: 'test@test.com',
        password: 'password123',
        name: 'Test User',
        projectName: 'Bulk Operations Test'
    });

    const { databaseId, collectionId } = provisionDatabase({
        endpoint: 'http://localhost/v1',
        apiHeaders: resources.apiHeaders
    });

    sleep(3); // Await Attributes to be provisioned

    console.log(`----- Amount of documents: ${amount} -----`);

    return {
        databaseId,
        collectionId,
        apiHeaders: resources.apiHeaders,
        resources
    };
}

export function teardown(data) {
    cleanup(data.resources);
}

let documents = Array(amount).fill({
    $id: "unique()",
    name: "asd",
});

documents = documents.map((document) => {
    return {
        ...document,
        age: Math.floor(Math.random() * 100),
        email: `${unique()}@test.com`,
        height: Math.random() * 100,
    };
});

export default function (data) {
    const payload = JSON.stringify({
        documents,
    });

    const res = http.post(
        `http://localhost/v1/databases/${data.databaseId}/collections/${data.collectionId}/documents`,
        payload,
        {
            headers: data.apiHeaders
        }
    );

    check(res, {
        "status is 201": (r) => r.status === 201,
    });

    return {
        resources: data.resources
    };
}

export const options = {
    scenarios: {
        bulk_create: {
            executor: 'per-vu-iterations',
            vus: 1,
            iterations: 20,
            exec: 'default'
        }
    }
};
