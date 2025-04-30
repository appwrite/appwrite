import { Client, Tokens } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tokens = new Tokens(client);

const result = await tokens.createFileToken(
    '<BUCKET_ID>', // bucketId
    '<FILE_ID>', // fileId
    '', // expire (optional)
    ["read("any")"] // permissions (optional)
);

console.log(result);
