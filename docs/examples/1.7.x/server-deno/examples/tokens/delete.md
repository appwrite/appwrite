import { Client, Tokens } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const tokens = new Tokens(client);

const response = await tokens.delete(
    '<TOKEN_ID>' // tokenId
);
