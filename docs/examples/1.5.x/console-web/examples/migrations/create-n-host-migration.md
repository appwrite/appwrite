import { Client, Migrations } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

const migrations = new Migrations(client);

const result = await migrations.createNHostMigration(
    [], // resources
    '<SUBDOMAIN>', // subdomain
    '<REGION>', // region
    '<ADMIN_SECRET>', // adminSecret
    '<DATABASE>', // database
    '<USERNAME>', // username
    '<PASSWORD>', // password
    null // port (optional)
);

console.log(response);
