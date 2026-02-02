import { Client, Migrations, Resources } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const migrations = new Migrations(client);

const result = await migrations.getNHostReport({
    resources: [Resources.User],
    subdomain: '<SUBDOMAIN>',
    region: '<REGION>',
    adminSecret: '<ADMIN_SECRET>',
    database: '<DATABASE>',
    username: '<USERNAME>',
    password: '<PASSWORD>',
    port: null // optional
});

console.log(result);
