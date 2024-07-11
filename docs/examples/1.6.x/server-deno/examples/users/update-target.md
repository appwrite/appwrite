import { Client, Users } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

const users = new Users(client);

const response = await users.updateTarget(
    '<USER_ID>', // userId
    '<TARGET_ID>', // targetId
    '<IDENTIFIER>', // identifier (optional)
    '<PROVIDER_ID>', // providerId (optional)
    '<NAME>' // name (optional)
);
