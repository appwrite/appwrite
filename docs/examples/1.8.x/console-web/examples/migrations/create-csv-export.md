import { Client, Migrations } from "@appwrite.io/console";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>'); // Your project ID

const migrations = new Migrations(client);

const result = await migrations.createCSVExport({
    resourceId: '<ID1:ID2>',
    filename: '<FILENAME>',
    columns: [], // optional
    queries: [], // optional
    delimiter: '<DELIMITER>', // optional
    enclosure: '<ENCLOSURE>', // optional
    escape: '<ESCAPE>', // optional
    header: false, // optional
    notify: false // optional
});

console.log(result);
