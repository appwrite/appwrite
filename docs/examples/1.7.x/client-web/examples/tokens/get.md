import { Client, Tokens } from "appwrite";

const client = new Client()
    .setEndpoint('https://example.com/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const tokens = new Tokens(client);

const result = await tokens.get(
    '<TOKEN_ID>' // tokenId
);

console.log(result);
