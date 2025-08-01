import { Client, Migrations } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const migrations = new Migrations(client);

const result = await migrations.getNHostReport(
    [], // resources
    '<SUBDOMAIN>', // subdomain
    '<REGION>', // region
    '<ADMIN_SECRET>', // adminSecret
    '<DATABASE>', // database
    '<USERNAME>', // username
    '<PASSWORD>', // password
    null // port (optional)
);

console.log(result);
