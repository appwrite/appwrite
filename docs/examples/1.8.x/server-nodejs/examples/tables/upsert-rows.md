const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setAdmin('') // 
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const tables = new sdk.Tables(client);

const result = await tables.upsertRows(
    '<DATABASE_ID>', // databaseId
    '<TABLE_ID>' // tableId
);
