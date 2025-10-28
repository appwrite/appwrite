const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const databases = new sdk.Databases(client);

const result = await databases.createStringAttribute(
    '<DATABASE_ID>', // databaseId
    '<COLLECTION_ID>', // collectionId
    '', // key
    1, // size
    false, // required
    '<DEFAULT>', // default (optional)
    false, // array (optional)
    false // encrypt (optional)
);
