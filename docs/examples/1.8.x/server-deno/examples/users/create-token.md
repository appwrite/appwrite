import { Client, Users } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const users = new Users(client);

const response = await users.createToken(
    '<USER_ID>', // userId
    4, // length (optional)
    60 // expire (optional)
);
