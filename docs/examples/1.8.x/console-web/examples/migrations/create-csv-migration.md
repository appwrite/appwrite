import { Client, Migrations } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const migrations = new Migrations(client);

const result = await migrations.createCsvMigration({
    bucketId: '<BUCKET_ID>',
    fileId: '<FILE_ID>',
    resourceId: '<ID1:ID2>',
    internalFile: false // optional
});

console.log(result);
