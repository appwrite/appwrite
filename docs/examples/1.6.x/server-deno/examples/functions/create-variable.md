import { Client, Functions } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('&lt;YOUR_PROJECT_ID&gt;') // Your project ID
    .setKey('&lt;YOUR_API_KEY&gt;'); // Your secret API key

const functions = new Functions(client);

const response = await functions.createVariable(
    '<FUNCTION_ID>', // functionId
    '<KEY>', // key
    '<VALUE>' // value
);
