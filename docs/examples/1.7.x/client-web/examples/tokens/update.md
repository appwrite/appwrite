import { Client, Tokens } from "appwrite";

const client = new Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tokens = new Tokens(client);

const result = await tokens.update(
    '<TOKEN_ID>', // tokenId
    '', // expire (optional)
    ["read("any")"] // permissions (optional)
);

console.log(result);
