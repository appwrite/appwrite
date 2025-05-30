const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const databases = new sdk.Databases(client);

const result = await databases.createDocuments(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    [] // documents
);
