import { Client, Migrations } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const migrations = new Migrations(client);

const result = await migrations.getNHostReport({
    resources: [],
    subdomain: '<SUBDOMAIN>',
    region: '<REGION>',
    adminSecret: '<ADMIN_SECRET>',
    database: '<DATABASE>',
    username: '<USERNAME>',
    password: '<PASSWORD>',
    port: null // optional
});

console.log(result);
