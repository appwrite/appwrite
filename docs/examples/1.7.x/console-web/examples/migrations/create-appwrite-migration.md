import { Client, Migrations } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const migrations = new Migrations(client);

const result = await migrations.createAppwriteMigration(
    [], // resources
    'https://example.com', // endpoint
    '<PROJECT_ID>', // projectId
    '<API_KEY>' // apiKey
);

console.log(result);
