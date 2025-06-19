const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setSession(''); // The user session to authenticate with

const tables = new sdk.Tables(client);

const result = await tables.getRow(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>', // tableId
    '<ROW_ID>', // rowId
    [] // queries (optional)
);
