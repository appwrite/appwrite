import { Client, Migrations, Resources } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const migrations = new Migrations(client);

const result = await migrations.getAppwriteReport({
    resources: [Resources.User],
    endpoint: 'https://example.com',
    projectID: '<PROJECT_ID>',
    key: '<KEY>'
});

console.log(result);
