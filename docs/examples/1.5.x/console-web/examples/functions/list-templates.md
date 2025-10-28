import { Client, Functions } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const functions = new Functions(client);

const result = await functions.listTemplates(
    [], // runtimes (optional)
    [], // useCases (optional)
    1, // limit (optional)
    0 // offset (optional)
);

console.log(result);
