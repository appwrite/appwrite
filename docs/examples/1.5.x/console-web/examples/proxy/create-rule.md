import { Client, Proxy,  } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const proxy = new Proxy(client);

const result = await proxy.createRule(
    '', // domain
    .Api, // resourceType
    '<RESOURCE_ID>' // resourceId (optional)
);

console.log(response);
