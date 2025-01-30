import { Client, Functions } from "https://deno.land/x/appwrite/mod.ts";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const response = await functions.listTemplates(
    [], // runtimes (optional)
    [], // useCases (optional)
    1, // limit (optional)
    0 // offset (optional)
);
