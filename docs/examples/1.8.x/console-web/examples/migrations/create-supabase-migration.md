import { Client, Migrations } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const migrations = new Migrations(client);

const result = await migrations.createSupabaseMigration(
    [], // resources
    'https://example.com', // endpoint
    '<API_KEY>', // apiKey
    '<DATABASE_HOST>', // databaseHost
    '<USERNAME>', // username
    '<PASSWORD>', // password
    null // port (optional)
);

console.log(result);
